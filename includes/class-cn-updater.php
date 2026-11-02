<?php
/**
 * به‌روزرسانی خودکار «کاریونیت — ابزار شبکه» از Releaseهای گیت‌هاب.
 *
 * - آخرین Release را از API گیت‌هاب می‌خواند و پیشوند v را از tag_name حذف می‌کند.
 * - فایل پیوست carunit-network.zip و در نبودِ آن zipball_url استفاده می‌شود.
 * - نتیجه‌ی موفق ۶ ساعت و نتیجه‌ی ناموفق ۱ ساعت کش می‌شود.
 * - توکن اختیاری: ثابت CN_GITHUB_TOKEN یا گزینه‌ی cn_github_token (برای محدودیت نرخ).
 * - پوشه‌ی استخراج‌شده همیشه به carunit-network تغییر نام می‌دهد تا افزونه فعال بماند.
 */

if (!defined('ABSPATH')) {
    exit;
}

class CN_Updater {

    const REPO      = 'silent4time/carunit-network';
    const SLUG      = 'carunit-network';
    const MAIN_FILE = 'carunit-network.php';
    const ASSET     = 'carunit-network.zip';
    const TRANSIENT = 'cn_update_info';
    const TTL_OK    = 21600; // ۶ ساعت
    const TTL_FAIL  = 3600;  // ۱ ساعت

    protected $basename;
    protected $version;

    public function __construct($basename = null, $version = null) {
        $this->basename = $basename ?: plugin_basename(CN_FILE);
        $this->version  = $version ?: CN_VERSION;
    }

    public static function init() {
        $u = new self();
        add_filter('pre_set_site_transient_update_plugins', array($u, 'inject_update'));
        add_filter('site_transient_update_plugins', array($u, 'drop_stale_update'));
        add_filter('plugins_api', array($u, 'plugins_api'), 20, 3);
        add_filter('upgrader_source_selection', array($u, 'fix_source_folder'), 10, 4);
        add_action('upgrader_process_complete', array($u, 'purge_cache'), 10, 2);
        add_filter('plugin_row_meta', array($u, 'row_meta'), 20, 2);
        add_action('admin_init', array($u, 'handle_check_now'));
        add_action('admin_notices', array($u, 'admin_notice'));
        return $u;
    }

    /** نسخه‌ی Release را با نسخه‌ی نصب‌شده مقایسه می‌کند. */
    public static function normalize($tag) {
        return ltrim(trim((string) $tag), 'vV');
    }

    public function is_newer($remote_version) {
        $v = self::normalize($remote_version);
        return $v !== '' && version_compare($v, $this->version, '>');
    }

    protected function token() {
        if (defined('CN_GITHUB_TOKEN') && CN_GITHUB_TOKEN) {
            return (string) CN_GITHUB_TOKEN;
        }
        return (string) get_option('cn_github_token', '');
    }

    /** اطلاعات آخرین نسخه (با کش). */
    public function get_remote($force = false) {
        if (!$force) {
            $cached = get_site_transient(self::TRANSIENT);
            if (is_array($cached)) {
                return empty($cached['version']) ? null : $cached;
            }
        }
        $info = $this->fetch_release();
        set_site_transient(self::TRANSIENT, $info ? $info : array('version' => ''), $info ? self::TTL_OK : self::TTL_FAIL);
        return $info;
    }

    protected function fetch_release() {
        $args = array(
            'timeout' => 15,
            'headers' => array(
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'carunit-network/' . $this->version,
            ),
        );
        $token = $this->token();
        if ($token !== '') {
            $args['headers']['Authorization'] = 'Bearer ' . $token;
        }
        $url = 'https://api.github.com/repos/' . self::REPO . '/releases/latest';
        $res = wp_remote_get($url, apply_filters('cn_updater_request_args', $args, $url));
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            return null;
        }
        $data = json_decode((string) wp_remote_retrieve_body($res), true);
        return self::parse_release($data);
    }

    public static function parse_release($data) {
        if (!is_array($data) || empty($data['tag_name'])) {
            return null;
        }
        $package = '';
        foreach ((array) ($data['assets'] ?? array()) as $asset) {
            if (($asset['name'] ?? '') === self::ASSET && !empty($asset['browser_download_url'])) {
                $package = (string) $asset['browser_download_url'];
                break;
            }
        }
        if ($package === '') {
            $package = (string) ($data['zipball_url'] ?? '');
        }
        return array(
            'version'   => self::normalize($data['tag_name']),
            'package'   => $package,
            'url'       => (string) ($data['html_url'] ?? 'https://github.com/' . self::REPO),
            'changelog' => (string) ($data['body'] ?? ''),
            'published' => (string) ($data['published_at'] ?? ''),
        );
    }

    /** پیام کهنه‌ی به‌روزرسانی (مثلاً بعد از نصب دستی) حذف می‌شود. */
    public function drop_stale_update($transient) {
        if (is_object($transient) && isset($transient->response[$this->basename])) {
            $item = $transient->response[$this->basename];
            $new  = is_object($item) ? ($item->new_version ?? '') : ($item['new_version'] ?? '');
            if ($new && version_compare($new, $this->version, '<=')) {
                unset($transient->response[$this->basename]);
            }
        }
        return $transient;
    }

    public function inject_update($transient) {
        if (!is_object($transient)) {
            return $transient;
        }
        $remote = $this->get_remote();
        $item = (object) array(
            'id'           => 'github.com/' . self::REPO,
            'slug'         => self::SLUG,
            'plugin'       => $this->basename,
            'new_version'  => $remote['version'] ?? $this->version,
            'url'          => $remote['url'] ?? 'https://github.com/' . self::REPO,
            'package'      => $remote['package'] ?? '',
            'requires_php' => '7.4',
        );
        if ($remote && $this->is_newer($remote['version']) && !empty($remote['package'])) {
            $transient->response[$this->basename] = $item;
            unset($transient->no_update[$this->basename]);
        } else {
            $transient->no_update[$this->basename] = $item;
            unset($transient->response[$this->basename]);
        }
        return $transient;
    }

    public function plugins_api($result, $action, $args) {
        if ($action !== 'plugin_information' || (is_object($args) ? ($args->slug ?? '') : '') !== self::SLUG) {
            return $result;
        }
        $remote = $this->get_remote();
        $remote = $remote ? $remote : array();
        return (object) array(
            'name'          => 'کاریونیت — ابزار شبکه',
            'slug'          => self::SLUG,
            'version'       => $remote['version'] ?? $this->version,
            'author'        => '<a href="https://carunit.ir">کاریونیت</a>',
            'homepage'      => 'https://github.com/' . self::REPO,
            'download_link' => $remote['package'] ?? '',
            'requires_php'  => '7.4',
            'last_updated'  => $remote['published'] ?? '',
            'sections'      => array(
                'description' => '<div dir="rtl" style="text-align:right;font-family:Vazirmatn,Tahoma,sans-serif">' . esc_html('اتصال به پنل‌های اکانت شبکه، ساخت خودکار اکانت پس از پرداخت ووکامرس، تمدید و مدیریت سرویس‌ها.') . '</div>',
                'changelog'   => $this->changelog_html((string) ($remote['changelog'] ?? '')),
            ),
        );
    }

    /** تبدیل Markdown سادهٔ تغییرات به HTML راست‌به‌چپ. */
    public function changelog_html($md) {
        if (trim($md) === '') {
            $file = CN_DIR . 'CHANGELOG.md';
            $md = file_exists($file) ? (string) file_get_contents($file) : '';
        }
        $html = '';
        $open = false;
        foreach (preg_split('/\R/u', $md) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^#{1,6}\s*(.+)$/u', $line, $m)) {
                if ($open) { $html .= '</ul>'; $open = false; }
                $html .= '<h4>' . esc_html($m[1]) . '</h4>';
            } elseif (preg_match('/^[\-\*]\s+(.+)$/u', $line, $m)) {
                if (!$open) { $html .= '<ul>'; $open = true; }
                $html .= '<li>' . esc_html($m[1]) . '</li>';
            } else {
                $html .= '<p>' . esc_html($line) . '</p>';
            }
        }
        if ($open) {
            $html .= '</ul>';
        }
        return '<div dir="rtl" style="text-align:right;font-family:Vazirmatn,Tahoma,sans-serif">' . $html . '</div>';
    }

    /** پوشهٔ zip گیت‌هاب (مثل silent4time-carunit-network-abc123) به carunit-network تغییر نام می‌دهد. */
    public function fix_source_folder($source, $remote_source, $upgrader = null, $hook_extra = array()) {
        global $wp_filesystem;
        if (is_wp_error($source)) {
            return $source;
        }
        $ours = (is_array($hook_extra) ? ($hook_extra['plugin'] ?? '') : '') === $this->basename;
        if (!$ours && !file_exists(trailingslashit($source) . self::MAIN_FILE)) {
            return $source;
        }
        $desired = trailingslashit($remote_source) . self::SLUG . '/';
        if (untrailingslashit($source) === untrailingslashit($desired)) {
            return $source;
        }
        if ($wp_filesystem && $wp_filesystem->move(untrailingslashit($source), untrailingslashit($desired), true)) {
            return $desired;
        }
        return new WP_Error('cn_rename_failed', 'تغییر نام پوشهٔ افزونه هنگام به‌روزرسانی ممکن نشد.');
    }

    public function purge_cache($upgrader = null, $options = array()) {
        if (is_array($options) && ($options['type'] ?? '') === 'plugin') {
            delete_site_transient(self::TRANSIENT);
        }
    }

    public function row_meta($meta, $file) {
        if ($file !== $this->basename || !current_user_can('update_plugins')) {
            return $meta;
        }
        $url = wp_nonce_url(add_query_arg('cn_check_update', '1', self_admin_url('plugins.php')), 'cn_check_update');
        $meta['cn_check_update'] = '<a href="' . esc_url($url) . '">' . esc_html('بررسی به‌روزرسانی') . '</a>';
        return $meta;
    }

    public function handle_check_now() {
        if (empty($_GET['cn_check_update']) || !current_user_can('update_plugins')) {
            return;
        }
        check_admin_referer('cn_check_update');
        delete_site_transient(self::TRANSIENT);
        $this->get_remote(true);
        delete_site_transient('update_plugins');
        wp_update_plugins();
        wp_safe_redirect(add_query_arg('cn_checked', '1', self_admin_url('plugins.php')));
        exit;
    }

    /** اعلان مدیریت: وجود نسخهٔ جدید با دکمهٔ «به‌روزرسانی»، یا نتیجهٔ بررسی دستی. */
    public function admin_notice() {
        if (!current_user_can('update_plugins')) {
            return;
        }
        $remote  = get_site_transient(self::TRANSIENT);
        $latest  = is_array($remote) ? (string) ($remote['version'] ?? '') : '';
        $checked = !empty($_GET['cn_checked']);
        $style   = 'direction:rtl;text-align:right;font-family:Vazirmatn,Tahoma,sans-serif';

        if ($latest !== '' && $this->is_newer($latest)) {
            $url = wp_nonce_url(self_admin_url('update.php?action=upgrade-plugin&plugin=' . rawurlencode($this->basename)), 'upgrade-plugin_' . $this->basename);
            $msg = sprintf('نسخهٔ جدید «کاریونیت — ابزار شبکه» (%s) منتشر شده است؛ نسخهٔ نصب‌شده: %s.', $latest, $this->version);
            echo '<div class="notice notice-warning cn-update-notice" style="' . esc_attr($style) . '"><p>' . esc_html($msg)
                . ' <a class="button button-primary" href="' . esc_url($url) . '">' . esc_html('به‌روزرسانی') . '</a></p></div>';
            return;
        }
        if (!$checked) {
            return;
        }
        $msg = $latest !== '' ? '«کاریونیت — ابزار شبکه» به‌روز است.' : 'ارتباط با گیت‌هاب برقرار نشد؛ بعداً دوباره بررسی کنید.';
        echo '<div class="notice notice-info is-dismissible" style="' . esc_attr($style) . '"><p>' . esc_html($msg) . '</p></div>';
    }
}
