<?php
if (!defined('ABSPATH')) {
    exit;
}

class CN_Admin {
    const CAP = 'manage_woocommerce';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        foreach (array('save_panel', 'delete_panel', 'test_panel', 'save_plan', 'delete_plan', 'create_product', 'service_action', 'stock_add', 'stock_delete', 'save_settings', 'import_plans') as $a) {
            add_action('admin_post_cn_' . $a, array(__CLASS__, 'post_' . $a));
        }
    }

    public static function menu() {
        add_menu_page('ابزار شبکه', 'ابزار شبکه', self::CAP, 'cn-dashboard', array(__CLASS__, 'page_dashboard'), 'dashicons-networking', 56);
        $pages = array(
            'cn-dashboard' => array('پیشخوان', 'page_dashboard'),
            'cn-panels'    => array('پنل‌ها', 'page_panels'),
            'cn-plans'     => array('پلن‌ها', 'page_plans'),
            'cn-services'  => array('سرویس‌ها', 'page_services'),
            'cn-stock'     => array('انبار فروش دستی', 'page_stock'),
            'cn-settings'  => array('تنظیمات', 'page_settings'),
            'cn-logs'      => array('گزارش', 'page_logs'),
        );
        foreach ($pages as $slug => $p) {
            add_submenu_page('cn-dashboard', $p[0], $p[0], self::CAP, $slug, array(__CLASS__, $p[1]));
        }
    }

    public static function assets($hook) {
        if (strpos((string) $hook, 'cn-') === false) {
            return;
        }
        wp_enqueue_style('cn-admin', CN_URL . 'assets/admin.css', array(), CN_VERSION);
        wp_enqueue_script('cn-admin', CN_URL . 'assets/admin.js', array(), CN_VERSION, true);
    }

    /* ---------- helpers ---------- */

    private static function guard($nonce) {
        if (!current_user_can(self::CAP)) {
            wp_die('دسترسی ندارید.');
        }
        check_admin_referer($nonce);
    }

    private static function flash($msg, $type = 'success') {
        set_transient('cn_flash_' . get_current_user_id(), array('m' => $msg, 't' => $type), 60);
    }

    private static function back($page, array $args = array()) {
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=' . $page)));
        exit;
    }

    private static function url($page, array $args = array()) {
        return add_query_arg($args, admin_url('admin.php?page=' . $page));
    }

    private static function action_url($action, array $args = array()) {
        return wp_nonce_url(add_query_arg(array_merge(array('action' => 'cn_' . $action), $args), admin_url('admin-post.php')), 'cn_' . $action);
    }

    private static function open($title, $actions = '') {
        echo '<div class="wrap cn-admin" dir="rtl" lang="fa">';
        echo '<div class="cn-top"><h1>' . esc_html($title) . '</h1><div class="cn-top__actions">' . $actions . '</div></div>';
        $f = get_transient('cn_flash_' . get_current_user_id());
        if ($f) {
            delete_transient('cn_flash_' . get_current_user_id());
            echo '<div class="cn-flash cn-flash--' . esc_attr($f['t']) . '">' . esc_html($f['m']) . '</div>';
        }
    }

    private static function close() {
        echo '</div>';
    }

    private static function form_open($action) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="cn-form">';
        echo '<input type="hidden" name="action" value="cn_' . esc_attr($action) . '">';
        wp_nonce_field('cn_' . $action);
    }

    private static function field($name, array $f, $value, $secret_set = false) {
        $type = isset($f['type']) ? $f['type'] : 'text';
        $req = !empty($f['required']) && !($secret_set && !empty($f['secret']));
        $id = 'cn_' . md5($name);
        $hide = !empty($f['hide_for']) ? ' data-cn-hide="' . esc_attr(implode(',', (array) $f['hide_for'])) . '"' : '';
        echo '<div class="cn-row"' . $hide . '><label for="' . esc_attr($id) . '">' . esc_html($f['label']) . ($req ? ' <span class="cn-req">*</span>' : '') . '</label><div class="cn-row__in">';
        switch ($type) {
            case 'password':
                echo '<input type="password" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="" autocomplete="new-password" dir="ltr" placeholder="' . esc_attr($secret_set ? '•••••• ذخیره شده — برای تغییر مقدار جدید وارد کنید' : '') . '">';
                break;
            case 'textarea':
                echo '<textarea id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" rows="3" dir="ltr">' . esc_textarea((string) $value) . '</textarea>';
                break;
            case 'checkbox':
                echo '<label class="cn-switch"><input type="checkbox" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="1"' . checked(!empty($value), true, false) . '><span></span></label>';
                break;
            case 'select':
                echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '">';
                foreach ((array) $f['options'] as $k => $l) {
                    echo '<option value="' . esc_attr($k) . '"' . selected((string) $value, (string) $k, false) . '>' . esc_html($l) . '</option>';
                }
                echo '</select>';
                break;
            default:
                $list = !empty($f['suggest']) ? $id . '_list' : '';
                echo '<input type="' . esc_attr($type === 'number' ? 'number' : ($type === 'url' ? 'url' : 'text')) . '" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '"' . ($type === 'number' ? ' step="any"' : '') . ($list ? ' list="' . esc_attr($list) . '" autocomplete="off"' : '') . ' dir="ltr">';
                if ($list) {
                    echo '<datalist id="' . esc_attr($list) . '">';
                    foreach ((array) $f['suggest'] as $s) {
                        echo '<option value="' . esc_attr($s) . '">';
                    }
                    echo '</datalist>';
                }
        }
        if (!empty($f['help'])) {
            echo '<p class="cn-help">' . esc_html($f['help']) . '</p>';
        }
        echo '</div></div>';
    }

    private static function badge($status) {
        $map = array('active' => 'ok', 'on_hold' => 'wait', 'disabled' => 'off', 'expired' => 'off', 'limited' => 'warn', 'deleted' => 'off', 'inactive' => 'off', 'free' => 'ok', 'sold' => 'off', 'error' => 'off', 'info' => 'ok');
        $label = $status === 'inactive' ? 'غیرفعال' : ($status === 'free' ? 'آزاد' : ($status === 'sold' ? 'فروخته‌شده' : ($status === 'error' ? 'خطا' : ($status === 'info' ? 'اطلاع' : CN_Util::status_label($status)))));
        return '<span class="cn-badge cn-badge--' . esc_attr(isset($map[$status]) ? $map[$status] : 'wait') . '">' . esc_html($label) . '</span>';
    }

    private static function panels_map() {
        $out = array();
        foreach (CN_Panels::all() as $p) {
            $out[$p['id']] = $p;
        }
        return $out;
    }

    /* ---------- dashboard ---------- */

    public static function page_dashboard() {
        global $wpdb;
        $panels = CN_Panels::all();
        $counts = CN_Services::counts();
        $active = (isset($counts['active']) ? $counts['active'] : 0) + (isset($counts['on_hold']) ? $counts['on_hold'] : 0);
        $soon = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . CN_DB::t('services') . " WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at < %s", gmdate('Y-m-d H:i:s', time() + 3 * DAY_IN_SECONDS)));
        $failed = wc_get_orders(array('limit' => 50, 'return' => 'ids', 'meta_key' => CN_Woo::ORDER_FAILED, 'meta_value' => '1'));

        self::open('ابزار شبکه', '<a class="cn-btn cn-btn--primary" href="' . esc_url(self::url('cn-panels', array('edit' => 'new'))) . '">افزودن پنل</a>');
        echo '<div class="cn-kpis">';
        $kpi = function ($label, $value, $mod = '') {
            echo '<div class="cn-kpi ' . esc_attr($mod) . '"><span>' . esc_html($label) . '</span><strong>' . esc_html(CN_Util::fa_digits($value)) . '</strong></div>';
        };
        $kpi('پنل‌های متصل', count($panels));
        $kpi('سرویس‌های فعال', $active);
        $kpi('انقضا تا ۳ روز آینده', $soon, $soon ? 'cn-kpi--warn' : '');
        $kpi('سفارش با تحویل ناموفق', count($failed), $failed ? 'cn-kpi--bad' : '');
        echo '</div>';

        echo '<div class="cn-grid2"><section class="cn-box"><h2>وضعیت پنل‌ها</h2>';
        if (!$panels) {
            echo '<p class="cn-muted">هنوز پنلی اضافه نشده است.</p>';
        } else {
            echo '<table class="cn-table"><thead><tr><th>نام</th><th>نوع</th><th>آخرین تست</th></tr></thead><tbody>';
            foreach ($panels as $p) {
                $cls = CN_Drivers::cls($p['type']);
                echo '<tr><td>' . esc_html($p['name']) . '</td><td>' . esc_html($cls ? $cls::label() : $p['type']) . '</td><td>';
                echo $p['last_check'] ? self::badge($p['last_check_ok'] ? 'active' : 'error') : '<span class="cn-muted">تست نشده</span>';
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</section><section class="cn-box"><h2>آخرین رویدادها</h2>';
        $logs = $wpdb->get_results('SELECT * FROM ' . CN_DB::t('logs') . ' ORDER BY id DESC LIMIT 8', ARRAY_A);
        if (!$logs) {
            echo '<p class="cn-muted">رویدادی ثبت نشده است.</p>';
        } else {
            echo '<ul class="cn-log">';
            foreach ($logs as $l) {
                echo '<li class="cn-log--' . esc_attr($l['level']) . '"><span>' . esc_html($l['message']) . '</span><time>' . esc_html(CN_Util::local_label($l['created_at'])) . '</time></li>';
            }
            echo '</ul>';
        }
        echo '</section></div>';
        self::close();
    }

    /* ---------- panels ---------- */

    public static function page_panels() {
        $edit = isset($_GET['edit']) ? sanitize_text_field(wp_unslash($_GET['edit'])) : '';
        if ($edit !== '') {
            self::panel_form($edit === 'new' ? null : CN_Panels::get((int) $edit));
            return;
        }
        if (!empty($_GET['remote'])) {
            self::remote_plans((int) $_GET['remote']);
            return;
        }
        self::open('پنل‌ها', '<a class="cn-btn cn-btn--primary" href="' . esc_url(self::url('cn-panels', array('edit' => 'new'))) . '">افزودن پنل</a>');
        $panels = CN_Panels::all();
        if (!$panels) {
            echo '<div class="cn-box cn-empty"><p>هنوز پنلی اضافه نشده است. از دکمهٔ «افزودن پنل» شروع کنید.</p></div>';
            self::close();
            return;
        }
        echo '<div class="cn-box"><table class="cn-table"><thead><tr><th>نام</th><th>نوع</th><th>آدرس</th><th>وضعیت</th><th>آخرین تست اتصال</th><th></th></tr></thead><tbody>';
        foreach ($panels as $p) {
            $cls = CN_Drivers::cls($p['type']);
            $host = $p['url'] ? wp_parse_url($p['url'], PHP_URL_HOST) : '—';
            echo '<tr><td><strong>' . esc_html($p['name']) . '</strong></td><td>' . esc_html($cls ? $cls::label() : $p['type']) . '</td><td dir="ltr">' . esc_html($host) . '</td><td>' . self::badge($p['status']) . '</td><td>';
            if ($p['last_check']) {
                echo self::badge($p['last_check_ok'] ? 'active' : 'error') . ' <span class="cn-muted">' . esc_html($p['last_check_msg']) . '</span>';
            } else {
                echo '<span class="cn-muted">تست نشده</span>';
            }
            echo '</td><td class="cn-actions">';
            echo '<a class="cn-btn cn-btn--ghost" href="' . esc_url(self::action_url('test_panel', array('id' => $p['id']))) . '">تست اتصال</a>';
            if ($p['type'] === 'connectix') {
                echo '<a class="cn-btn cn-btn--ghost" href="' . esc_url(self::url('cn-panels', array('remote' => $p['id']))) . '">دریافت پلن‌ها</a>';
            }
            echo '<a class="cn-btn cn-btn--ghost" href="' . esc_url(self::url('cn-panels', array('edit' => $p['id']))) . '">ویرایش</a>';
            echo '<a class="cn-btn cn-btn--danger" data-cn-confirm="پنل «' . esc_attr($p['name']) . '» و پلن‌هایش حذف شود؟" href="' . esc_url(self::action_url('delete_panel', array('id' => $p['id']))) . '">حذف</a>';
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
        self::close();
    }

    private static function panel_form($panel) {
        $types = CN_Drivers::types();
        $type = $panel ? $panel['type'] : (isset($_GET['type']) && isset($types[$_GET['type']]) ? sanitize_key($_GET['type']) : 'marzban');
        self::open($panel ? 'ویرایش پنل: ' . $panel['name'] : 'افزودن پنل', '<a class="cn-btn cn-btn--ghost" href="' . esc_url(self::url('cn-panels')) . '">بازگشت</a>');
        self::form_open('save_panel');
        echo '<input type="hidden" name="id" value="' . ($panel ? (int) $panel['id'] : 0) . '">';
        echo '<div class="cn-box">';
        $opts = array();
        foreach ($types as $t => $cls) {
            $opts[$t] = $cls::label();
        }
        self::field('type', array('label' => 'نوع پنل', 'type' => 'select', 'options' => $opts, 'required' => true), $type);
        self::field('name', array('label' => 'نام نمایشی', 'type' => 'text', 'required' => true, 'help' => 'فقط برای مدیریت؛ به مشتری نشان داده نمی‌شود.'), $panel ? $panel['name'] : '');
        foreach ($types as $t => $cls) {
            if ($cls::uses_url()) {
                echo '<div class="cn-type" data-cn-type="' . esc_attr($t) . '">';
                self::field('url_' . $t, array('label' => 'آدرس پنل', 'type' => 'url', 'required' => $t !== 'connectix', 'help' => $cls::url_help()), $panel && $panel['type'] === $t ? $panel['url'] : '');
                echo '</div>';
            }
        }
        foreach ($types as $t => $cls) {
            echo '<div class="cn-type" data-cn-type="' . esc_attr($t) . '">';
            $same = $panel && $panel['type'] === $t;
            $fields = $cls::fields();
            foreach (array(false, true) as $adv) {
                $group = array_filter($fields, function ($f) use ($adv) {
                    return !empty($f['advanced']) === $adv;
                });
                if (!$group) {
                    continue;
                }
                if ($adv) {
                    echo '<details class="cn-adv"><summary>تنظیمات بیشتر (اختیاری)</summary>';
                }
                foreach ($group as $key => $f) {
                    $val = $same && isset($panel['settings'][$key]) ? $panel['settings'][$key] : (isset($f['default']) ? $f['default'] : '');
                    self::field('f_' . $t . '[' . $key . ']', $f, $val, $same && !empty($panel['secrets'][$key]));
                }
                if ($adv) {
                    echo '</details>';
                }
            }
            echo '</div>';
        }
        self::field('status', array('label' => 'وضعیت', 'type' => 'select', 'options' => array('active' => 'فعال', 'inactive' => 'غیرفعال (فروش متوقف)')), $panel ? $panel['status'] : 'active');
        echo '</div><div class="cn-formbar"><button type="submit" class="cn-btn cn-btn--primary">ذخیره</button>';
        echo '<label class="cn-check"><input type="checkbox" name="test_after" value="1" checked> بعد از ذخیره اتصال را تست کن</label></div></form>';
        self::close();
    }

    public static function post_save_panel() {
        self::guard('cn_save_panel');
        $id = (int) (isset($_POST['id']) ? $_POST['id'] : 0);
        $type = isset($_POST['type']) ? sanitize_key($_POST['type']) : '';
        $in = array(
            'type'   => $type,
            'name'   => isset($_POST['name']) ? wp_unslash($_POST['name']) : '',
            'url'    => isset($_POST['url_' . $type]) ? wp_unslash($_POST['url_' . $type]) : '',
            'f'      => isset($_POST['f_' . $type]) && is_array($_POST['f_' . $type]) ? $_POST['f_' . $type] : array(),
            'status' => isset($_POST['status']) ? sanitize_key($_POST['status']) : 'active',
        );
        $r = CN_Panels::save($in, $id);
        if (is_wp_error($r)) {
            self::flash($r->get_error_message(), 'error');
            self::back('cn-panels', array('edit' => $id ? $id : 'new', 'type' => $type));
        }
        $msg = 'پنل ذخیره شد.';
        if (!empty($_POST['test_after'])) {
            $t = CN_Panels::test($r);
            $msg .= ' تست اتصال: ' . (isset($t['msg']) ? $t['msg'] : '');
            self::flash($msg, empty($t['ok']) ? 'error' : 'success');
        } else {
            self::flash($msg);
        }
        self::back('cn-panels');
    }

    public static function post_test_panel() {
        self::guard('cn_test_panel');
        $r = CN_Panels::test((int) $_GET['id']);
        self::flash('تست اتصال: ' . (isset($r['msg']) ? $r['msg'] : ''), empty($r['ok']) ? 'error' : 'success');
        self::back('cn-panels');
    }

    public static function post_delete_panel() {
        self::guard('cn_delete_panel');
        $r = CN_Panels::delete((int) $_GET['id']);
        self::flash(is_wp_error($r) ? $r->get_error_message() : 'پنل حذف شد.', is_wp_error($r) ? 'error' : 'success');
        self::back('cn-panels');
    }

    private static function remote_plans($panel_id) {
        $panel = CN_Panels::get($panel_id);
        self::open('پلن‌های کانکتیکس', '<a class="cn-btn cn-btn--ghost" href="' . esc_url(self::url('cn-panels')) . '">بازگشت</a>');
        $d = $panel ? CN_Panels::driver($panel) : null;
        $r = $d && method_exists($d, 'remote_plans') ? $d->remote_plans() : array('ok' => false, 'msg' => 'پنل پیدا نشد.');
        if (empty($r['ok'])) {
            echo '<div class="cn-flash cn-flash--error">' . esc_html($r['msg']) . '</div>';
            self::close();
            return;
        }
        $existing = array();
        foreach (CN_Plans::all($panel_id) as $pl) {
            if (!empty($pl['options']['plan_id'])) {
                $existing[$pl['options']['plan_id']] = true;
            }
        }
        self::form_open('import_plans');
        echo '<input type="hidden" name="panel_id" value="' . (int) $panel_id . '"><div class="cn-box"><table class="cn-table"><thead><tr><th></th><th>عنوان</th><th>مدت</th><th>حجم</th><th>دستگاه</th><th>قیمت فروشنده</th></tr></thead><tbody>';
        foreach ($r['plans'] as $pid => $p) {
            $has = isset($existing[$pid]);
            echo '<tr><td><input type="checkbox" name="plans[]" value="' . esc_attr($pid) . '"' . disabled($has, true, false) . '></td><td>' . esc_html($p['title']) . ($has ? ' <span class="cn-muted">(اضافه شده)</span>' : '') . '</td><td>' . esc_html(CN_Util::days_label($p['days'])) . '</td><td>' . esc_html(CN_Util::gb_label($p['gb'])) . '</td><td>' . esc_html(!empty($p['users']) ? CN_Util::fa_digits($p['users']) : '—') . '</td><td>' . esc_html(CN_Util::fa_digits($p['price'])) . '</td></tr>';
            echo '<input type="hidden" name="meta[' . esc_attr($pid) . ']" value="' . esc_attr(wp_json_encode($p)) . '">';
        }
        echo '</tbody></table></div><div class="cn-formbar"><button class="cn-btn cn-btn--primary" type="submit">ساخت پلن برای موارد انتخاب‌شده</button></div></form>';
        self::close();
    }

    public static function post_import_plans() {
        self::guard('cn_import_plans');
        $panel_id = (int) $_POST['panel_id'];
        $n = 0;
        foreach ((array) (isset($_POST['plans']) ? $_POST['plans'] : array()) as $pid) {
            $pid = sanitize_text_field(wp_unslash($pid));
            $meta = isset($_POST['meta'][$pid]) ? json_decode(wp_unslash($_POST['meta'][$pid]), true) : array();
            $r = CN_Plans::save(array(
                'panel_id'  => $panel_id,
                'name'      => isset($meta['title']) ? $meta['title'] : $pid,
                'volume_gb' => isset($meta['gb']) ? $meta['gb'] : 0,
                'days'      => isset($meta['days']) ? $meta['days'] : 0,
                'users_limit' => isset($meta['users']) ? $meta['users'] : 0,
                'o'         => array('plan_id' => $pid),
            ));
            if (!is_wp_error($r)) {
                $n++;
            }
        }
        self::flash(CN_Util::fa_digits($n) . ' پلن ساخته شد.');
        self::back('cn-plans');
    }

    /* ---------- plans ---------- */

    public static function page_plans() {
        $edit = isset($_GET['edit']) ? sanitize_text_field(wp_unslash($_GET['edit'])) : '';
        if ($edit !== '') {
            self::plan_form($edit === 'new' ? null : CN_Plans::get((int) $edit));
            return;
        }
        $panels = self::panels_map();
        self::open('پلن‌ها', $panels ? '<a class="cn-btn cn-btn--primary" href="' . esc_url(self::url('cn-plans', array('edit' => 'new'))) . '">افزودن پلن</a>' : '');
        if (!$panels) {
            echo '<div class="cn-box cn-empty"><p>اول یک پنل اضافه کنید.</p></div>';
            self::close();
            return;
        }
        $plans = CN_Plans::all();
        if (!$plans) {
            echo '<div class="cn-box cn-empty"><p>هنوز پلنی ساخته نشده است.</p></div>';
        }
        foreach ($panels as $panel) {
            $rows = array_filter($plans, function ($p) use ($panel) {
                return $p['panel_id'] === $panel['id'];
            });
            if (!$rows) {
                continue;
            }
            echo '<div class="cn-box"><h2>' . esc_html($panel['name']) . '</h2><table class="cn-table"><thead><tr><th>نام پلن</th><th>حجم</th><th>مدت</th><th>تمدید</th><th>محصول ووکامرس</th>' . ($panel['type'] === 'manual' ? '<th>موجودی</th>' : '') . '<th>وضعیت</th><th></th></tr></thead><tbody>';
            foreach ($rows as $pl) {
                echo '<tr><td><strong>' . esc_html($pl['name']) . '</strong></td><td>' . esc_html(CN_Util::gb_label($pl['volume_gb'])) . '</td><td>' . esc_html(CN_Util::days_label($pl['days'])) . '</td>';
                echo '<td>' . ($pl['renew_mode'] === 'reset' ? 'از نو' : 'افزودنی') . '</td><td>';
                $prod = $pl['product_id'] ? wc_get_product($pl['product_id']) : null;
                if ($prod) {
                    echo '<a href="' . esc_url(get_edit_post_link($prod->get_id())) . '">' . esc_html($prod->get_name()) . '</a> ' . self::badge($prod->get_status() === 'publish' ? 'active' : 'inactive');
                } else {
                    echo '<a class="cn-btn cn-btn--ghost" data-cn-confirm="یک محصول پیش‌نویس (منتشرنشده) برای این پلن ساخته شود؟" href="' . esc_url(self::action_url('create_product', array('id' => $pl['id']))) . '">ساخت محصول پیش‌نویس</a>';
                }
                echo '</td>';
                if ($panel['type'] === 'manual') {
                    echo '<td>' . esc_html(CN_Util::fa_digits(CN_Stock::count_free($pl['id']))) . '</td>';
                }
                echo '<td>' . self::badge($pl['status']) . '</td><td class="cn-actions">';
                echo '<a class="cn-btn cn-btn--ghost" href="' . esc_url(self::url('cn-plans', array('edit' => $pl['id']))) . '">ویرایش</a>';
                echo '<a class="cn-btn cn-btn--danger" data-cn-confirm="پلن حذف شود؟ سرویس‌های ساخته‌شده باقی می‌مانند." href="' . esc_url(self::action_url('delete_plan', array('id' => $pl['id']))) . '">حذف</a>';
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        self::close();
    }

    private static function plan_form($plan) {
        $panels = self::panels_map();
        self::open($plan ? 'ویرایش پلن: ' . $plan['name'] : 'افزودن پلن', '<a class="cn-btn cn-btn--ghost" href="' . esc_url(self::url('cn-plans')) . '">بازگشت</a>');
        self::form_open('save_plan');
        echo '<input type="hidden" name="id" value="' . ($plan ? (int) $plan['id'] : 0) . '"><div class="cn-box">';
        $opts = array();
        foreach ($panels as $p) {
            $cls = CN_Drivers::cls($p['type']);
            $opts[$p['id']] = $p['name'] . ' (' . ($cls ? $cls::label() : $p['type']) . ')';
        }
        echo '<div data-cn-panel-types="' . esc_attr(wp_json_encode(array_map(function ($p) {
            return $p['type'];
        }, $panels))) . '"></div>';
        self::field('panel_id', array('label' => 'پنل', 'type' => 'select', 'options' => $opts, 'required' => true), $plan ? $plan['panel_id'] : (isset($_GET['panel']) ? (int) $_GET['panel'] : ''));
        self::field('name', array('label' => 'نام پلن', 'type' => 'text', 'required' => true, 'help' => 'مثل «۳۰ گیگ یک‌ماهه»'), $plan ? $plan['name'] : '');
        $remote = array();
        $one_mode = array();
        foreach (CN_Drivers::types() as $t => $cls) {
            if ($cls::remote_limits()) {
                $remote[] = $t;
            }
            if (!$cls::supports('renew') || !$cls::supports('reset')) {
                $one_mode[] = $t;
            }
        }
        self::field('volume_gb', array('label' => 'حجم (گیگابایت)', 'type' => 'number', 'help' => '۰ = نامحدود', 'hide_for' => array('connectix')), $plan ? $plan['volume_gb'] : '');
        self::field('days', array('label' => 'مدت (روز)', 'type' => 'number', 'help' => '۰ = نامحدود', 'hide_for' => array('connectix')), $plan ? $plan['days'] : '');
        echo '<p class="cn-help cn-row-note" data-cn-show="' . esc_attr(implode(',', array_diff($remote, array('connectix')))) . '">حجم و مدت این پنل از گروه/پروفایل خود پنل می‌آید؛ اینجا فقط برای نمایش به مشتری است و باید با همان یکی باشد.</p>';
        self::field('users_limit', array('label' => 'کاربر همزمان (فقط نمایشی)', 'type' => 'number', 'hide_for' => array('connectix')), $plan ? $plan['users_limit'] : '');
        self::field('renew_mode', array('label' => 'روش تمدید', 'type' => 'select', 'hide_for' => $one_mode, 'options' => array(
            'add'   => 'افزودنی — حجم و روز به باقی‌ماندهٔ قبلی اضافه شود',
            'reset' => 'از نو — مصرف صفر و دوره از امروز شروع شود',
        )), $plan ? $plan['renew_mode'] : 'add');
        foreach (CN_Drivers::types() as $t => $cls) {
            $pf = $cls::plan_fields();
            if (!$pf) {
                continue;
            }
            echo '<div class="cn-type cn-type--plan" data-cn-type="' . esc_attr($t) . '"><h3>تنظیمات مخصوص ' . esc_html($cls::label()) . '</h3>';
            $suggest = array();
            if (method_exists($cls, 'plan_suggestions')) {
                foreach ($panels as $p) {
                    if ($p['type'] === $t) {
                        $suggest = array_merge($suggest, $cls::plan_suggestions($p));
                    }
                }
            }
            foreach ($pf as $key => $f) {
                if ($key === 'template' && $suggest) {
                    $f['suggest'] = array_values(array_unique($suggest));
                    $f['help'] .= ' — تعریف‌شده: ' . implode('، ', $f['suggest']);
                }
                $val = $plan && isset($plan['options'][$key]) && isset($panels[$plan['panel_id']]) && $panels[$plan['panel_id']]['type'] === $t ? $plan['options'][$key] : '';
                self::field('o_' . $t . '[' . $key . ']', $f, $val);
            }
            echo '</div>';
        }
        self::field('status', array('label' => 'وضعیت', 'type' => 'select', 'options' => array('active' => 'فعال', 'inactive' => 'غیرفعال')), $plan ? $plan['status'] : 'active');
        self::field('sort', array('label' => 'ترتیب', 'type' => 'number'), $plan ? $plan['sort'] : 0);
        echo '</div><div class="cn-formbar"><button type="submit" class="cn-btn cn-btn--primary">ذخیره</button></div></form>';
        self::close();
    }

    public static function post_save_plan() {
        self::guard('cn_save_plan');
        $id = (int) (isset($_POST['id']) ? $_POST['id'] : 0);
        $panel = CN_Panels::get((int) (isset($_POST['panel_id']) ? $_POST['panel_id'] : 0));
        $in = wp_unslash($_POST);
        $in['o'] = $panel && isset($_POST['o_' . $panel['type']]) && is_array($_POST['o_' . $panel['type']]) ? $_POST['o_' . $panel['type']] : array();
        if ($panel && $panel['type'] === 'connectix' && !empty($in['o']['plan_id'])) {
            $d = CN_Panels::driver($panel);
            $rp = $d ? $d->remote_plans() : array();
            $key = trim((string) wp_unslash($in['o']['plan_id']));
            if (!empty($rp['ok']) && isset($rp['plans'][$key])) {
                $in['volume_gb'] = $rp['plans'][$key]['gb'];
                $in['days'] = $rp['plans'][$key]['days'];
                $in['users_limit'] = $rp['plans'][$key]['users'];
            }
        }
        $r = CN_Plans::save($in, $id);
        if (is_wp_error($r)) {
            self::flash($r->get_error_message(), 'error');
            self::back('cn-plans', array('edit' => $id ? $id : 'new'));
        }
        self::flash('پلن ذخیره شد.');
        self::back('cn-plans');
    }

    public static function post_delete_plan() {
        self::guard('cn_delete_plan');
        CN_Plans::delete((int) $_GET['id']);
        self::flash('پلن حذف شد.');
        self::back('cn-plans');
    }

    public static function post_create_product() {
        self::guard('cn_create_product');
        $r = CN_Plans::create_product((int) $_GET['id']);
        if (is_wp_error($r)) {
            self::flash($r->get_error_message(), 'error');
            self::back('cn-plans');
        }
        self::flash('محصول پیش‌نویس ساخته شد؛ قیمت و تصویر را تنظیم و بعد منتشر کنید.');
        wp_safe_redirect(get_edit_post_link($r, 'raw'));
        exit;
    }

    /* ---------- services ---------- */

    public static function page_services() {
        if (!empty($_GET['service'])) {
            self::service_detail((int) $_GET['service']);
            return;
        }
        $panels = self::panels_map();
        $f = array(
            'panel_id' => isset($_GET['panel']) ? (int) $_GET['panel'] : 0,
            'status'   => isset($_GET['status']) ? sanitize_key($_GET['status']) : '',
            'q'        => isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '',
        );
        self::open('سرویس‌ها');
        echo '<form class="cn-filter" method="get"><input type="hidden" name="page" value="cn-services">';
        echo '<select name="panel"><option value="">همهٔ پنل‌ها</option>';
        foreach ($panels as $p) {
            echo '<option value="' . (int) $p['id'] . '"' . selected($f['panel_id'], $p['id'], false) . '>' . esc_html($p['name']) . '</option>';
        }
        echo '</select><select name="status"><option value="">همهٔ وضعیت‌ها</option>';
        foreach (array('active', 'on_hold', 'limited', 'expired', 'disabled', 'deleted') as $s) {
            echo '<option value="' . esc_attr($s) . '"' . selected($f['status'], $s, false) . '>' . esc_html(CN_Util::status_label($s)) . '</option>';
        }
        echo '</select><input type="search" name="q" value="' . esc_attr($f['q']) . '" placeholder="نام کاربری یا شمارهٔ سفارش"><button class="cn-btn cn-btn--dark">فیلتر</button></form>';

        $rows = CN_Services::search($f, 200);
        echo '<div class="cn-box">';
        if (!$rows) {
            echo '<p class="cn-muted">سرویسی پیدا نشد.</p>';
        } else {
            echo '<table class="cn-table"><thead><tr><th>نام کاربری</th><th>پنل</th><th>سفارش</th><th>مشتری</th><th>مصرف</th><th>انقضا</th><th>وضعیت</th><th></th></tr></thead><tbody>';
            foreach ($rows as $s) {
                $o = $s['order_id'] ? wc_get_order($s['order_id']) : null;
                $u = $s['user_id'] ? get_userdata($s['user_id']) : null;
                echo '<tr><td dir="ltr"><code>' . esc_html($s['username']) . '</code></td><td data-label="پنل">' . esc_html(isset($panels[$s['panel_id']]) ? $panels[$s['panel_id']]['name'] : '—') . '</td>';
                echo '<td data-label="سفارش">' . ($o ? '<a href="' . esc_url($o->get_edit_order_url()) . '">#' . esc_html($o->get_order_number()) . '</a>' : '—') . '</td>';
                echo '<td data-label="مشتری">' . esc_html($u ? $u->display_name : 'مهمان') . '</td>';
                echo '<td data-label="مصرف" class="cn-nw">' . esc_html(CN_Util::bytes_label($s['usage_bytes']) . ' / ' . ($s['limit_bytes'] ? CN_Util::bytes_label($s['limit_bytes']) : 'نامحدود')) . '</td>';
                echo '<td data-label="انقضا" class="cn-nw">' . esc_html(CN_Util::expiry_label($s)) . '</td><td>' . self::badge($s['status']) . '</td>';
                echo '<td><a class="cn-btn cn-btn--ghost" href="' . esc_url(self::url('cn-services', array('service' => $s['id']))) . '">مدیریت</a></td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
        self::close();
    }

    private static function service_detail($id) {
        $s = CN_Services::get($id);
        self::open($s ? 'سرویس ' . $s['username'] : 'سرویس پیدا نشد', '<a class="cn-btn cn-btn--ghost" href="' . esc_url(self::url('cn-services')) . '">بازگشت</a>');
        if (!$s) {
            self::close();
            return;
        }
        $panel = CN_Panels::get($s['panel_id']);
        $cls = $panel ? CN_Drivers::cls($panel['type']) : null;
        echo '<div class="cn-grid2"><section class="cn-box"><h2>مشخصات</h2><table class="cn-table cn-table--kv">';
        $kv = function ($k, $v) {
            echo '<tr><th>' . esc_html($k) . '</th><td>' . $v . '</td></tr>';
        };
        $kv('پنل', esc_html($panel ? $panel['name'] : '—'));
        $kv('نام کاربری', '<code dir="ltr">' . esc_html($s['username']) . '</code>');
        $kv('وضعیت', self::badge($s['status']));
        $kv('مصرف', esc_html(CN_Util::bytes_label($s['usage_bytes']) . ' از ' . ($s['limit_bytes'] ? CN_Util::bytes_label($s['limit_bytes']) : 'نامحدود')));
        $kv('انقضا', esc_html(CN_Util::expiry_label($s)));
        $kv('آخرین همگام‌سازی', esc_html(CN_Util::local_label($s['synced_at'])));
        if ($s['sub_url']) {
            $kv('لینک اشتراک', '<code dir="ltr" class="cn-break">' . esc_html($s['sub_url']) . '</code>');
        }
        echo '</table></section><section class="cn-box"><h2>عملیات روی پنل</h2><div class="cn-actions cn-actions--wrap">';
        $ops = array('refresh' => 'به‌روزرسانی از پنل');
        if ($cls && $cls::supports('toggle')) {
            $ops[$s['status'] === 'disabled' ? 'enable' : 'disable'] = $s['status'] === 'disabled' ? 'فعال کردن' : 'غیرفعال کردن';
        }
        if ($cls && $cls::supports('reset')) {
            $ops['reset'] = 'صفر کردن مصرف';
        }
        if ($cls && $cls::supports('revoke')) {
            $ops['revoke'] = 'تغییر لینک اشتراک';
        }
        if ($cls && $cls::supports('delete') && $s['status'] !== 'deleted') {
            $ops['delete'] = 'حذف از پنل';
        }
        foreach ($ops as $op => $label) {
            $danger = in_array($op, array('delete', 'disable', 'revoke'), true);
            echo '<a class="cn-btn ' . ($danger ? 'cn-btn--danger' : 'cn-btn--ghost') . '"' . ($danger ? ' data-cn-confirm="' . esc_attr($label) . '؟"' : '') . ' href="' . esc_url(self::action_url('service_action', array('id' => $s['id'], 'op' => $op))) . '">' . esc_html($label) . '</a>';
        }
        echo '</div>';
        $configs = CN_Services::configs($s);
        if ($configs) {
            echo '<h3>اطلاعات تحویل‌شده به مشتری</h3><details><summary>نمایش (حاوی رمز)</summary><table class="cn-table cn-table--kv">';
            foreach ($configs as $c) {
                $v = isset($c[2]) && $c[2] === 'file' ? '<pre class="cn-pre" dir="ltr">' . esc_html($c[1]) . '</pre>' : '<code dir="ltr" class="cn-break">' . esc_html($c[1]) . '</code>';
                $kv(isset($c[0]) ? $c[0] : '', $v);
            }
            echo '</table></details>';
        }
        echo '</section></div>';
        self::close();
    }

    public static function post_service_action() {
        self::guard('cn_service_action');
        $s = CN_Services::get((int) $_GET['id']);
        $op = isset($_GET['op']) ? sanitize_key($_GET['op']) : '';
        if (!$s) {
            self::flash('سرویس پیدا نشد.', 'error');
            self::back('cn-services');
        }
        $r = $op === 'refresh' ? CN_Services::refresh($s) : CN_Services::action($s, $op);
        self::flash(is_wp_error($r) ? $r->get_error_message() : 'انجام شد.', is_wp_error($r) ? 'error' : 'success');
        self::back('cn-services', array('service' => $s['id']));
    }

    /* ---------- stock ---------- */

    public static function page_stock() {
        $plans = array();
        foreach (CN_Plans::all() as $pl) {
            $panel = CN_Panels::get($pl['panel_id']);
            if ($panel && $panel['type'] === 'manual') {
                $plans[$pl['id']] = $pl;
            }
        }
        self::open('انبار فروش دستی');
        if (!$plans) {
            echo '<div class="cn-box cn-empty"><p>برای فروش دستی، یک پنل از نوع «فروش دستی» و پلن آن را بسازید.</p></div>';
            self::close();
            return;
        }
        $cur = isset($_GET['plan']) && isset($plans[(int) $_GET['plan']]) ? (int) $_GET['plan'] : (int) key($plans);
        echo '<div class="cn-tabs">';
        foreach ($plans as $pl) {
            echo '<a class="' . ($pl['id'] === $cur ? 'is-active' : '') . '" href="' . esc_url(self::url('cn-stock', array('plan' => $pl['id']))) . '">' . esc_html($pl['name']) . ' <span>' . esc_html(CN_Util::fa_digits(CN_Stock::count_free($pl['id']))) . '</span></a>';
        }
        echo '</div><div class="cn-grid2"><section class="cn-box"><h2>افزودن اکانت</h2>';
        self::form_open('stock_add');
        echo '<input type="hidden" name="plan_id" value="' . (int) $cur . '"><textarea name="content" rows="10" dir="ltr" placeholder="اطلاعات هر اکانت (لینک، نام کاربری/رمز یا متن کانفیگ)&#10;---&#10;اکانت بعدی"></textarea>';
        echo '<p class="cn-help">اکانت‌ها را با یک خط شامل فقط <code>---</code> از هم جدا کنید. محتوا رمزنگاری‌شده ذخیره می‌شود.</p>';
        echo '<button class="cn-btn cn-btn--primary" type="submit">افزودن به انبار</button></form></section>';
        echo '<section class="cn-box"><h2>موجودی</h2><table class="cn-table"><thead><tr><th>#</th><th>وضعیت</th><th>تاریخ</th><th></th></tr></thead><tbody>';
        foreach (CN_Stock::rows($cur) as $row) {
            echo '<tr><td>' . esc_html(CN_Util::fa_digits($row['id'])) . '</td><td>' . self::badge($row['status']) . '</td><td>' . esc_html(CN_Util::local_label($row['status'] === 'sold' ? $row['sold_at'] : $row['created_at'], false)) . '</td><td>';
            if ($row['status'] === 'free') {
                echo '<a class="cn-btn cn-btn--danger" data-cn-confirm="این اکانت از انبار حذف شود؟" href="' . esc_url(self::action_url('stock_delete', array('id' => $row['id'], 'plan' => $cur))) . '">حذف</a>';
            } elseif ($row['service_id']) {
                echo '<a href="' . esc_url(self::url('cn-services', array('service' => $row['service_id']))) . '">سرویس</a>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></section></div>';
        self::close();
    }

    public static function post_stock_add() {
        self::guard('cn_stock_add');
        $plan = (int) $_POST['plan_id'];
        $n = CN_Stock::add($plan, isset($_POST['content']) ? wp_unslash($_POST['content']) : '');
        self::flash(CN_Util::fa_digits($n) . ' اکانت به انبار اضافه شد.');
        self::back('cn-stock', array('plan' => $plan));
    }

    public static function post_stock_delete() {
        self::guard('cn_stock_delete');
        CN_Stock::delete((int) $_GET['id']);
        self::flash('حذف شد.');
        self::back('cn-stock', array('plan' => (int) $_GET['plan']));
    }

    /* ---------- settings & logs ---------- */

    public static function settings_fields() {
        return array(
            'username_prefix'   => array('label' => 'پیشوند نام کاربری اکانت‌ها', 'type' => 'text', 'default' => 'cu', 'help' => 'فقط حروف انگلیسی کوچک و عدد؛ مثل cu ← cu_1234_ab3k'),
            'category_slug'     => array('label' => 'نامک دستهٔ محصولات ابزار شبکه', 'type' => 'text', 'default' => 'network'),
            'auto_complete'     => array('label' => 'تکمیل خودکار سفارش بعد از تحویل موفق', 'type' => 'checkbox', 'default' => '1'),
            'disable_on_cancel' => array('label' => 'غیرفعال کردن اکانت با لغو یا بازپرداخت سفارش', 'type' => 'checkbox', 'default' => '1'),
            'sync_enabled'      => array('label' => 'همگام‌سازی ساعتی مصرف از پنل‌ها', 'type' => 'checkbox', 'default' => '1'),
            'log_days'          => array('label' => 'نگهداری گزارش (روز)', 'type' => 'number', 'default' => '60'),
        );
    }

    public static function page_settings() {
        self::open('تنظیمات');
        self::form_open('save_settings');
        echo '<div class="cn-box">';
        $saved = get_option('cn_settings', array());
        foreach (self::settings_fields() as $k => $f) {
            $v = array_key_exists($k, $saved) ? $saved[$k] : $f['default'];
            self::field('s[' . $k . ']', $f, $v);
        }
        echo '</div><div class="cn-formbar"><button type="submit" class="cn-btn cn-btn--primary">ذخیره</button></div></form>';
        self::close();
    }

    public static function post_save_settings() {
        self::guard('cn_save_settings');
        $in = isset($_POST['s']) && is_array($_POST['s']) ? wp_unslash($_POST['s']) : array();
        $out = array();
        foreach (self::settings_fields() as $k => $f) {
            if ($f['type'] === 'checkbox') {
                $out[$k] = empty($in[$k]) ? '0' : '1';
            } elseif ($k === 'username_prefix') {
                $out[$k] = substr(preg_replace('/[^a-z0-9]/', '', strtolower(isset($in[$k]) ? $in[$k] : '')), 0, 10);
            } elseif ($k === 'category_slug') {
                $out[$k] = sanitize_title(isset($in[$k]) ? $in[$k] : '');
            } else {
                $out[$k] = (string) (int) (isset($in[$k]) ? $in[$k] : $f['default']);
            }
        }
        update_option('cn_settings', $out, false);
        self::flash('تنظیمات ذخیره شد.');
        self::back('cn-settings');
    }

    public static function page_logs() {
        global $wpdb;
        $level = isset($_GET['level']) && $_GET['level'] === 'error' ? 'error' : '';
        self::open('گزارش', '<a class="cn-btn ' . ($level ? 'cn-btn--ghost' : 'cn-btn--dark') . '" href="' . esc_url(self::url('cn-logs')) . '">همه</a><a class="cn-btn ' . ($level ? 'cn-btn--dark' : 'cn-btn--ghost') . '" href="' . esc_url(self::url('cn-logs', array('level' => 'error'))) . '">فقط خطاها</a>');
        $sql = 'SELECT * FROM ' . CN_DB::t('logs') . ($level ? " WHERE level = 'error'" : '') . ' ORDER BY id DESC LIMIT 300';
        $rows = $wpdb->get_results($sql, ARRAY_A);
        $panels = self::panels_map();
        echo '<div class="cn-box"><table class="cn-table"><thead><tr><th>زمان</th><th>سطح</th><th>پنل</th><th>سفارش</th><th>پیام</th></tr></thead><tbody>';
        foreach ((array) $rows as $l) {
            echo '<tr><td>' . esc_html(CN_Util::local_label($l['created_at'])) . '</td><td>' . self::badge($l['level']) . '</td>';
            echo '<td>' . esc_html(isset($panels[$l['panel_id']]) ? $panels[$l['panel_id']]['name'] : '—') . '</td>';
            echo '<td>' . ((int) $l['order_id'] ? '#' . esc_html(CN_Util::fa_digits($l['order_id'])) : '—') . '</td><td>' . esc_html($l['message']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        self::close();
    }
}
