<?php
if (!defined('ABSPATH')) {
    exit;
}

class CN_Account {
    const ENDPOINT = 'network-services';

    public static function init() {
        add_action('init', array(__CLASS__, 'endpoint'));
        add_filter('woocommerce_get_query_vars', array(__CLASS__, 'query_vars'));
        add_filter('woocommerce_account_menu_items', array(__CLASS__, 'menu'));
        add_filter('woocommerce_endpoint_' . self::ENDPOINT . '_title', array(__CLASS__, 'title'));
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', array(__CLASS__, 'page'));
        add_action('template_redirect', array(__CLASS__, 'handle'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'register'));
    }

    public static function endpoint() {
        add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);
        if (get_option('cn_flush_rewrite')) {
            delete_option('cn_flush_rewrite');
            flush_rewrite_rules(false);
        }
    }

    public static function query_vars($vars) {
        $vars[self::ENDPOINT] = self::ENDPOINT;
        return $vars;
    }

    public static function title() {
        return 'سرویس‌های من';
    }

    public static function menu($items) {
        $out = array();
        foreach ($items as $k => $v) {
            $out[$k] = $v;
            if ($k === 'orders') {
                $out[self::ENDPOINT] = 'سرویس‌های من';
            }
        }
        if (!isset($out[self::ENDPOINT])) {
            $out[self::ENDPOINT] = 'سرویس‌های من';
        }
        return $out;
    }

    public static function register() {
        wp_register_style('cn-front', CN_URL . 'assets/front.css', array(), CN_VERSION);
        wp_register_script('cn-front', CN_URL . 'assets/front.js', array(), CN_VERSION, true);
    }

    public static function enqueue() {
        if (!wp_style_is('cn-front', 'registered')) {
            self::register();
        }
        wp_enqueue_style('cn-front');
        wp_enqueue_script('cn-front');
    }

    private static function page_url() {
        return wc_get_account_endpoint_url(self::ENDPOINT);
    }

    public static function handle() {
        if (empty($_GET['cn_refresh']) || !is_user_logged_in()) {
            return;
        }
        $id = (int) $_GET['cn_refresh'];
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'cn_refresh_' . $id)) {
            return;
        }
        $svc = CN_Services::get($id);
        if ($svc && $svc['user_id'] === get_current_user_id()) {
            $recent = $svc['synced_at'] && (strtotime(CN_DB::now()) - strtotime($svc['synced_at'])) < 60;
            if ($recent) {
                wc_add_notice('اطلاعات همین الان به‌روز شده است.', 'notice');
            } else {
                $r = CN_Services::refresh($svc);
                wc_add_notice(is_wp_error($r) ? 'دریافت اطلاعات از سرور ممکن نشد؛ کمی بعد دوباره امتحان کنید.' : 'اطلاعات سرویس به‌روز شد.', is_wp_error($r) ? 'error' : 'success');
            }
        }
        wp_safe_redirect(self::page_url());
        exit;
    }

    public static function page() {
        self::enqueue();
        $services = CN_Services::for_user(get_current_user_id());
        echo '<div class="cn-wrap" dir="rtl" lang="fa">';
        if (!$services) {
            $cat = get_term_by('slug', CN_Util::setting('category_slug', 'network'), 'product_cat');
            echo '<div class="cn-empty"><p>هنوز سرویسی ندارید.</p>';
            if ($cat && $cat->count > 0 && !is_wp_error($link = get_term_link($cat))) {
                echo '<a class="cn-btn cn-btn--primary" href="' . esc_url($link) . '">مشاهدهٔ ابزارهای شبکه</a>';
            }
            echo '</div></div>';
            return;
        }
        foreach ($services as $svc) {
            self::render_service($svc, 'account');
        }
        echo '</div>';
    }

    /* ---------- rendering ---------- */

    private static function plan_name(array $svc) {
        $plan = $svc['plan_id'] ? CN_Plans::get($svc['plan_id']) : null;
        return $plan ? $plan['name'] : 'سرویس شبکه';
    }

    private static function status_class($status) {
        $map = array('active' => 'ok', 'on_hold' => 'wait', 'disabled' => 'off', 'expired' => 'off', 'limited' => 'warn', 'deleted' => 'off');
        return isset($map[$status]) ? $map[$status] : 'wait';
    }

    /** Driver guide for opening the subscription page, or null. */
    private static function guide(array $svc) {
        if (empty($svc['sub_url'])) {
            return null;
        }
        $panel = CN_Panels::get($svc['panel_id']);
        $cls = $panel ? CN_Drivers::cls($panel['type']) : null;
        $g = $cls ? $cls::sub_guide() : null;
        return is_array($g) && !empty($g['button']) ? $g + array('steps' => array()) : null;
    }

    private static function copy_row($label, $value, $mono = true) {
        $id = 'cn-' . wp_generate_uuid4();
        echo '<div class="cn-field"><span class="cn-label">' . esc_html($label) . '</span>';
        echo '<div class="cn-copy"><code id="' . esc_attr($id) . '" class="' . ($mono ? 'cn-mono' : '') . '" dir="ltr">' . esc_html($value) . '</code>';
        echo '<button type="button" class="cn-btn cn-btn--ghost" data-cn-copy="#' . esc_attr($id) . '">کپی</button></div></div>';
    }

    public static function render_service(array $svc, $context = 'account', $renewed = false) {
        $status = $svc['status'];
        $limit = $svc['limit_bytes'];
        $used = $svc['usage_bytes'];
        $pct = $limit > 0 ? min(100, round($used * 100 / $limit)) : 0;
        $left = CN_Util::days_left($svc['expires_at']);
        $panel = CN_Panels::get($svc['panel_id']);
        $cls = $panel ? CN_Drivers::cls($panel['type']) : null;
        $tracks = $cls && $cls::supports('usage');
        $off = in_array($status, array('disabled', 'deleted'), true);

        echo '<article class="cn-card">';
        echo '<header class="cn-card__head"><div><h3 class="cn-card__title">' . esc_html(self::plan_name($svc)) . ($renewed ? ' <span class="cn-card__tag">تمدید شد</span>' : '') . '</h3>';
        echo '<span class="cn-card__user" dir="ltr">' . esc_html($svc['username']) . '</span></div>';
        echo '<span class="cn-badge cn-badge--' . esc_attr(self::status_class($status)) . '">' . esc_html(CN_Util::status_label($status)) . '</span></header>';

        echo '<div class="cn-stats">';
        if ($tracks) {
            echo '<div class="cn-stat"><span class="cn-label">مصرف</span><strong>' . esc_html(CN_Util::bytes_label($used)) . ' از ' . esc_html($limit > 0 ? CN_Util::bytes_label($limit) : 'نامحدود') . '</strong>';
            if ($limit > 0) {
                echo '<div class="cn-bar"><span style="width:' . (int) $pct . '%"></span></div>';
            }
        } else {
            echo '<div class="cn-stat"><span class="cn-label">حجم</span><strong>' . esc_html($limit > 0 ? CN_Util::bytes_label($limit) : 'نامحدود') . '</strong>';
        }
        echo '</div>';
        echo '<div class="cn-stat"><span class="cn-label">انقضا</span><strong>' . esc_html(CN_Util::expiry_label($svc)) . '</strong>';
        if ($left !== null) {
            echo '<small>' . esc_html(CN_Util::fa_digits($left)) . ' روز مانده</small>';
        }
        echo '</div></div>';

        if ($off) {
            echo '<div class="cn-field"><div class="cn-note">این سرویس غیرفعال است. برای پیگیری با پشتیبانی تماس بگیرید.</div></div>';
            echo '</article>';
            return;
        }
        $guide = self::guide($svc);
        if ($guide) {
            echo '<div class="cn-guide"><a class="cn-btn cn-btn--primary cn-btn--block" href="' . esc_url($svc['sub_url']) . '" target="_blank" rel="noopener">' . esc_html($guide['button']) . '</a>';
            if ($guide['steps']) {
                echo '<ol class="cn-steps">';
                foreach ($guide['steps'] as $step) {
                    echo '<li>' . esc_html($step) . '</li>';
                }
                echo '</ol>';
            }
            echo '</div>';
        }
        if (!empty($svc['sub_url'])) {
            self::copy_row('لینک اشتراک', $svc['sub_url']);
        }
        foreach (CN_Services::configs($svc) as $c) {
            $label = isset($c[0]) ? $c[0] : '';
            $value = isset($c[1]) ? (string) $c[1] : '';
            $kind = isset($c[2]) ? $c[2] : 'text';
            if ($value === '') {
                continue;
            }
            if ($kind === 'file') {
                $fname = isset($c[3]) ? $c[3] : 'config.txt';
                echo '<div class="cn-field"><span class="cn-label">' . esc_html($label) . '</span>';
                echo '<a class="cn-btn cn-btn--dark" download="' . esc_attr($fname) . '" href="data:application/octet-stream;base64,' . esc_attr(base64_encode($value)) . '">دانلود ' . esc_html($fname) . '</a></div>';
            } elseif ($kind === 'note') {
                echo '<div class="cn-field"><span class="cn-label">' . esc_html($label) . '</span><pre class="cn-pre">' . esc_html($value) . '</pre></div>';
            } else {
                self::copy_row($label, $value);
            }
        }

        if ($context === 'account') {
            ob_start();
            if ($tracks) {
                $refresh = wp_nonce_url(add_query_arg('cn_refresh', $svc['id'], self::page_url()), 'cn_refresh_' . $svc['id']);
                echo '<a class="cn-btn cn-btn--ghost" href="' . esc_url($refresh) . '">به‌روزرسانی مصرف</a>';
            }
            self::renew_form($svc);
            $foot = ob_get_clean();
            if ($foot !== '') {
                echo '<footer class="cn-card__foot">' . $foot . '</footer>';
            }
        } else {
            echo '<footer class="cn-card__foot"><span class="cn-label">مصرف، انقضا و تمدید را بعداً هم در حساب کاربری می‌بینید.</span>';
            echo '<a class="cn-btn cn-btn--dark" href="' . esc_url(self::page_url()) . '">سرویس‌های من</a></footer>';
        }
        echo '</article>';
    }

    private static function renew_form(array $svc) {
        $panel = CN_Panels::get($svc['panel_id']);
        $cls = $panel ? CN_Drivers::cls($panel['type']) : null;
        if (!$cls || !$cls::supports('renew') || $panel['status'] !== 'active') {
            return;
        }
        $options = array();
        foreach (CN_Plans::all($svc['panel_id']) as $plan) {
            if ($plan['status'] !== 'active' || !$plan['product_id']) {
                continue;
            }
            $p = wc_get_product($plan['product_id']);
            if (!$p || !$p->is_purchasable() || $p->get_status() !== 'publish') {
                continue;
            }
            $options[$p->get_id()] = $plan['name'] . ' — ' . wp_strip_all_tags(wc_price($p->get_price()));
        }
        if (!$options) {
            return;
        }
        $current = 0;
        foreach (CN_Plans::all($svc['panel_id']) as $plan) {
            if ($plan['id'] === $svc['plan_id']) {
                $current = $plan['product_id'];
            }
        }
        echo '<form class="cn-renew" method="get" action="' . esc_url(wc_get_cart_url()) . '">';
        echo '<select name="add-to-cart" aria-label="پلن تمدید">';
        foreach ($options as $pid => $label) {
            echo '<option value="' . (int) $pid . '"' . selected($pid, $current, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<input type="hidden" name="cn_renew" value="' . (int) $svc['id'] . '">';
        echo '<input type="hidden" name="_cn_nonce" value="' . esc_attr(wp_create_nonce('cn_renew_' . $svc['id'])) . '">';
        echo '<button type="submit" class="cn-btn cn-btn--primary">تمدید</button></form>';
    }

    public static function plain_service(array $svc, $renewed = false) {
        $lines = array(self::plan_name($svc) . ($renewed ? ' (تمدید شد)' : '') . ' — ' . $svc['username']);
        $lines[] = 'حجم: ' . ($svc['limit_bytes'] > 0 ? CN_Util::bytes_label($svc['limit_bytes']) : 'نامحدود') . ' | انقضا: ' . CN_Util::expiry_label($svc);
        $guide = self::guide($svc);
        if ($guide) {
            $lines[] = $guide['button'] . ': ' . $svc['sub_url'];
            foreach ($guide['steps'] as $i => $step) {
                $lines[] = CN_Util::fa_digits($i + 1) . '. ' . $step;
            }
        } elseif (!empty($svc['sub_url'])) {
            $lines[] = 'لینک اشتراک: ' . $svc['sub_url'];
        }
        $files = 0;
        foreach (CN_Services::configs($svc) as $c) {
            if (isset($c[2]) && $c[2] === 'file') {
                $files++;
            } elseif (!empty($c[1])) {
                $lines[] = $c[0] . ': ' . $c[1];
            }
        }
        if ($files) {
            $lines[] = 'فایل‌های اتصال (' . CN_Util::fa_digits($files) . ' فایل): از حساب کاربری › سرویس‌های من دانلود کنید';
        }
        return implode("\n", $lines);
    }

    /** Email card: one column so it stays readable on phones; values LTR. */
    public static function email_service(array $svc, $renewed = false) {
        $account = self::page_url();
        $label = function ($t) {
            return '<div style="color:#6E6E6E;font-size:12px;margin:12px 0 4px">' . esc_html($t) . '</div>';
        };
        $value = function ($v, $multiline = false) {
            return '<div dir="ltr" style="background:#F4F4F4;border:1px solid #E5E5E5;border-radius:6px;padding:8px 10px;font-family:Consolas,Menlo,monospace;font-size:13px;line-height:1.6;color:#1A1A1A;text-align:left;word-break:break-all;' . ($multiline ? 'white-space:pre-wrap;' : '') . '">' . esc_html($v) . '</div>';
        };
        $button = function ($href, $text, $bg, $fg) {
            return '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:4px 0 8px"><tr><td align="center" bgcolor="' . $bg . '" style="border-radius:8px">'
                . '<a href="' . esc_url($href) . '" target="_blank" style="display:block;padding:13px 16px;color:' . $fg . ';background:' . $bg . ';border-radius:8px;font-weight:bold;font-size:15px;text-decoration:none;text-align:center">' . esc_html($text) . '</a></td></tr></table>';
        };

        $html = '<table role="presentation" dir="rtl" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #E5E5E5;border-radius:10px;margin:0 0 16px;border-collapse:separate;text-align:right">';
        $html .= '<tr><td style="background:#1A1A1A;color:#F5C518;padding:12px 16px;font-weight:bold;font-size:15px;border-radius:10px 10px 0 0;border-bottom:3px solid #F5C518">' . esc_html(self::plan_name($svc));
        if ($renewed) {
            $html .= ' <span style="background:#F5C518;color:#0D0D0D;border-radius:20px;padding:1px 10px;font-size:12px">تمدید شد</span>';
        }
        $html .= '<div dir="ltr" style="color:#BDBDBD;font-weight:normal;font-size:12px;font-family:Consolas,Menlo,monospace;text-align:right;margin-top:2px">' . esc_html($svc['username']) . '</div></td></tr>';
        $html .= '<tr><td style="padding:14px 16px">';
        $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>'
            . '<td width="50%" style="background:#F4F4F4;border-radius:8px;padding:10px 12px"><div style="color:#6E6E6E;font-size:12px">حجم</div><strong style="color:#0D0D0D">' . esc_html($svc['limit_bytes'] > 0 ? CN_Util::bytes_label($svc['limit_bytes']) : 'نامحدود') . '</strong></td>'
            . '<td width="8"></td>'
            . '<td width="50%" style="background:#F4F4F4;border-radius:8px;padding:10px 12px"><div style="color:#6E6E6E;font-size:12px">انقضا</div><strong style="color:#0D0D0D">' . esc_html(CN_Util::expiry_label($svc)) . '</strong></td>'
            . '</tr></table>';

        $guide = self::guide($svc);
        if ($guide) {
            $html .= '<div style="margin-top:14px">' . $button($svc['sub_url'], $guide['button'], '#F5C518', '#0D0D0D') . '</div>';
            if ($guide['steps']) {
                $html .= '<ol style="margin:6px 0 0;padding:0 20px 0 0;color:#2C2C2C;font-size:13px;line-height:1.9">';
                foreach ($guide['steps'] as $step) {
                    $html .= '<li>' . esc_html($step) . '</li>';
                }
                $html .= '</ol>';
            }
        }
        if (!empty($svc['sub_url'])) {
            $html .= $label('لینک اشتراک') . $value($svc['sub_url']);
        }
        $files = 0;
        foreach (CN_Services::configs($svc) as $c) {
            $kind = isset($c[2]) ? $c[2] : 'text';
            if ($kind === 'file') {
                $files++;
            } elseif (!empty($c[1])) {
                $html .= $label($c[0]) . $value($c[1], $kind === 'note');
            }
        }
        if ($files) {
            $html .= $label('فایل‌های اتصال') . '<div style="font-size:13px;color:#1A1A1A">' . esc_html(CN_Util::fa_digits($files)) . ' فایل آماده است؛ از <a href="' . esc_url($account) . '" style="color:#D4A70F;font-weight:bold">حساب کاربری › سرویس‌های من</a> دانلود کنید.</div>';
        }
        return $html . '</td></tr></table>';
    }
}
