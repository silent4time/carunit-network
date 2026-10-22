<?php
if (!defined('ABSPATH')) {
    exit;
}

class CN_Driver_Eylan extends CN_Driver {
    const MAX_FILES = 10;

    public static function label() {
        return 'ایلان (OVPN / L2TP / Cisco / WireGuard)';
    }

    public static function sub_guide() {
        return array(
            'button' => 'دریافت فایل‌ها و راهنمای راه‌اندازی',
            'steps'  => array(
                'روی دکمهٔ «دریافت فایل‌ها و راهنمای راه‌اندازی» بزنید تا صفحهٔ اختصاصی سرویس شما باز شود.',
                'فایل اتصال مخصوص دستگاه خود را بگیرید و آموزش راه‌اندازی همان صفحه را قدم‌به‌قدم انجام دهید.',
                'حجم مصرفی، تاریخ انقضا و تمدید سرویس همیشه در «حساب کاربری › سرویس‌های من» در دسترس است.',
            ),
        );
    }

    public static function protocols() {
        return array(
            'multi'     => 'همه (چندپروتکلی)',
            'openvpn'   => 'OVPN',
            'l2tp'      => 'L2TP / IPsec',
            'cisco'     => 'Cisco AnyConnect',
            'wireguard' => 'WireGuard',
        );
    }

    public static function fields() {
        return array(
            'api_key'     => array('label' => 'کلید API (X-API-KEY)', 'type' => 'password', 'secret' => true, 'required' => true),
            'templates'   => array('label' => 'نوع پلن‌ها (کاربر نمونه)', 'type' => 'textarea', 'default' => "openvpn\nwireguard\nl2tp\ncisco\nmulti", 'help' => 'هر خط نام کاربری یک کاربر نمونه که در پنل ایلان ساخته‌اید (مثل openvpn). هر پلن یکی از این‌ها را انتخاب می‌کند و پروتکل‌ها، سرورهای وایرگارد و کاربر همزمان از روی همان کاربر برداشته می‌شود؛ پروتکل جدیدی هم که بعداً به پنل اضافه شود خودکار منتقل می‌شود. اولین خط پیش‌فرض است.'),
            'protocol'    => array('label' => 'پروتکل (وقتی کاربر نمونه ندارید)', 'type' => 'select', 'options' => self::protocols(), 'default' => 'multi', 'advanced' => true),
            'max_clients' => array('label' => 'کاربر همزمان (وقتی کاربر نمونه ندارید)', 'type' => 'number', 'default' => '1', 'help' => '۰ = نامحدود', 'advanced' => true),
            'public_base' => array('label' => 'آدرس عمومی برای مشتری', 'type' => 'url', 'advanced' => true, 'help' => 'اگر آدرس API با آدرسی که مشتری باز می‌کند فرق دارد، مثل http://panel.example.com:8050'),
            'server_host' => array('label' => 'آدرس سرور برای L2TP/Cisco', 'type' => 'text', 'advanced' => true, 'help' => 'خالی = دامنهٔ پنل'),
            'l2tp_psk'    => array('label' => 'کلید مشترک L2TP (PSK)', 'type' => 'text', 'default' => '123456', 'advanced' => true, 'help' => 'وقتی پنل کلید را برنگرداند، همین به مشتری داده می‌شود.'),
        ) + CN_Drivers::common_fields();
    }

    public static function plan_fields() {
        return array(
            'template' => array('label' => 'نوع پلن (کاربر نمونه)', 'type' => 'text', 'help' => 'یکی از نوع‌های تعریف‌شده در تنظیمات پنل، مثل openvpn؛ خالی = اولین نوع'),
        );
    }

    /** Template usernames listed on the panel, in order. */
    public static function template_list(array $settings) {
        $out = array();
        foreach (preg_split('/[\r\n,]+/', isset($settings['templates']) ? (string) $settings['templates'] : '') as $line) {
            $line = trim($line);
            if ($line !== '' && !in_array($line, $out, true)) {
                $out[] = $line;
            }
        }
        return $out;
    }

    public static function plan_suggestions(array $panel) {
        return self::template_list($panel['settings']);
    }

    const FLAG_KEYS = array('enable_openvpn', 'enable_l2tp', 'enable_cisco', 'enable_wg1', 'enable_singbox');

    /**
     * Settings copied from a user the admin created on the panel: every enable_* protocol flag,
     * WireGuard instances and max clients. A missing user named after a protocol (openvpn, wireguard…)
     * falls back to that protocol, like the bot.
     */
    protected function template_user($name, $fresh = false) {
        $ck = 'tpl_' . md5($name);
        if (!$fresh) {
            $c = $this->cache_get($ck);
            if (is_array($c) && !empty($c['ok'])) {
                return $c;
            }
        }
        $r = $this->get_user($name);
        $u = is_array($r['json']) && isset($r['json']['data']['username']) ? $r['json']['data'] : $r['json'];
        if (!$r['ok'] || !is_array($u)) {
            $key = strtolower(trim($name));
            if ($r['code'] === 404 && isset(self::protocols()[$key])) {
                return $this->ok(array('name' => $name, 'flags' => self::flags($key), 'wg' => array(), 'max_clients' => null, 'guessed' => true));
            }
            return $this->fail('کاربر نمونه «' . $name . '» در پنل ایلان پیدا نشد', $r);
        }
        $flags = array();
        foreach ($u as $k => $v) {
            if (strpos((string) $k, 'enable_') === 0 && (is_bool($v) || is_numeric($v))) {
                $flags[$k] = !empty($v);
            }
        }
        $flags = $flags ? $flags + array_fill_keys(self::FLAG_KEYS, false) : self::flags($this->norm_protocol($name));
        $wg = array();
        foreach (array('allowed_wg_instances', 'wg_instances') as $k) {
            foreach (isset($u[$k]) && is_array($u[$k]) ? $u[$k] : array() as $row) {
                $inst = is_array($row) ? (isset($row['instance']) ? $row['instance'] : '') : $row;
                if (is_string($inst) && $inst !== '' && $inst !== 'wg1' && !in_array($inst, $wg, true)) {
                    $wg[] = $inst;
                }
            }
        }
        $tpl = $this->ok(array(
            'name'        => $name,
            'flags'       => $flags,
            'wg'          => $wg,
            'max_clients' => isset($u['max_clients']) && is_numeric($u['max_clients']) ? (int) $u['max_clients'] : null,
        ));
        $this->cache_set($ck, $tpl, HOUR_IN_SECONDS);
        return $tpl;
    }

    protected function norm_protocol($name) {
        $n = strtolower(preg_replace('/[\s_\-]+/', '', (string) $name));
        foreach (array('wireguard' => array('wireguard', 'wg'), 'openvpn' => array('openvpn', 'ovpn'), 'l2tp' => array('l2tp', 'ipsec'), 'cisco' => array('cisco', 'anyconnect', 'ocserv')) as $p => $keys) {
            foreach ($keys as $k) {
                if (strpos($n, $k) !== false) {
                    return $p;
                }
            }
        }
        return 'multi';
    }

    protected static function flag_names(array $flags) {
        $names = array('enable_openvpn' => 'OVPN', 'enable_l2tp' => 'L2TP', 'enable_cisco' => 'Cisco', 'enable_wg1' => 'WireGuard', 'enable_singbox' => 'Sing-box');
        $on = array();
        foreach ($flags as $k => $v) {
            if ($v) {
                $on[] = isset($names[$k]) ? $names[$k] : substr($k, 7);
            }
        }
        return $on ? implode('، ', $on) : 'هیچ پروتکلی';
    }

    /** Resolve plan template > plan protocol > first panel template > panel protocol. */
    protected function profile(array $a) {
        $t = trim((string) $this->opt($a, 'template', ''));
        if ($t === '' && (string) $this->opt($a, 'protocol', '') === '') {
            $list = self::template_list($this->panel['settings']);
            $t = $list ? $list[0] : '';
        }
        if ($t !== '') {
            $tpl = $this->template_user($t);
            if (empty($tpl['ok'])) {
                return $tpl;
            }
            $wg = !empty($tpl['flags']['enable_wg1']) && !$tpl['wg'] ? array('wg2') : $tpl['wg'];
            return $this->ok(array(
                'flags'       => $tpl['flags'],
                'wg'          => $wg,
                'max_clients' => $tpl['max_clients'] !== null ? $tpl['max_clients'] : max(0, (int) $this->s('max_clients', 1)),
            ));
        }
        $flags = self::flags($this->protocol($a));
        return $this->ok(array('flags' => $flags, 'wg' => $flags['enable_wg1'] ? array('wg2') : array(), 'max_clients' => max(0, (int) $this->s('max_clients', 1))));
    }

    public static function caps() {
        return array('renew', 'reset', 'toggle', 'delete', 'usage', 'revoke');
    }

    protected function api($method, $path, array $o = array()) {
        $o['headers'] = array('X-API-KEY' => $this->secret('api_key'));
        $o += array('timeout' => 30);
        $r = $this->http($method, $path, $o);
        if ($r['ok'] && is_array($r['json']) && array_key_exists('success', $r['json']) && $r['json']['success'] === false) {
            $r['ok'] = false;
        }
        return $r;
    }

    public function test() {
        $r = $this->api('GET', '/api/v1/status');
        if (!$r['ok']) {
            return $this->fail('اتصال ناموفق بود', $r);
        }
        $parts = array();
        foreach (self::template_list($this->panel['settings']) as $t) {
            $tpl = $this->template_user($t, true);
            if (empty($tpl['ok'])) {
                return $tpl;
            }
            $parts[] = '«' . $t . '»' . (!empty($tpl['guessed']) ? ' (در پنل نیست؛ از روی نام)' : '') . ': ' . self::flag_names($tpl['flags'])
                . ($tpl['max_clients'] !== null ? '، کاربر همزمان ' . CN_Util::fa_digits($tpl['max_clients']) : '');
        }
        return $this->ok(array('msg' => 'اتصال برقرار است.' . ($parts ? ' نوع پلن‌ها — ' . implode(' | ', $parts) : '')));
    }

    protected static function flags($protocol) {
        $all = $protocol === 'multi';
        return array(
            'enable_openvpn' => $all || $protocol === 'openvpn',
            'enable_l2tp'    => $all || $protocol === 'l2tp',
            'enable_cisco'   => $all || $protocol === 'cisco',
            'enable_wg1'     => $all || $protocol === 'wireguard',
            'enable_singbox' => false,
        );
    }

    protected function protocol(array $a) {
        $p = (string) $this->opt($a, 'protocol', $this->s('protocol', 'multi'));
        return isset(self::protocols()[$p]) ? $p : 'multi';
    }

    protected function public_url($u) {
        $base = rtrim((string) $this->s('public_base'), '/');
        $u = $this->abs_url($u);
        if ($base === '' || $u === '') {
            return $u;
        }
        return preg_replace('#^https?://[^/]+#i', $base, $u);
    }

    protected function server_host() {
        $h = trim((string) $this->s('server_host'));
        if ($h !== '') {
            return $h;
        }
        $p = wp_parse_url($this->s('public_base') ? $this->s('public_base') : $this->base());
        return isset($p['host']) ? $p['host'] : '';
    }

    protected static function days($expire) {
        return $expire > 0 ? max(1, (int) ceil(($expire - time()) / DAY_IN_SECONDS)) : 0;
    }

    public function create(array $a) {
        $prof = $this->profile($a);
        if (empty($prof['ok'])) {
            return $prof;
        }
        $flags = $prof['flags'];
        $on = array_keys(array_filter($flags));
        $map = array('enable_openvpn' => 'openvpn', 'enable_l2tp' => 'l2tp', 'enable_cisco' => 'cisco', 'enable_wg1' => 'wireguard');
        $protocol = count($on) === 1 && isset($map[$on[0]]) ? $map[$on[0]] : 'multi';
        $gb = (int) $a['bytes'] > 0 ? round((int) $a['bytes'] / CN_Util::GB, 2) : 0;
        $body = array(
            'username'        => $a['username'],
            'data_limit'      => $gb,
            'data_limit_unit' => 'GB',
            'max_clients'     => $prof['max_clients'],
            'notes'           => isset($a['note']) ? $a['note'] : '',
            'activation_type' => 'fixed_date',
        ) + $flags;
        $days = self::days((int) $a['expire']);
        if ($days > 0) {
            $body['expiry_days'] = $days;
        }
        $secret = array();
        if ($flags['enable_l2tp']) {
            $body['l2tp_password'] = $secret['l2tp_password'] = 'L2' . bin2hex(random_bytes(4));
        }
        if ($flags['enable_cisco']) {
            $body['cisco_password'] = $secret['cisco_password'] = 'C' . bin2hex(random_bytes(4));
        }
        $wg = $prof['wg'];
        $body['wg_instances'] = $wg;
        $body['allowed_wg_instances'] = $wg;

        $r = $this->api('POST', '/api/v1/users', array('json' => $body));
        if (!$r['ok']) {
            return $this->fail('ساخت کاربر در پنل ناموفق بود', $r);
        }
        // Some panel versions enable every protocol on create; pin the chosen ones.
        $this->api('PUT', '/api/v1/users/' . rawurlencode($a['username']), array('json' => $flags + array('wg_instances' => $wg, 'allowed_wg_instances' => $wg)));

        return $this->ok(array(
            'username' => $a['username'],
            'sub_url'  => $this->sub_url($a['username']),
            'configs'  => $this->delivery($a['username'], $flags, $secret),
            'data'     => array('protocol' => $protocol, 'flags' => $flags),
            'limit'    => (int) $a['bytes'],
            'expire'   => (int) $a['expire'],
        ));
    }

    protected function sub_url($username) {
        $r = $this->api('GET', '/api/v1/users/' . rawurlencode($username) . '/sub');
        $j = is_array($r['json']) ? $r['json'] : array();
        foreach (array('sub_url', 'url', 'subscription_url') as $k) {
            if (!empty($j[$k]) && is_string($j[$k])) {
                return $this->public_url($j[$k]);
            }
        }
        return '';
    }

    protected function psk() {
        $r = $this->api('GET', '/api/v1/server_settings');
        $j = is_array($r['json']) ? $r['json'] : array();
        foreach (array('ipsec_psk', 'psk', 'l2tp_psk', 'ipsec_secret', 'secret') as $k) {
            if (!empty($j[$k]) && is_string($j[$k])) {
                return $j[$k];
            }
            if (!empty($j['data'][$k]) && is_string($j['data'][$k])) {
                return $j['data'][$k];
            }
        }
        return '';
    }

    /** Collect raw config texts and download URLs from any JSON shape. */
    protected static function collect($data, array &$raw, array &$urls, $depth = 0) {
        if ($depth > 8 || $data === null) {
            return;
        }
        if (is_string($data)) {
            $s = trim($data);
            if (strlen($s) > 40 && (strpos($s, '[Interface]') !== false || (stripos($s, 'remote ') !== false && stripos($s, 'client') !== false))) {
                $raw[] = $s;
            } elseif (preg_match('#^(https?://|/)\S+$#i', $s) && preg_match('#(download|\.ovpn|\.conf)#i', $s)) {
                $urls[] = $s;
            }
            return;
        }
        if (is_array($data)) {
            foreach ($data as $v) {
                self::collect($v, $raw, $urls, $depth + 1);
            }
        }
    }

    protected function fetch_files($endpoint, $kind) {
        $r = $this->api('GET', '/api/v1/users/' . $endpoint);
        $raw = array();
        $urls = array();
        if (is_array($r['json'])) {
            self::collect($r['json'], $raw, $urls);
        } elseif ($r['ok']) {
            self::collect($r['body'], $raw, $urls);
        }
        foreach (array_unique($urls) as $u) {
            if (count($raw) >= self::MAX_FILES) {
                break;
            }
            $d = $this->api('GET', $this->abs_url($u), array('headers' => array('Accept' => '*/*')));
            if ($d['ok']) {
                $ignored = array();
                self::collect($d['body'], $raw, $ignored);
            }
        }
        $seen = array();
        $out = array();
        foreach ($raw as $text) {
            $fp = md5(preg_replace('/^\s*[#;].*$/m', '', strtolower($text)));
            if (isset($seen[$fp])) {
                continue;
            }
            $seen[$fp] = true;
            $out[] = $text;
        }
        return array_slice($out, 0, self::MAX_FILES);
    }

    protected function delivery($username, array $flags, array $secret) {
        $host = $this->server_host();
        $out = array();
        if ($flags['enable_l2tp']) {
            $psk = $this->psk();
            if ($psk === '') {
                $psk = trim((string) $this->s('l2tp_psk', '123456'));
            }
            $out[] = array('L2TP — سرور', $host, 'text');
            $out[] = array('L2TP — نام کاربری', $username, 'text');
            $out[] = array('L2TP — رمز', isset($secret['l2tp_password']) ? $secret['l2tp_password'] : '', 'text');
            $out[] = array('L2TP — کلید مشترک (PSK)', $psk !== '' ? $psk : '123456', 'text');
        }
        if ($flags['enable_cisco']) {
            $out[] = array('Cisco — سرور', $host, 'text');
            $out[] = array('Cisco — نام کاربری', $username, 'text');
            $out[] = array('Cisco — رمز', isset($secret['cisco_password']) ? $secret['cisco_password'] : '', 'text');
        }
        $u = rawurlencode($username);
        if ($flags['enable_openvpn']) {
            foreach ($this->fetch_files($u . '/all_ovpn_links', 'ovpn') as $i => $text) {
                $out[] = array('فایل OVPN ' . ($i + 1), $text, 'file', $username . '_' . ($i + 1) . '.ovpn');
            }
        }
        if ($flags['enable_wg1']) {
            foreach ($this->fetch_files($u . '/wg1_files', 'wg') as $i => $text) {
                $out[] = array('فایل WireGuard ' . ($i + 1), $text, 'file', $username . '_wg' . ($i + 1) . '.conf');
            }
        }
        return $out;
    }

    protected function get_user($username) {
        return $this->api('GET', '/api/v1/users/' . rawurlencode($username));
    }

    public function info(array $svc) {
        $r = $this->get_user($svc['username']);
        if ($r['code'] === 404) {
            return $this->ok(array('status' => 'deleted', 'used' => 0, 'limit' => 0, 'expire' => 0, 'online' => null, 'sub_url' => '', 'configs' => array()));
        }
        if (!$r['ok'] || !is_array($r['json'])) {
            return $this->fail('دریافت اطلاعات کاربر ناموفق بود', $r);
        }
        $u = isset($r['json']['data']) && is_array($r['json']['data']) && isset($r['json']['data']['username']) ? $r['json']['data'] : $r['json'];
        $limit = CN_Util::gb_to_bytes(isset($u['data_limit']) ? $u['data_limit'] : 0);
        $used = (float) (isset($u['download_bytes']) ? $u['download_bytes'] : (isset($u['used_traffic']) ? $u['used_traffic'] : (isset($u['traffic_bytes']) ? $u['traffic_bytes'] : 0)));
        if ($used > 0 && $used < 10000 && $limit > 0) {
            $used = $used * CN_Util::GB;
        }
        $expire = self::ts(isset($u['expire']) ? $u['expire'] : (isset($u['expiry_date']) ? $u['expiry_date'] : 0));
        $active = !isset($u['is_active']) || $u['is_active'];
        if (!$active || !empty($u['is_disabled'])) {
            $status = 'disabled';
        } elseif ($expire && $expire < time()) {
            $status = 'expired';
        } elseif ($limit > 0 && $used >= $limit) {
            $status = 'limited';
        } else {
            $status = 'active';
        }
        return $this->ok(array(
            'status'  => $status,
            'used'    => (int) $used,
            'limit'   => $limit,
            'expire'  => $expire,
            'online'  => isset($u['online_at']) ? $u['online_at'] : null,
            'sub_url' => !empty($u['sub_url']) ? $this->public_url($u['sub_url']) : '',
            'configs' => array(),
            'raw'     => $u,
        ));
    }

    protected function set_limits(array $svc, $limit, $expire, array $info) {
        $body = array(
            'data_limit'      => $limit > 0 ? round($limit / CN_Util::GB, 2) : 0,
            'data_limit_unit' => 'GB',
            'activation_type' => 'fixed_date',
        );
        $days = self::days($expire);
        if ($days > 0) {
            $body['expiry_days'] = $days;
        }
        $r = $this->api('PUT', '/api/v1/users/' . rawurlencode($svc['username']), array('json' => $body));
        if (!$r['ok']) {
            return $this->fail('به‌روزرسانی کاربر ناموفق بود', $r);
        }
        if (isset($info['status']) && $info['status'] !== 'active') {
            $this->set_enabled($svc, true);
        }
        return $this->ok();
    }

    public function reset(array $svc) {
        $r = $this->api('POST', '/api/v1/users/' . rawurlencode($svc['username']) . '/reset_traffic');
        return $r['ok'] ? $this->ok() : $this->fail('صفر کردن مصرف ناموفق بود', $r);
    }

    /** The panel only offers a toggle, so read the state first. */
    public function set_enabled(array $svc, $enabled) {
        $r = $this->get_user($svc['username']);
        if (!$r['ok'] || !is_array($r['json'])) {
            return $this->fail('دریافت اطلاعات کاربر ناموفق بود', $r);
        }
        $u = isset($r['json']['data']['username']) ? $r['json']['data'] : $r['json'];
        $active = !isset($u['is_active']) || $u['is_active'];
        if ($active === (bool) $enabled) {
            return $this->ok();
        }
        $t = $this->api('POST', '/api/v1/users/' . rawurlencode($svc['username']) . '/toggle');
        return $t['ok'] ? $this->ok() : $this->fail('تغییر وضعیت ناموفق بود', $t);
    }

    public function delete(array $svc) {
        $r = $this->api('DELETE', '/api/v1/users/' . rawurlencode($svc['username']));
        return ($r['ok'] || $r['code'] === 404) ? $this->ok() : $this->fail('حذف کاربر ناموفق بود', $r);
    }

    public function revoke(array $svc) {
        $r = $this->api('POST', '/api/v1/users/' . rawurlencode($svc['username']) . '/revoke');
        return $r['ok'] ? $this->ok(array('sub_url' => $this->sub_url($svc['username']))) : $this->fail('تغییر لینک ناموفق بود', $r);
    }
}
