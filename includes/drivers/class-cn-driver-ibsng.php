<?php
if (!defined('ABSPATH')) {
    exit;
}

/** IBSng admin web UI (no official API; HTML forms with a session cookie). */
class CN_Driver_Ibsng extends CN_Driver {

    public static function label() {
        return 'IBSng';
    }

    public static function fields() {
        return array(
            'username'    => array('label' => 'نام کاربری ادمین', 'type' => 'text', 'required' => true),
            'password'    => array('label' => 'رمز ادمین', 'type' => 'password', 'secret' => true, 'required' => true),
            'group'       => array('label' => 'نام گروه پیش‌فرض', 'type' => 'text', 'required' => true, 'help' => 'حجم و زمان از تنظیمات گروه در IBSng می‌آید'),
            'server_info' => array('label' => 'اطلاعات اتصال برای مشتری', 'type' => 'textarea', 'help' => 'مثل آدرس سرور و نوع اتصال؛ همراه نام کاربری و رمز به مشتری نمایش داده می‌شود'),
        );
    }

    public static function plan_fields() {
        return array(
            'group' => array('label' => 'نام گروه IBSng این پلن', 'type' => 'text', 'help' => 'خالی = گروه پیش‌فرض پنل'),
        );
    }

    public static function remote_limits() {
        return true;
    }

    public static function caps() {
        return array('delete', 'usage');
    }

    public static function url_help() {
        return 'آدرس سرور IBSng بدون مسیر، مثل http://1.2.3.4';
    }

    protected function login($force = false) {
        if (!$force) {
            $c = $this->cache_get('cookies');
            if (is_array($c) && $c) {
                return $c;
            }
        }
        $r = $this->http('POST', '/IBSng/admin/', array(
            'form'        => array('username' => $this->s('username'), 'password' => $this->secret('password')),
            'redirection' => 0,
            'headers'     => array('Accept' => 'text/html'),
        ));
        $loc = isset($r['headers']['location']) ? (string) (is_array($r['headers']['location']) ? reset($r['headers']['location']) : $r['headers']['location']) : '';
        $ok = $r['error'] === '' && $r['cookies'] && (strpos($r['body'], 'admin_index') !== false || strpos($loc, 'admin_index') !== false);
        if (!$ok) {
            return null;
        }
        $this->cache_set('cookies', $r['cookies'], 20 * MINUTE_IN_SECONDS);
        return $r['cookies'];
    }

    protected function page($method, $path, array $form = array(), $retry = true) {
        $c = $this->login();
        if (!$c) {
            return array('ok' => false, 'code' => 401, 'error' => 'ورود به IBSng ناموفق بود', 'body' => '', 'headers' => array(), 'json' => null);
        }
        $o = array('cookies' => $c, 'redirection' => 0, 'headers' => array('Accept' => 'text/html'));
        if ($form) {
            $o['form'] = $form;
        }
        $r = $this->http($method, $path, $o);
        if ($retry && strpos($r['body'], 'name="password"') !== false && strpos($r['body'], 'admin_index') === false && strpos($path, 'edit.php') === false) {
            $this->cache_del('cookies');
            return $this->page($method, $path, $form, false);
        }
        return $r;
    }

    public function test() {
        return $this->login(true) ? $this->ok(array('msg' => 'ورود به IBSng موفق بود.')) : $this->fail('ورود به IBSng ناموفق بود؛ آدرس، نام کاربری و رمز را بررسی کنید.');
    }

    protected static function between($s, $start, $a, $b, $len = 2000) {
        $p = strpos($s, $start);
        if ($p === false) {
            return '';
        }
        $s = substr($s, $p, $len + strlen($start));
        $p = strpos($s, $a);
        if ($p === false) {
            return '';
        }
        $s = substr($s, $p + strlen($a));
        $q = strpos($s, $b);
        return trim($q === false ? $s : substr($s, 0, $q));
    }

    public function create(array $a) {
        $group = (string) $this->opt($a, 'group', $this->s('group'));
        $owner = (string) $this->s('username');
        $pass = CN_Util::random(10, 'abcdefghjkmnpqrstuvwxyz23456789');

        $r = $this->page('POST', '/IBSng/admin/user/add_new_users.php', array(
            'submit_form' => 1, 'add' => 1, 'count' => 1, 'credit' => 1,
            'owner_name' => $owner, 'group_name' => $group, 'edit__normal_username' => 1,
        ));
        $hay = $r['body'] . ' ' . wp_json_encode($r['headers']);
        if (!preg_match('/user_id=(\d+)/', $hay, $m)) {
            return $this->fail('ساخت شناسهٔ کاربر در IBSng ناموفق بود؛ نام گروه را بررسی کنید.');
        }
        $uid = $m[1];
        $q = http_build_query(array(
            'edit_user' => 1, 'user_id' => $uid, 'submit_form' => 1, 'add' => 1, 'count' => 1, 'credit' => 1,
            'owner_name' => $owner, 'group_name' => $group, 'x' => 35, 'y' => 1, 'edit__normal_username' => 'normal_username',
        ));
        $r = $this->page('POST', '/IBSng/admin/plugins/edit.php?' . $q, array(
            'target' => 'user', 'target_id' => $uid, 'update' => 1, 'edit_tpl_cs' => 'normal_username',
            'attr_update_method_0' => 'normalAttrs', 'has_normal_username' => 't', 'current_normal_username' => '',
            'normal_username' => $a['username'], 'password' => $pass, 'normal_save_user_add' => 't', 'credit' => 1,
        ));
        $hay = $r['body'] . ' ' . wp_json_encode($r['headers']);
        if (strpos($hay, 'user_info.php?user_id_multi') === false) {
            return $this->fail('ثبت نام کاربری در IBSng ناموفق بود' . (stripos($hay, 'exist') !== false ? ' (نام کاربری تکراری است)' : '') . '.');
        }
        return $this->ok(array(
            'username' => $a['username'],
            'sub_url'  => '',
            'configs'  => $this->cred_configs($a['username'], $pass),
            'data'     => array('uid' => $uid),
            'limit'    => (int) $a['bytes'],
            'expire'   => (int) $a['expire'],
        ));
    }

    protected function cred_configs($user, $pass) {
        $out = array(
            array('نام کاربری', $user, 'text'),
            array('رمز عبور', $pass, 'text'),
        );
        $info = trim((string) $this->s('server_info'));
        if ($info !== '') {
            $out[] = array('اطلاعات اتصال', $info, 'note');
        }
        return $out;
    }

    public function info(array $svc) {
        $r = $this->page('GET', '/IBSng/admin/user/user_info.php?normal_username_multi=' . rawurlencode($svc['username']));
        if ($r['error'] !== '' || $r['body'] === '') {
            return $this->fail('دریافت اطلاعات کاربر ناموفق بود', $r);
        }
        $html = $r['body'];
        if (strpos($html, 'does not exists') !== false) {
            return $this->ok(array('status' => 'deleted', 'used' => 0, 'limit' => 0, 'expire' => 0, 'online' => null, 'sub_url' => '', 'configs' => array()));
        }
        $lock = self::between($html, 'Lock', '<td class="Form_Content_Row_Right_textarea_td_light">', '</td>', 600);
        $exp = self::between($html, 'Nearest Expiration Date:', '<td class="Form_Content_Row_Right_userinfo_light">', '</td>');
        $expire = ($exp !== '' && strpos($exp, '---') === false) ? (int) strtotime(trim(wp_strip_all_tags($exp))) : 0;
        if (stripos($lock, 'Yes') !== false) {
            $status = 'disabled';
        } elseif ($expire && $expire < time()) {
            $status = 'expired';
        } else {
            $status = 'active';
        }
        return $this->ok(array(
            'status'  => $status,
            'used'    => 0,
            'limit'   => (int) (isset($svc['limit_bytes']) ? $svc['limit_bytes'] : 0),
            'expire'  => $expire,
            'online'  => null,
            'sub_url' => '',
            'configs' => array(),
        ));
    }

    public function delete(array $svc) {
        $uid = isset($svc['data']['uid']) ? $svc['data']['uid'] : '';
        if (!$uid) {
            $r = $this->page('GET', '/IBSng/admin/user/user_info.php?normal_username_multi=' . rawurlencode($svc['username']));
            if (strpos($r['body'], 'does not exists') !== false) {
                return $this->ok();
            }
            if (!preg_match('/change_credit\.php\?user_id=(\d+)/', $r['body'], $m)) {
                return $this->fail('شناسهٔ کاربر در IBSng پیدا نشد.');
            }
            $uid = $m[1];
        }
        $r = $this->page('POST', '/IBSng/admin/user/del_user.php', array(
            'user_id' => $uid, 'delete' => 1, 'delete_comment' => '',
            'delete_connection_logs' => 'on', 'delete_audit_logs' => 'on',
        ));
        return strpos($r['body'], 'Successfully') !== false ? $this->ok() : $this->fail('حذف کاربر در IBSng ناموفق بود.');
    }
}
