<?php
// Mock test: existing-user check on retry (panel 8 pasarguard via Polban, panel 5 direct), groups cache, Polban timeout, other drivers untouched.
define('ABSPATH', '/'); define('MINUTE_IN_SECONDS', 60); define('HOUR_IN_SECONDS', 3600); define('DAY_IN_SECONDS', 86400);
$F = array(); $OPT = array('cn_polban_url' => 'https://pb.test', 'cn_polban_key' => 'K'); $TR = array(); $IM = array();
function add_filter($t, $c) { global $F; $F[$t] = $c; } function apply_filters($t, $v, ...$a) { global $F; return isset($F[$t]) ? $F[$t]($v, ...$a) : $v; }
function add_action() {} function do_action() {} function is_admin() { return false; } function get_option($k, $d = '') { global $OPT; return $OPT[$k] ?? $d; }
function wp_json_encode($x) { return json_encode($x); } function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function get_transient($k) { global $TR; return $TR[$k] ?? false; } function set_transient($k, $v) { global $TR; $TR[$k] = $v; } function delete_transient($k) { global $TR; unset($TR[$k]); }
function is_wp_error($x) { return $x instanceof WP_Error; }
class WP_Error { public $m, $c; function __construct($c, $m) { $this->c = $c; $this->m = $m; } function get_error_code() { return $this->c; } function get_error_message() { return $this->m; } }
function wc_get_order_item_meta($i, $k) { global $IM; return $IM[$i][$k] ?? ''; } function wc_update_order_item_meta($i, $k, $v) { global $IM; $IM[$i][$k] = $v; } function wc_delete_order_item_meta($i, $k) { global $IM; unset($IM[$i][$k]); }
class WPDB { public $rows = array(), $insert_id = 0, $last_error = '', $options = 'wp_options';
    function prepare($q, ...$a) { return array($q, $a); } function get_var($q) { foreach ($this->rows as $r) if (isset($r['username']) && $r['panel_id'] == $q[1][0] && $r['username'] === $q[1][1]) return 1; return null; }
    function insert($t, $d) { if ($t === 'services') { $this->rows[] = $d; $this->insert_id = count($this->rows); } return 1; } function query() { return 1; } }
$wpdb = new WPDB();
class CN_DB { static function t($n) { return $n; } static function now() { return '2026-10-26 12:00:00'; } }
class CN_Woo { const ITEM_TRY_USER = '_cn_try_user'; }
class CN_Stock { static function attach() {} }
$PANELS = array(
    8 => array('id' => 8, 'type' => 'pasarguard', 'status' => 'active', 'url' => 'https://pg8.test', 'settings' => array('use_polban' => 1, 'username' => 'a'), 'secrets' => array()),
    5 => array('id' => 5, 'type' => 'pasarguard', 'status' => 'active', 'url' => 'https://pg5.test', 'settings' => array('username' => 'a'), 'secrets' => array()),
    9 => array('id' => 9, 'type' => 'eylan', 'status' => 'active', 'url' => 'https://ey.test', 'settings' => array('use_polban' => 1), 'secrets' => array()),
    7 => array('id' => 7, 'type' => 'connectix', 'status' => 'active', 'url' => 'https://cx.test', 'settings' => array(), 'secrets' => array()),
);
class CN_Panels { static function get($id) { global $PANELS; return $PANELS[$id] ?? null; } }
$base = __DIR__ . '/../includes/';
require $base . 'class-cn-http.php'; require $base . 'class-cn-crypto.php'; require $base . 'class-cn-util.php'; require $base . 'drivers/class-cn-driver.php';
CN_Drivers::load(); require $base . 'class-cn-services.php';
class CN_Driver_Pasarguard_T extends CN_Driver_Pasarguard { public function secret($k, $d = '') { return 'p'; } }

// Fake panels. $MODE: create = 'ok' | 'timeout'; get = 'found' | 'missing' | 'error'
$REQ = array(); $MODE = array('create' => 'ok', 'get' => 'missing');
add_filter('cn_http_mock', function ($v, $m, $url, $args, $o) {
    global $REQ, $MODE;
    $via = strpos($url, 'https://pb.test/') === 0;
    if ($via) { $p = json_decode($args['body'], true); $m = $p['method']; $url = $p['url']; }
    $REQ[] = array($m, $url, $via, $args['timeout']);
    $path = parse_url($url, PHP_URL_PATH);
    $wrap = function ($code, $body) use ($via) { return $via ? array('code' => 200, 'body' => json_encode(array('ok' => true, 'code' => $code, 'error' => '', 'headers' => array(), 'body' => json_encode($body)))) : array('code' => $code, 'body' => json_encode($body)); };
    $timeout = $via ? array('code' => 502, 'body' => json_encode(array('ok' => false, 'code' => 0, 'error' => 'Connection timed out after 25001 milliseconds', 'headers' => array(), 'body' => ''))) : array('code' => 0, 'body' => '', 'error' => 'cURL error 28: Operation timed out');
    if ($m === 'POST' && substr($path, -14) === '/clients/store') return $MODE['create'] === 'timeout' ? $timeout : $wrap(200, array('client_id' => '1'));
    if ($path === '/api/admin/token') return $wrap(200, array('access_token' => 'T'));
    if ($path === '/api/groups') return $wrap(200, array('groups' => array(array('id' => 1, 'name' => 'g'))));
    if ($m === 'POST' && $path === '/api/user') { $b = json_decode($via ? $p['body'] : $args['body'], true); return $MODE['create'] === 'timeout' ? $timeout : $wrap(200, array('username' => $b['username'], 'subscription_url' => '/sub/x', 'links' => array('vless://a'))); }
    if ($m === 'GET' && strpos($path, '/api/user/') === 0) {
        if ($MODE['get'] === 'error') return $timeout;
        if ($MODE['get'] === 'missing') return $wrap(404, array('detail' => 'User not found'));
        return $wrap(200, array('username' => rawurldecode(substr($path, 10)), 'subscription_url' => '/sub/y', 'links' => array('vless://b'), 'proxy_settings' => array('vless' => array('id' => 'x', 'flow' => '')), 'group_ids' => array(1), 'data_limit' => 5, 'expire' => null));
    }
    return $wrap(404, array());
}, 10, 5);
$fail = 0; function ok($c, $m) { global $fail; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; if (!$c) $fail++; }
function cnt($m, $sub) { global $REQ; $n = 0; foreach ($REQ as $r) if ($r[0] === $m && strpos($r[1], $sub) !== false) $n++; return $n; }
$plan8 = array('id' => 1, 'panel_id' => 8, 'days' => 30, 'volume_gb' => 50, 'options' => array());
$plan5 = array('panel_id' => 5) + $plan8;

// A panel 8 via Polban: create times out -> username kept on item; Polban request timeout 35s
$MODE = array('create' => 'timeout', 'get' => 'missing'); $REQ = array();
$r = CN_Services::create($plan8, array('order_id' => 11453, 'item_id' => 81));
$u = wc_get_order_item_meta(81, '_cn_try_user');
ok(is_wp_error($r) && $u !== '', 'timeout: error + tried username saved on item');
ok(strpos($r->get_error_message(), 'سرور سایت') !== false || strpos($r->get_error_message(), 'ناموفق') !== false, 'Persian timeout message kept: ' . $r->get_error_message());
ok($REQ[0][2] === true && $REQ[0][3] === 75, 'Polban request timeout 75s');
// B retry: panel had created it -> adopt, no second POST
$MODE = array('create' => 'ok', 'get' => 'found'); $REQ = array();
$r = CN_Services::create($plan8, array('order_id' => 11453, 'item_id' => 81));
ok(is_int($r) && end($wpdb->rows)['username'] === $u, 'retry adopts existing panel user ' . $u);
ok(cnt('POST', '/api/user') === 0 && cnt('GET', '/api/user/' . $u) === 1, 'no duplicate create on retry');
ok(wc_get_order_item_meta(81, '_cn_try_user') === '', 'tried username cleared after success');
// C retry: user missing -> create with the same username
$MODE = array('create' => 'timeout', 'get' => 'missing'); CN_Services::create($plan8, array('order_id' => 2, 'item_id' => 82)); $u2 = wc_get_order_item_meta(82, '_cn_try_user');
$MODE = array('create' => 'ok', 'get' => 'missing'); $REQ = array();
$r = CN_Services::create($plan8, array('order_id' => 2, 'item_id' => 82));
ok(is_int($r) && end($wpdb->rows)['username'] === $u2 && cnt('POST', '/api/user') === 1, 'missing user -> created with the same username');
// D lookup fails (panel unreachable) -> no create
$MODE = array('create' => 'timeout', 'get' => 'error'); CN_Services::create($plan8, array('order_id' => 3, 'item_id' => 83)); $REQ = array();
$MODE['create'] = 'ok';
$r = CN_Services::create($plan8, array('order_id' => 3, 'item_id' => 83));
ok(is_wp_error($r) && cnt('POST', '/api/user') === 0, 'lookup error -> abort, no blind create');
// E groups cached ~10 min (one GET /api/groups for several creates)
$TR = array(); $REQ = array(); $MODE = array('create' => 'ok', 'get' => 'missing');
CN_Services::create($plan8, array('order_id' => 4, 'item_id' => 84)); CN_Services::create($plan8, array('order_id' => 4, 'item_id' => 85));
ok(cnt('GET', '/api/groups') === 1 && cnt('POST', '/api/user') === 2, 'pasarguard groups fetched once (cached)');
// F panel 5 direct: no Polban, default 20s, same guard
$REQ = array();
$r = CN_Services::create($plan5, array('order_id' => 5, 'item_id' => 51));
ok(is_int($r) && $REQ && !$REQ[0][2] && $REQ[0][3] === 20 && strpos($REQ[0][1], 'https://pg5.test') === 0, 'panel 5 direct untouched (20s, no Polban)');
// G other drivers do not get the lookup
ok(!CN_Driver_Eylan::can_find() && !CN_Driver_Connectix::can_find() && !CN_Driver_Marzneshin::can_find() && !CN_Driver_Rebecca::can_find(), 'eylan/connectix/marzneshin/rebecca: no lookup (unchanged)');
ok(CN_Driver_Pasarguard::can_find() && CN_Driver_Marzban::can_find(), 'pasarguard/marzban: lookup enabled');
// H longer driver timeouts kept through Polban (connectix 60s)
$REQ = array();
$cx = new class(array('id' => 70, 'type' => 'x', 'url' => 'https://cx.test', 'settings' => array('use_polban' => 1), 'secrets' => array())) extends CN_Driver_Xui { function h($o) { return $this->http('GET', '/z', $o); } };
$cx->h(array('timeout' => 90)); ok($REQ[0][3] === 90, 'Polban timeout = max(75, driver timeout)');

// I v1.2.4 template fallback (panel 5 with template)
$PANELS[50] = array('id' => 50, 'type' => 'pasarguard', 'status' => 'active', 'url' => 'https://pg5.test', 'settings' => array('username' => 'a', 'template' => 'Pasargadae_server'), 'secrets' => array());
$plan50 = array('panel_id' => 50) + $plan8;
$TR = array(); $MODE = array('create' => 'ok', 'get' => 'found');
$r = CN_Services::create($plan50, array('order_id' => 6, 'item_id' => 0));
ok(is_int($r), 'template read and create ok');
// expire the 1h copy, keep the non-expiring one
$d50 = CN_Drivers::make($PANELS[50]);
$keyfn = function ($k) use ($PANELS) { $p = $PANELS[50]; return 'cn_c_' . md5($p['id'] . '|' . $p['url'] . '|' . wp_json_encode($p['settings']) . '|' . $k); };
ok(isset($TR[$keyfn('tpl_keep')]), 'non-expiring template copy stored');
unset($TR[$keyfn('tpl')]);
$MODE = array('create' => 'ok', 'get' => 'error'); $REQ = array();
$r = CN_Services::create($plan50, array('order_id' => 7, 'item_id' => 0));
ok(is_int($r) && cnt('POST', '/api/user') === 1, 'template GET fails -> last saved template used, account created');
$t = $d50->test();
ok(empty($t['ok']) && strpos($t['msg'], 'Pasargadae_server') !== false && strpos($t['msg'], 'pg5.test') !== false, 'connection test still reports template failure with host: ' . $t['msg']);
// J connectix: polban field, timeouts, UNSURE code, paid
ok(isset(CN_Driver_Connectix::fields()['use_polban']), 'connectix has use_polban field');
ok(CN_Driver_Connectix::paid() && !CN_Driver_Pasarguard::paid() && !CN_Driver_Eylan::paid(), 'only connectix is paid');
class CXT extends CN_Driver_Connectix { function h() { return $this->api('GET', '/clients/get-enabled-plans'); } }
$REQ = array(); (new CXT(array('id' => 71, 'type' => 'connectix', 'url' => '', 'settings' => array('use_polban' => 1), 'secrets' => array('token' => 't'))))->h();
ok($REQ[0][2] === true && $REQ[0][3] === 75, 'connectix via polban 75s');
$REQ = array(); (new CXT(array('id' => 72, 'type' => 'connectix', 'url' => '', 'settings' => array(), 'secrets' => array('token' => 't'))))->h();
ok($REQ[0][2] === false && $REQ[0][3] === 60, 'connectix direct 60s (unchanged)');
$PANELS[7]['settings'] = array('use_polban' => 1); $PANELS[7]['secrets'] = array('token' => 't');
$planc = array('panel_id' => 7, 'options' => array('plan_id' => '3')) + $plan8;
$MODE = array('create' => 'timeout', 'get' => 'missing'); $REQ = array();
$r = CN_Services::create($planc, array('order_id' => 11455, 'item_id' => 27));
ok(is_wp_error($r) && $r->c === 'cn_unsure' && strpos($r->get_error_message(), 'پل‌بان به پنل') !== false, 'connectix no answer -> cn_unsure: ' . $r->get_error_message());
exit($fail ? 1 : 0);
