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
    const ORDER_MAILED = '_cn_mailed_services';
    const ORDER_RETRY  = '_cn_retry_attempt';
    const ITEM_TRY_USER = '_cn_try_user';
    const ITEM_MANUAL  = '_cn_manual_check';
    const MANUAL_NOTE  = 'ابزار شبکه: نیاز به بررسی دستی';
    const AS_GROUP     = 'carunit-network';
    const READY_NOTE   = 'ابزار شبکه: سرویس شما آماده شد';
    const ORDER_ALERTED = '_cn_admin_alerted';   // 1 = first-failure alert sent, 2 = final alert sent
    const ORDER_PARTIAL = '_cn_partial_mailed';  // customer got the partial-delivery email

    /** Retry delays after a failed delivery (seconds): 2, 10, 30 minutes. */
    public static $retry_delays = array(120, 600, 1800);

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

        // Priority 5: before WC_Emails (priority 10 on the same status hook) builds the customer email.
        add_action('woocommerce_order_status_processing', array(__CLASS__, 'deliver_on_status'), 5, 2);
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'deliver_on_status'), 5, 2);
        add_action('woocommerce_payment_complete', array(__CLASS__, 'deliver'), 5);
        add_action('cn_retry_delivery', array(__CLASS__, 'retry_delivery'));
        add_action('cn_send_ready_mail', array(__CLASS__, 'send_ready_mail'));
        add_action('woocommerce_email_order_details', array(__CLASS__, 'record_mailed'), 100, 4);
        add_filter('woocommerce_email_classes', array(__CLASS__, 'email_classes'));
        add_filter('woocommerce_email_enabled_customer_note', array(__CLASS__, 'skip_ready_note_email'), 10, 3);
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

    /**
     * v1.2.5: undelivered network lines: list of [name, qty_missing, error].
     */
    public static function pending_items($order) {
        $out = array();
        $order = $order instanceof WC_Order ? $order : wc_get_order($order);
        if (!$order) {
            return $out;
        }
        foreach ($order->get_items() as $item_id => $item) {
            if (!self::item_plan($item)) {
                continue;
            }
            $qty = max(0, (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item($item_id));
            $done = $item->get_meta(self::ITEM_RENEW) ? (int) $item->get_meta(self::ITEM_RENEWED) : count(CN_Services::for_item($item_id));
            if ($done < $qty) {
                $out[] = array($item->get_name(), $qty - $done, (string) $item->get_meta(self::ITEM_ERROR));
            }
        }
        return $out;
    }

    /** Customer-facing «در حال انجام» box for an item that is not delivered yet. */
    public static function pending_box_html($name, $qty = 1) {
        $font = 'font-family:Tahoma,Arial,sans-serif;';
        return '<div style="' . $font . 'background:#FFFBEA;border:1px solid #F5C518;border-top:4px solid #F5C518;border-radius:14px;padding:16px 18px;margin:0 0 14px;color:#2C2C2C;font-size:14px;line-height:2;direction:rtl;text-align:right;">'
            . '<strong style="color:#0D0D0D;">' . esc_html($name) . '</strong>'
            . ((int) $qty > 1 ? ' <span style="color:#6E6E6E;">(' . esc_html(CN_Util::fa_digits((string) (int) $qty)) . ' عدد)</span>' : '')
            . ' <span style="display:inline-block;background:#F5C518;color:#0D0D0D;border-radius:6px;padding:0 8px;font-size:12px;font-weight:bold;">در حال انجام</span>'
            . '<br>این محصول در حال آماده‌سازی است و به‌زودی برایتان ارسال می‌شود.</div>';
    }

    /** Admin recipients: site admin email + WooCommerce «new order» recipients. */
    public static function admin_recipients() {
        $to = array(get_option('admin_email'));
        if (function_exists('WC') && WC()->mailer()) {
            $emails = WC()->mailer()->get_emails();
            if (isset($emails['WC_Email_New_Order'])) {
                $to = array_merge($to, array_map('trim', explode(',', (string) $emails['WC_Email_New_Order']->get_recipient())));
            }
        }
        return array_values(array_unique(array_filter($to, 'is_email')));
    }

    /**
     * Alert the admin once on the first failure and once more when no automatic retry is left (final).
     */
    public static function maybe_alert_admin($order, $final) {
        $level = (int) $order->get_meta(self::ORDER_ALERTED);
        if ($level >= 2 || ($level >= 1 && !$final)) {
            return false;
        }
        $pending = self::pending_items($order);
        if (!$pending) {
            return false;
        }
        $order->update_meta_data(self::ORDER_ALERTED, $final ? 2 : 1);
        $order->save();
        $rows = '';
        foreach ($pending as $p) {
            $rows .= '<li><strong>' . esc_html($p[0]) . '</strong> × ' . (int) $p[1] . '<br><code dir="ltr" style="white-space:pre-wrap">' . esc_html($p[2] !== '' ? $p[2] : '—') . '</code></li>';
        }
        $url = method_exists($order, 'get_edit_order_url') ? $order->get_edit_order_url() : admin_url('post.php?post=' . $order->get_id() . '&action=edit');
        $state = $final ? 'تلاش خودکار دیگری انجام نمی‌شود؛ بررسی دستی لازم است.' : 'تلاش خودکار دوباره زمان‌بندی شد.';
        $body = '<div dir="rtl" style="font-family:Tahoma,Arial,sans-serif;text-align:right">'
            . '<p>تحویل بخشی از سفارش <strong>#' . esc_html($order->get_order_number()) . '</strong> ناموفق بود. ' . esc_html($state) . '</p>'
            . '<ul>' . $rows . '</ul>'
            . '<p><a href="' . esc_url($url) . '">باز کردن سفارش در پیشخوان</a></p></div>';
        $subject = sprintf('[%s] %s تحویل سفارش %s ناموفق بود', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES), $final ? 'هشدار نهایی:' : 'هشدار:', $order->get_order_number());
        $headers = array('Content-Type: text/html; charset=UTF-8');
        $sent = false;
        foreach (self::admin_recipients() as $to) {
            $msg = function_exists('WC') && WC()->mailer() ? WC()->mailer()->wrap_message('خطا در تحویل سفارش', $body) : $body;
            $sent = wp_mail($to, $subject, $msg, $headers) || $sent;
        }
        $order->add_order_note($sent ? 'ابزار شبکه: هشدار خطای تحویل برای مدیر فرستاده شد.' : 'ابزار شبکه: ارسال هشدار مدیر ناموفق بود.');
        return $sent;
    }

    /**
     * v1.2.5: true when every network line of the order is fully delivered (no error, manual check or pending retry).
     * Used by other CarUnit plugins (license manager) to decide whether a mixed order can be completed.
     */
    public static function items_ready($order) {
        $order = $order instanceof WC_Order ? $order : wc_get_order($order);
        if (!$order) {
            return false;
        }
        if ($order->get_meta(self::ORDER_FAILED)) {
            return false;
        }
        foreach ($order->get_items() as $item_id => $item) {
            if (!self::item_plan($item)) {
                continue;
            }
            if ($item->get_meta(self::ITEM_MANUAL) || (string) $item->get_meta(self::ITEM_ERROR) !== '') {
                return false;
            }
            $qty = max(0, (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item($item_id));
            $done = $item->get_meta(self::ITEM_RENEW) ? (int) $item->get_meta(self::ITEM_RENEWED) : count(CN_Services::for_item($item_id));
            if ($done < $qty) {
                return false;
            }
        }
        return true;
    }

    /** v1.2.8: is this order line a network service (by item meta or by product→plan mapping)? */
    public static function is_network_item($item) {
        return (bool) self::item_plan($item);
    }

    /** v1.2.8: are all non-network lines (license products) delivered? True when the order has none. */
    public static function foreign_ready($order) {
        foreach ($order->get_items() as $item) {
            if (!self::item_plan($item)) {
                return (bool) apply_filters('carunit_order_foreign_items_ready', false, $order);
            }
        }
        return true;
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
            return array('created' => 0, 'renewed' => 0, 'locked' => true);
        }
        try {
            $any = false;
            $failed = false;
            $manual_only = null; // true when every failed item waits for a manual check
            $all_ours = true;
            foreach ($order->get_items() as $item_id => $item) {
                $plan = self::item_plan($item);
                if (!$plan) {
                    $all_ours = false;
                    continue;
                }
                $any = true;
                if (!$item->get_meta(self::ITEM_PLAN) && !empty($plan['id'])) {
                    // v1.2.8: REST/admin orders skip checkout meta; stamp it so other plugins recognise network lines.
                    $item->update_meta_data(self::ITEM_PLAN, (int) $plan['id']);
                    $item->save();
                }
                // Automatic retries never touch items waiting for a manual check (possible paid purchase).
                if (!empty(self::$running['cn_retry_delivery']) && ($item->get_meta(self::ITEM_MANUAL) || ($item->get_meta(self::ITEM_ERROR) !== '' && self::manual_plan($plan)))) {
                    if (!$item->get_meta(self::ITEM_MANUAL)) {
                        self::flag_manual($order, $item_id, $item, $plan, (string) $item->get_meta(self::ITEM_ERROR));
                    }
                    $failed = true;
                    if ($manual_only === null) {
                        $manual_only = true;
                    }
                    continue;
                }
                $qty = max(0, (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item($item_id));
                $error = '';
                $error_code = '';
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
                            $error_code = method_exists($r, 'get_error_code') ? $r->get_error_code() : '';
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
                            $error_code = method_exists($r, 'get_error_code') ? $r->get_error_code() : '';
                            break;
                        }
                        $created++;
                    }
                }
                if ($error !== '') {
                    $failed = true;
                    $item->update_meta_data(self::ITEM_ERROR, $error);
                    if ($error_code === 'cn_unsure' || self::manual_plan($plan)) {
                        self::flag_manual($order, $item_id, $item, $plan, $error);
                        if ($manual_only === null) {
                            $manual_only = true;
                        }
                    } else {
                        $manual_only = false;
                    }
                } else {
                    $item->delete_meta_data(self::ITEM_ERROR);
                    $item->delete_meta_data(self::ITEM_MANUAL);
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
                $order->delete_meta_data(self::ORDER_RETRY);
            }
            $order->save();
            $final = false;
            if ($failed) {
                $final = $manual_only === true || !self::schedule_retry($order);
                self::maybe_alert_admin($order, $final);
            }
            if ($created || $renewed || ($failed && !$order->get_meta(self::ORDER_PARTIAL))) {
                self::queue_ready_mail($order_id);
            }
            // v1.2.5: mixed orders (e.g. + license products) complete once the other plugin reports its lines delivered.
            $others_ready = $all_ours ? true : self::foreign_ready($order);
            if (!$failed && $others_ready && $order->has_status('processing') && CN_Util::setting('auto_complete', '1') === '1') {
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

    /* ---------- delivery timing, retry, late mail ---------- */

    /** Status hooks pass ($order_id, $order): use that same object so the email built next sees fresh item meta. */
    public static function deliver_on_status($order_id, $order = null) {
        return self::deliver($order instanceof WC_Order ? $order : $order_id);
    }

    private static function as_ok() {
        return function_exists('as_schedule_single_action');
    }

    /** Hook currently running as a scheduled action (its own in-progress entry must not block rescheduling). */
    private static $running = array();

    private static function schedule($delay, $hook, $order_id) {
        $args = array('order_id' => (int) $order_id);
        if (self::as_ok()) {
            if (empty(self::$running[$hook]) && function_exists('as_has_scheduled_action') && as_has_scheduled_action($hook, $args, self::AS_GROUP)) {
                return false;
            }
            as_schedule_single_action(time() + $delay, $hook, $args, self::AS_GROUP);
            return true;
        }
        if (wp_next_scheduled($hook, $args)) {
            return false;
        }
        wp_schedule_single_event(time() + $delay, $hook, $args);
        return true;
    }

    /** Plan on a paid panel that cannot look a user up: a blind retry could buy twice. */
    public static function manual_plan($plan) {
        if (!class_exists('CN_Panels') || !class_exists('CN_Drivers') || empty($plan['panel_id'])) {
            return false;
        }
        $panel = CN_Panels::get($plan['panel_id']);
        $cls = $panel ? CN_Drivers::cls($panel['type']) : null;
        return $cls && $cls::paid() && !$cls::can_find();
    }

    /** Mark an item for a manual check (no automatic retry) and leave a private admin note. */
    private static function flag_manual($order, $item_id, $item, $plan, $error) {
        $item->update_meta_data(self::ITEM_MANUAL, 1);
        $item->save();
        $panel = class_exists('CN_Panels') && !empty($plan['panel_id']) ? CN_Panels::get($plan['panel_id']) : null;
        $order->add_order_note(sprintf(
            '%s — آیتم «%s» (#%s)، پنل «%s» (#%s): %s. تلاش خودکار انجام نمی‌شود چون ممکن است خرید انجام شده باشد؛ پنل را بررسی کنید و در صورت نیاز از «عملیات سفارش» دوباره تحویل دهید.',
            self::MANUAL_NOTE,
            $item->get_name(),
            CN_Util::fa_digits($item_id),
            $panel && isset($panel['name']) ? $panel['name'] : '—',
            CN_Util::fa_digits(isset($plan['panel_id']) ? $plan['panel_id'] : 0),
            $error
        ), 0, false);
    }

    /** Schedule the next automatic retry (2, 10, 30 min); counter in _cn_retry_attempt. */
    public static function schedule_retry($order) {
        $n = (int) $order->get_meta(self::ORDER_RETRY);
        if ($n >= count(self::$retry_delays)) {
            return false;
        }
        if (self::schedule(self::$retry_delays[$n], 'cn_retry_delivery', $order->get_id())) {
            $order->add_order_note(sprintf('ابزار شبکه: تلاش خودکار %s از %s برای %s دقیقهٔ دیگر زمان‌بندی شد.', CN_Util::fa_digits($n + 1), CN_Util::fa_digits(count(self::$retry_delays)), CN_Util::fa_digits(self::$retry_delays[$n] / 60)));
            return true;
        }
        return false;
    }

    public static function retry_delivery($order_id) {
        $order_id = is_array($order_id) ? (int) reset($order_id) : (int) $order_id;
        self::$running['cn_retry_delivery'] = true;
        try {
            self::run_retry($order_id);
        } finally {
            unset(self::$running['cn_retry_delivery']);
        }
    }

    private static function run_retry($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || !$order->is_paid() || !$order->get_meta(self::ORDER_FAILED) || $order->has_status(array('cancelled', 'refunded', 'failed'))) {
            return;
        }
        if (CN_Util::is_locked('order_' . $order_id)) {
            // Another delivery is running: try again shortly without spending an attempt.
            self::schedule(60, 'cn_retry_delivery', $order_id);
            return;
        }
        $order->update_meta_data(self::ORDER_RETRY, (int) $order->get_meta(self::ORDER_RETRY) + 1);
        $order->save();
        self::deliver($order);
    }

    /** Service ids already shown to the customer in an email. */
    public static function mailed_ids($order) {
        $v = $order->get_meta(self::ORDER_MAILED);
        return array_values(array_unique(array_map('intval', is_array($v) ? $v : array())));
    }

    public static function mark_mailed($order, array $ids) {
        $all = array_values(array_unique(array_merge(self::mailed_ids($order), array_map('intval', $ids))));
        $order->update_meta_data(self::ORDER_MAILED, $all);
        $order->save_meta_data();
    }

    /** Email ids that show the service block (email_block, or the unified snippet when it replaced email_block). */
    public static function service_email_ids() {
        $ids = array('customer_completed_order', 'customer_invoice', 'cn_service_ready');
        return apply_filters('cn_service_email_ids', $ids);
    }

    /** Record which services a customer email showed, whoever rendered them. */
    public static function record_mailed($order, $sent_to_admin = false, $plain_text = false, $email = null) {
        if ($sent_to_admin || !$order instanceof WC_Order || !is_object($email) || empty($email->id)) {
            return;
        }
        $block = has_action('woocommerce_email_after_order_table', array(__CLASS__, 'email_block')) !== false;
        $is_customer = method_exists($email, 'is_customer_email') ? $email->is_customer_email() : strpos($email->id, 'customer_') === 0;
        if (!($block && $is_customer) && !in_array($email->id, self::service_email_ids(), true)) {
            return;
        }
        $ids = array();
        foreach (self::order_services($order) as $s) {
            $ids[] = (int) $s[0]['id'];
        }
        if ($ids) {
            self::mark_mailed($order, $ids);
        }
    }

    /** Services of this order that no email has shown yet. */
    public static function unmailed_services($order) {
        $seen = self::mailed_ids($order);
        $out = array();
        foreach (self::order_services($order) as $s) {
            if (!in_array((int) $s[0]['id'], $seen, true)) {
                $out[] = $s;
            }
        }
        return $out;
    }

    /** Async "service ready" email, a minute later so a same-request status email can record first. */
    public static function queue_ready_mail($order_id) {
        return self::schedule(60, 'cn_send_ready_mail', $order_id);
    }

    public static function send_ready_mail($order_id) {
        $order_id = is_array($order_id) ? (int) reset($order_id) : (int) $order_id;
        if (!CN_Util::lock('ready_mail_' . $order_id, 120)) {
            self::$running['cn_send_ready_mail'] = true;
            self::schedule(60, 'cn_send_ready_mail', $order_id);
            unset(self::$running['cn_send_ready_mail']);
            return false;
        }
        try {
            $order = wc_get_order($order_id);
            if (!$order || !$order->is_paid() || $order->has_status(array('cancelled', 'refunded', 'failed'))) {
                return false;
            }
            if ($order->has_status('completed')) {
                // v1.2.8: the order-completed email carries everything; never a separate "ready" email.
                return false;
            }
            $new = self::unmailed_services($order);
            $pending = self::pending_items($order);
            $others_ready = self::foreign_ready($order);
            if (!$pending && $others_ready) {
                // v1.2.8: everything delivered -> complete; the unified completed email is the only mail.
                if ($order->has_status('processing') && CN_Util::setting('auto_complete', '1') === '1') {
                    $order->update_status('completed', 'ابزار شبکه: تحویل کامل شد.');
                }
                return false;
            }
            if (!$pending) {
                // Only foreign (license) lines are waiting: still a partial-delivery mail (licenses render via cn_ready_mail_extra).
                $pending = array();
                $partial = !$order->get_meta(self::ORDER_PARTIAL);
                if (!$partial) {
                    return false;
                }
            }
            if (!isset($partial)) {
                $partial = !$order->get_meta(self::ORDER_PARTIAL);
            }
            // v1.2.8: only ONE in-between (partial-delivery) email per order; later success completes the order instead.
            if (!$partial) {
                return false;
            }
            $ids = array();
            foreach ($new as $s) {
                $ids[] = (int) $s[0]['id'];
            }
            if ($ids) {
                self::mark_mailed($order, $ids);
            }
            $order->update_meta_data(self::ORDER_PARTIAL, 1);
            $order->save();
            $sent = false;
            if (function_exists('WC') && WC()->mailer()) {
                $emails = WC()->mailer()->get_emails();
                if (isset($emails['CN_Email_Service_Ready'])) {
                    $sent = (bool) $emails['CN_Email_Service_Ready']->trigger($order_id, $order, $new);
                }
            }
            $names = array();
            foreach ($new as $s) {
                $names[] = $s[0]['username'];
            }
            $order->add_order_note(self::READY_NOTE . ' (' . implode('، ', $names) . ')؛ اطلاعات اتصال در «سرویس‌های من» در حساب کاربری شماست.', 1, false);
            if ($sent) {
                $order->add_order_note('ابزار شبکه: ایمیل «سرویس شما آماده شد» برای مشتری ارسال شد.');
            }
            return $sent;
        } finally {
            CN_Util::unlock('ready_mail_' . $order_id);
        }
    }

    /** The ready note is a customer note for the order page; the ready email already informs, so skip WC's note email. */
    public static function skip_ready_note_email($enabled, $order = null, $email = null) {
        if ($enabled && is_object($email) && isset($email->customer_note) && strpos((string) $email->customer_note, self::READY_NOTE) === 0) {
            return false;
        }
        return $enabled;
    }

    public static function email_classes($emails) {
        if (class_exists('WC_Email') && !class_exists('CN_Email_Service_Ready')) {
            require_once CN_DIR . 'includes/class-cn-email-ready.php';
        }
        if (class_exists('CN_Email_Service_Ready')) {
            $emails['CN_Email_Service_Ready'] = new CN_Email_Service_Ready();
        }
        return $emails;
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
        $r = self::deliver($order);
        if (!empty($r['locked'])) {
            $order->add_order_note('ابزار شبکه: تحویل این سفارش همین حالا در حال انجام است؛ چند دقیقهٔ دیگر دوباره بررسی کنید.');
        }
        // A "service ready" email (deduped by _cn_mailed_services) is queued by deliver() when something was made.
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
