<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Another Mirza bot acting as an upstream seller (agent API). */
class CN_Driver_Mirza_Agent extends CN_Driver {

    public static function label() {
        return 'نمایندگی ربات میرزا';
    }

    public static function fields() {
        return array(
            'token'    => array('label' => 'توکن نمایندگی', 'type' => 'password', 'secret' => true, 'required' => true),
            'panel_id' => array('label' => 'شناسهٔ پنل در ربات مبدأ', 'type' => 'text', 'required' => true, 'help' => 'با دکمهٔ «تست اتصال» فهرست پنل‌ها نمایش داده می‌شود'),
        ) + CN_Drivers::common_fields();
    }

    public static function caps() {
        return array('renew', 'delete', 'usage', 'revoke');
    }

    public static function url_help() {
        return 'آدرس API نمایندگی ربات مبدأ (همان آدرسی که فروشنده داده)';
    }

    protected function api($method, $query, array $o = array()) {
        $o['bearer'] = $this->secret('token');
        return $this->http($method, $this->base() . $query, $o);
    }

    protected static function ok_body(array $r) {
        return $r['ok'] && is_array($r['json']) && (!isset($r['json']['status']) || $r['json']['status'] === true || $r['json']['status'] === 'true' || $r['json']['status'] === 1);
    }

    public function test() {
        $r = $this->api('GET', '?actions=list_panel');
        if (!self::ok_body($r)) {
            return $this->fail('اتصال ناموفق بود', $r);
        }
        $names = array();
        $list = isset($r['json']['obj']) ? $r['json']['obj'] : (isset($r['json']['data']) ? $r['json']['data'] : array());
        foreach ((array) $list as $p) {
            if (is_array($p)) {
                $names[] = (isset($p['id']) ? $p['id'] : (isset($p['code_panel']) ? $p['code_panel'] : '?')) . ': ' . (isset($p['name_panel']) ? $p['name_panel'] : (isset($p['name']) ? $p['name'] : ''));
            }
        }
        return $this->ok(array('msg' => 'اتصال برقرار است.' . ($names ? ' پنل‌ها: ' . implode('، ', $names) : '')));
    }

    protected static function days($expire) {
        return $expire > 0 ? max(1, (int) ceil(($expire - time()) / DAY_IN_SECONDS)) : 0;
    }

    public function create(array $a) {
        $r = $this->api('POST', '', array('json' => array(
            'actions'       => 'user_create',
            'username'      => $a['username'],
            'data_limit_gb' => (int) ceil((int) $a['bytes'] / CN_Util::GB),
            'expire_days'   => self::days((int) $a['expire']),
            'panel_id'      => $this->s('panel_id'),
        )));
        if (!self::ok_body($r)) {
            return $this->fail('ساخت سرویس در ربات مبدأ ناموفق بود', $r);
        }
        $u = $this->user_from($r['json']);
        return $this->ok(array(
            'username' => isset($u['username']) ? $u['username'] : $a['username'],
            'sub_url'  => isset($u['subscription_url']) ? (string) $u['subscription_url'] : '',
            'configs'  => self::links($u),
            'data'     => array(),
            'limit'    => (int) $a['bytes'],
            'expire'   => (int) $a['expire'],
        ));
    }

    protected function user_from(array $j) {
        foreach (array(array('obj', 'user'), array('obj'), array('data', 'user'), array('data')) as $path) {
            $cur = $j;
            foreach ($path as $p) {
                $cur = isset($cur[$p]) && is_array($cur[$p]) ? $cur[$p] : null;
                if ($cur === null) {
                    break;
                }
            }
            if (is_array($cur) && (isset($cur['username']) || isset($cur['subscription_url']))) {
                return $cur;
            }
        }
        return array();
    }

    protected static function links(array $u) {
        $out = array();
        foreach ((array) (isset($u['links']) ? $u['links'] : array()) as $i => $l) {
            if (is_string($l) && $l !== '') {
                $out[] = array('کانفیگ ' . ($i + 1), $l, 'link');
            }
        }
        return $out;
    }

    public function info(array $svc) {
        $r = $this->api('GET', '?actions=get_user_data&username=' . rawurlencode($svc['username']));
        if (!self::ok_body($r)) {
            return $this->fail('دریافت اطلاعات از ربات مبدأ ناموفق بود', $r);
        }
        $u = $this->user_from($r['json']);
        if (!$u) {
            return $this->ok(array('status' => 'deleted', 'used' => 0, 'limit' => 0, 'expire' => 0, 'online' => null, 'sub_url' => '', 'configs' => array()));
        }
        return $this->ok(array(
            'status'  => isset($u['status']) ? (string) $u['status'] : 'active',
            'used'    => (int) (isset($u['used_traffic']) ? $u['used_traffic'] : 0),
            'limit'   => (int) (isset($u['data_limit']) ? $u['data_limit'] : 0),
            'expire'  => self::ts(isset($u['expire']) ? $u['expire'] : 0),
            'online'  => isset($u['online_at']) ? $u['online_at'] : null,
            'sub_url' => isset($u['subscription_url']) ? (string) $u['subscription_url'] : '',
            'configs' => self::links($u),
        ));
    }

    /** The agent API only adds on top of the current service. */
    public function renew(array $svc, array $a) {
        $r = $this->api('PUT', '', array('json' => array(
            'actions'       => 'extend_service',
            'username'      => $svc['username'],
            'data_limit_gb' => (int) ceil((float) $a['gb']),
            'time_day'      => (int) $a['days'],
        )));
        if (!self::ok_body($r)) {
            return $this->fail('تمدید در ربات مبدأ ناموفق بود', $r);
        }
        $info = $this->info($svc);
        if (!empty($info['ok'])) {
            return $this->ok(array('limit' => $info['limit'], 'expire' => $info['expire']));
        }
        $base = max(time(), CN_Util::db_to_ts(isset($svc['expires_at']) ? $svc['expires_at'] : ''));
        return $this->ok(array(
            'limit'  => (int) (isset($svc['limit_bytes']) ? $svc['limit_bytes'] : 0) + CN_Util::gb_to_bytes($a['gb']),
            'expire' => (int) $a['days'] > 0 ? $base + (int) $a['days'] * DAY_IN_SECONDS : 0,
        ));
    }

    public function delete(array $svc) {
        $r = $this->api('DELETE', '', array('json' => array('actions' => 'user_delete', 'username' => $svc['username'])));
        return self::ok_body($r) ? $this->ok() : $this->fail('حذف در ربات مبدأ ناموفق بود', $r);
    }

    public function revoke(array $svc) {
        $r = $this->api('PUT', '', array('json' => array('actions' => 'change_link', 'username' => $svc['username'])));
        if (!self::ok_body($r)) {
            return $this->fail('تغییر لینک ناموفق بود', $r);
        }
        $u = $this->user_from($r['json']);
        return $this->ok(array('sub_url' => isset($u['subscription_url']) ? (string) $u['subscription_url'] : ''));
    }
}
