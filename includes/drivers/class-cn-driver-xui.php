<?php
if (!defined('ABSPATH')) {
    exit;
}

/** 3x-ui (and Alireza x-ui via a different path prefix). One inbound per panel entry. */
class CN_Driver_Xui extends CN_Driver {

    protected function prefix() {
        return '/panel/api/inbounds';
    }

    protected function list_path() {
        return $this->prefix() . '/list';
    }

    public static function label() {
        return 'ثنایی (3x-ui)';
    }

    public static function url_help() {
        return 'آدرس پنل همراه پورت و مسیر مخفی (webBasePath)، مثل https://panel.example.com:2053/secret — آدرس صفحهٔ پنل (…/panel/) هم قبول است';
    }

    protected static function ui_path() {
        return '/panel';
    }

    public static function fields() {
        return array(
            'username' => array('label' => 'نام کاربری پنل', 'type' => 'text', 'required' => true),
            'password' => array('label' => 'رمز پنل', 'type' => 'password', 'secret' => true, 'required' => true),
            'inbound'  => array('label' => 'شناسهٔ اینباند', 'type' => 'number', 'required' => true),
            'sub_base' => array('label' => 'آدرس پایهٔ لینک ساب', 'type' => 'url', 'required' => true, 'help' => 'مثل https://example.com:2096/sub — شناسهٔ ساب به انتهایش اضافه می‌شود'),
            'flow'     => array('label' => 'flow (فقط VLESS)', 'type' => 'text', 'advanced' => true, 'help' => 'مثل xtls-rprx-vision؛ معمولاً خالی'),
            'on_hold'  => array('label' => 'شروع زمان از اولین اتصال', 'type' => 'checkbox'),
        ) + CN_Drivers::common_fields();
    }

    public static function plan_fields() {
        return array(
            'inbound' => array('label' => 'شناسهٔ اینباند این پلن (اختیاری)', 'type' => 'number', 'help' => 'خالی = تنظیم پنل'),
        );
    }

    public static function caps() {
        return array('renew', 'reset', 'toggle', 'delete', 'usage');
    }

    protected function login($force = false) {
        if (!$force) {
            $c = $this->cache_get('cookies');
            if (is_array($c) && $c) {
                return $c;
            }
        }
        $r = $this->http('POST', '/login', array(
            'form' => array('username' => $this->s('username'), 'password' => $this->secret('password')),
        ));
        if (!$r['ok'] || (is_array($r['json']) && empty($r['json']['success'])) || empty($r['cookies'])) {
            return null;
        }
        $this->cache_set('cookies', $r['cookies'], 50 * MINUTE_IN_SECONDS);
        return $r['cookies'];
    }

    protected function api($method, $path, array $o = array()) {
        $c = $this->login();
        if (!$c) {
            return array('ok' => false, 'code' => 401, 'error' => 'ورود به پنل ناموفق بود', 'json' => null, 'body' => '');
        }
        $o['cookies'] = $c;
        $r = $this->http($method, $path, $o);
        if (in_array($r['code'], array(401, 404), true) || ($r['ok'] && $r['json'] === null)) {
            $c = $this->login(true);
            if ($c) {
                $o['cookies'] = $c;
                $r = $this->http($method, $path, $o);
            }
        }
        if ($r['ok'] && is_array($r['json']) && array_key_exists('success', $r['json']) && !$r['json']['success']) {
            $r['ok'] = false;
            $r['error'] = isset($r['json']['msg']) ? (string) $r['json']['msg'] : 'success=false';
        }
        return $r;
    }

    public function test() {
        if (!$this->login(true)) {
            return $this->fail('ورود به پنل ناموفق بود؛ آدرس (همراه مسیر پنل)، نام کاربری و رمز را بررسی کنید.');
        }
        $ib = $this->inbound((int) $this->s('inbound'));
        if (!$ib) {
            return $this->fail('ورود موفق بود ولی اینباند با این شناسه پیدا نشد.');
        }
        return $this->ok(array('msg' => 'اتصال برقرار است. اینباند: ' . (isset($ib['remark']) ? $ib['remark'] : '') . ' (' . $ib['protocol'] . ')'));
    }

    protected function inbounds() {
        $r = $this->api('GET', $this->list_path());
        return $r['ok'] && isset($r['json']['obj']) && is_array($r['json']['obj']) ? $r['json']['obj'] : null;
    }

    protected function inbound($id) {
        foreach ((array) $this->inbounds() as $ib) {
            if ((int) $ib['id'] === (int) $id) {
                return $ib;
            }
        }
        return null;
    }

    /** Locate a client by email: [inbound, client settings, client stats]. */
    protected function find($email, $inbound_id = 0) {
        $list = $this->inbounds();
        if ($list === null) {
            return null;
        }
        foreach ($list as $ib) {
            if ($inbound_id && (int) $ib['id'] !== (int) $inbound_id) {
                continue;
            }
            $settings = json_decode(isset($ib['settings']) ? $ib['settings'] : '', true);
            $clients = isset($settings['clients']) ? $settings['clients'] : array();
            foreach ($clients as $c) {
                if (isset($c['email']) && $c['email'] === $email) {
                    $stat = array();
                    foreach ((array) (isset($ib['clientStats']) ? $ib['clientStats'] : array()) as $s) {
                        if (isset($s['email']) && $s['email'] === $email) {
                            $stat = $s;
                            break;
                        }
                    }
                    return array($ib, $c, $stat);
                }
            }
        }
        return array();
    }

    protected static function client_key(array $ib, array $c) {
        switch (isset($ib['protocol']) ? $ib['protocol'] : '') {
            case 'trojan':
                return $c['password'];
            case 'shadowsocks':
                return $c['email'];
            default:
                return $c['id'];
        }
    }

    protected function expiry_ms($expire, $create) {
        if ($expire <= 0) {
            return 0;
        }
        if ($create && $this->s('on_hold')) {
            return -1 * max(DAY_IN_SECONDS, $expire - time()) * 1000;
        }
        return $expire * 1000;
    }

    protected function sub_url($sub_id) {
        $b = rtrim((string) $this->s('sub_base'), '/');
        return $b !== '' && $sub_id !== '' ? $b . '/' . $sub_id : '';
    }

    public function create(array $a) {
        $inbound_id = (int) $this->opt($a, 'inbound', $this->s('inbound'));
        $ib = $this->inbound($inbound_id);
        if (!$ib) {
            return $this->fail('اینباند ' . $inbound_id . ' در پنل پیدا نشد یا ورود ناموفق بود.');
        }
        $sub_id = CN_Util::random(16, 'abcdefghijklmnopqrstuvwxyz0123456789');
        $client = array(
            'email'      => $a['username'],
            'limitIp'    => 0,
            'totalGB'    => (int) $a['bytes'],
            'expiryTime' => $this->expiry_ms((int) $a['expire'], true),
            'enable'     => true,
            'tgId'       => '',
            'subId'      => $sub_id,
            'reset'      => 0,
        );
        switch ($ib['protocol']) {
            case 'trojan':
                $client['password'] = CN_Util::random(16, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789');
                break;
            case 'shadowsocks':
                $client['method'] = '';
                $client['password'] = base64_encode(random_bytes(32));
                break;
            case 'vless':
                $client['id'] = CN_Util::uuid();
                $client['flow'] = (string) $this->s('flow');
                break;
            default:
                $client['id'] = CN_Util::uuid();
                $client['alterId'] = 0;
        }
        $r = $this->api('POST', $this->prefix() . '/addClient', array('json' => array(
            'id'       => $inbound_id,
            'settings' => wp_json_encode(array('clients' => array($client))),
        )));
        if (!$r['ok']) {
            return $this->fail('ساخت کاربر در پنل ناموفق بود', $r);
        }
        return $this->ok(array(
            'username' => $a['username'],
            'sub_url'  => $this->sub_url($sub_id),
            'configs'  => array(),
            'data'     => array('inbound' => $inbound_id, 'sub_id' => $sub_id),
            'limit'    => (int) $a['bytes'],
            'expire'   => (int) $a['expire'],
        ));
    }

    protected function svc_inbound(array $svc) {
        return isset($svc['data']['inbound']) ? (int) $svc['data']['inbound'] : (int) $this->s('inbound');
    }

    public function info(array $svc) {
        $f = $this->find($svc['username'], $this->svc_inbound($svc));
        if ($f === null) {
            return $this->fail('دریافت اطلاعات از پنل ناموفق بود.');
        }
        if (!$f) {
            return $this->ok(array('status' => 'deleted', 'used' => 0, 'limit' => 0, 'expire' => 0, 'online' => null, 'sub_url' => '', 'configs' => array()));
        }
        list(, $c, $s) = $f;
        $used = (int) (isset($s['up']) ? $s['up'] : 0) + (int) (isset($s['down']) ? $s['down'] : 0);
        $limit = (int) (isset($c['totalGB']) ? $c['totalGB'] : 0);
        $exp_ms = (int) (isset($c['expiryTime']) ? $c['expiryTime'] : 0);
        $expire = $exp_ms > 0 ? (int) floor($exp_ms / 1000) : 0;
        if (empty($c['enable'])) {
            $status = 'disabled';
        } elseif ($exp_ms < 0) {
            $status = 'on_hold';
        } elseif ($expire && $expire < time()) {
            $status = 'expired';
        } elseif ($limit > 0 && $used >= $limit) {
            $status = 'limited';
        } else {
            $status = 'active';
        }
        $sub_id = isset($c['subId']) ? $c['subId'] : '';
        return $this->ok(array(
            'status'  => $status,
            'used'    => $used,
            'limit'   => $limit,
            'expire'  => $expire,
            'online'  => null,
            'sub_url' => $this->sub_url($sub_id),
            'configs' => array(),
        ));
    }

    protected function update_client(array $svc, callable $mutate) {
        $f = $this->find($svc['username'], $this->svc_inbound($svc));
        if (!$f) {
            return $this->fail('کاربر در پنل پیدا نشد.');
        }
        list($ib, $c) = $f;
        $c = $mutate($c);
        $r = $this->api('POST', $this->prefix() . '/updateClient/' . rawurlencode(self::client_key($ib, $c)), array('json' => array(
            'id'       => (int) $ib['id'],
            'settings' => wp_json_encode(array('clients' => array($c))),
        )));
        return $r['ok'] ? $this->ok() : $this->fail('به‌روزرسانی کاربر ناموفق بود', $r);
    }

    protected function set_limits(array $svc, $limit, $expire, array $info) {
        $ms = $this->expiry_ms($expire, false);
        return $this->update_client($svc, function ($c) use ($limit, $ms) {
            $c['totalGB'] = (int) $limit;
            $c['expiryTime'] = $ms;
            $c['enable'] = true;
            return $c;
        });
    }

    public function reset(array $svc) {
        $r = $this->api('POST', $this->prefix() . '/' . $this->svc_inbound($svc) . '/resetClientTraffic/' . rawurlencode($svc['username']));
        return $r['ok'] ? $this->ok() : $this->fail('صفر کردن مصرف ناموفق بود', $r);
    }

    public function set_enabled(array $svc, $enabled) {
        return $this->update_client($svc, function ($c) use ($enabled) {
            $c['enable'] = (bool) $enabled;
            return $c;
        });
    }

    public function delete(array $svc) {
        $f = $this->find($svc['username'], $this->svc_inbound($svc));
        if ($f === array()) {
            return $this->ok();
        }
        if (!$f) {
            return $this->fail('دریافت اطلاعات از پنل ناموفق بود.');
        }
        list($ib, $c) = $f;
        $r = $this->api('POST', $this->prefix() . '/' . (int) $ib['id'] . '/delClient/' . rawurlencode(self::client_key($ib, $c)));
        return $r['ok'] ? $this->ok() : $this->fail('حذف کاربر ناموفق بود', $r);
    }
}

class CN_Driver_Alireza extends CN_Driver_Xui {
    protected function prefix() {
        return '/xui/API/inbounds';
    }

    protected function list_path() {
        return $this->prefix();
    }

    public static function label() {
        return 'علیرضا (x-ui)';
    }

    public static function url_help() {
        return 'آدرس پنل همراه پورت و مسیر (اگر دارد)، مثل https://panel.example.com:54321 — آدرس صفحهٔ پنل (…/xui/) هم قبول است';
    }

    protected static function ui_path() {
        return '/xui';
    }
}
