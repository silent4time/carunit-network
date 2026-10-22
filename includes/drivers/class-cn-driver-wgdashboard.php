<?php
if (!defined('ABSPATH')) {
    exit;
}

class CN_Driver_Wgdashboard extends CN_Driver {

    public static function label() {
        return 'وایرگارد (WGDashboard)';
    }

    public static function fields() {
        return array(
            'api_key' => array('label' => 'کلید API', 'type' => 'password', 'secret' => true, 'required' => true),
            'conf'    => array('label' => 'نام کانفیگ وایرگارد', 'type' => 'text', 'required' => true, 'help' => 'مثل wg0'),
        ) + CN_Drivers::common_fields();
    }

    protected function api($method, $path, array $o = array()) {
        $o['headers'] = array('wg-dashboard-apikey' => $this->secret('api_key'));
        $r = $this->http($method, $path, $o);
        if ($r['ok'] && is_array($r['json']) && array_key_exists('status', $r['json']) && !$r['json']['status']) {
            $r['ok'] = false;
            $r['error'] = isset($r['json']['message']) ? (string) $r['json']['message'] : 'status=false';
        }
        return $r;
    }

    protected function conf() {
        return (string) $this->s('conf');
    }

    public function test() {
        $r = $this->api('GET', '/api/getWireguardConfigurationInfo?configurationName=' . rawurlencode($this->conf()));
        return $r['ok'] ? $this->ok(array('msg' => 'اتصال برقرار است.')) : $this->fail('اتصال ناموفق بود', $r);
    }

    protected static function keys() {
        if (!function_exists('sodium_crypto_box_keypair')) {
            return null;
        }
        $kp = sodium_crypto_box_keypair();
        return array(
            'private_key'   => base64_encode(sodium_crypto_box_secretkey($kp)),
            'public_key'    => base64_encode(sodium_crypto_box_publickey($kp)),
            'preshared_key' => base64_encode(random_bytes(32)),
        );
    }

    protected function job($pub, $field, $value) {
        return $this->api('POST', '/api/savePeerScheduleJob', array('json' => array('Job' => array(
            'JobID'         => CN_Util::uuid(),
            'Configuration' => $this->conf(),
            'Peer'          => $pub,
            'Field'         => $field,
            'Operator'      => 'lgt',
            'Value'         => (string) $value,
            'CreationDate'  => '',
            'ExpireDate'    => null,
            'Action'        => 'restrict',
        ))));
    }

    protected function set_jobs($pub, $limit, $expire) {
        if ($limit > 0) {
            $this->job($pub, 'total_data', round($limit / CN_Util::GB, 2));
        }
        if ($expire > 0) {
            $this->job($pub, 'date', function_exists('wp_date') ? wp_date('Y-m-d H:i:s', $expire) : date('Y-m-d H:i:s', $expire));
        }
    }

    public function create(array $a) {
        $keys = self::keys();
        if (!$keys) {
            return $this->fail('افزونهٔ sodium در PHP سرور فعال نیست؛ ساخت کلید وایرگارد ممکن نیست.');
        }
        $r = $this->api('GET', '/api/getAvailableIPs/' . rawurlencode($this->conf()));
        if (!$r['ok'] || empty($r['json']['data']) || !is_array($r['json']['data'])) {
            return $this->fail('دریافت IP آزاد ناموفق بود', $r);
        }
        $first = reset($r['json']['data']);
        $ip = is_array($first) ? reset($first) : $first;
        if (!$ip) {
            return $this->fail('IP آزادی در این کانفیگ باقی نمانده است.');
        }
        $r = $this->api('POST', '/api/addPeers/' . rawurlencode($this->conf()), array('json' => array(
            'name'          => $a['username'],
            'allowed_ips'   => array($ip),
            'private_key'   => $keys['private_key'],
            'public_key'    => $keys['public_key'],
            'preshared_key' => $keys['preshared_key'],
        )));
        if (!$r['ok']) {
            return $this->fail('ساخت کاربر در پنل ناموفق بود', $r);
        }
        $pub = $keys['public_key'];
        $this->set_jobs($pub, (int) $a['bytes'], (int) $a['expire']);

        $configs = array();
        $d = $this->api('GET', '/api/downloadPeer/' . rawurlencode($this->conf()) . '?id=' . rawurlencode($pub));
        if ($d['ok'] && !empty($d['json']['data']['file'])) {
            $name = !empty($d['json']['data']['fileName']) ? $d['json']['data']['fileName'] : $a['username'];
            $configs[] = array('فایل کانفیگ وایرگارد', (string) $d['json']['data']['file'], 'file', sanitize_file_name($name) . '.conf');
        }
        return $this->ok(array(
            'username' => $a['username'],
            'sub_url'  => '',
            'configs'  => $configs,
            'data'     => array('public_key' => $pub, 'ip' => $ip),
            'limit'    => (int) $a['bytes'],
            'expire'   => (int) $a['expire'],
        ));
    }

    protected function pub(array $svc) {
        return isset($svc['data']['public_key']) ? $svc['data']['public_key'] : '';
    }

    /** [peer, restricted] or [] when missing, null on error. */
    protected function peer(array $svc) {
        $r = $this->api('GET', '/api/getWireguardConfigurationInfo?configurationName=' . rawurlencode($this->conf()));
        if (!$r['ok'] || !isset($r['json']['data'])) {
            return null;
        }
        $pub = $this->pub($svc);
        $d = $r['json']['data'];
        foreach (array('configurationPeers' => false, 'configurationRestrictedPeers' => true) as $k => $restricted) {
            foreach ((array) (isset($d[$k]) ? $d[$k] : array()) as $p) {
                if ((isset($p['id']) && $p['id'] === $pub) || (!$pub && isset($p['name']) && $p['name'] === $svc['username'])) {
                    return array($p, $restricted);
                }
            }
        }
        return array();
    }

    public function info(array $svc) {
        $f = $this->peer($svc);
        if ($f === null) {
            return $this->fail('دریافت اطلاعات از پنل ناموفق بود.');
        }
        if (!$f) {
            return $this->ok(array('status' => 'deleted', 'used' => 0, 'limit' => 0, 'expire' => 0, 'online' => null, 'sub_url' => '', 'configs' => array()));
        }
        list($p, $restricted) = $f;
        $used = CN_Util::gb_to_bytes((float) (isset($p['total_data']) ? $p['total_data'] : 0) + (float) (isset($p['cumu_data']) ? $p['cumu_data'] : 0));
        $limit = 0;
        $expire = 0;
        foreach ((array) (isset($p['jobs']) ? $p['jobs'] : array()) as $j) {
            if ($j['Field'] === 'total_data') {
                $limit = CN_Util::gb_to_bytes((float) $j['Value']);
            } elseif ($j['Field'] === 'date') {
                $expire = (int) strtotime($j['Value']);
            }
        }
        if ($restricted) {
            $status = ($limit > 0 && $used >= $limit) ? 'limited' : (($expire && $expire < time()) ? 'expired' : 'disabled');
        } else {
            $status = 'active';
        }
        return $this->ok(array(
            'status'  => $status,
            'used'    => $used,
            'limit'   => $limit,
            'expire'  => $expire,
            'online'  => isset($p['latest_handshake']) ? $p['latest_handshake'] : null,
            'sub_url' => '',
            'configs' => array(),
            'raw'     => $p,
        ));
    }

    protected function set_limits(array $svc, $limit, $expire, array $info) {
        $pub = $this->pub($svc);
        $jobs = isset($info['raw']['jobs']) ? (array) $info['raw']['jobs'] : array();
        foreach ($jobs as $j) {
            $this->api('POST', '/api/deletePeerScheduleJob', array('json' => array('Job' => $j)));
        }
        $this->set_jobs($pub, $limit, $expire);
        if (isset($info['status']) && $info['status'] === 'active') {
            return $this->ok();
        }
        return $this->set_enabled($svc, true);
    }

    public function reset(array $svc) {
        $r = $this->api('POST', '/api/resetPeerData/' . rawurlencode($this->conf()), array('json' => array('id' => $this->pub($svc), 'type' => 'total')));
        return $r['ok'] ? $this->ok() : $this->fail('صفر کردن مصرف ناموفق بود', $r);
    }

    public function set_enabled(array $svc, $enabled) {
        $path = ($enabled ? '/api/allowAccessPeers/' : '/api/restrictPeers/') . rawurlencode($this->conf());
        $r = $this->api('POST', $path, array('json' => array('peers' => array($this->pub($svc)))));
        return $r['ok'] ? $this->ok() : $this->fail('تغییر وضعیت ناموفق بود', $r);
    }

    public function delete(array $svc) {
        $this->set_enabled($svc, true);
        $r = $this->api('POST', '/api/deletePeers/' . rawurlencode($this->conf()), array('json' => array('peers' => array($this->pub($svc)))));
        return $r['ok'] ? $this->ok() : $this->fail('حذف کاربر ناموفق بود', $r);
    }
}
