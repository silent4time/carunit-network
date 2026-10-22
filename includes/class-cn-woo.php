<?php
if (!defined('ABSPATH')) {
    exit;
}

class CN_Woo {
    const ITEM_PLAN    = '_cn_plan_id';
    const ITEM_RENEW   = '_cn_renew';
    const ITEM_RENEWED = '_cn_renewed';
    const ITEM_ERROR   = '_cn_error';
    const ORDER_FAILED = '_cn_failed';

    public static function init() {
        add_action('woocommerce_product_options_general_product_data', array(__CLASS__, 'product_field'));
        add_action('woocommerce_admin_process_product_object', array(__CLASS__, 'save_product_field'));
        add_action('woocommerce_product_after_variable_attributes', array(__CLASS__, 'variation_field'), 10, 3);
        add_action('woocommerce_save_product_variation', array(__CLASS__, 'save_variation_field'), 10, 2);

        add_filter('woocommerce_add_to_cart_validation', array(__CLASS__, 'validate_add'), 10, 5);
        add_filter('woocommerce_add_cart_item_data', array(__CLASS__, 'cart_item_data'), 10, 3);
        add_filter('woocommerce_get_item_data', array(__CLASS__, 'cart_item_display'), 10, 2);
        add_action('woocommerce_check_cart_items', array(__CLASS__, 'check_cart'));
        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'order_item_meta'), 10, 4);

        add_action('woocommerce_order_status_processing', array(__CLASS__, 'deliver'), 20);
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'deliver'), 20);
        add_action('woocommerce_payment_complete', array(__CLASS__, 'deliver'), 20);
        add_action('woocommerce_order_status_cancelled', array(__CLASS__, 'on_cancel'));
        add_action('woocommerce_order_status_refunded', array(__CLASS__, 'on_cancel'));

        add_action('woocommerce_order_details_after_order_table', array(__CLASS__, 'order_details'), 10);
        add_action('woocommerce_email_after_order_table', array(__CLASS__, 'email_block'), 10, 4);
        add_filter('woocommerce_email_enabled_customer_processing_order', array(__CLASS__, 'skip_processing_email'), 10, 2);

        add_filter('woocommerce_order_actions', array(__CLASS__, 'order_actions'), 10, 2);
        add_action('woocommerce_order_action_cn_deliver', array(__CLASS__, 'action_deliver'));
        add_action('add_meta_boxes', array(__CLASS__, 'meta_box'));
        add_action('admin_notices', array(__CLASS__, 'failed_notice'));
    }

    /* ---------- product ↔ plan ---------- */

    private static function plan_options() {
        $panels = array();
        foreach (CN_Panels::all() as $p) {
            $panels[$p['id']] = $p['name'];
        }
        $opts = array('' => '— بدون پلن (محصول عادی) —');
        foreach (CN_Plans::all() as $pl) {
            $opts[$pl['id']] = (isset($panels[$pl['panel_id']]) ? $panels[$pl['panel_id']] . ' / ' : '') . $pl['name'] . ' — ' . CN_Plans::summary($pl);
        }
        return $opts;
    }

    public static function product_field() {
        global $product_object;
        echo '<div class="options_group show_if_simple">';
        woocommerce_wp_select(array(
            'id'          => 'cn_plan_id',
            'label'       => 'پلن ابزار شبکه',
            'options'     => self::plan_options(),
            'value'       => $product_object ? (string) $product_object->get_meta(CN_Plans::META) : '',
            'desc_tip'    => true,
            'description' => 'اگر پلن انتخاب شود، بعد از پرداخت موفق اکانت به‌صورت خودکار ساخته و به مشتری تحویل داده می‌شود.',
        ));
        echo '</div>';
    }

    public static function save_product_field($product) {
        if (!isset($_POST['cn_plan_id'])) {
            return;
        }
        $plan = (int) $_POST['cn_plan_id'];
        if ($plan) {
            $product->update_meta_data(CN_Plans::META, $plan);
        } else {
            $product->delete_meta_data(CN_Plans::META);
        }
        $product_id = (int) $product->get_id();
        add_action('woocommerce_after_product_object_save', function ($p) use ($plan, $product_id) {
            global $wpdb;
            if ((int) $p->get_id() !== $product_id) {
                return;
            }
            $wpdb->update(CN_DB::t('plans'), array('product_id' => 0), array('product_id' => $product_id));
            if ($plan) {
                $wpdb->update(CN_DB::t('plans'), array('product_id' => (int) $p->get_id()), array('id' => $plan));
            }
        });
    }

    public static function variation_field($loop, $data, $variation) {
        woocommerce_wp_select(array(
            'id'            => 'cn_plan_id_' . $loop,
            'name'          => 'cn_plan_id[' . $loop . ']',
            'label'         => 'پلن ابزار شبکه',
            'options'       => self::plan_options(),
            'value'         => (string) get_post_meta($variation->ID, CN_Plans::META, true),
            'wrapper_class' => 'form-row form-row-full',
        ));
    }

    public static function save_variation_field($variation_id, $loop) {
        if (!isset($_POST['cn_plan_id'][$loop])) {
            return;
        }
        $plan = (int) $_POST['cn_plan_id'][$loop];
        if ($plan) {
            update_post_meta($variation_id, CN_Plans::META, $plan);
        } else {
            delete_post_meta($variation_id, CN_Plans::META);
        }
    }

    /* ---------- cart ---------- */

    /** Service the current user may renew with a given plan, or WP_Error. */
    public static function renewable($service_id, $plan) {
        $svc = CN_Services::get($service_id);
        if (!$svc || !is_user_logged_in() || $svc['user_id'] !== get_current_user_id() || $svc['status'] === 'deleted') {
            return new WP_Error('cn_owner', 'این سرویس متعلق به حساب شما نیست.');
        }
        if (!$plan || $plan['panel_id'] !== $svc['panel_id']) {
            return new WP_Error('cn_plan', 'این محصول برای تمدید این سرویس مناسب نیست.');
        }
        $cls = CN_Drivers::cls(($p = CN_Panels::get($svc['panel_id'])) ? $p['type'] : '');
        if (!$cls || !$cls::supports('renew')) {
            return new WP_Error('cn_renew', 'تمدید این سرویس پشتیبانی نمی‌شود؛ لطفاً سرویس جدید بخرید.');
        }
        return $svc;
    }

    /** Service id from a renew link: 0 = none, -1 = bad nonce. */
    public static function requested_renew() {
        if (empty($_REQUEST['cn_renew'])) {
            return 0;
        }
        $id = (int) $_REQUEST['cn_renew'];
        if (!isset($_REQUEST['_cn_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_cn_nonce'])), 'cn_renew_' . $id)) {
            return -1;
        }
        return $id;
    }

    public static function validate_add($passed, $product_id, $qty, $variation_id = 0, $variations = array()) {
        $plan = CN_Plans::for_product($variation_id ? $variation_id : $product_id);
        if (!$plan) {
            return $passed;
        }
        if ($plan['status'] !== 'active') {
            wc_add_notice('این پلن فعلاً قابل خرید نیست.', 'error');
            return false;
        }
        $panel = CN_Panels::get($plan['panel_id']);
        if (!$panel || $panel['status'] !== 'active') {
            wc_add_notice('فروش این سرویس موقتاً متوقف است.', 'error');
            return false;
        }
        $renew = self::requested_renew();
        if ($renew === -1) {
            wc_add_notice('لینک تمدید منقضی شده است؛ دوباره از صفحهٔ «سرویس‌های من» اقدام کنید.', 'error');
            return false;
        }
        if ($renew) {
            $r = self::renewable($renew, $plan);
            if (is_wp_error($r)) {
                wc_add_notice($r->get_error_message(), 'error');
                return false;
            }
        } elseif ($panel['type'] === 'manual' && CN_Stock::count_free($plan['id']) < (int) $qty) {
            wc_add_notice('موجودی این سرویس کافی نیست.', 'error');
            return false;
        }
        return $passed;
    }

    public static function cart_item_data($data, $product_id, $variation_id) {
        $renew = self::requested_renew();
        if ($renew > 0) {
            $data['cn_renew'] = $renew;
            $data['cn_key'] = md5('renew' . $renew . microtime());
        }
        return $data;
    }

    public static function cart_item_display($rows, $cart_item) {
        if (!empty($cart_item['cn_renew'])) {
            $svc = CN_Services::get((int) $cart_item['cn_renew']);
            $rows[] = array('name' => 'تمدید سرویس', 'value' => $svc ? esc_html($svc['username']) : '#' . (int) $cart_item['cn_renew']);
        }
        return $rows;
    }

    public static function check_cart() {
        if (!WC()->cart) {
            return;
        }
        $need = array();
        foreach (WC()->cart->get_cart() as $ci) {
            $plan = CN_Plans::for_product($ci['variation_id'] ? $ci['variation_id'] : $ci['product_id']);
            if (!$plan) {
                continue;
            }
            if (!empty($ci['cn_renew'])) {
                $r = self::renewable((int) $ci['cn_renew'], $plan);
                if (is_wp_error($r)) {
                    wc_add_notice($r->get_error_message(), 'error');
                }
                continue;
            }
            $panel = CN_Panels::get($plan['panel_id']);
            if ($panel && $panel['type'] === 'manual') {
                $need[$plan['id']] = (isset($need[$plan['id']]) ? $need[$plan['id']] : 0) + (int) $ci['quantity'];
            }
        }
        foreach ($need as $plan_id => $qty) {
            if (CN_Stock::count_free($plan_id) < $qty) {
                $plan = CN_Plans::get($plan_id);
                wc_add_notice('موجودی «' . esc_html($plan ? $plan['name'] : '') . '» کافی نیست.', 'error');
            }
        }
    }

    public static function order_item_meta($item, $cart_item_key, $values, $order) {
        $plan = CN_Plans::for_product(!empty($values['variation_id']) ? $values['variation_id'] : $values['product_id']);
        if (!$plan) {
            return;
        }
        $item->add_meta_data(self::ITEM_PLAN, $plan['id'], true);
        if (!empty($values['cn_renew'])) {
            $item->add_meta_data(self::ITEM_RENEW, (int) $values['cn_renew'], true);
        }
    }

    /* ---------- delivery ---------- */

    private static function item_plan($item) {
        $id = (int) $item->get_meta(self::ITEM_PLAN);
        if ($id) {
            return CN_Plans::get($id);
        }
        $product = $item->get_product();
        return $product ? CN_Plans::for_product($product) : null;
    }

    public static function order_has_services($order) {
        foreach ($order->get_items() as $item) {
            if (self::item_plan($item)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Create or renew accounts for every unpaid-for unit; safe to call many times.
     * @return array created, renewed
     */
    public static function deliver($order_id) {
        $created = 0;
        $renewed = 0;
        $order = $order_id instanceof WC_Order ? $order_id : wc_get_order($order_id);
        if (!$order || !$order->is_paid()) {
            return array('created' => 0, 'renewed' => 0);
        }
        $order_id = $order->get_id();
        if (!CN_Util::lock('order_' . $order_id, 300)) {
            return array('created' => 0, 'renewed' => 0);
        }
        try {
            $any = false;
            $failed = false;
            $all_ours = true;
            foreach ($order->get_items() as $item_id => $item) {
                $plan = self::item_plan($item);
                if (!$plan) {
                    $all_ours = false;
                    continue;
                }
                $any = true;
                $qty = max(0, (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item($item_id));
                $error = '';
                $renew_id = (int) $item->get_meta(self::ITEM_RENEW);
                if ($renew_id) {
                    $done = (int) $item->get_meta(self::ITEM_RENEWED);
                    for ($i = $done; $i < $qty; $i++) {
                        $svc = CN_Services::get($renew_id);
                        if (!$svc || $svc['user_id'] !== (int) $order->get_customer_id() || $svc['panel_id'] !== $plan['panel_id']) {
                            $error = 'سرویس قابل تمدید پیدا نشد.';
                            break;
                        }
                        $r = CN_Services::renew($svc, $plan, array('order_id' => $order_id));
                        if (is_wp_error($r)) {
                            $error = $r->get_error_message();
                            break;
                        }
                        $renewed++;
                        $item->update_meta_data(self::ITEM_RENEWED, $i + 1);
                        $item->save();
                    }
                } else {
                    $have = count(CN_Services::for_item($item_id));
                    for ($i = $have; $i < $qty; $i++) {
                        $r = CN_Services::create($plan, array(
                            'order_id' => $order_id,
                            'item_id'  => $item_id,
                            'user_id'  => (int) $order->get_customer_id(),
                            'note'     => 'order ' . $order->get_order_number(),
                        ));
                        if (is_wp_error($r)) {
                            $error = $r->get_error_message();
                            break;
                        }
                        $created++;
                    }
                }
                if ($error !== '') {
                    $failed = true;
                    $item->update_meta_data(self::ITEM_ERROR, $error);
                } else {
                    $item->delete_meta_data(self::ITEM_ERROR);
                }
                $item->save();
            }
            if (!$any) {
                return array('created' => 0, 'renewed' => 0);
            }
            if ($created || $renewed) {
                $order->add_order_note(sprintf('ابزار شبکه: %s اکانت ساخته و %s سرویس تمدید شد.', CN_Util::fa_digits($created), CN_Util::fa_digits($renewed)));
            }
            if ($failed) {
                $order->update_meta_data(self::ORDER_FAILED, 1);
                $order->add_order_note('ابزار شبکه: تحویل بخشی از سفارش ناموفق بود؛ جزئیات در گزارش افزونه. از «عملیات سفارش» می‌توانید دوباره تلاش کنید.');
            } else {
                $order->delete_meta_data(self::ORDER_FAILED);
            }
            $order->save();
            if (!$failed && $all_ours && $order->has_status('processing') && CN_Util::setting('auto_complete', '1') === '1') {
                $order->update_status('completed', 'ابزار شبکه: تحویل کامل شد.');
            }
        } finally {
            CN_Util::unlock('order_' . $order_id);
        }
        return array('created' => $created, 'renewed' => $renewed);
    }

    public static function on_cancel($order_id) {
        if (CN_Util::setting('disable_on_cancel', '1') !== '1') {
            return;
        }
        foreach (CN_Services::for_order($order_id) as $svc) {
            if ($svc['status'] === 'active') {
                CN_Services::action($svc, 'disable');
            }
        }
    }

    /* ---------- display ---------- */

    /** Services bought or renewed by this order: list of [service, renewed]. */
    public static function order_services($order) {
        $out = array();
        foreach (CN_Services::for_order($order->get_id()) as $svc) {
            $out[$svc['id']] = array($svc, false);
        }
        foreach ($order->get_items() as $item) {
            $id = (int) $item->get_meta(self::ITEM_RENEW);
            if ($id && (int) $item->get_meta(self::ITEM_RENEWED) > 0 && !isset($out[$id])) {
                $svc = CN_Services::get($id);
                if ($svc && $svc['user_id'] === (int) $order->get_customer_id()) {
                    $out[$id] = array($svc, true);
                }
            }
        }
        return array_values($out);
    }

    public static function order_details($order) {
        $services = self::order_services($order);
        $pending = false;
        foreach ($order->get_items() as $item) {
            if (self::item_plan($item) && !$item->get_meta(self::ITEM_RENEW)) {
                $pending = $pending || count(CN_Services::for_item($item->get_id())) < (int) $item->get_quantity();
            }
        }
        if (!$services && !$pending) {
            return;
        }
        CN_Account::enqueue();
        echo '<section class="cn-wrap" dir="rtl" lang="fa"><h2 class="cn-title">اطلاعات سرویس</h2>';
        foreach ($services as $s) {
            CN_Account::render_service($s[0], 'order', $s[1]);
        }
        if ($pending) {
            echo '<div class="cn-note">' . ($order->is_paid()
                ? 'سرویس شما در حال آماده‌سازی است؛ چند دقیقهٔ دیگر این صفحه را تازه کنید. اگر آماده نشد با پشتیبانی تماس بگیرید.'
                : 'بعد از تأیید پرداخت، اطلاعات سرویس همین‌جا نمایش داده می‌شود.') . '</div>';
        }
        echo '</section>';
    }

    public static function email_block($order, $sent_to_admin, $plain_text, $email = null) {
        if ($sent_to_admin) {
            return;
        }
        $services = self::order_services($order);
        if (!$services) {
            return;
        }
        $account = wc_get_account_endpoint_url(CN_Account::ENDPOINT);
        if ($plain_text) {
            echo "\n==== اطلاعات سرویس ====\n";
            foreach ($services as $s) {
                echo CN_Account::plain_service($s[0], $s[1]) . "\n\n";
            }
            echo 'سرویس‌های من (مصرف، فایل‌ها و تمدید): ' . esc_url_raw($account) . "\n";
            return;
        }
        echo '<div dir="rtl" lang="fa" style="margin:24px 0;text-align:right;direction:rtl">';
        echo '<h2 style="color:#0D0D0D;border-right:4px solid #F5C518;padding-right:8px;margin:0 0 12px;font-size:18px">اطلاعات سرویس</h2>';
        foreach ($services as $s) {
            echo CN_Account::email_service($s[0], $s[1]);
        }
        echo '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:4px 0 8px"><tr><td align="center" bgcolor="#0D0D0D" style="border-radius:8px">';
        echo '<a href="' . esc_url($account) . '" target="_blank" style="display:block;padding:12px 16px;color:#F5C518;background:#0D0D0D;border-radius:8px;font-weight:bold;font-size:14px;text-decoration:none;text-align:center">رفتن به سرویس‌های من</a></td></tr></table>';
        echo '<p style="color:#6E6E6E;font-size:12px;margin:6px 0 0">حجم مصرفی، تاریخ انقضا، فایل‌های اتصال و تمدید همیشه در حساب کاربری شما در دسترس است.</p>';
        echo '</div>';
    }

    /** When delivery already completed the order, the "completed" email carries the details; skip the duplicate. */
    public static function skip_processing_email($enabled, $order = null) {
        if (!$enabled || !$order instanceof WC_Order) {
            return $enabled;
        }
        $fresh = wc_get_order($order->get_id());
        return $fresh && $fresh->has_status('completed') && CN_Services::for_order($order->get_id()) ? false : $enabled;
    }

    /* ---------- admin order screen ---------- */

    public static function order_actions($actions, $order = null) {
        if ($order && self::order_has_services($order)) {
            $actions['cn_deliver'] = 'ابزار شبکه: ساخت/تمدید دوبارهٔ اکانت‌های تحویل‌نشده';
        }
        return $actions;
    }

    public static function action_deliver($order) {
        if (!$order->is_paid()) {
            $order->add_order_note('ابزار شبکه: سفارش هنوز پرداخت‌شده نیست؛ تحویل انجام نشد.');
            return;
        }
        $was_completed = $order->has_status('completed');
        $r = self::deliver($order);
        $auto_completed = !$was_completed && $order->has_status('completed');
        if (($r['created'] || $r['renewed']) && !$auto_completed && function_exists('WC') && WC()->mailer()) {
            WC()->mailer()->customer_invoice($order);
            $order->add_order_note('ابزار شبکه: ایمیل اطلاعات سرویس برای مشتری ارسال شد.');
        }
    }

    public static function meta_box() {
        $screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';
        add_meta_box('cn-order-services', 'ابزار شبکه', array(__CLASS__, 'render_meta_box'), $screen, 'side', 'default');
    }

    public static function render_meta_box($post_or_order) {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID);
        if (!$order) {
            return;
        }
        $services = CN_Services::for_order($order->get_id());
        $errors = array();
        foreach ($order->get_items() as $item) {
            if ($e = $item->get_meta(self::ITEM_ERROR)) {
                $errors[] = $item->get_name() . ': ' . $e;
            }
        }
        if (!$services && !$errors) {
            echo '<p style="color:#6E6E6E">سرویسی برای این سفارش ثبت نشده است.</p>';
            return;
        }
        echo '<div dir="rtl">';
        foreach ($services as $svc) {
            $url = admin_url('admin.php?page=cn-services&service=' . $svc['id']);
            echo '<p><a href="' . esc_url($url) . '"><code>' . esc_html($svc['username']) . '</code></a> — ' . esc_html(CN_Util::status_label($svc['status'])) . '</p>';
        }
        foreach ($errors as $e) {
            echo '<p style="color:#b32d2e">' . esc_html($e) . '</p>';
        }
        echo '</div>';
    }

    public static function failed_notice() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $ids = wc_get_orders(array('limit' => 10, 'return' => 'ids', 'meta_key' => self::ORDER_FAILED, 'meta_value' => '1'));
        if (!$ids) {
            return;
        }
        $links = array();
        foreach ($ids as $id) {
            $o = wc_get_order($id);
            if ($o) {
                $links[] = '<a href="' . esc_url($o->get_edit_order_url()) . '">#' . esc_html($o->get_order_number()) . '</a>';
            }
        }
        echo '<div class="notice notice-error" dir="rtl"><p><strong>ابزار شبکه:</strong> تحویل اکانت در این سفارش‌ها ناموفق بود: ' . implode('، ', $links) . ' — در صفحهٔ سفارش از «عملیات سفارش» دوباره تلاش کنید.</p></div>';
    }
}
