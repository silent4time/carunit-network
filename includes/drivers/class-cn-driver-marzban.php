<?php
if (!defined('ABSPATH')) {
    exit;
}

class CN_Driver_Marzban extends CN_Driver {
    protected $last_login = null;

    /** New API (PasarGuard / Marzban with groups): proxy_settings + group_ids + ISO dates. */
    protected function v1() {
        return false;
    }

    public static function label() {
        return 'مرزبان';
    }

    public static function url_help() {
        return 'همان آدرس ورود به پنل، مثل https://panel.example.com:8000/dashboard';
    }

    protected static function ui_path() {
        return '/dashboard';
    }

    public static function fields() {
        return array(
            'username' => array('label' => 'نام کاربری ادمین', 'type' => 'text', 'required' => true),
            'password' => array('label' => 'رمز ادمین', 'type' => 'password', 'secret' => true, 'required' => true),
            'template' => array('label' => 'کاربر نمونه', 'type' => 'text', 'help' => 'نام کاربری یک کاربر موجود در پنل؛ پروتکل‌ها و اینباندها (یا گروه‌ها) از روی همین کاربر برداشته می‌شود. خالی = تنظیمات بیشتر.'),
            'proxies'  => array('label' => 'پروتکل‌ها (JSON)', 'type' => 'textarea', 'default' => '{"vless":{},"vmess":{}}', 'advanced' => true, 'help' => 'مثل {"vless":{"flow":""},"vmess":{}}'),
            'inbounds' => array('label' => 'اینباندها (JSON)', 'type' => 'textarea', 'advanced' => true, 'help' => 'مثل {"vless":["VLESS TCP REALITY"]}؛ خالی = همه'),
            'on_hold'  => array('label' => 'شروع زمان از اولین اتصال', 'type' => 'checkbox'),
        ) + CN_Drivers::common_fields();
    }

    public static function plan_fields() {
        return array(
            'inbounds' => array('label' => 'اینباندهای این پلن (JSON، اختیاری)', 'type' => 'textarea', 'help' => 'خالی = تنظیم پنل'),
        );
    }

    public static function caps() {
        return array('renew', 'reset', 'toggle', 'delete', 'usage', 'revoke');
    }

    protected function token($force = false) {
        if (!$force) {
            $t = $this->cache_get('token');
            if ($t) {
                return $t;
            }
        }
        $r = $this->http('POST', '/api/admin/token', array(
            'form' => array('username' => $this->s('username'), 'password' => $this->secret('password')),
        ));
        $this->last_login = $r;
        if (!$r['ok'] || empty($r['json']['access_token'])) {
            return null;
        }
        $this->cache_set('token', $r['json']['access_token'], 50 * MINUTE_IN_SECONDS);
        return $r['json']['access_token'];
    }

    protected function api($method, $path, array $o = array()) {
        $t = $this->token();
        if (!$t) {
            return array('ok' => false, 'code' => 401, 'error' => 'ورود به پنل ناموفق بود', 'json' => null, 'body' => '');
        }
        $o['bearer'] = $t;
        $r = $this->http($method, $path, $o);
        if ($r['code'] === 401) {
            $t = $this->token(true);
            if ($t) {
                $o['bearer'] = $t;
                $r = $this->http($method, $path, $o);
            }
        }
        return $r;
    }

    public function test() {
        if (!$this->token(true)) {
            return $this->fail('ورود به پنل ناموفق بود؛ آدرس، نام کاربری و رمز را بررسی کنید', $this->last_login);
        }
        // Token means the panel is reachable and credentials work. /api/admin and
        // /api/system are optional — agent/reseller admins often cannot call them.
        $r = $this->api('GET', '/api/admin');
        if (!$r['ok']) {
            $this->api('GET', '/api/system');
        }
        $out = $this->ok(array('msg' => 'اتصال برقرار است.'));
        if ($this->s('template') !== '') {
            $tpl = $this->template(true);
            if (empty($tpl['ok'])) {
                return $tpl;
            }
            $out['msg'] .= ' کاربر نمونه «' . $tpl['name'] . '» خوانده شد'
                . ($tpl['inbounds'] ? ' (' . ($this->v1() ? 'گروه‌ها: ' . CN_Util::fa_digits(implode('، ', $tpl['inbounds'])) : 'اینباندها: ' . implode('، ', array_keys($tpl['inbounds']))) . ')' : '') . '.';
        }
        return $out;
    }

    /** Protocol settings and inbounds/groups copied from the panel's template user (ids and passwords stripped). */
    protected function template($fresh = false) {
        $name = trim((string) $this->s('template'));
        if (!$fresh) {
            $c = $this->cache_get('tpl');
            if (is_array($c) && isset($c['name']) && $c['name'] === $name) {
                return $c;
            }
        }
        $r = $this->get_user($name);
        $key = $this->v1() ? 'proxy_settings' : 'proxies';
        if (!$r['ok'] || !is_array($r['json']) || !isset($r['json'][$key])) {
            return $this->fail('کاربر نمونه «' . $name . '» در پنل پیدا نشد', $r);
        }
        $u = $r['json'];
        $proxies = array();
        foreach ((array) $u[$key] as $proto => $set) {
            $set = (array) $set;
            unset($set['id'], $set['password']);
            $proxies[$proto] = $set;
        }
        $in = $this->v1() ? (isset($u['group_ids']) ? array_values((array) $u['group_ids']) : array()) : (isset($u['inbounds']) ? (array) $u['inbounds'] : array());
        $tpl = $this->ok(array('name' => $name, 'proxies' => $proxies, 'inbounds' => $in));
        $this->cache_set('tpl', $tpl, HOUR_IN_SECONDS);
        return $tpl;
    }

    /** {"vless":[]} must go out as {"vless":{}}. */
    protected static function proxy_map($p) {
        $out = new stdClass();
        foreach ((array) $p as $k => $v) {
            $out->$k = $v ? $v : new stdClass();
        }
        return $out;
    }

    protected function json_setting($raw) {
        $raw = trim((string) $raw);
        if ($raw === '' || $raw === 'null') {
            return null;
        }
        $j = json_decode($raw, true);
        return is_array($j) ? $j : null;
    }

    protected function groups() {
        return array();
    }

    public function create(array $a) {
        $tpl = $this->s('template') !== '' ? $this->template() : null;
        if ($tpl && empty($tpl['ok'])) {
            return $tpl;
        }
        $proxies  = $tpl ? $tpl['proxies'] : $this->json_setting($this->s('proxies', $this->v1() ? '' : '{"vless":{},"vmess":{}}'));
        $inbounds = $this->json_setting($this->opt($a, 'inbounds', ''));
        if (!$inbounds) {
            $inbounds = $tpl ? $tpl['inbounds'] : $this->json_setting($this->s('inbounds'));
        }
        if (!$inbounds && $this->v1()) {
            $inbounds = array_keys((array) $this->groups());
        }
        $expire   = (int) $a['expire'];
        $limit    = (int) $a['bytes'];

        $data = array(
            'username'                  => $a['username'],
            'data_limit'                => $limit,
            'note'                      => isset($a['note']) ? $a['note'] : '',
            'data_limit_reset_strategy' => 'no_reset',
        );
        if ($this->v1()) {
            $data['proxy_settings'] = self::proxy_map($proxies);
            if ($inbounds) {
                $data['group_ids'] = array_values($inbounds);
            }
        } else {
            $data['proxies'] = self::proxy_map($proxies);
            if ($inbounds) {
                $data['inbounds'] = $inbounds;
            }
        }
        if ($expire > 0 && $this->s('on_hold')) {
            $data['expire'] = 0;
            $data['status'] = 'on_hold';
            $data['on_hold_expire_duration'] = max(DAY_IN_SECONDS, $expire - time());
        } else {
            $data['expire'] = $expire > 0 ? ($this->v1() ? date('c', $expire) : $expire) : 0;
        }

        $r = $this->api('POST', '/api/user', array('json' => $data));
        if (!$r['ok'] || empty($r['json']['username'])) {
            return $this->fail('ساخت کاربر در پنل ناموفق بود', $r);
        }
        $u = $r['json'];
        return $this->ok(array(
            'username' => $u['username'],
            'sub_url'  => $this->abs_url(isset($u['subscription_url']) ? $u['subscription_url'] : ''),
            'configs'  => $this->links($u),
            'data'     => array(),
            'limit'    => $limit,
            'expire'   => $expire,
        ));
    }

    protected function links(array $u) {
        $out = array();
        foreach ((array) (isset($u['links']) ? $u['links'] : array()) as $i => $l) {
            $out[] = array('کانفیگ ' . ($i + 1), $l, 'link');
        }
        return $out;
    }

    protected function get_user($username) {
        return $this->api('GET', '/api/user/' . rawurlencode($username));
    }

    public function info(array $svc) {
        $r = $this->get_user($svc['username']);
        if ($r['code'] === 404) {
            return $this->ok(array('status' => 'deleted', 'used' => 0, 'limit' => 0, 'expire' => 0, 'online' => null, 'sub_url' => '', 'configs' => array()));
        }
        if (!$r['ok'] || !is_array($r['json'])) {
            return $this->fail('دریافت اطلاعات کاربر ناموفق بود', $r);
        }
        $u = $r['json'];
        return $this->ok(array(
            'status'  => isset($u['status']) ? $u['status'] : 'active',
            'used'    => (int) (isset($u['used_traffic']) ? $u['used_traffic'] : 0),
            'limit'   => (int) (isset($u['data_limit']) ? $u['data_limit'] : 0),
            'expire'  => self::ts(isset($u['expire']) ? $u['expire'] : 0),
            'online'  => isset($u['online_at']) ? $u['online_at'] : null,
            'sub_url' => $this->abs_url(isset($u['subscription_url']) ? $u['subscription_url'] : ''),
            'configs' => $this->links($u),
            'raw'     => $u,
        ));
    }

    protected function put_user(array $svc, array $data, array $raw = null) {
        if ($this->v1()) {
            if ($raw === null) {
                $g = $this->get_user($svc['username']);
                $raw = is_array($g['json']) ? $g['json'] : array();
            }
            if (isset($raw['proxy_settings'])) {
                $data['proxy_settings'] = $raw['proxy_settings'];
            }
        }
        return $this->api('PUT', '/api/user/' . rawurlencode($svc['username']), array('json' => $data));
    }

    protected function set_limits(array $svc, $limit, $expire, array $info) {
        $data = array(
            'data_limit' => (int) $limit,
            'expire'     => $expire > 0 ? ($this->v1() ? date('c', $expire) : (int) $expire) : 0,
            'status'     => 'active',
        );
        $r = $this->put_user($svc, $data, isset($info['raw']) ? $info['raw'] : null);
        return $r['ok'] ? $this->ok() : $this->fail('به‌روزرسانی کاربر ناموفق بود', $r);
    }

    public function reset(array $svc) {
        $r = $this->api('POST', '/api/user/' . rawurlencode($svc['username']) . '/reset');
        return $r['ok'] ? $this->ok() : $this->fail('صفر کردن مصرف ناموفق بود', $r);
    }

    public function set_enabled(array $svc, $enabled) {
        $r = $this->put_user($svc, array('status' => $enabled ? 'active' : 'disabled'));
        return $r['ok'] ? $this->ok() : $this->fail('تغییر وضعیت ناموفق بود', $r);
    }

    public function delete(array $svc) {
        $r = $this->api('DELETE', '/api/user/' . rawurlencode($svc['username']));
        return ($r['ok'] || $r['code'] === 404) ? $this->ok() : $this->fail('حذف کاربر ناموفق بود', $r);
    }

    public function revoke(array $svc) {
        $r = $this->api('POST', '/api/user/' . rawurlencode($svc['username']) . '/revoke_sub');
        if (!$r['ok']) {
            return $this->fail('تغییر لینک ناموفق بود', $r);
        }
        $u = is_array($r['json']) ? $r['json'] : array();
        return $this->ok(array('sub_url' => $this->abs_url(isset($u['subscription_url']) ? $u['subscription_url'] : '')));
    }
}
