<?php
if (!defined('ABSPATH')) {
    exit;
}

class CN_Driver_Sui extends CN_Driver {

    public static function label() {
        return 'اس‌یوآی (s-ui)';
    }

    public static function fields() {
        return array(
            'token'    => array('label' => 'توکن API', 'type' => 'password', 'secret' => true, 'required' => true),
            'inbounds' => array('label' => 'شناسهٔ اینباندها (JSON)', 'type' => 'textarea', 'required' => true, 'help' => 'مثل [1,2]'),
            'sub_base' => array('label' => 'آدرس پایهٔ ساب', 'type' => 'url', 'advanced' => true, 'help' => 'خالی = از تنظیمات s-ui خوانده می‌شود'),
        ) + CN_Drivers::common_fields();
    }

    public static function plan_fields() {
        return array(
            'inbounds' => array('label' => 'اینباندهای این پلن (JSON، اختیاری)', 'type' => 'textarea', 'help' => 'خالی = تنظیم پنل'),
        );
    }

    public static function url_help() {
        return 'آدرس پنل همراه مسیر، مثل https://panel.example.com:2095/app';
    }

    protected function api($method, $path, array $o = array()) {
        $o['headers'] = array('Token' => $this->secret('token'));
        $r = $this->http($method, $path, $o);
        if ($r['ok'] && is_array($r['json']) && empty($r['json']['success'])) {
            $r['ok'] = false;
            $r['error'] = isset($r['json']['msg']) ? (string) $r['json']['msg'] : 'success=false';
        }
        return $r;
    }

    public function test() {
        $r = $this->api('GET', '/apiv2/clients');
        return $r['ok'] ? $this->ok(array('msg' => 'اتصال برقرار است.')) : $this->fail('اتصال ناموفق بود', $r);
    }

    protected function sub_base() {
        $b = (string) $this->s('sub_base');
        if ($b !== '') {
            return rtrim($b, '/') . '/';
        }
        $cached = $this->cache_get('sub_base');
        if ($cached) {
            return $cached;
        }
        $r = $this->api('GET', '/apiv2/settings');
        $s = $r['ok'] && isset($r['json']['obj']) && is_array($r['json']['obj']) ? $r['json']['obj'] : array();
        $p = wp_parse_url($this->base());
        if (empty($s['subPort']) || !$p || empty($p['host'])) {
            return '';
        }
        $path = isset($s['subPath']) && $s['subPath'] !== '' ? $s['subPath'] : '/sub/';
        $b = $p['scheme'] . '://' . $p['host'] . ':' . $s['subPort'] . '/' . trim($path, '/') . '/';
        $this->cache_set('sub_base', $b, DAY_IN_SECONDS);
        return $b;
    }

    protected static function auth_str() {
        return CN_Util::random(16, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789');
    }

    public function create(array $a) {
        $raw = $this->opt($a, 'inbounds', $this->s('inbounds'));
        $inbounds = json_decode((string) $raw, true);
        if (!is_array($inbounds) || !$inbounds) {
            return $this->fail('شناسهٔ اینباندها برای s-ui تنظیم نشده است.');
        }
        $u = $a['username'];
        $ss = bin2hex(random_bytes(16));
        $client = array(
            'enable'   => true,
            'name'     => $u,
            'config'   => array(
                'mixed'         => array('username' => $u, 'password' => self::auth_str()),
                'socks'         => array('username' => $u, 'password' => self::auth_str()),
                'http'          => array('username' => $u, 'password' => self::auth_str()),
                'shadowsocks'   => array('name' => $u, 'password' => $ss),
                'shadowsocks16' => array('name' => $u, 'password' => $ss),
                'shadowtls'     => array('name' => $u, 'password' => $ss),
                'vmess'         => array('name' => $u, 'uuid' => CN_Util::uuid(), 'alterId' => 0),
                'vless'         => array('name' => $u, 'uuid' => CN_Util::uuid(), 'flow' => ''),
                'trojan'        => array('name' => $u, 'password' => self::auth_str()),
                'naive'         => array('username' => $u, 'password' => self::auth_str()),
                'hysteria'      => array('name' => $u, 'auth_str' => self::auth_str()),
                'tuic'          => array('name' => $u, 'uuid' => CN_Util::uuid(), 'password' => self::auth_str()),
                'hysteria2'     => array('name' => $u, 'password' => self::auth_str()),
            ),
            'inbounds' => array_map('intval', array_values($inbounds)),
            'links'    => array(),
            'volume'   => (int) $a['bytes'],
            'expiry'   => (int) $a['expire'],
            'desc'     => isset($a['note']) ? $a['note'] : '',
        );
        $r = $this->api('POST', '/apiv2/save', array('form' => array(
            'object' => 'clients',
            'action' => 'new',
            'data'   => wp_json_encode($client),
        )));
        if (!$r['ok']) {
            return $this->fail('ساخت کاربر در پنل ناموفق بود', $r);
        }
        $base = $this->sub_base();
        return $this->ok(array(
            'username' => $u,
            'sub_url'  => $base !== '' ? $base . $u : '',
            'configs'  => array(),
            'data'     => array(),
            'limit'    => (int) $a['bytes'],
            'expire'   => (int) $a['expire'],
        ));
    }

    /** Full client record or [] when missing, null on error. */
    protected function client($name) {
        $r = $this->api('GET', '/apiv2/clients');
        if (!$r['ok']) {
            return null;
        }
        $id = 0;
        foreach ((array) (isset($r['json']['obj']['clients']) ? $r['json']['obj']['clients'] : array()) as $c) {
            if (isset($c['name']) && $c['name'] === $name) {
                $id = (int) $c['id'];
                break;
            }
        }
        if (!$id) {
            return array();
        }
        $r = $this->api('GET', '/apiv2/clients?id=' . $id);
        return $r['ok'] && !empty($r['json']['obj']['clients'][0]) ? $r['json']['obj']['clients'][0] : null;
    }

    public function info(array $svc) {
        $c = $this->client($svc['username']);
        if ($c === null) {
            return $this->fail('دریافت اطلاعات کاربر ناموفق بود.');
        }
        if (!$c) {
            return $this->ok(array('status' => 'deleted', 'used' => 0, 'limit' => 0, 'expire' => 0, 'online' => null, 'sub_url' => '', 'configs' => array()));
        }
        $used = (int) $c['up'] + (int) $c['down'];
        $limit = (int) $c['volume'];
        $expire = self::ts($c['expiry']);
        if (empty($c['enable'])) {
            $status = ($limit > 0 && $used >= $limit) ? 'limited' : (($expire && $expire < time()) ? 'expired' : 'disabled');
        } else {
            $status = 'active';
        }
        $configs = array();
        foreach ((array) (isset($c['links']) ? $c['links'] : array()) as $i => $l) {
            if (!empty($l['uri'])) {
                $configs[] = array(isset($l['remark']) ? $l['remark'] : 'کانفیگ ' . ($i + 1), $l['uri'], 'link');
            }
        }
        $base = $this->sub_base();
        return $this->ok(array(
            'status'  => $status,
            'used'    => $used,
            'limit'   => $limit,
            'expire'  => $expire,
            'online'  => null,
            'sub_url' => $base !== '' ? $base . $svc['username'] : '',
            'configs' => $configs,
            'raw'     => $c,
        ));
    }

    protected function edit(array $svc, callable $mutate, array $raw = null) {
        $c = $raw ? $raw : $this->client($svc['username']);
        if (!$c) {
            return $this->fail('کاربر در پنل پیدا نشد.');
        }
        $c = $mutate($c);
        $data = array();
        foreach (array('id', 'enable', 'name', 'config', 'inbounds', 'links', 'volume', 'expiry', 'desc', 'up', 'down') as $k) {
            if (array_key_exists($k, $c)) {
                $data[$k] = $c[$k];
            }
        }
        $r = $this->api('POST', '/apiv2/save', array('form' => array(
            'object' => 'clients',
            'action' => 'edit',
            'data'   => wp_json_encode($data),
        )));
        return $r['ok'] ? $this->ok() : $this->fail('به‌روزرسانی کاربر ناموفق بود', $r);
    }

    protected function set_limits(array $svc, $limit, $expire, array $info) {
        return $this->edit($svc, function ($c) use ($limit, $expire) {
            $c['volume'] = (int) $limit;
            $c['expiry'] = (int) $expire;
            $c['enable'] = true;
            return $c;
        }, isset($info['raw']) ? $info['raw'] : null);
    }

    public function reset(array $svc) {
        return $this->edit($svc, function ($c) {
            $c['up'] = 0;
            $c['down'] = 0;
            return $c;
        });
    }

    public function set_enabled(array $svc, $enabled) {
        return $this->edit($svc, function ($c) use ($enabled) {
            $c['enable'] = (bool) $enabled;
            return $c;
        });
    }

    public function delete(array $svc) {
        $c = $this->client($svc['username']);
        if ($c === array()) {
            return $this->ok();
        }
        if (!$c) {
            return $this->fail('دریافت اطلاعات کاربر ناموفق بود.');
        }
        $r = $this->api('POST', '/apiv2/save', array('form' => array(
            'object' => 'clients',
            'action' => 'del',
            'data'   => (string) (int) $c['id'],
        )));
        return $r['ok'] ? $this->ok() : $this->fail('حذف کاربر ناموفق بود', $r);
    }
}
