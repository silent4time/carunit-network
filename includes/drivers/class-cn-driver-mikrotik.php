<?php
if (!defined('ABSPATH')) {
    exit;
}

/** MikroTik RouterOS v7 User Manager over the REST API. */
class CN_Driver_Mikrotik extends CN_Driver {

    public static function label() {
        return 'میکروتیک (User Manager)';
    }

    public static function fields() {
        return array(
            'username'    => array('label' => 'نام کاربری روتر', 'type' => 'text', 'required' => true),
            'password'    => array('label' => 'رمز روتر', 'type' => 'password', 'secret' => true, 'required' => true),
            'profile'     => array('label' => 'نام پروفایل پیش‌فرض', 'type' => 'text', 'required' => true, 'help' => 'حجم و زمان از پروفایل User Manager می‌آید'),
            'server_info' => array('label' => 'اطلاعات اتصال برای مشتری', 'type' => 'textarea', 'help' => 'مثل آدرس سرور و نوع اتصال'),
        ) + CN_Drivers::common_fields();
    }

    public static function plan_fields() {
        return array(
            'profile' => array('label' => 'نام پروفایل این پلن', 'type' => 'text', 'help' => 'خالی = پروفایل پیش‌فرض پنل'),
        );
    }

    public static function remote_limits() {
        return true;
    }

    public static function caps() {
        return array('renew', 'toggle', 'delete', 'usage');
    }

    public static function url_help() {
        return 'آدرس REST روتر، مثل https://1.2.3.4 (سرویس www-ssl فعال باشد)';
    }

    protected function api($method, $path, array $o = array()) {
        $o['auth'] = array($this->s('username'), $this->secret('password'));
        return $this->http($method, $path, $o);
    }

    public function test() {
        $r = $this->api('GET', '/rest/system/resource');
        return $r['ok'] ? $this->ok(array('msg' => 'اتصال برقرار است.')) : $this->fail('اتصال ناموفق بود', $r);
    }

    protected function profile(array $a) {
        return (string) $this->opt($a, 'profile', $this->s('profile'));
    }

    public function create(array $a) {
        $pass = CN_Util::random(10, 'abcdefghjkmnpqrstuvwxyz23456789');
        $r = $this->api('POST', '/rest/user-manager/user/add', array('json' => array('name' => $a['username'], 'password' => $pass)));
        if (!$r['ok']) {
            return $this->fail('ساخت کاربر در روتر ناموفق بود', $r);
        }
        $r = $this->api('POST', '/rest/user-manager/user-profile/add', array('json' => array('user' => $a['username'], 'profile' => $this->profile($a))));
        if (!$r['ok']) {
            return $this->fail('کاربر ساخته شد ولی اتصال پروفایل ناموفق بود', $r);
        }
        $configs = array(array('نام کاربری', $a['username'], 'text'), array('رمز عبور', $pass, 'text'));
        $info = trim((string) $this->s('server_info'));
        if ($info !== '') {
            $configs[] = array('اطلاعات اتصال', $info, 'note');
        }
        return $this->ok(array(
            'username' => $a['username'],
            'sub_url'  => '',
            'configs'  => $configs,
            'data'     => array(),
            'limit'    => (int) $a['bytes'],
            'expire'   => (int) $a['expire'],
        ));
    }

    protected function user($name) {
        $r = $this->api('GET', '/rest/user-manager/user?name=' . rawurlencode($name));
        if (!$r['ok'] || !is_array($r['json'])) {
            return null;
        }
        return isset($r['json'][0]) ? $r['json'][0] : array();
    }

    public function info(array $svc) {
        $u = $this->user($svc['username']);
        if ($u === null) {
            return $this->fail('دریافت اطلاعات کاربر ناموفق بود.');
        }
        if (!$u) {
            return $this->ok(array('status' => 'deleted', 'used' => 0, 'limit' => 0, 'expire' => 0, 'online' => null, 'sub_url' => '', 'configs' => array()));
        }
        $used = 0;
        $m = $this->api('POST', '/rest/user-manager/user/monitor', array('json' => array('once' => true, '.id' => $u['.id'])));
        if ($m['ok'] && isset($m['json'][0])) {
            $used = (int) (isset($m['json'][0]['total-download']) ? $m['json'][0]['total-download'] : 0)
                + (int) (isset($m['json'][0]['total-upload']) ? $m['json'][0]['total-upload'] : 0);
        }
        $disabled = isset($u['disabled']) && ($u['disabled'] === true || $u['disabled'] === 'true');
        return $this->ok(array(
            'status'  => $disabled ? 'disabled' : 'active',
            'used'    => $used,
            'limit'   => (int) (isset($svc['limit_bytes']) ? $svc['limit_bytes'] : 0),
            'expire'  => CN_Util::db_to_ts(isset($svc['expires_at']) ? $svc['expires_at'] : ''),
            'online'  => null,
            'sub_url' => '',
            'configs' => array(),
            'raw'     => $u,
        ));
    }

    /** Assigning the profile again queues another period in User Manager. */
    public function renew(array $svc, array $a) {
        $r = $this->api('POST', '/rest/user-manager/user-profile/add', array('json' => array('user' => $svc['username'], 'profile' => $this->profile($a))));
        if (!$r['ok']) {
            return $this->fail('تمدید در روتر ناموفق بود', $r);
        }
        $this->set_enabled($svc, true);
        $days = (int) $a['days'];
        $base = max(time(), CN_Util::db_to_ts(isset($svc['expires_at']) ? $svc['expires_at'] : ''));
        return $this->ok(array(
            'limit'  => CN_Util::gb_to_bytes($a['gb']),
            'expire' => $days > 0 ? $base + $days * DAY_IN_SECONDS : 0,
        ));
    }

    public function set_enabled(array $svc, $enabled) {
        $u = $this->user($svc['username']);
        if (!$u) {
            return $this->fail('کاربر در روتر پیدا نشد.');
        }
        $r = $this->api('POST', '/rest/user-manager/user/set', array('json' => array('.id' => $u['.id'], 'disabled' => $enabled ? 'false' : 'true')));
        return $r['ok'] ? $this->ok() : $this->fail('تغییر وضعیت ناموفق بود', $r);
    }

    public function delete(array $svc) {
        $u = $this->user($svc['username']);
        if ($u === array()) {
            return $this->ok();
        }
        if (!$u) {
            return $this->fail('دریافت اطلاعات کاربر ناموفق بود.');
        }
        $r = $this->api('POST', '/rest/user-manager/user/remove', array('json' => array('.id' => $u['.id'])));
        return $r['ok'] ? $this->ok() : $this->fail('حذف کاربر ناموفق بود', $r);
    }
}
