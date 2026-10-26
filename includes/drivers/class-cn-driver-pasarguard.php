<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * PasarGuard driver — separate from classic Marzban.
 * API v1: proxy_settings, group_ids, ISO expire dates.
 * Token login is enough to prove connectivity (agent/reseller admins
 * often cannot call /api/system or list groups).
 */
class CN_Driver_Pasarguard extends CN_Driver_Marzban {
    protected function v1() {
        return true;
    }

    public static function label() {
        return 'پاسارگارد';
    }

    public static function url_help() {
        return 'همان آدرس ورود به پنل پاسارگارد، مثل https://panel.example.com:8000/dashboard';
    }

    public static function fields() {
        $f = parent::fields();
        unset($f['proxies']);
        $f['template']['help'] = 'نام کاربری یک کاربر موجود در پنل؛ گروه‌ها و تنظیمات پروتکل از روی همین کاربر برداشته می‌شود (مثل ربات). خالی = همهٔ گروه‌های فعال.';
        $f['inbounds'] = array(
            'label'    => 'شناسهٔ گروه‌ها group_ids (JSON)',
            'type'     => 'textarea',
            'advanced' => true,
            'help'     => 'مثل [1,2]؛ خالی = همهٔ گروه‌های فعال. شناسه‌ها در نتیجهٔ «تست اتصال» نمایش داده می‌شوند.',
        );
        return $f;
    }

    public static function plan_fields() {
        return array(
            'inbounds' => array(
                'label' => 'شناسهٔ گروه‌های این پلن (JSON، اختیاری)',
                'type'  => 'textarea',
                'help'  => 'مثل [1]؛ خالی = تنظیم پنل',
            ),
        );
    }

    /** Enabled groups: id => name; null when this admin cannot list groups. */
    protected function groups() {
        $cached = $this->cache_get('groups');
        if (is_array($cached)) {
            return $cached;
        }
        $r = $this->api('GET', '/api/groups');
        if (!$r['ok']) {
            $r = $this->api('GET', '/api/groups/simple');
        }
        if (!$r['ok']) {
            return null;
        }
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
            if (!is_array($g) || !isset($g['id']) || !empty($g['is_disabled'])) {
                continue;
            }
            $out[(int) $g['id']] = isset($g['name']) ? (string) $g['name'] : '';
        }
        $this->cache_set('groups', $out, 10 * MINUTE_IN_SECONDS);
        return $out;
    }

    private static function is_list(array $a) {
        if ($a === array()) {
            return true;
        }
        return array_keys($a) === range(0, count($a) - 1);
    }

    public function test() {
        $r = parent::test();
        if (empty($r['ok'])) {
            return $r;
        }
        $this->cache_del('groups');
        $groups = $this->groups();
        if ($groups === null) {
            $r['msg'] .= ' این ادمین اجازهٔ دیدن گروه‌ها را ندارد؛ شناسهٔ گروه‌ها را دستی در تنظیمات پنل وارد کنید.';
            return $r;
        }
        $names = array();
        foreach ($groups as $id => $name) {
            $names[] = CN_Util::fa_digits($id) . ' = ' . $name;
        }
        $r['msg'] .= $names
            ? ' گروه‌های فعال: ' . implode('، ', $names)
            : ' هیچ گروه فعالی در پنل نیست؛ اول یک گروه بسازید.';
        return $r;
    }

    public function create(array $a) {
        $tpl = $this->s('template') !== '' ? $this->template() : null;
        if ($tpl && empty($tpl['ok'])) {
            return $tpl;
        }
        $proxies  = $tpl ? $tpl['proxies'] : null;
        $inbounds = $this->json_setting($this->opt($a, 'inbounds', ''));
        if (!$inbounds) {
            $inbounds = $tpl ? $tpl['inbounds'] : $this->json_setting($this->s('inbounds'));
        }
        if (!$inbounds) {
            $g = $this->groups();
            $inbounds = $g === null ? null : array_keys($g);
        }
        $expire = (int) $a['expire'];
        $limit  = (int) $a['bytes'];

        $data = array(
            'username'                  => $a['username'],
            'data_limit'                => $limit,
            'note'                      => isset($a['note']) ? $a['note'] : '',
            'data_limit_reset_strategy' => 'no_reset',
        );
        // Empty proxy_settings is omitted so PasarGuard generates protocols from the groups.
        if ($proxies) {
            $data['proxy_settings'] = self::proxy_map($proxies);
        }
        if ($inbounds) {
            $data['group_ids'] = array_values($inbounds);
        }
        if ($expire > 0 && $this->s('on_hold')) {
            $data['expire'] = 0;
            $data['status'] = 'on_hold';
            $data['on_hold_expire_duration'] = max(DAY_IN_SECONDS, $expire - time());
        } else {
            $data['expire'] = $expire > 0 ? date('c', $expire) : 0;
        }

        $r = $this->api('POST', '/api/user', array('json' => $data));
        if (!$r['ok'] || empty($r['json']['username'])) {
            return $this->fail('ساخت کاربر در پنل ناموفق بود', $r);
        }
        $u = $r['json'];
        $hold = $expire > 0 && $this->s('on_hold');
        return $this->ok(array(
            'status'   => $hold ? 'on_hold' : 'active',
            'username' => $u['username'],
            'sub_url'  => $this->abs_url(isset($u['subscription_url']) ? $u['subscription_url'] : ''),
            'configs'  => $this->links($u),
            'data'     => array(),
            'limit'    => $limit,
            'expire'   => $hold ? 0 : $expire,
        ));
    }
}
