<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Connectix seller API (externel/v1). Volume and duration come from the Connectix plan itself.
 * The API has no disable/delete endpoints; every successful add-plan call is a paid purchase.
 */
class CN_Driver_Connectix extends CN_Driver {
    const BASE = 'https://seller-api.connectix.vip/externel/v1';
    const GB = 1073741824;
    const UNSURE = 'پاسخی از کانکتیکس نرسید و معلوم نیست خرید انجام شده یا نه؛ قبل از تلاش دوباره، پنل کانکتیکس را بررسی کنید';

    public static function label() {
        return 'کانکتیکس';
    }

    public static function fields() {
        return array(
            'token'       => array('label' => 'توکن API فروشنده', 'type' => 'password', 'secret' => true, 'required' => true, 'help' => 'در پنل فروشندهٔ کانکتیکس، بخش API ساخته می‌شود.'),
            'first_login' => array('label' => 'شروع اعتبار از اولین اتصال مشتری', 'type' => 'checkbox', 'help' => 'اگر روشن باشد، مدت پلن از اولین ورود مشتری به برنامه شمرده می‌شود، نه از لحظهٔ خرید.', 'advanced' => true),
            'use_polban'  => array('label' => 'از طریق پل‌بان', 'type' => 'checkbox', 'help' => 'درخواست‌ها از طریق پل‌بان ارسال شوند. https://seller-api.connectix.vip باید در allowlist پل‌بان باشد.'),
        );
    }

    public static function plan_fields() {
        return array(
            'plan_id' => array('label' => 'شناسهٔ پلن کانکتیکس', 'type' => 'text', 'required' => true, 'help' => 'با دکمهٔ «دریافت پلن‌ها» در صفحهٔ پنل‌ها خودکار پر می‌شود.'),
        );
    }

    public static function paid() {
        return true;
    }

    public static function caps() {
        return array('renew', 'usage');
    }

    public static function uses_url() {
        return false;
    }

    public static function remote_limits() {
        return true;
    }

    protected function base() {
        $b = rtrim(trim((string) $this->panel['url']), '/');
        if ($b === '') {
            return self::BASE;
        }
        $b = preg_replace('#^http://#i', 'https://', $b);
        $b = preg_replace('#(/(externel|external)/v\d+)+$#i', '', $b);
        return $b . '/externel/v1';
    }

    protected function api($method, $path, array $o = array()) {
        $o['bearer'] = $this->secret('token');
        $o['verify'] = true;
        // Via Polban the wait must outlast Polban's own 60s upstream timeout.
        $o += array('timeout' => $this->s('use_polban') ? self::POLBAN_TIMEOUT : 60);
        return $this->http($method, $path, $o);
    }

    protected static function plans_from(array $r) {
        $b = is_array($r['json']) ? $r['json'] : array();
        return isset($b['plans']) && is_array($b['plans']) ? $b['plans'] : array();
    }

    public function test() {
        $r = $this->api('GET', '/clients/get-enabled-plans');
        if ($r['code'] === 401) {
            return $this->fail('توکن API نامعتبر است', $r);
        }
        if (!$r['ok']) {
            return $this->fail('اتصال ناموفق بود', $r);
        }
        $msg = 'اتصال برقرار است. پلن‌های فعال: ' . CN_Util::fa_digits(count(self::plans_from($r)));
        $c = $this->get('/clients', array('recordPerPage' => 1), 30);
        if ($c['ok'] && isset($c['json']['total_clients'])) {
            $msg .= '، کاربران: ' . CN_Util::fa_digits((int) $c['json']['total_clients']);
        }
        return $this->ok(array('msg' => $msg));
    }

    /** Plans offered by the seller account: id => [title, days, gb, users, price]. */
    public function remote_plans() {
        $r = $this->api('GET', '/clients/get-enabled-plans');
        if (!$r['ok']) {
            return $this->fail('دریافت پلن‌ها ناموفق بود', $r);
        }
        $out = array();
        foreach (self::plans_from($r) as $p) {
            if (empty($p['id'])) {
                continue;
            }
            $traffic = isset($p['traffic_amount']) ? $p['traffic_amount'] : 0;
            $out[(string) $p['id']] = array(
                'title' => isset($p['title']) && $p['title'] !== '' ? (string) $p['title'] : (string) $p['id'],
                'days'  => isset($p['full_period_days']) ? (int) $p['full_period_days'] : 0,
                'gb'    => is_numeric($traffic) ? (float) $traffic : 0,
                'users' => isset($p['count_of_devices']) ? (int) $p['count_of_devices'] : 0,
                'price' => isset($p['sell_price']) ? (string) $p['sell_price'] : '',
            );
        }
        return $this->ok(array('plans' => $out));
    }

    /** GET with query string. */
    protected function get($path, array $q = array(), $timeout = 60) {
        return $this->api('GET', $path . ($q ? '?' . http_build_query($q) : ''), array('timeout' => $timeout));
    }

    /** Client by id: array, 'missing' (404) or null (request failed). */
    protected function show($id) {
        $r = $this->get('/clients/show', array('id' => $id));
        if ($r['code'] === 404) {
            return 'missing';
        }
        return $r['ok'] && isset($r['json']['client']) && is_array($r['json']['client']) ? $r['json']['client'] : null;
    }

    /** Client id from the seller's list, exact username match. */
    protected function find_id($username) {
        $username = strtolower((string) $username);
        for ($page = 1; $page <= 5; $page++) {
            $r = $this->get('/clients', array('username' => $username, 'recordPerPage' => 100, 'page' => $page));
            if (!$r['ok'] || !isset($r['json']['clients']['data']) || !is_array($r['json']['clients']['data'])) {
                return '';
            }
            foreach ($r['json']['clients']['data'] as $c) {
                if (isset($c['username'], $c['id']) && strtolower((string) $c['username']) === $username) {
                    return (string) $c['id'];
                }
            }
            if (empty($r['json']['clients']['next_page_url'])) {
                return '';
            }
        }
        return '';
    }

    protected function client_id(array $svc) {
        if (!empty($svc['data']['client_id'])) {
            return (string) $svc['data']['client_id'];
        }
        return $this->find_id($svc['username']);
    }

    protected static function credentials($text) {
        $text = str_replace('`', '', (string) $text);
        $out = array('username' => '', 'password' => '');
        if (preg_match('/username\s*:\s*(\S+)/iu', $text, $m)) {
            $out['username'] = trim($m[1]);
        }
        if (preg_match('/password\s*:\s*(\S+)/iu', $text, $m)) {
            $out['password'] = trim($m[1]);
        }
        return $out;
    }

    protected static function bytes($gb) {
        return is_numeric($gb) && (float) $gb > 0 ? (int) round((float) $gb * self::GB) : 0;
    }

    public function create(array $a) {
        $plan = (string) $this->opt($a, 'plan_id', '');
        if ($plan === '') {
            return $this->fail('شناسهٔ پلن کانکتیکس برای این پلن تنظیم نشده است.');
        }
        $pass = CN_Util::random(8, 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789');
        $form = array(
            'password' => $pass,
            'plan_id'  => $plan,
            'name'     => $a['username'],
        );
        if ($this->s('first_login')) {
            $form['enable_plan_after_first_login'] = '1';
        }
        $r = $this->api('POST', '/clients/store', array('form' => $form));
        if ($r['code'] === 0) {
            return array('unsure' => true) + $this->fail(self::UNSURE, $r);
        }
        if (!$r['ok']) {
            return $this->fail('ساخت کاربر در کانکتیکس ناموفق بود', $r);
        }
        $body = is_array($r['json']) ? $r['json'] : array();
        $id = isset($body['client_id']) ? (string) $body['client_id'] : '';
        $cred = self::credentials(isset($body['text_to_copy']) ? $body['text_to_copy'] : '');
        $client = $id !== '' ? $this->show($id) : null;
        $client = is_array($client) ? $client : array();

        $user = !empty($client['username']) ? (string) $client['username'] : ($cred['username'] !== '' ? $cred['username'] : $a['username']);
        $pass = !empty($client['password']) ? (string) $client['password'] : ($cred['password'] !== '' ? $cred['password'] : $pass);
        $plan_row = isset($client['active_plan']) && is_array($client['active_plan']) ? $client['active_plan'] : array();
        $expire = self::ts(isset($client['expire_date']) ? $client['expire_date'] : (isset($plan_row['expire_date']) ? $plan_row['expire_date'] : 0));
        $limit = array_key_exists('traffic_amount', $plan_row) ? self::bytes($plan_row['traffic_amount']) : (int) $a['bytes'];

        $hold = (bool) $this->s('first_login');
        return $this->ok(array(
            'status'   => $hold ? 'on_hold' : 'active',
            'username' => $user,
            'sub_url'  => !empty($client['subscription_link']) ? (string) $client['subscription_link'] : '',
            'configs'  => array(array('نام کاربری', $user, 'text'), array('رمز عبور', $pass, 'text')),
            'data'     => array('client_id' => $id, 'plan_id' => $plan),
            'limit'    => $limit,
            'expire'   => $hold ? 0 : ($expire ? $expire : (int) $a['expire']),
        ));
    }

    public function info(array $svc) {
        $id = $this->client_id($svc);
        $c = $id !== '' ? $this->show($id) : null;
        if ($c === 'missing') {
            return $this->ok(array('status' => 'deleted', 'used' => 0, 'limit' => 0, 'expire' => 0, 'online' => null, 'sub_url' => '', 'configs' => array()));
        }
        if (!is_array($c)) {
            return $this->fail('اطلاعات کاربر از کانکتیکس دریافت نشد.');
        }
        $p = isset($c['active_plan']) && is_array($c['active_plan']) ? $c['active_plan'] : array();
        $limit = $p ? self::bytes(isset($p['traffic_amount']) ? $p['traffic_amount'] : null) : (int) (isset($svc['limit_bytes']) ? $svc['limit_bytes'] : 0);
        $used = $p ? self::bytes(isset($p['used_traffic']) ? $p['used_traffic'] : 0) : 0;
        $expire = self::ts(!empty($c['expire_date']) ? $c['expire_date'] : (isset($p['expire_date']) ? $p['expire_date'] : 0));
        if (!empty($c['is_expired'])) {
            $status = 'expired';
        } elseif (isset($c['is_activated']) && !$c['is_activated']) {
            $status = 'on_hold';
        } elseif (isset($c['is_active']) && !$c['is_active']) {
            $status = 'disabled';
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
            'online'  => null,
            'sub_url' => !empty($c['subscription_link']) ? (string) $c['subscription_link'] : '',
            'configs' => array(),
        ));
    }

    /** One add-plan call only: a repeated request would buy the plan twice. */
    public function renew(array $svc, array $a) {
        $plan = (string) $this->opt($a, 'plan_id', isset($svc['data']['plan_id']) ? $svc['data']['plan_id'] : '');
        if ($plan === '') {
            return $this->fail('شناسهٔ پلن کانکتیکس برای تمدید مشخص نیست.');
        }
        $id = $this->client_id($svc);
        if ($id === '') {
            return $this->fail('کاربر «' . $svc['username'] . '» در کانکتیکس پیدا نشد.');
        }
        $r = $this->api('POST', '/clients/add-plan', array('json' => array('id' => $id, 'plan_id' => $plan)));
        if ($r['code'] === 0) {
            return array('unsure' => true) + $this->fail(self::UNSURE, $r);
        }
        if ($r['code'] === 403) {
            return $this->fail('کانکتیکس اجازهٔ خرید این پلن را نداد؛ موجودی یا دسترسی حساب فروشنده را بررسی کنید', $r);
        }
        if ($r['code'] === 422) {
            return $this->fail('شناسهٔ کاربر یا پلن کانکتیکس نامعتبر است', $r);
        }
        if (!$r['ok']) {
            return $this->fail('تمدید در کانکتیکس ناموفق بود', $r);
        }
        $days = (int) $a['days'];
        $base = max(time(), CN_Util::db_to_ts(isset($svc['expires_at']) ? $svc['expires_at'] : ''));
        return $this->ok(array(
            'limit'  => CN_Util::gb_to_bytes($a['gb']),
            'expire' => $days > 0 ? $base + $days * DAY_IN_SECONDS : 0,
        ));
    }
}
