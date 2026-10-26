<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Base class for panel drivers.
 *
 * Panel array: id, name, type, url, settings (array), secrets (array).
 * Service array: id, username, sub_url, data (array), limit_bytes, expires_at …
 *
 * create() returns: ok, username, sub_url, configs (list of [label, value, kind]), data (array), limit (bytes), expire (ts|0)
 * info()   returns: ok, status, used, limit, expire (ts|0), online, sub_url, configs
 */
abstract class CN_Driver {
    /** Site -> Polban wait. Polban 1.0.1 waits up to 60s for the panel, so this must be longer. */
    const POLBAN_TIMEOUT = 75;

    /** @var array */
    protected $panel;

    public function __construct(array $panel) {
        $panel += array('id' => 0, 'url' => '', 'settings' => array(), 'secrets' => array());
        $this->panel = $panel;
    }

    abstract public static function label();

    /** Panel connection fields: key => [label, type, secret, help, options, default, required]. */
    public static function fields() {
        return array();
    }

    /** Plan-level options for this panel type. */
    public static function plan_fields() {
        return array();
    }

    /** Operations the panel supports: renew, reset, toggle, delete, usage, revoke. */
    public static function caps() {
        return array('renew', 'reset', 'toggle', 'delete', 'usage');
    }

    public static function supports($op) {
        return in_array($op, static::caps(), true);
    }

    /** Driver implements find_created() (look up a user a timed-out create may have made). */
    public static function can_find() {
        return false;
    }

    /** Every successful create/renew is a paid purchase (no automatic retry without a user lookup). */
    public static function paid() {
        return false;
    }

    public static function uses_url() {
        return true;
    }

    /** Volume and duration are defined by the panel itself (group, profile or remote plan). */
    public static function remote_limits() {
        return false;
    }

    public static function url_help() {
        return 'آدرس کامل پنل همراه پورت، مثل https://panel.example.com:8000';
    }

    /** When the subscription link is a page the customer should open: button label and setup steps. */
    public static function sub_guide() {
        return null;
    }

    abstract public function test();

    abstract public function create(array $a);

    abstract public function info(array $svc);

    /**
     * Renew a service. $a: gb, days, mode (add|reset), options.
     * add:   extra volume on top of the current limit, extra days after the current expiry.
     * reset: usage zeroed, limit and expiry recalculated from now.
     */
    public function renew(array $svc, array $a) {
        $info = $this->info($svc);
        if (empty($info['ok'])) {
            return $info;
        }
        $now   = time();
        $gb    = isset($a['gb']) ? (float) $a['gb'] : 0;
        $days  = isset($a['days']) ? (int) $a['days'] : 0;
        $bytes = CN_Util::gb_to_bytes($gb);
        $mode  = isset($a['mode']) && $a['mode'] === 'reset' ? 'reset' : 'add';

        if ($mode === 'reset' && static::supports('reset')) {
            $r = $this->reset($svc);
            if (empty($r['ok'])) {
                return $r;
            }
            $limit  = $gb > 0 ? $bytes : 0;
            $expire = $days > 0 ? $now + $days * DAY_IN_SECONDS : 0;
        } else {
            $old_limit = (int) $info['limit'];
            if ($gb <= 0) {
                $limit = 0;
            } elseif ($old_limit > 0) {
                $limit = $old_limit + $bytes;
            } else {
                $limit = (int) $info['used'] + $bytes;
            }
            $base   = max($now, (int) $info['expire']);
            $expire = $days > 0 ? $base + $days * DAY_IN_SECONDS : 0;
        }

        $r = $this->set_limits($svc, $limit, $expire, $info);
        if (empty($r['ok'])) {
            return $r;
        }
        return $this->ok(array('limit' => $limit, 'expire' => $expire));
    }

    /** Write a new data limit (bytes, 0 = unlimited) and expiry (ts, 0 = never) and enable the account. */
    protected function set_limits(array $svc, $limit, $expire, array $info) {
        return $this->unsupported();
    }

    public function reset(array $svc) {
        return $this->unsupported();
    }

    public function set_enabled(array $svc, $enabled) {
        return $this->unsupported();
    }

    public function delete(array $svc) {
        return $this->unsupported();
    }

    public function revoke(array $svc) {
        return $this->unsupported();
    }

    /* ---------- helpers ---------- */

    /** Regex of the page path admins copy from the browser after the panel root (e.g. /dashboard). */
    protected static function ui_path() {
        return '';
    }

    /** API root: the saved address without the browser page path or #fragment. */
    protected function base() {
        $b = rtrim(preg_replace('/#.*$/', '', trim((string) $this->panel['url'])), '/');
        if (static::ui_path() !== '') {
            $b = rtrim(preg_replace('#' . static::ui_path() . '(/.*)?$#i', '', $b), '/');
        }
        return $b;
    }

    protected function url($path) {
        return $this->base() . $path;
    }

    protected function s($key, $default = '') {
        $v = isset($this->panel['settings'][$key]) ? $this->panel['settings'][$key] : '';
        return $v === '' || $v === null ? $default : $v;
    }

    protected function secret($key) {
        return isset($this->panel['secrets'][$key]) ? (string) $this->panel['secrets'][$key] : '';
    }

    protected function opt(array $a, $key, $default = '') {
        $o = isset($a['options']) && is_array($a['options']) ? $a['options'] : array();
        return isset($o[$key]) && $o[$key] !== '' ? $o[$key] : $default;
    }

    protected function http($method, $path, array $o = array()) {
        $url = preg_match('#^https?://#i', $path) ? $path : $this->url($path);
        $o += array('verify' => (bool) $this->s('verify_ssl', 0), 'timeout' => 20);

        // Polban proxy support
        if (!empty($this->s('use_polban'))) {
            $polban_url = rtrim(get_option('cn_polban_url', ''), '/');
            $polban_key = get_option('cn_polban_key', '');
            if ($polban_url && $polban_key) {
                return $this->proxy_via_polban($method, $url, $o, $polban_url, $polban_key);
            }
        }

        $res = CN_Http::request($method, $url, $o);
        $res['target'] = (string) wp_parse_url($url, PHP_URL_HOST);
        $res['via'] = 'direct';
        return $res;
    }

    /**
     * Proxy the request through Polban /v1/proxy
     */
    protected function proxy_via_polban($method, $target_url, array $o, $polban_url, $polban_key) {
        $headers = isset($o['headers']) && is_array($o['headers']) ? $o['headers'] : array();
        // Mirror CN_Http::request(): bearer/auth/cookies only become headers there,
        // so they must be added here or Polban forwards the request without them.
        // Custom headers (X-API-KEY, Hiddify-API-Key, ...) pass through unchanged.
        if (!isset($headers['Accept'])) {
            $headers['Accept'] = 'application/json';
        }
        if (!empty($o['bearer'])) {
            $headers['Authorization'] = 'Bearer ' . $o['bearer'];
        }
        if (!empty($o['auth'])) {
            $headers['Authorization'] = 'Basic ' . base64_encode($o['auth'][0] . ':' . $o['auth'][1]);
        }
        if (!empty($o['cookies']) && !isset($headers['Cookie'])) {
            $pairs = array();
            foreach ((array) $o['cookies'] as $name => $value) {
                if (is_object($value) && isset($value->name)) {
                    $pairs[] = $value->name . '=' . $value->value;
                } elseif (!is_object($value) && !is_array($value)) {
                    $pairs[] = $name . '=' . $value;
                }
            }
            if ($pairs) {
                $headers['Cookie'] = implode('; ', $pairs);
            }
        }
        $body = null;
        if (array_key_exists('json', $o)) {
            $body = wp_json_encode($o['json']);
            $headers['Content-Type'] = 'application/json';
        } elseif (array_key_exists('form', $o)) {
            $body = is_array($o['form']) ? http_build_query($o['form']) : $o['form'];
            if (!isset($headers['Content-Type'])) {
                $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            }
        } elseif (array_key_exists('body', $o)) {
            $body = $o['body'];
        }

        // Polban /v1/proxy accepts only method, url, headers and body. SSL verification
        // and redirects are fixed on the Polban side (config verify_ssl, never follows redirects).
        $proxy_payload = array(
            'method'  => strtoupper($method),
            'url'     => $target_url,
            'headers' => $headers,
        );
        if ($body !== null) {
            $proxy_payload['body'] = $body;
        }

        $proxy_args = array(
            'headers' => array(
                'X-Polban-Key' => $polban_key,
                'Content-Type' => 'application/json',
            ),
            'json'    => $proxy_payload,
            // Longer than Polban's own upstream timeout (25s) so its 502 error arrives instead of a local timeout.
            'timeout' => max(self::POLBAN_TIMEOUT, isset($o['timeout']) ? (int) $o['timeout'] : 0),
            'verify'  => true,
        );

        $res = CN_Http::request('POST', $polban_url . '/v1/proxy', $proxy_args);

        // Unwrap Polban response (uses "code" not "status"). Polban answers 502 with
        // code/error when the upstream is unreachable; surface that error as-is.
        $j = is_array($res['json']) ? $res['json'] : null;
        if ($j !== null && isset($j['code']) && (isset($j['body']) || !empty($j['error']))) {
            $pbody = isset($j['body']) ? (string) $j['body'] : '';
            $decoded = json_decode($pbody, true);
            $code = (int) $j['code'];
            $error = isset($j['error']) ? (string) $j['error'] : '';
            $target = (string) wp_parse_url($target_url, PHP_URL_HOST);
            $rh = isset($j['headers']) && is_array($j['headers']) ? array_change_key_case($j['headers'], CASE_LOWER) : array();
            return array(
                'code'    => $code,
                'body'    => $pbody,
                'headers' => $rh,
                'error'   => $error,
                'ok'      => $error === '' && $code >= 200 && $code < 300,
                'json'    => is_array($decoded) ? $decoded : null,
                'cookies' => self::parse_set_cookie(isset($rh['set-cookie']) ? $rh['set-cookie'] : array()),
                'target'  => $target,
                'via'     => 'polban',
            );
        }

        // Polban itself did not answer with a proxy result (unreachable, bad key, ...).
        $res['target'] = (string) wp_parse_url($polban_url, PHP_URL_HOST);
        $res['via'] = 'polban_link';
        return $res;
    }

    /**
     * Set-Cookie header value(s) -> name => value map (same shape CN_Http::request returns).
     * Note: Polban flattens response headers into a map, so only the last Set-Cookie line survives.
     */
    protected static function parse_set_cookie($raw) {
        $out = array();
        foreach ((array) $raw as $line) {
            $line = (string) $line;
            // A folded multi-cookie value: split on commas that start a new name=value pair.
            foreach (preg_split('/,\s*(?=[^;,\s]+=)/', $line) as $one) {
                $first = trim(strtok($one, ';'));
                if ($first === '' || strpos($first, '=') === false) {
                    continue;
                }
                list($n, $v) = explode('=', $first, 2);
                $n = trim($n);
                if ($n === '' || in_array(strtolower($n), array('expires', 'path', 'domain', 'max-age', 'samesite'), true)) {
                    continue;
                }
                $out[$n] = trim($v);
            }
        }
        return $out;
    }

    protected function ok(array $extra = array()) {
        return array('ok' => true) + $extra;
    }

    protected function fail($msg, $res = null) {
        if (is_array($res)) {
            $msg .= ' (' . CN_Http::error_text($res) . ')';
        }
        return array('ok' => false, 'msg' => $msg);
    }

    protected function unsupported() {
        return $this->fail('این عملیات در این نوع پنل پشتیبانی نمی‌شود.');
    }

    /** Per-panel cache (tokens, cookies); stored encrypted. */
    protected function cache_get($key) {
        $v = get_transient($this->cache_key($key));
        if (!$v) {
            return null;
        }
        $d = CN_Crypto::decrypt($v);
        return isset($d['v']) ? $d['v'] : null;
    }

    protected function cache_set($key, $value, $ttl) {
        set_transient($this->cache_key($key), CN_Crypto::encrypt(array('v' => $value)), $ttl);
    }

    protected function cache_del($key) {
        delete_transient($this->cache_key($key));
    }

    private function cache_key($key) {
        return 'cn_c_' . md5($this->panel['id'] . '|' . $this->panel['url'] . '|' . wp_json_encode($this->panel['settings']) . '|' . $key);
    }

    /** Absolute subscription URL from a value that may be relative. */
    protected function abs_url($u, $base = '') {
        $u = (string) $u;
        if ($u === '' || preg_match('#^[a-z]+://#i', $u)) {
            return $u;
        }
        $base = $base !== '' ? $base : $this->base();
        $p = wp_parse_url($base);
        if (!$p || empty($p['host'])) {
            return $u;
        }
        $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        return $origin . '/' . ltrim($u, '/');
    }

    protected static function ts($v) {
        if ($v === null || $v === '' || $v === 0 || $v === '0') {
            return 0;
        }
        if (is_numeric($v)) {
            $v = (int) $v;
            return $v > 100000000000 ? (int) floor($v / 1000) : $v;
        }
        $t = strtotime((string) $v);
        return $t ? $t : 0;
    }

    protected static function iso($ts) {
        return $ts > 0 ? gmdate('Y-m-d\TH:i:s\Z', (int) $ts) : null;
    }
}

final class CN_Drivers {
    private static $types = array(
        'marzban'     => 'CN_Driver_Marzban',
        'pasarguard'  => 'CN_Driver_Pasarguard',
        'marzneshin'  => 'CN_Driver_Marzneshin',
        'rebecca'     => 'CN_Driver_Rebecca',
        'xui'         => 'CN_Driver_Xui',
        'alireza'     => 'CN_Driver_Alireza',
        'hiddify'     => 'CN_Driver_Hiddify',
        'sui'         => 'CN_Driver_Sui',
        'wgdashboard' => 'CN_Driver_Wgdashboard',
        'ibsng'       => 'CN_Driver_Ibsng',
        'mikrotik'    => 'CN_Driver_Mikrotik',
        'mirza_agent' => 'CN_Driver_Mirza_Agent',
        'eylan'       => 'CN_Driver_Eylan',
        'connectix'   => 'CN_Driver_Connectix',
        'manual'      => 'CN_Driver_Manual',
    );

    public static function load() {
        foreach (glob(__DIR__ . '/class-cn-driver-*.php') as $f) {
            require_once $f;
        }
    }

    public static function types() {
        $out = array();
        foreach (self::$types as $type => $class) {
            if (class_exists($class)) {
                $out[$type] = $class;
            }
        }
        return $out;
    }

    public static function cls($type) {
        $t = self::types();
        return isset($t[$type]) ? $t[$type] : null;
    }

    /** @return CN_Driver|null */
    public static function make(array $panel) {
        $c = self::cls($panel['type']);
        return $c ? new $c($panel) : null;
    }

    /** Common connection fields shared by all URL-based panels. */
    public static function common_fields() {
        return array(
            'verify_ssl'  => array('label' => 'بررسی گواهی SSL پنل', 'type' => 'checkbox', 'help' => 'اگر پنل گواهی معتبر دارد روشن کنید.', 'advanced' => true),
            'use_polban'  => array('label' => 'از طریق پل‌بان', 'type' => 'checkbox', 'help' => 'درخواست‌های این پنل از طریق سرویس پل‌بان ارسال شوند (نیاز به تنظیم URL و کلید API در تنظیمات کلی).'),
        );
    }
}

CN_Drivers::load();
