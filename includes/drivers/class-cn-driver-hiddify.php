<?php
if (!defined('ABSPATH')) {
    exit;
}

class CN_Driver_Hiddify extends CN_Driver {
    const UNLIMITED_DAYS = 111111;
    const UNLIMITED_GB = 100000;

    public static function label() {
        return 'هیدیفای';
    }

    public static function fields() {
        return array(
            'api_key'  => array('label' => 'کلید API ادمین (UUID ادمین)', 'type' => 'password', 'secret' => true, 'required' => true),
            'sub_base' => array('label' => 'آدرس پایهٔ لینک کاربر', 'type' => 'url', 'required' => true, 'help' => 'مثل https://example.com/client-path — UUID کاربر به انتهایش اضافه می‌شود'),
        ) + CN_Drivers::common_fields();
    }

    public static function url_help() {
        return 'آدرس پنل را همراه مسیر ادمین وارد کنید، مثل https://example.com/admin-proxy-path';
    }

    protected function api($method, $path, array $o = array()) {
        $o['headers'] = array('Hiddify-API-Key' => $this->secret('api_key'));
        return $this->http($method, $path, $o);
    }

    public function test() {
        $r = $this->api('GET', '/api/v2/admin/server_status/');
        if (!$r['ok']) {
            $r = $this->http('GET', '/api/v2/admin/server_status/', array('auth' => array($this->secret('api_key'), '')));
        }
        return $r['ok'] ? $this->ok(array('msg' => 'اتصال برقرار است.')) : $this->fail('اتصال ناموفق بود', $r);
    }

    protected function sub_url($uuid) {
        return rtrim((string) $this->s('sub_base'), '/') . '/' . $uuid . '/';
    }

    protected static function days_for($expire) {
        return $expire > 0 ? max(1, (int) ceil(($expire - time()) / DAY_IN_SECONDS)) : self::UNLIMITED_DAYS;
    }

    public function create(array $a) {
        $uuid = CN_Util::uuid();
        $gb = (int) $a['bytes'] > 0 ? round((int) $a['bytes'] / CN_Util::GB, 3) : self::UNLIMITED_GB;
        $r = $this->api('POST', '/api/v2/admin/user/', array('json' => array(
            'uuid'             => $uuid,
            'name'             => $a['username'],
            'added_by_uuid'    => $this->secret('api_key'),
            'current_usage_GB' => 0,
            'usage_limit_GB'   => $gb,
            'package_days'     => self::days_for((int) $a['expire']),
            'comment'          => isset($a['note']) ? $a['note'] : '',
        )));
        if (!$r['ok'] || (is_array($r['json']) && !empty($r['json']['message']) && empty($r['json']['uuid']))) {
            return $this->fail('ساخت کاربر در پنل ناموفق بود', $r);
        }
        $uuid = isset($r['json']['uuid']) ? $r['json']['uuid'] : $uuid;
        return $this->ok(array(
            'username' => $a['username'],
            'sub_url'  => $this->sub_url($uuid),
            'configs'  => array(),
            'data'     => array('uuid' => $uuid),
            'limit'    => (int) $a['bytes'],
            'expire'   => (int) $a['expire'],
        ));
    }

    protected function uuid(array $svc) {
        return isset($svc['data']['uuid']) ? $svc['data']['uuid'] : '';
    }

    public function info(array $svc) {
        $uuid = $this->uuid($svc);
        $r = $this->api('GET', '/api/v2/admin/user/' . rawurlencode($uuid) . '/');
        if ($r['code'] === 404) {
            return $this->ok(array('status' => 'deleted', 'used' => 0, 'limit' => 0, 'expire' => 0, 'online' => null, 'sub_url' => '', 'configs' => array()));
        }
        if (!$r['ok'] || !is_array($r['json'])) {
            return $this->fail('دریافت اطلاعات کاربر ناموفق بود', $r);
        }
        $u = $r['json'];
        $limit_gb = (float) (isset($u['usage_limit_GB']) ? $u['usage_limit_GB'] : 0);
        $limit = $limit_gb >= self::UNLIMITED_GB ? 0 : CN_Util::gb_to_bytes($limit_gb);
        $used = CN_Util::gb_to_bytes(isset($u['current_usage_GB']) ? $u['current_usage_GB'] : 0);
        $days = (int) (isset($u['package_days']) ? $u['package_days'] : 0);
        $start = !empty($u['start_date']) ? strtotime($u['start_date']) : 0;
        $expire = ($start && $days < self::UNLIMITED_DAYS) ? $start + $days * DAY_IN_SECONDS : 0;
        $enabled = !isset($u['enable']) || $u['enable'];
        if (!$enabled) {
            $status = 'disabled';
        } elseif (!$start && $days < self::UNLIMITED_DAYS) {
            $status = 'on_hold';
        } elseif ($expire && $expire < time()) {
            $status = 'expired';
        } elseif ($limit > 0 && $used >= $limit) {
            $status = 'limited';
        } else {
            $status = 'active';
        }
        return $this->ok(array(
            'status'  => $status,
            'used'    => $used,
            'limit'   => $limit,
            'expire'  => $expire,
            'online'  => isset($u['last_online']) ? $u['last_online'] : null,
            'sub_url' => $this->sub_url($uuid),
            'configs' => array(),
            'raw'     => $u,
        ));
    }

    protected function patch(array $svc, array $data) {
        $r = $this->api('PATCH', '/api/v2/admin/user/' . rawurlencode($this->uuid($svc)) . '/', array('json' => $data));
        return $r['ok'] ? $this->ok() : $this->fail('به‌روزرسانی کاربر ناموفق بود', $r);
    }

    protected function set_limits(array $svc, $limit, $expire, array $info) {
        return $this->patch($svc, array(
            'usage_limit_GB' => $limit > 0 ? round($limit / CN_Util::GB, 3) : self::UNLIMITED_GB,
            'package_days'   => self::days_for($expire),
            'start_date'     => $expire > 0 ? gmdate('Y-m-d') : null,
            'enable'         => true,
        ));
    }

    public function reset(array $svc) {
        return $this->patch($svc, array('current_usage_GB' => 0));
    }

    public function set_enabled(array $svc, $enabled) {
        return $this->patch($svc, array('enable' => (bool) $enabled));
    }

    public function delete(array $svc) {
        $r = $this->api('DELETE', '/api/v2/admin/user/' . rawurlencode($this->uuid($svc)) . '/');
        return ($r['ok'] || $r['code'] === 404) ? $this->ok() : $this->fail('حذف کاربر ناموفق بود', $r);
    }
}
