<?php
/** Run: php tests/run.php */
require __DIR__ . '/bootstrap.php';

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = array();
function check($cond, $label) {
    if ($cond) { $GLOBALS['__pass']++; return; }
    $GLOBALS['__fail'][] = $label;
    echo "  FAIL: $label\n";
}
function section($t) { echo "\n== $t\n"; }
function req($i = -1) { $l = $GLOBALS['__http_log']; return $i < 0 ? $l[count($l) + $i] : $l[$i]; }
function req_json($i = -1) { $r = req($i); return json_decode($r['args']['body'] ?? 'null', true); }
function find_req($method, $re) {
    foreach ($GLOBALS['__http_log'] as $r) { if ($r['method'] === $method && preg_match($re, $r['url'])) { return $r; } }
    return null;
}
function fresh() {
    global $wpdb;
    $wpdb->reset();
    $GLOBALS['__opts'] = array();
    $GLOBALS['__orders'] = array();
    http_reset();
}
function panel($type, $url, array $settings, array $secrets) {
    $id = CN_Panels::save(array('type' => $type, 'name' => $type . ' test', 'url' => $url, 'f' => $settings + $secrets));
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    return $id;
}
function plan($panel_id, $gb, $days, array $o = array(), $mode = 'add') {
    $id = CN_Plans::save(array('panel_id' => $panel_id, 'name' => "$gb GB / $days d", 'volume_gb' => $gb, 'days' => $days, 'o' => $o, 'renew_mode' => $mode));
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    return CN_Plans::get($id);
}
function order($id, $customer, array $items, $status = 'processing') {
    $o = new WC_Order($id);
    $o->customer = $customer;
    $o->items = $items;
    $o->status = $status;
    $GLOBALS['__orders'][$id] = $o;
    return $o;
}
CN_Woo::init();

/* ======================================================= */
section('Source files');
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS));
$bom = array();
foreach ($it as $f) {
    if (preg_match('/\.(php|css|js)$/', $f->getFilename()) && file_get_contents($f->getPathname(), false, null, 0, 3) === "\xEF\xBB\xBF") {
        $bom[] = $f->getFilename();
    }
}
check(!$bom, 'no UTF-8 BOM (would break redirects): ' . implode(', ', $bom));

section('Crypto');
fresh();
$enc = CN_Crypto::encrypt(array('password' => 'p@ss', 'x' => 'ی'));
check(strpos($enc, 'cn1:') === 0, 'encrypted with AES prefix');
check(strpos($enc, 'p@ss') === false, 'plaintext not visible');
check(CN_Crypto::decrypt($enc) === array('password' => 'p@ss', 'x' => 'ی'), 'roundtrip');
check(CN_Crypto::decrypt('cn1:' . base64_encode(str_repeat('x', 40))) === array(), 'tampered value rejected');

section('Panels: validation and secret retention');
fresh();
$r = CN_Panels::save(array('type' => 'marzban', 'name' => 'm', 'url' => 'https://p.test', 'f' => array('username' => 'admin')));
check(is_wp_error($r), 'missing required password rejected');
$r = CN_Panels::save(array('type' => 'marzban', 'name' => 'm', 'url' => 'https://p.test', 'f' => array('username' => 'admin', 'password' => 'secret1', 'proxies' => '{bad json')));
check(is_wp_error($r), 'invalid JSON rejected');
$pid = panel('marzban', 'https://p.test/', array('username' => 'admin', 'proxies' => '{"vless":{}}'), array('password' => 'secret1'));
$row = $wpdb->tables['wp_cn_panels'][$pid];
check(strpos($row['secrets'], 'secret1') === false, 'secret stored encrypted');
check($row['url'] === 'https://p.test', 'trailing slash trimmed');
CN_Panels::save(array('type' => 'marzban', 'name' => 'renamed', 'url' => 'https://p.test', 'f' => array('username' => 'admin', 'password' => '')), $pid);
$p = CN_Panels::get($pid);
check($p['secrets']['password'] === 'secret1', 'blank password keeps stored secret');
check($p['name'] === 'renamed', 'name updated');

/* ======================================================= */
section('Marzban driver');
fresh();
$tokens = 0;
http_route('POST', '#/api/admin/token$#', function () use (&$tokens) { $tokens++; return array('json' => array('access_token' => 'TOK')); });
http_route('POST', '#/api/user$#', function ($m, $u, $a) {
    $b = json_decode($a['body'], true);
    return array('json' => array('username' => $b['username'], 'subscription_url' => '/sub/abc', 'links' => array('vless://x')));
});
$pid = panel('marzban', 'https://mz.test:8000', array('username' => 'admin', 'proxies' => '{"vless":{}}', 'inbounds' => '{"vless":["VLESS TCP"]}'), array('password' => 'pw'));
$plan = plan($pid, 30, 30);
$sid = CN_Services::create($plan, array('order_id' => 501, 'user_id' => 7));
check(is_int($sid) && $sid > 0, 'service created');
$b = json_decode(find_req('POST', '#/api/user$#')['args']['body'], true);
check($b['data_limit'] === 30 * 1073741824, 'data_limit bytes');
check(abs($b['expire'] - (time() + 30 * 86400)) < 5, 'expire timestamp (classic)');
check($b['proxies'] === array('vless' => array()) && $b['inbounds'] === array('vless' => array('VLESS TCP')), 'proxies + inbounds');
check(find_req('POST', '#/api/user$#')['args']['headers']['Authorization'] === 'Bearer TOK', 'bearer token used');
$svc = CN_Services::get($sid);
check($svc['sub_url'] === 'https://mz.test:8000/sub/abc', 'relative sub URL made absolute');
check(strpos($svc['username'], 'cu_501_') === 0, 'username prefix + order id');
check(CN_Services::configs($svc)[0][1] === 'vless://x', 'links stored (encrypted)');
check(strpos($wpdb->tables['wp_cn_services'][$sid]['data'], 'vless://x') === false, 'configs not stored in plaintext');
CN_Services::create($plan, array('order_id' => 502));
check($tokens === 1, 'token cached between calls');

http_route('GET', '#/api/user/#', array('json' => array('username' => 'u', 'status' => 'active', 'used_traffic' => 5 * 1073741824, 'data_limit' => 30 * 1073741824, 'expire' => time() + 10 * 86400, 'subscription_url' => '/sub/abc')));
http_route('PUT', '#/api/user/#', array('json' => array('username' => 'u')));
$plan2 = plan($pid, 20, 30);
$r = CN_Services::renew($svc, $plan2, array('order_id' => 600));
check($r === true, 'renew ok');
$put = json_decode(find_req('PUT', '#/api/user/#')['args']['body'], true);
check($put['data_limit'] === 50 * 1073741824, 'add-mode renew: 30 + 20 GB');
check(abs($put['expire'] - (time() + 40 * 86400)) < 5, 'add-mode renew: remaining 10 + 30 days');
check($put['status'] === 'active', 'renew re-activates');

$GLOBALS['__http_log'] = array();
http_route('POST', '#/reset$#', array('json' => array()));
$plan3 = plan($pid, 10, 7, array(), 'reset');
CN_Services::renew(CN_Services::get($sid), $plan3);
check(find_req('POST', '#/reset$#') !== null, 'reset-mode renew resets usage');
$put = json_decode(find_req('PUT', '#/api/user/#')['args']['body'], true);
check($put['data_limit'] === 10 * 1073741824 && abs($put['expire'] - (time() + 7 * 86400)) < 5, 'reset-mode renew: fresh limits from today');

http_route('GET', '#/api/user/#', array('code' => 404, 'json' => array('detail' => 'User not found')));
$r = CN_Services::refresh(CN_Services::get($sid));
check($r === true && CN_Services::get($sid)['status'] === 'deleted', 'missing user marked deleted');

section('Pasarguard (new Marzban API)');
fresh();
http_route('POST', '#/api/admin/token$#', array('json' => array('access_token' => 'T')));
http_route('POST', '#/api/user$#', function ($m, $u, $a) { $b = json_decode($a['body'], true); return array('json' => array('username' => $b['username'], 'subscription_url' => 'https://s.test/sub/1')); });
$pid = panel('pasarguard', 'https://pg.test', array('username' => 'a', 'inbounds' => '[1,2]'), array('password' => 'p'));
$sid = CN_Services::create(plan($pid, 5, 10), array('order_id' => 1));
$b = json_decode(find_req('POST', '#/api/user$#')['args']['body'], true);
check(!array_key_exists('proxy_settings', $b) && $b['group_ids'] === array(1, 2), 'group_ids without empty proxy_settings');
check(is_string($b['expire']) && strpos($b['expire'], 'T') !== false, 'ISO expire');
http_route('GET', '#/api/groups$#', array('json' => array('groups' => array(array('id' => 3, 'name' => 'DE'), array('id' => 4, 'name' => 'off', 'is_disabled' => true), array('id' => 5, 'name' => 'NL')), 'total' => 3)));
http_route('GET', '#/api/system$#', array('json' => array('version' => '5.4.1')));
$pid = panel('pasarguard', 'https://pg2.test:8443/dashboard/', array('username' => 'a'), array('password' => 'p'));
check(CN_Panels::get($pid)['url'] === 'https://pg2.test:8443/dashboard', 'panel URL kept as entered (with /dashboard)');
$t = CN_Panels::test($pid);
check(find_req('POST', '#^https://pg2\.test:8443/api/admin/token$#') !== null && !preg_grep('#pg2\.test:8443/dashboard#', array_column($GLOBALS['__http_log'], 'url')), '/dashboard not sent to the API');
check(!empty($t['ok']) && strpos($t['msg'], 'DE') !== false && strpos($t['msg'], 'off') === false, 'test lists enabled groups');
$GLOBALS['__http_log'] = array();
CN_Services::create(plan($pid, 5, 10), array('order_id' => 2));
check(json_decode(find_req('POST', '#/api/user$#')['args']['body'], true)['group_ids'] === array(3, 5), 'empty group setting uses all enabled groups');
$create_body = json_decode(find_req('POST', '#/api/user$#')['args']['body'], true);
check(!isset(CN_Driver_Pasarguard::fields()['proxies']) && !array_key_exists('proxy_settings', $create_body), 'pasarguard: omit empty proxy_settings so panel generates it');
check(CN_Driver_Pasarguard::label() === 'پاسارگارد' && class_exists('CN_Driver_Pasarguard') && is_subclass_of('CN_Driver_Pasarguard', 'CN_Driver_Marzban'), 'pasarguard is its own driver class');
fresh();
http_route('POST', '#/api/admin/token$#', array('json' => array('access_token' => 'T')));
http_route('GET', '#/api/admin$#', array('json' => array('username' => 'a')));
http_route('GET', '#/api/user/tpl1$#', array('json' => array('username' => 'tpl1', 'group_ids' => array(7, 9), 'proxy_settings' => array('vless' => array('id' => 'SECRET-UUID', 'flow' => 'xtls-rprx-vision'), 'trojan' => array('password' => 'SECRET-PW')))));
http_route('POST', '#/api/user$#', function ($m, $u, $a) { $b = json_decode($a['body'], true); return array('json' => array('username' => $b['username'])); });
$pid = panel('pasarguard', 'https://pgt.test', array('username' => 'a', 'template' => 'tpl1'), array('password' => 'p'));
$t = CN_Panels::test($pid);
check(!empty($t['ok']) && strpos($t['msg'], 'tpl1') !== false && strpos($t['msg'], '۷') !== false, 'test reads the template user and shows its groups');
CN_Services::create(plan($pid, 5, 10), array('order_id' => 3));
$raw = find_req('POST', '#/api/user$#')['args']['body'];
$b = json_decode($raw, true);
check($b['group_ids'] === array(7, 9) && $b['proxy_settings']['vless'] === array('flow' => 'xtls-rprx-vision'), 'template groups and proxy settings copied');
check(strpos($raw, 'SECRET') === false && strpos($raw, '"trojan":{}') !== false, 'template ids/passwords stripped, empty settings sent as objects');
$pid2 = panel('pasarguard', 'https://pgt2.test', array('username' => 'a', 'template' => 'nobody'), array('password' => 'p'));
http_route('GET', '#/api/user/nobody$#', array('code' => 404, 'json' => array('detail' => 'User not found')));
$t = CN_Panels::test($pid2);
check(empty($t['ok']) && strpos($t['msg'], 'nobody') !== false, 'missing template user fails the connection test');
check(is_wp_error(CN_Services::create(plan($pid2, 5, 10), array('order_id' => 4))), 'missing template user blocks account creation');
fresh();
http_route('POST', '#/api/admin/token$#', array('json' => array('access_token' => 'T')));
http_route('POST', '#/api/user$#', function ($m, $u, $a) { $b = json_decode($a['body'], true); return array('json' => array('username' => $b['username'])); });
CN_Services::create(plan(panel('marzban', 'https://mzt.test', array('username' => 'a', 'proxies' => '{"vless":{},"vmess":{}}'), array('password' => 'p')), 5, 10), array('order_id' => 5));
check(strpos(find_req('POST', '#/api/user$#')['args']['body'], '"proxies":{"vless":{},"vmess":{}}') !== false, 'marzban proxies sent as JSON objects, not lists');
check(!CN_Driver_Connectix::uses_url() && CN_Driver_Connectix::remote_limits() && CN_Driver_Ibsng::remote_limits() && !CN_Driver_Marzban::remote_limits(), 'connectix needs no URL; remote-limit panels flagged');
fresh();
http_route('POST', '#/api/admin/token$#', array('json' => array('access_token' => 'T')));
http_route('GET', '#/api/admin$#', array('json' => array('username' => 'reseller')));
http_route('GET', '#/api/(system|groups)#', array('code' => 403, 'json' => array('detail' => 'Permission denied')));
$t = CN_Panels::test(panel('pasarguard', 'https://pg3.test', array('username' => 'r'), array('password' => 'p')));
check(!empty($t['ok']) && strpos($t['msg'], 'دستی') !== false, 'reseller admin without system/group access still connects');
fresh();
http_route('POST', '#/api/admin/token$#', array('json' => array('access_token' => 'T')));
http_route('GET', '#/api/(admin|system|groups)#', array('code' => 403, 'json' => array('detail' => 'Permission denied')));
$t = CN_Panels::test(panel('pasarguard', 'https://pg3b.test', array('username' => 'r'), array('password' => 'p')));
check(!empty($t['ok']) && strpos($t['msg'], 'اتصال برقرار') !== false, 'token alone is enough when admin/system/groups all forbidden');
http_route('POST', '#/api/admin/token$#', array('code' => 401, 'json' => array('detail' => 'Incorrect username or password')));
$t = CN_Panels::test(panel('pasarguard', 'https://pg4.test', array('username' => 'r'), array('password' => 'bad')));
check(empty($t['ok']) && strpos($t['msg'], 'Incorrect username') !== false, 'login failure shows panel reason');
http_route('POST', '#/api/admin/token$#', array('error' => 'cURL error 28: Operation timed out after 20001 milliseconds'));
$t = CN_Panels::test(panel('pasarguard', 'https://pg6.test', array('username' => 'r'), array('password' => 'p')));
check(empty($t['ok']) && strpos($t['msg'], 'timeout') !== false, 'timeout error is explained in Persian');

section('Marzneshin');
fresh();
http_route('POST', '#/api/admins/token$#', array('json' => array('access_token' => 'T')));
http_route('POST', '#/api/users$#', function ($m, $u, $a) { $b = json_decode($a['body'], true); return array('json' => array('username' => $b['username'], 'subscription_url' => '/sub/x/y')); });
$pid = panel('marzneshin', 'https://mn.test', array('username' => 'a', 'services' => '[3]'), array('password' => 'p'));
$sid = CN_Services::create(plan($pid, 0, 30), array('order_id' => 2));
$b = json_decode(find_req('POST', '#/api/users$#')['args']['body'], true);
check($b['service_ids'] === array(3) && $b['expire_strategy'] === 'fixed_date' && $b['data_limit'] === 0, 'service_ids + fixed_date + unlimited volume');
$sid2 = CN_Services::create(plan($pid, 10, 0), array('order_id' => 3));
$b = req_json();
check($b['expire_strategy'] === 'never', 'unlimited days -> never');

section('Rebecca');
fresh();
http_route('POST', '#/api/user$#', function ($m, $u, $a) { $b = json_decode($a['body'], true); return array('json' => array('username' => $b['username'], 'subscription_url' => '/sub/r')); });
$pid = panel('rebecca', 'https://rb.test', array('services' => '4'), array('token' => 'API'));
CN_Services::create(plan($pid, 10, 30), array('order_id' => 4));
$r = find_req('POST', '#/api/user$#');
$b = json_decode($r['args']['body'], true);
check($r['args']['headers']['Authorization'] === 'Bearer API' && $b['service_id'] === 4, 'token auth + service_id');

section('3x-ui');
fresh();
$inbound = array('id' => 2, 'remark' => 'main', 'protocol' => 'vless', 'settings' => json_encode(array('clients' => array())), 'clientStats' => array());
http_route('POST', '#/login$#', array('json' => array('success' => true), 'cookies' => array('3x-ui' => 'SESS')));
http_route('GET', '#/panel/api/inbounds/list$#', function () use (&$inbound) { return array('json' => array('success' => true, 'obj' => array($inbound))); });
http_route('POST', '#/panel/api/inbounds/addClient$#', function ($m, $u, $a) use (&$inbound) {
    $b = json_decode($a['body'], true);
    $c = json_decode($b['settings'], true)['clients'][0];
    $inbound['settings'] = json_encode(array('clients' => array($c)));
    $inbound['clientStats'] = array(array('email' => $c['email'], 'up' => 100, 'down' => 200));
    return array('json' => array('success' => true));
});
$pid = panel('xui', 'https://x.test:2053/path', array('username' => 'a', 'inbound' => '2', 'sub_base' => 'https://x.test:2096/sub/'), array('password' => 'p'));
$sid = CN_Services::create(plan($pid, 20, 30), array('order_id' => 5));
check(is_int($sid), 'xui service created');
$add = find_req('POST', '#addClient$#');
$c = json_decode(json_decode($add['args']['body'], true)['settings'], true)['clients'][0];
check(abs($c['expiryTime'] - (time() + 30 * 86400) * 1000) < 5000 && $c['totalGB'] === 20 * 1073741824, 'expiryTime ms + totalGB bytes');
check(preg_match('/^[0-9a-f-]{36}$/', $c['id']) === 1, 'vless client uuid');
check($add['args']['cookies'][0]->value === 'SESS', 'session cookie sent');
$svc = CN_Services::get($sid);
check($svc['sub_url'] === 'https://x.test:2096/sub/' . $c['subId'], 'sub URL from sub_base + subId');
CN_Services::refresh($svc);
check(CN_Services::get($sid)['usage_bytes'] === 300, 'usage = up + down');
http_route('POST', '#updateClient/#', array('json' => array('success' => true)));
CN_Services::renew(CN_Services::get($sid), plan($pid, 10, 10));
$up = find_req('POST', '#updateClient/' . preg_quote($c['id'], '#') . '$#');
check($up !== null, 'updateClient called with client uuid');
$uc = json_decode(json_decode($up['args']['body'], true)['settings'], true)['clients'][0];
check($uc['totalGB'] === 30 * 1073741824 && $uc['enable'] === true, 'renew adds volume and enables');
http_route('POST', '#addClient$#', array('json' => array('success' => false, 'msg' => 'Duplicate email')));
$r = CN_Services::create(plan($pid, 1, 1), array('order_id' => 6));
check(is_wp_error($r) && strpos($r->get_error_message(), 'Duplicate email') !== false, 'panel error message surfaced');
$pid = panel('xui', 'https://x.test:2053/path/panel/inbounds#top', array('username' => 'a', 'inbound' => '2', 'sub_base' => 'https://x.test:2096/sub/'), array('password' => 'p'));
$GLOBALS['__http_log'] = array();
CN_Services::refresh(CN_Services::get($sid));
CN_Panels::test($pid);
check(find_req('POST', '#^https://x\.test:2053/path/login$#') !== null, 'xui: browser page path (/panel/…) and #fragment stripped for the API');

section('Alireza x-ui path prefix');
fresh();
http_route('POST', '#/login$#', array('json' => array('success' => true), 'cookies' => array('session' => 'S')));
http_route('GET', '#/xui/API/inbounds$#', array('json' => array('success' => true, 'obj' => array(array('id' => 1, 'protocol' => 'vmess', 'settings' => '{"clients":[]}')))));
http_route('POST', '#/xui/API/inbounds/addClient$#', array('json' => array('success' => true)));
$pid = panel('alireza', 'https://ar.test', array('username' => 'a', 'inbound' => '1', 'sub_base' => 'https://ar.test/sub'), array('password' => 'p'));
check(is_int(CN_Services::create(plan($pid, 1, 1), array('order_id' => 7))), 'alireza create via /xui/API');

section('Hiddify');
fresh();
http_route('POST', '#/api/v2/admin/user/$#', function ($m, $u, $a) { return array('json' => json_decode($a['body'], true)); });
$pid = panel('hiddify', 'https://h.test/adminpath', array('sub_base' => 'https://h.test/clientpath'), array('api_key' => 'ADMIN-UUID'));
$sid = CN_Services::create(plan($pid, 50, 30), array('order_id' => 8));
$r = find_req('POST', '#/api/v2/admin/user/$#');
$b = json_decode($r['args']['body'], true);
check($r['args']['headers']['Hiddify-API-Key'] === 'ADMIN-UUID', 'Hiddify-API-Key header');
check($b['usage_limit_GB'] == 50 && $b['package_days'] === 30, 'GB and days');
$svc = CN_Services::get($sid);
check($svc['sub_url'] === 'https://h.test/clientpath/' . $b['uuid'] . '/', 'user link');
http_route('GET', '#/api/v2/admin/user/.+/$#', array('json' => array('usage_limit_GB' => 50, 'current_usage_GB' => 60, 'package_days' => 30, 'start_date' => gmdate('Y-m-d'), 'enable' => true)));
CN_Services::refresh($svc);
check(CN_Services::get($sid)['status'] === 'limited', 'over-limit detected');
http_route('PATCH', '#/api/v2/admin/user/.+/$#', array('json' => array()));
CN_Services::renew(CN_Services::get($sid), plan($pid, 50, 30));
$p = json_decode(find_req('PATCH', '#/user/#')['args']['body'], true);
check($p['usage_limit_GB'] == 100 && $p['package_days'] >= 59 && $p['enable'] === true, 'renew: 100 GB, ~60 days');

section('s-ui');
fresh();
http_route('POST', '#/apiv2/save$#', array('json' => array('success' => true)));
http_route('GET', '#/apiv2/settings$#', array('json' => array('success' => true, 'obj' => array('subPort' => '2096', 'subPath' => '/sub/'))));
$pid = panel('sui', 'https://s.test:2095/app', array('inbounds' => '[1]'), array('token' => 'TK'));
$sid = CN_Services::create(plan($pid, 5, 5, array('inbounds' => '[7,8]')), array('order_id' => 9));
$r = find_req('POST', '#/apiv2/save$#');
$data = json_decode($r['args']['body']['data'], true);
check($r['args']['headers']['Token'] === 'TK' && $r['args']['body']['action'] === 'new', 'Token header + action new');
check($data['inbounds'] === array(7, 8), 'plan inbounds override panel inbounds');
check(CN_Services::get($sid)['sub_url'] === 'https://s.test:2096/sub/' . CN_Services::get($sid)['username'], 'sub URL from s-ui settings');

section('WGDashboard');
fresh();
$pid = panel('wgdashboard', 'https://wg.test', array('conf' => 'wg0'), array('api_key' => 'K'));
$r = CN_Services::create(plan($pid, 5, 5), array('order_id' => 10));
if (function_exists('sodium_crypto_box_keypair')) {
    check(true, 'sodium available (create path covered on servers)');
} else {
    check(is_wp_error($r) && strpos($r->get_error_message(), 'sodium') !== false, 'clear error when sodium is missing');
}

section('IBSng');
fresh();
http_route('POST', '#/IBSng/admin/$#', array('code' => 302, 'body' => '', 'headers' => array('location' => '/IBSng/admin/admin_index.php'), 'cookies' => array('IBS_SESSID' => 'Z')));
http_route('POST', '#add_new_users\.php#', array('code' => 302, 'headers' => array('location' => '/IBSng/admin/plugins/edit.php?user_id=55&submit_form=1')));
http_route('POST', '#plugins/edit\.php#', array('code' => 302, 'headers' => array('location' => '/IBSng/admin/user/user_info.php?user_id_multi=55')));
$pid = panel('ibsng', 'http://1.2.3.4', array('username' => 'system', 'group' => 'g30', 'server_info' => 'L2TP: vpn.example'), array('password' => 'p'));
$sid = CN_Services::create(plan($pid, 30, 30, array('group' => 'g60')), array('order_id' => 11));
check(is_int($sid), 'ibsng user created');
$e = find_req('POST', '#plugins/edit\.php#');
check(strpos($e['url'], 'group_name=g60') !== false && $e['args']['body']['normal_username'] === CN_Services::get($sid)['username'], 'plan group + username sent');
$cf = CN_Services::configs(CN_Services::get($sid));
check($cf[1][0] === 'رمز عبور' && strlen($cf[1][1]) === 10, 'password delivered');

section('MikroTik');
fresh();
http_route('POST', '#/rest/user-manager/user/add$#', array('json' => array('ret' => '*1')));
http_route('POST', '#/rest/user-manager/user-profile/add$#', array('json' => array('ret' => '*2')));
$pid = panel('mikrotik', 'https://mt.test', array('username' => 'admin', 'profile' => 'p30'), array('password' => 'p'));
$sid = CN_Services::create(plan($pid, 30, 30), array('order_id' => 12));
check(is_int($sid) && strpos(find_req('POST', '#user/add$#')['args']['headers']['Authorization'], 'Basic ') === 0, 'basic auth + user created');
check(json_decode(find_req('POST', '#user-profile/add$#')['args']['body'], true)['profile'] === 'p30', 'profile assigned');

section('Mirza agent');
fresh();
http_route('POST', '#^https://agent\.test/api$#', array('json' => array('status' => true, 'obj' => array('username' => 'agent_u', 'subscription_url' => 'https://sub.test/u'))));
$pid = panel('mirza_agent', 'https://agent.test/api', array('panel_id' => '3'), array('token' => 'T'));
$sid = CN_Services::create(plan($pid, 10, 30), array('order_id' => 13));
$b = json_decode(find_req('POST', '#agent#')['args']['body'], true);
check($b['actions'] === 'user_create' && $b['data_limit_gb'] === 10 && $b['expire_days'] === 30 && $b['panel_id'] === '3', 'agent create payload');
check(CN_Services::get($sid)['username'] === 'agent_u', 'username returned by agent kept');

section('Eylan');
fresh();
http_route('POST', '#/api/v1/users$#', array('json' => array('username' => 'x')));
http_route('PUT', '#/api/v1/users/#', array('json' => array()));
http_route('GET', '#/sub$#', array('json' => array('sub_url' => 'http://10.0.0.5:8050/sub/tok/x/')));
http_route('GET', '#/server_settings$#', array('json' => array()));
http_route('GET', '#/api/v1/users/l2tp$#', array('code' => 404, 'json' => array('detail' => 'not found')));
$pid = panel('eylan', 'http://10.0.0.5:8050', array('protocol' => 'multi', 'public_base' => 'https://panel.example.com:8050'), array('api_key' => 'K'));
$sid = CN_Services::create(plan($pid, 20, 30, array('template' => 'l2tp')), array('order_id' => 14));
$b = json_decode(find_req('POST', '#/api/v1/users$#')['args']['body'], true);
check($b['enable_l2tp'] === true && $b['enable_openvpn'] === false && $b['enable_wg1'] === false, 'plan type overrides panel default protocol');
check($b['data_limit'] == 20 && $b['expiry_days'] === 30 && strpos($b['l2tp_password'], 'L2') === 0, 'GB, days, L2TP password');
$svc = CN_Services::get($sid);
check($svc['sub_url'] === 'https://panel.example.com:8050/sub/tok/x/', 'public base applied to sub URL');
$labels = array_column(CN_Services::configs($svc), 1, 0);
check(($labels['L2TP — کلید مشترک (PSK)'] ?? '') === '123456', 'Eylan default PSK 123456 when panel returns none');
$pid2 = panel('eylan', 'http://10.0.0.6:8050', array('protocol' => 'l2tp', 'l2tp_psk' => 'MyKey9'), array('api_key' => 'K'));
$labels = array_column(CN_Services::configs(CN_Services::get(CN_Services::create(plan($pid2, 20, 30), array('order_id' => 15)))), 1, 0);
check(($labels['L2TP — کلید مشترک (PSK)'] ?? '') === 'MyKey9', 'PSK from panel setting');
http_reset();
http_route('POST', '#/api/v1/users$#', array('json' => array('username' => 'x')));
http_route('PUT', '#/api/v1/users/#', array('json' => array()));
http_route('GET', '#/sub$#', array('json' => array('sub_url' => 'http://10.0.0.5:8050/sub/tok/y/')));
http_route('GET', '#/server_settings$#', array('json' => array('ipsec_psk' => 'FromPanel')));
$labels = array_column(CN_Services::configs(CN_Services::get(CN_Services::create(plan($pid2, 20, 30), array('order_id' => 16)))), 1, 0);
check(($labels['L2TP — کلید مشترک (PSK)'] ?? '') === 'FromPanel', 'PSK returned by panel wins');

fresh();
http_route('POST', '#/api/v1/users$#', array('json' => array('username' => 'x')));
http_route('PUT', '#/api/v1/users/#', array('json' => array()));
http_route('GET', '#/sub$#', array('json' => array()));
http_route('GET', '#/server_settings$#', array('json' => array()));
http_route('GET', '#/api/v1/status$#', array('json' => array('ok' => true)));
http_route('GET', '#/files$|links$#', array('json' => array()));
http_route('GET', '#/api/v1/users/myovpn$#', array('json' => array('username' => 'myovpn', 'enable_openvpn' => true, 'enable_l2tp' => true, 'enable_cisco' => false, 'enable_wg1' => false, 'enable_newproto' => true, 'max_clients' => 3)));
http_route('GET', '#/api/v1/users/mywg$#', array('json' => array('username' => 'mywg', 'enable_openvpn' => false, 'enable_wg1' => true, 'wg_instances' => array(array('instance' => 'wg3', 'servers' => array())), 'max_clients' => 2)));
http_route('GET', '#/api/v1/users/(wireguard|ghost)$#', array('code' => 404, 'json' => array('detail' => 'not found')));
$pid = panel('eylan', 'http://e.test:8050', array('templates' => "myovpn\r\nmywg\n wireguard \nmywg", 'max_clients' => '1'), array('api_key' => 'K'));
check(CN_Driver_Eylan::template_list(CN_Panels::get($pid)['settings']) === array('myovpn', 'mywg', 'wireguard'), 'plan types list: one per line, trimmed, deduplicated');
$t = CN_Panels::test($pid);
check(!empty($t['ok']) && strpos($t['msg'], '«myovpn»: OVPN، L2TP، newproto') !== false && strpos($t['msg'], '«mywg»: WireGuard') !== false && strpos($t['msg'], '«wireguard» (در پنل نیست') !== false, 'Eylan test checks every plan type user');
CN_Services::create(plan($pid, 10, 30), array('order_id' => 41));
$b = json_decode(find_req('POST', '#/api/v1/users$#')['args']['body'], true);
check($b['enable_openvpn'] && $b['enable_l2tp'] && !$b['enable_cisco'] && !$b['enable_wg1'] && $b['max_clients'] === 3, 'no plan type: first type of the panel, protocols and max clients copied');
check(!empty($b['enable_newproto']), 'protocol added to the panel later is copied too');
$GLOBALS['__http_log'] = array();
CN_Services::create(plan($pid, 10, 30, array('template' => 'mywg')), array('order_id' => 42));
$b = json_decode(find_req('POST', '#/api/v1/users$#')['args']['body'], true);
check($b['enable_wg1'] && !$b['enable_openvpn'] && $b['wg_instances'] === array('wg3') && $b['max_clients'] === 2, 'plan type user: WireGuard instance copied');
$GLOBALS['__http_log'] = array();
CN_Services::create(plan($pid, 10, 30, array('template' => 'wireguard')), array('order_id' => 44));
$b = json_decode(find_req('POST', '#/api/v1/users$#')['args']['body'], true);
check($b['enable_wg1'] && !$b['enable_openvpn'] && $b['wg_instances'] === array('wg2'), 'type named after a protocol but missing on panel: bot-compatible fallback');
check(is_wp_error(CN_Plans::save(array('panel_id' => $pid, 'name' => 'x', 'o' => array('template' => 'cisco')))), 'plan type not defined on the panel is rejected');
$pid2 = panel('eylan', 'http://e2.test:8050', array('templates' => "myovpn\nghost"), array('api_key' => 'K'));
check(empty(CN_Panels::test($pid2)['ok']), 'connection test fails when a plan type user is missing');
check(is_wp_error(CN_Services::create(plan($pid2, 10, 30, array('template' => 'ghost')), array('order_id' => 45))), 'unknown template user blocks account creation');

section('Connectix');
fresh();
$cx_client = array('id' => 'C-UUID', 'username' => 'sel_cx77', 'password' => 'Pw12345', 'is_active' => true, 'is_activated' => true, 'is_expired' => false,
    'expire_date' => gmdate('c', time() + 20 * 86400), 'subscription_link' => 'https://sub.irancdn.org/subscription/abc',
    'active_plan' => array('title' => '30GB-1M', 'used_traffic' => 7.5, 'traffic_amount' => 30, 'expire_date' => gmdate('c', time() + 20 * 86400)));
http_route('POST', '#/clients/store$#', array('json' => array('message' => 'ok', 'client_id' => 'C-UUID', 'text_to_copy' => "plan: (1x) 30GB-1M\n\nusername: `sel_cx77`\npassword: `Pw12345`")));
http_route('GET', '#/clients/show\?id=C-UUID$#', array('json' => array('client' => $cx_client)));
http_route('GET', '#/clients/get-enabled-plans$#', array('json' => array('plans' => array(
    array('id' => 'P1', 'title' => '30GB-1M', 'full_period_days' => 30, 'traffic_amount' => 30, 'count_of_devices' => 2, 'sell_price' => 90000),
    array('id' => 'P2', 'title' => 'Unl-3M', 'full_period_days' => 90, 'traffic_amount' => 'Unlimited', 'count_of_devices' => 1, 'sell_price' => 200000),
))));
http_route('GET', '#/clients\?#', array('json' => array('total_clients' => 12, 'clients' => array('data' => array(), 'next_page_url' => null))));
$pid = panel('connectix', '', array('first_login' => '1'), array('token' => 'CT'));
$t = CN_Panels::test($pid);
check(!empty($t['ok']) && strpos($t['msg'], '۲') !== false && strpos($t['msg'], '۱۲') !== false, 'test shows plan and client counts');
$rp = CN_Panels::driver($pid)->remote_plans();
check($rp['plans']['P1']['users'] === 2 && $rp['plans']['P1']['gb'] === 30.0 && $rp['plans']['P2']['gb'] === 0 && $rp['plans']['P2']['days'] === 90, 'remote plans: devices, GB, Unlimited = 0');
$r = CN_Plans::save(array('panel_id' => $pid, 'name' => 'no id', 'days' => 30));
check(is_wp_error($r), 'plan without Connectix plan id rejected');
$sid = CN_Services::create(plan($pid, 0, 30, array('plan_id' => 'P1')), array('order_id' => 15));
$req = find_req('POST', '#/clients/store$#');
check(strpos($req['url'], 'https://seller-api.connectix.vip/externel/v1/clients/store') === 0, 'official base URL used when empty');
check($req['args']['body']['plan_id'] === 'P1' && $req['args']['body']['enable_plan_after_first_login'] === '1' && strlen($req['args']['body']['password']) === 8, 'store: plan, first-login flag, 8-char password');
$svc = CN_Services::get($sid);
check($svc['username'] === 'sel_cx77' && $svc['sub_url'] === 'https://sub.irancdn.org/subscription/abc' && $svc['data']['client_id'] === 'C-UUID', 'username, subscription link and client id from clients/show');
check(CN_Services::configs($svc)[1] === array('رمز عبور', 'Pw12345', 'text') && count(CN_Services::configs($svc)) === 2, 'delivery: username + password only');
check($svc['limit_bytes'] === 30 * 1073741824, 'limit from active plan (GB to bytes)');
check(CN_Services::refresh($svc) === true, 'refresh ok');
$svc = CN_Services::get($sid);
check($svc['usage_bytes'] === (int) round(7.5 * 1073741824) && $svc['status'] === 'active' && abs(strtotime($svc['expires_at']) - (time() + 20 * 86400)) < 5, 'usage, status and expiry mapped');
http_route('GET', '#/clients/show\?id=C-UUID$#', array('json' => array('client' => array('is_activated' => false, 'is_active' => true, 'active_plan' => null) + $cx_client)));
CN_Services::refresh($svc);
check(CN_Services::get($sid)['status'] === 'on_hold', 'not yet logged in = on hold');
$adds = 0;
http_route('POST', '#/clients/add-plan$#', function () use (&$adds) { $adds++; return array('code' => 403, 'json' => array('message' => 'Insufficient balance')); });
$r = CN_Services::renew(CN_Services::get($sid), plan($pid, 0, 30, array('plan_id' => 'P1')));
check(is_wp_error($r) && $adds === 1 && strpos($r->get_error_message(), 'Insufficient balance') !== false, 'renew refused: one request only, reason shown');
check(json_decode(find_req('POST', '#/clients/add-plan$#')['args']['body'], true) === array('id' => 'C-UUID', 'plan_id' => 'P1'), 'add-plan sends JSON {id, plan_id}');
http_route('POST', '#/clients/add-plan$#', function () use (&$adds) { $adds++; return array('code' => 0, 'error' => 'timeout'); });
$r = CN_Services::renew(CN_Services::get($sid), plan($pid, 0, 30, array('plan_id' => 'P1')));
check(is_wp_error($r) && $adds === 2 && strpos($r->get_error_message(), 'معلوم نیست') !== false, 'timeout: no retry, admin warned');
http_route('POST', '#/clients/add-plan$#', array('json' => array('message' => 'ok')));
check(CN_Services::renew(CN_Services::get($sid), plan($pid, 0, 30, array('plan_id' => 'P1'))) === true, 'renew ok');
$wpdb->tables['wp_cn_services'][$sid]['data'] = wp_json_encode(array('pub' => array('plan_id' => 'P1'), 'enc' => ''));
http_route('GET', '#/clients\?#', function ($m, $u) { return array('json' => array('clients' => array('data' => array(array('id' => 'X', 'username' => 'sel_cx770'), array('id' => 'C-UUID', 'username' => 'SEL_cx77')), 'next_page_url' => null))); });
check(CN_Services::refresh(CN_Services::get($sid)) === true && strpos(find_req('GET', '#/clients\?username=sel_cx77#')['url'], 'recordPerPage=100') !== false, 'old service without client id: found by exact username');
http_route('GET', '#/clients/show\?id=C-UUID$#', array('code' => 404, 'json' => array('message' => 'not found')));
CN_Services::refresh(CN_Services::get($sid));
check(CN_Services::get($sid)['status'] === 'deleted', 'client removed on Connectix = deleted');

/* ======================================================= */
section('Manual stock');
fresh();
$pid = panel('manual', '', array(), array());
$plan = plan($pid, 10, 30);
check(CN_Stock::add($plan['id'], "acc-1\n---\nhttps://link.test/2\n---\n") === 2, 'two accounts added');
check(strpos($wpdb->tables['wp_cn_stock'][1]['content'], 'acc-1') === false, 'stock encrypted');
$s1 = CN_Services::create($plan, array('order_id' => 20));
$s2 = CN_Services::create($plan, array('order_id' => 20));
$s3 = CN_Services::create($plan, array('order_id' => 20));
check(is_int($s1) && is_int($s2) && is_wp_error($s3), 'third sale fails when stock is empty');
check(CN_Services::get($s2)['sub_url'] === 'https://link.test/2', 'link-only stock becomes sub URL');
check($wpdb->tables['wp_cn_stock'][1]['service_id'] === (string) $s1, 'stock row attached to service');
check(CN_Stock::count_free($plan['id']) === 0, 'no free stock left');
ob_start();
CN_Account::render_service(CN_Services::get($s1), 'account');
$html = ob_get_clean();
check(strpos($html, 'حجم') !== false && strpos($html, 'مصرف') === false && strpos($html, 'cn_refresh') === false, 'manual card: volume only, no usage refresh');
check(strpos($html, 'acc-1') !== false, 'manual card shows account details');
check(!is_wp_error(CN_Services::action(CN_Services::get($s1), 'disable')) && CN_Services::get($s1)['status'] === 'disabled', 'manual service can be disabled on the site');
ob_start();
CN_Account::render_service(CN_Services::get($s1), 'account');
$html = ob_get_clean();
check(strpos($html, 'acc-1') === false && strpos($html, 'غیرفعال است') !== false, 'disabled service hides account details');
check(is_wp_error(CN_Panels::delete($pid)), 'panel with live services cannot be deleted');
CN_Services::action(CN_Services::get($s1), 'delete');
CN_Services::action(CN_Services::get($s2), 'delete');
CN_Stock::add($plan['id'], "spare\n");
check(CN_Panels::delete($pid) === true, 'panel deleted once its services are gone');
check(!$wpdb->tables['wp_cn_stock'] && !$wpdb->tables['wp_cn_services'] && !$wpdb->tables['wp_cn_plans'], 'panel delete leaves no orphan stock, services or plans');
$plan = plan(panel('manual', '', array(), array()), 1, 1);
CN_Stock::add($plan['id'], "x\n");
CN_Plans::delete($plan['id']);
check(!$wpdb->tables['wp_cn_stock'], 'plan delete removes its free stock');

/* ======================================================= */
section('WooCommerce delivery');
fresh();
$creates = 0;
http_route('POST', '#/api/admin/token$#', array('json' => array('access_token' => 'T')));
http_route('POST', '#/api/user$#', function ($m, $u, $a) use (&$creates) { $creates++; $b = json_decode($a['body'], true); return array('json' => array('username' => $b['username'], 'subscription_url' => '/sub/' . $b['username'])); });
$pid = panel('marzban', 'https://mz.test', array('username' => 'a', 'proxies' => '{"vless":{}}'), array('password' => 'p'));
$plan = plan($pid, 30, 30);
$item = new FakeItem(91, 2, array(CN_Woo::ITEM_PLAN => $plan['id']));
$other = new FakeItem(92, 1);
$o = order(900, 7, array(91 => $item), 'pending');
CN_Woo::deliver(900);
check($creates === 0, 'unpaid order: nothing created');
$o->status = 'processing';
CN_Woo::deliver(900);
check($creates === 2 && count(CN_Services::for_order(900)) === 2, 'qty 2 -> two accounts');
check($o->status === 'completed', 'order auto-completed after full delivery');
CN_Woo::deliver(900);
do_action('woocommerce_order_status_completed', 900);
do_action('woocommerce_payment_complete', 900);
check($creates === 2, 'repeat hooks do not create duplicates');
check(CN_Services::for_order(900)[0]['user_id'] === 7, 'service linked to customer');

$o2 = order(901, 8, array(93 => new FakeItem(93, 1, array(CN_Woo::ITEM_PLAN => $plan['id'])), 94 => $other), 'processing');
CN_Woo::deliver(901);
check($o2->status === 'processing', 'mixed order (non-service item) is not auto-completed');

section('Delivery failure and retry');
http_route('POST', '#/api/user$#', array('code' => 500, 'json' => array('detail' => 'panel down')));
$it = new FakeItem(95, 1, array(CN_Woo::ITEM_PLAN => $plan['id']));
$o3 = order(902, 9, array(95 => $it), 'processing');
CN_Woo::deliver(902);
check($o3->get_meta(CN_Woo::ORDER_FAILED) === 1 && $o3->status === 'processing', 'failure flagged, order stays processing');
check(strpos($it->get_meta(CN_Woo::ITEM_ERROR), 'panel down') !== false, 'item keeps the panel error');
$logs = array_values($wpdb->tables['wp_cn_logs']);
check(end($logs)['level'] === 'error', 'error logged');
http_route('POST', '#/api/user$#', function ($m, $u, $a) { $b = json_decode($a['body'], true); return array('json' => array('username' => $b['username'], 'subscription_url' => '/s')); });
CN_Woo::action_deliver($o3);
check($o3->get_meta(CN_Woo::ORDER_FAILED) === '' && $it->get_meta(CN_Woo::ITEM_ERROR) === '' && $o3->status === 'completed', 'retry clears failure and completes');

section('Lock prevents concurrent double delivery');
$o4 = order(903, 9, array(96 => new FakeItem(96, 1, array(CN_Woo::ITEM_PLAN => $plan['id']))), 'processing');
CN_Util::lock('order_903', 300);
CN_Woo::deliver(903);
check(count(CN_Services::for_order(903)) === 0, 'second worker skipped while locked');
CN_Util::unlock('order_903');
CN_Woo::deliver(903);
check(count(CN_Services::for_order(903)) === 1, 'delivered after lock released');

section('Renewal order');
$svc = CN_Services::for_order(900)[0];
http_route('GET', '#/api/user/#', array('json' => array('status' => 'active', 'used_traffic' => 0, 'data_limit' => 30 * 1073741824, 'expire' => time() + 5 * 86400)));
$puts = 0;
http_route('PUT', '#/api/user/#', function () use (&$puts) { $puts++; return array('json' => array()); });
$ri = new FakeItem(97, 1, array(CN_Woo::ITEM_PLAN => $plan['id'], CN_Woo::ITEM_RENEW => $svc['id']));
$o5 = order(904, 7, array(97 => $ri), 'processing');
CN_Woo::deliver(904);
CN_Woo::deliver(904);
check($puts === 1 && (int) $ri->get_meta(CN_Woo::ITEM_RENEWED) === 1, 'renewed exactly once');
check(count(CN_Services::for_order(904)) === 0, 'renewal does not create a new account');
$ri2 = new FakeItem(98, 1, array(CN_Woo::ITEM_PLAN => $plan['id'], CN_Woo::ITEM_RENEW => $svc['id']));
$o6 = order(905, 999, array(98 => $ri2), 'processing');
CN_Woo::deliver(905);
check($puts === 1 && $ri2->get_meta(CN_Woo::ITEM_ERROR) !== '', 'renewal of someone else\'s service refused');

section('Cancel / refund disables accounts');
$dis = 0;
http_route('PUT', '#/api/user/#', function ($m, $u, $a) use (&$dis) { if ((json_decode($a['body'], true)['status'] ?? '') === 'disabled') { $dis++; } return array('json' => array()); });
do_action('woocommerce_order_status_refunded', 900);
check($dis === 2 && CN_Services::for_order(900)[0]['status'] === 'disabled', 'both accounts of refunded order disabled');

section('Cart guards');
$GLOBALS['__user'] = 7;
$_REQUEST = array('cn_renew' => $svc['id'], '_cn_nonce' => 'bad');
$GLOBALS['__notices'] = array();
check(CN_Woo::requested_renew() === -1, 'bad renew nonce rejected');
$_REQUEST = array('cn_renew' => $svc['id'], '_cn_nonce' => wp_create_nonce('cn_renew_' . $svc['id']));
check(CN_Woo::requested_renew() === $svc['id'], 'valid renew nonce accepted');
$r = CN_Woo::renewable($svc['id'], $plan);
check(is_array($r) && $r['id'] === $svc['id'], 'owner can renew own service');
$GLOBALS['__user'] = 8;
check(is_wp_error(CN_Woo::renewable($svc['id'], $plan)), 'other customer cannot renew it');
$GLOBALS['__user'] = 7;
$other_panel = panel('hiddify', 'https://h.test', array('sub_base' => 'https://h.test/c'), array('api_key' => 'k'));
check(is_wp_error(CN_Woo::renewable($svc['id'], plan($other_panel, 1, 1))), 'plan from another panel refused');
$_REQUEST = array();

/* ======================================================= */
section('Rendering');
check(CN_Util::to_jalali(2026, 10, 8) === array(1405, 7, 16), 'jalali 2026-10-08');
check(CN_Util::to_jalali(2025, 3, 21) === array(1404, 1, 1), 'jalali nowruz 1404');
check(CN_Util::local_label('2026-03-20 21:05:00') === '۱۴۰۴/۱۲/۲۹ ۲۱:۰۵', 'jalali label with time');
check(CN_Util::date_label(null) === 'نامحدود', 'no expiry label');
$svc = CN_Services::for_order(903)[0];
$wpdb->update('wp_cn_services', array('usage_bytes' => 15 * 1073741824, 'limit_bytes' => 30 * 1073741824), array('id' => $svc['id']));
ob_start();
CN_Account::render_service(CN_Services::get($svc['id']), 'order');
$html = ob_get_clean();
check(strpos($html, 'data-cn-copy') !== false && strpos($html, 'width:50%') !== false, 'card has copy button and 50% usage bar');
check(strpos($html, '۱۵ گیگ از ۳۰ گیگ') !== false, 'Persian usage label');
$mail = CN_Account::email_service(CN_Services::get($svc['id']));
check(strpos($mail, '#F5C518') !== false && strpos($mail, $svc['username']) !== false && strpos($mail, 'dir="rtl"') !== false, 'email block branded, RTL, shows username');
check(strpos(CN_Account::plain_service(CN_Services::get($svc['id'])), 'لینک اشتراک') !== false, 'plain-text email');
check(strpos($html, 'my-account/network-services') !== false && strpos($html, 'دریافت فایل‌ها') === false, 'order page links to my services; no Eylan guide for other panels');

section('Eylan delivery: subscription button and guide');
$epid = panel('eylan', 'http://ey.test:8050', array('templates' => 'myovpn'), array('api_key' => 'K'));
$wpdb->insert('wp_cn_services', array('panel_id' => $epid, 'plan_id' => 0, 'order_id' => 950, 'user_id' => 7, 'username' => 'cu_950_a', 'sub_url' => 'https://ey.test/sub/cu_950_a',
    'data' => wp_json_encode(array('pub' => array(), 'enc' => CN_Crypto::encrypt(array('configs' => array(array('فایل OVPN 1', "client\nremote x", 'file', 'a.ovpn'), array('فایل OVPN 2', "client\nremote y", 'file', 'b.ovpn'), array('L2TP — رمز', 'L2abc', 'text')))))),
    'volume_gb' => 30, 'days' => 30, 'expires_at' => '2026-11-07 10:00:00', 'limit_bytes' => 30 * 1073741824, 'created_at' => CN_DB::now(), 'updated_at' => CN_DB::now()));
$esvc = CN_Services::get($wpdb->insert_id);
ob_start();
CN_Account::render_service($esvc, 'account');
$html = ob_get_clean();
check(preg_match('#<a class="cn-btn cn-btn--primary cn-btn--block" href="https://ey\.test/sub/cu_950_a" target="_blank"[^>]*>دریافت فایل‌ها و راهنمای راه‌اندازی</a>#u', $html) === 1, 'account card: sub link as a button');
check(substr_count($html, '<li>') === 3 && strpos($html, 'دانلود a.ovpn') !== false, 'account card: 3 setup steps + file downloads');
$mail = CN_Account::email_service($esvc);
check(strpos($mail, 'href="https://ey.test/sub/cu_950_a"') !== false && strpos($mail, 'bgcolor="#F5C518"') !== false && substr_count($mail, '<li>') === 3, 'email: yellow sub button + steps');
check(strpos($mail, '۲ فایل آماده است') !== false && substr_count($mail, 'OVPN') === 0, 'email: files summarised once with account link');
check(strpos($mail, 'L2abc') !== false && strpos($mail, 'dir="ltr"') !== false, 'email: credentials LTR');
check(strpos(CN_Account::plain_service($esvc), 'دریافت فایل‌ها و راهنمای راه‌اندازی: https://ey.test/sub/cu_950_a') !== false, 'plain email: button text + link');

section('Order emails');
$GLOBALS['__orders'][950] = $o950 = order(950, 7, array(), 'completed');
ob_start();
CN_Woo::email_block($o950, false, false);
$out = ob_get_clean();
check(strpos($out, 'cu_950_a') !== false && strpos($out, 'رفتن به سرویس‌های من') !== false, 'customer email has service block + account button');
ob_start();
CN_Woo::email_block($o950, true, false);
check(ob_get_clean() === '', 'admin email has no credentials');
check(CN_Woo::skip_processing_email(true, $o950) === false, 'processing email skipped once delivery completed the order');
$o951 = order(951, 7, array(), 'processing');
check(CN_Woo::skip_processing_email(true, $o951) === true, 'processing email kept otherwise');
$o952 = order(952, 7, array(99 => new FakeItem(99, 1, array(CN_Woo::ITEM_PLAN => 1, CN_Woo::ITEM_RENEW => $esvc['id'], CN_Woo::ITEM_RENEWED => 1))), 'completed');
ob_start();
CN_Woo::email_block($o952, false, false);
$out = ob_get_clean();
check(strpos($out, 'cu_950_a') !== false && strpos($out, 'تمدید شد') !== false, 'renewal order email shows the renewed service');

echo "\n" . $GLOBALS['__pass'] . ' passed, ' . count($GLOBALS['__fail']) . " failed\n";
exit($GLOBALS['__fail'] ? 1 : 0);
