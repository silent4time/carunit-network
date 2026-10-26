<?php
if (!defined('ABSPATH')) {
    exit;
}

class CN_Driver_Marzneshin extends CN_Driver_Marzban {

    public static function label() {
        return 'مرزنشین';
    }

    public static function fields() {
        return array(
            'username' => array('label' => 'نام کاربری ادمین', 'type' => 'text', 'required' => true),
            'password' => array('label' => 'رمز ادمین', 'type' => 'password', 'secret' => true, 'required' => true),
            'services' => array('label' => 'شناسهٔ سرویس‌ها service_ids (JSON)', 'type' => 'textarea', 'help' => 'مثل [1,2]', 'required' => true),
            'on_hold'  => array('label' => 'شروع زمان از اولین اتصال', 'type' => 'checkbox'),
        ) + CN_Drivers::common_fields();
    }

    public static function plan_fields() {
        return array(
            'services' => array('label' => 'شناسهٔ سرویس‌های این پلن (JSON، اختیاری)', 'type' => 'textarea', 'help' => 'خالی = تنظیم پنل'),
        );
    }

    protected function token($force = false) {
        if (!$force) {
            $t = $this->cache_get('token');
            if ($t) {
                return $t;
            }
        }
        $r = $this->http('POST', '/api/admins/token', array(
            'form' => array('username' => $this->s('username'), 'password' => $this->secret('password')),
        ));
        $this->last_login = $r;
        if (!$r['ok'] || empty($r['json']['access_token'])) {
            return null;
        }
        $this->cache_set('token', $r['json']['access_token'], 50 * MINUTE_IN_SECONDS);
        return $r['json']['access_token'];
    }

    public function test() {
        if (!$this->token(true)) {
            return $this->fail('ورود به پنل ناموفق بود؛ آدرس، نام کاربری و رمز را بررسی کنید.');
        }
        $r = $this->api('GET', '/api/system/stats/users');
        return $r['ok'] ? $this->ok(array('msg' => 'اتصال برقرار است.')) : $this->fail('پاسخ پنل نامعتبر بود', $r);
    }

    protected function expire_fields($expire, $create) {
        if ($expire <= 0) {
            return array('expire_strategy' => 'never', 'expire_date' => null);
        }
        if ($create && $this->s('on_hold')) {
            return array('expire_strategy' => 'start_on_first_use', 'expire_date' => null, 'usage_duration' => max(DAY_IN_SECONDS, $expire - time()));
        }
        return array('expire_strategy' => 'fixed_date', 'expire_date' => date('c', $expire));
    }

    public function create(array $a) {
        $services = $this->json_setting($this->opt($a, 'services', $this->s('services')));
        $expire = (int) $a['expire'];
        $data = array(
            'username'                  => $a['username'],
            'service_ids'               => $services ? array_values($services) : array(),
            'data_limit'                => (int) $a['bytes'],
            'note'                      => isset($a['note']) ? $a['note'] : '',
            'data_limit_reset_strategy' => 'no_reset',
        ) + $this->expire_fields($expire, true);

        $r = $this->api('POST', '/api/users', array('json' => $data));
        if (!$r['ok'] || empty($r['json']['username'])) {
            return $this->fail('ساخت کاربر در پنل ناموفق بود', $r);
        }
        $u = $r['json'];
        $hold = $expire > 0 && $this->s('on_hold');
        return $this->ok(array(
            'status'   => $hold ? 'on_hold' : 'active',
            'username' => $u['username'],
            'sub_url'  => $this->abs_url(isset($u['subscription_url']) ? $u['subscription_url'] : ''),
            'configs'  => array(),
            'data'     => array(),
            'limit'    => (int) $a['bytes'],
            'expire'   => $hold ? 0 : $expire,
        ));
    }

    protected function get_user($username) {
        return $this->api('GET', '/api/users/' . rawurlencode($username));
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
        $used  = (int) (isset($u['used_traffic']) ? $u['used_traffic'] : 0);
        $limit = (int) (isset($u['data_limit']) ? $u['data_limit'] : 0);
        if (empty($u['enabled'])) {
            $status = 'disabled';
        } elseif (!empty($u['expired'])) {
            $status = 'expired';
        } elseif ($limit > 0 && $used >= $limit) {
            $status = 'limited';
        } elseif (isset($u['expire_strategy']) && $u['expire_strategy'] === 'start_on_first_use') {
            $status = 'on_hold';
        } else {
            $status = 'active';
        }
        return $this->ok(array(
            'status'  => $status,
            'used'    => $used,
            'limit'   => $limit,
            'expire'  => self::ts(isset($u['expire_date']) ? $u['expire_date'] : 0),
            'online'  => isset($u['online_at']) ? $u['online_at'] : null,
            'sub_url' => $this->abs_url(isset($u['subscription_url']) ? $u['subscription_url'] : ''),
            'configs' => array(),
            'raw'     => $u,
        ));
    }

    protected function set_limits(array $svc, $limit, $expire, array $info) {
        $data = array('username' => $svc['username'], 'data_limit' => (int) $limit) + $this->expire_fields($expire, false);
        $r = $this->api('PUT', '/api/users/' . rawurlencode($svc['username']), array('json' => $data));
        if (!$r['ok']) {
            return $this->fail('به‌روزرسانی کاربر ناموفق بود', $r);
        }
        if (isset($info['status']) && $info['status'] === 'disabled') {
            $this->set_enabled($svc, true);
        }
        return $this->ok();
    }

    public function reset(array $svc) {
        $r = $this->api('POST', '/api/users/' . rawurlencode($svc['username']) . '/reset');
        return $r['ok'] ? $this->ok() : $this->fail('صفر کردن مصرف ناموفق بود', $r);
    }

    public function set_enabled(array $svc, $enabled) {
        $r = $this->api('POST', '/api/users/' . rawurlencode($svc['username']) . ($enabled ? '/enable' : '/disable'));
        return $r['ok'] ? $this->ok() : $this->fail('تغییر وضعیت ناموفق بود', $r);
    }

    public function delete(array $svc) {
        $r = $this->api('DELETE', '/api/users/' . rawurlencode($svc['username']));
        return ($r['ok'] || $r['code'] === 404) ? $this->ok() : $this->fail('حذف کاربر ناموفق بود', $r);
    }

    public function revoke(array $svc) {
        $r = $this->api('POST', '/api/users/' . rawurlencode($svc['username']) . '/revoke_sub');
        if (!$r['ok']) {
            return $this->fail('تغییر لینک ناموفق بود', $r);
        }
        $u = is_array($r['json']) ? $r['json'] : array();
        return $this->ok(array('sub_url' => $this->abs_url(isset($u['subscription_url']) ? $u['subscription_url'] : '')));
    }
}

class CN_Driver_Rebecca extends CN_Driver_Marzban {

    public static function label() {
        return 'ربکا';
    }

    public static function fields() {
        return array(
            'token'    => array('label' => 'توکن API', 'type' => 'password', 'secret' => true, 'required' => true),
            'services' => array('label' => 'شناسهٔ سرویس service_id', 'type' => 'text', 'required' => true),
            'ip_limit' => array('label' => 'محدودیت IP', 'type' => 'number', 'advanced' => true),
            'on_hold'  => array('label' => 'شروع زمان از اولین اتصال', 'type' => 'checkbox'),
        ) + CN_Drivers::common_fields();
    }

    public static function plan_fields() {
        return array(
            'services' => array('label' => 'شناسهٔ سرویس این پلن (اختیاری)', 'type' => 'text', 'help' => 'خالی = تنظیم پنل'),
        );
    }

    protected function token($force = false) {
        $t = $this->secret('token');
        return $t !== '' ? $t : null;
    }

    public function test() {
        $r = $this->api('GET', '/api/system');
        return $r['ok'] ? $this->ok(array('msg' => 'اتصال برقرار است.')) : $this->fail('اتصال ناموفق بود', $r);
    }

    public function create(array $a) {
        $sid = $this->opt($a, 'services', $this->s('services'));
        $j = json_decode((string) $sid, true);
        $sid = is_array($j) ? (int) reset($j) : (int) $sid;
        $expire = (int) $a['expire'];
        $data = array(
            'username'                  => $a['username'],
            'service_id'                => $sid,
            'data_limit'                => (int) $a['bytes'],
            'note'                      => isset($a['note']) ? $a['note'] : '',
            'data_limit_reset_strategy' => 'no_reset',
        );
        if ((int) $this->s('ip_limit') > 0) {
            $data['ip_limit'] = (int) $this->s('ip_limit');
        }
        if ($expire > 0 && $this->s('on_hold')) {
            $data['expire'] = null;
            $data['status'] = 'on_hold';
            $data['on_hold_expire_duration'] = max(DAY_IN_SECONDS, $expire - time());
        } else {
            $data['expire'] = $expire > 0 ? $expire : null;
            $data['status'] = 'active';
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
            'limit'    => (int) $a['bytes'],
            'expire'   => $expire,
        ));
    }

    protected function set_limits(array $svc, $limit, $expire, array $info) {
        $r = $this->put_user($svc, array(
            'data_limit' => (int) $limit,
            'expire'     => $expire > 0 ? (int) $expire : null,
            'status'     => 'active',
        ));
        return $r['ok'] ? $this->ok() : $this->fail('به‌روزرسانی کاربر ناموفق بود', $r);
    }
}
