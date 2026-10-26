<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * PasarGuard driver — completely independent from Marzban.
 */
class CN_Driver_Pasarguard extends CN_Driver {
    protected $last_login = null;

    public static function label() {
        return 'پاسارگارد';
    }

    public static function url_help() {
        return 'همان آدرس ورود به پنل پاسارگارد، مثل https://panel.example.com:8000/dashboard';
    }

    protected static function ui_path() {
        return '/dashboard';
    }

    public static function fields() {
        return array(
            'username' => array('label' => 'نام کاربری ادمین', 'type' => 'text', 'required' => true),
            'password' => array('label' => 'رمز ادمین', 'type' => 'password', 'secret' => true, 'required' => true),
            'template' => array('label' => 'کاربر نمونه', 'type' => 'text', 'help' => 'نام کاربری یک کاربر موجود در پنل؛ گروه‌ها و تنظیمات پروتکل از روی همین کاربر برداشته می‌شود. خالی = همهٔ گروه‌های فعال.'),
            'inbounds' => array('label' => 'شناسهٔ گروه‌ها group_ids (JSON)', 'type' => 'textarea', 'advanced' => true, 'help' => 'مثل [1,2]؛ خالی = همهٔ گروه‌های فعال.'),
            'on_hold'  => array('label' => 'شروع زمان از اولین اتصال', 'type' => 'checkbox'),
        ) + CN_Drivers::common_fields();
    }

    public static function plan_fields() {
        return array(
            'inbounds' => array('label' => 'شناسهٔ گروه‌های این پلن (JSON، اختیاری)', 'type' => 'textarea', 'help' => 'مثل [1]؛ خالی = تنظیم پنل'),
        );
    }

    public static function caps() {
        return array('renew', 'reset', 'toggle', 'delete', 'usage', 'revoke');
    }

    protected function token($force = false) {
        if (!$force) {
            $t = $this->cache_get('token');
            if ($t) return $t;
        }
        $r = $this->http('POST', '/api/admin/token', array(
            'form' => array(
                'username' => $this->s('username'),
                'password' => $this->secret('password'),
                'grant_type' => 'password',
            ),
        ));
        $this->last_login = $r;
        if (!$r['ok'] || empty($r['json']['access_token'])) return null;
        $this->cache_set('token', $r['json']['access_token'], 50 * MINUTE_IN_SECONDS);
        return $r['json']['access_token'];
    }

    protected function api($method, $path, array $o = array()) {
        $t = $this->token();
        if (!$t) return array('ok' => false, 'code' => 401, 'error' => 'ورود به پنل ناموفق بود', 'json' => null, 'body' => '');
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
        $out = $this->ok(array('msg' => 'اتصال برقرار است.'));
        $groups = $this->groups();
        if ($groups === null) {
            $out['msg'] .= ' این ادمین اجازهٔ دیدن گروه‌ها را ندارد.';
            return $out;
        }
        $names = array();
        foreach ($groups as $id => $name) {
            $names[] = CN_Util::fa_digits($id) . ' = ' . $name;
        }
        $out['msg'] .= $names ? ' گروه‌های فعال: ' . implode('، ', $names) : ' هیچ گروه فعالی در پنل نیست.';
        return $out;
    }

    protected function groups() {
        $r = $this->api('GET', '/api/groups');
        if (!$r['ok']) $r = $this->api('GET', '/api/groups/simple');
        if (!$r['ok']) return null;
        $out = array();
        $list = array();
        if (is_array($r['json'])) {
            if (isset($r['json']['groups']) && is_array($r['json']['groups'])) {
                $list = $r['json']['groups'];
            } elseif (self::is_list($r['json'])) {
                $list = $r['json'];
            }
        }
        foreach ($list as $g) {
            if (!is_array($g) || !isset($g['id']) || !empty($g['is_disabled'])) continue;
            $out[(int) $g['id']] = isset($g['name']) ? (string) $g['name'] : '';
        }
        return $out;
    }

    private static function is_list(array $a) {
        if ($a === array()) return true;
        return array_keys($a) === range(0, count($a) - 1);
    }

    public function create(array $a) {
        $inbounds = $this->json_setting($this->opt($a, 'inbounds', ''));
        if (!$inbounds) $inbounds = $this->json_setting($this->s('inbounds'));
        if (!$inbounds) {
            $g = $this->groups();
            $inbounds = $g === null ? null : array_keys($g);
        }
        $expire = (int) $a['expire'];
        $limit  = (int) $a['bytes'];

        $data = array(
            'username' => $a['username'],
            'data_limit' => $limit,
            'note' => isset($a['note']) ? $a['note'] : '',
            'data_limit_reset_strategy' => 'no_reset',
        );
        if ($inbounds) $data['group_ids'] = array_values($inbounds);
        if ($expire > 0 && $this->s('on_hold')) {
            $data['expire'] = 0;
            $data['status'] = 'on_hold';
            $data['on_hold_expire_duration'] = max(DAY_IN_SECONDS, $expire - time());
        } else {
            $data['expire'] = $expire > 0 ? date('c', $expire) : 0;
        }

        $r = $this->api('POST', '/api/user', array('json' => $data));
        if (!$r['ok'] || empty($r['json']['username'])) return $this->fail('ساخت کاربر در پنل ناموفق بود', $r);
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
        if ($r['code'] === 404) return $this->ok(array('status' => 'deleted', 'used' => 0, 'limit' => 0, 'expire' => 0, 'online' => null, 'sub_url' => '', 'configs' => array()));
        if (!$r['ok'] || !is_array($r['json'])) return $this->fail('دریافت اطلاعات کاربر ناموفق بود', $r);
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

    protected function put_user(array $svc, array $data) {
        return $this->api('PUT', '/api/user/' . rawurlencode($svc['username']), array('json' => $data));
    }

    protected function set_limits(array $svc, $limit, $expire, array $info) {
        $data = array(
            'data_limit' => (int) $limit,
            'expire'     => $expire > 0 ? date('c', $expire) : 0,
            'status'     => 'active',
        );
        $r = $this->put_user($svc, $data);
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
        if (!$r['ok']) return $this->fail('تغییر لینک ناموفق بود', $r);
        $u = is_array($r['json']) ? $r['json'] : array();
        return $this->ok(array('sub_url' => $this->abs_url(isset($u['subscription_url']) ? $u['subscription_url'] : '')));
    }

    protected function json_setting($raw) {
        $raw = trim((string) $raw);
        if ($raw === '' || $raw === 'null') return null;
        $j = json_decode($raw, true);
        return is_array($j) ? $j : null;
    }
}

