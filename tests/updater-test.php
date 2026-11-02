<?php
// Mock test: CN_Updater version compare, cache TTLs, asset/zipball, stale drop, folder rename, notice.
define('ABSPATH', '/'); define('CN_DIR', __DIR__ . '/../'); define('CN_VERSION', '1.6.2'); define('CN_FILE', CN_DIR . 'carunit-network.php');
$T = array(); $HTTP = array(); $CALLS = 0; $PASS = 0; $FAIL = 0;
function ok($c, $m) { global $PASS, $FAIL; if ($c) { $PASS++; echo "PASS $m\n"; } else { $FAIL++; echo "FAIL $m\n"; } }
class WP_Error { public $c, $m; function __construct($c, $m = '') { $this->c = $c; $this->m = $m; } }
function is_wp_error($x) { return $x instanceof WP_Error; }
function plugin_basename($f) { return 'carunit-network/carunit-network.php'; }
function get_site_transient($k) { global $T; return $T[$k]['v'] ?? false; }
function set_site_transient($k, $v, $ttl) { global $T; $T[$k] = array('v' => $v, 'ttl' => $ttl); return true; }
function delete_site_transient($k) { global $T; unset($T[$k]); }
function get_option($k, $d = false) { return $d; }
function apply_filters($h, $v) { return $v; }
function wp_remote_get($u, $a) { global $HTTP, $CALLS; $CALLS++; $HTTP['last'] = array($u, $a); return $HTTP['res']; }
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function esc_html($s) { return htmlspecialchars((string) $s); }
function esc_attr($s) { return htmlspecialchars((string) $s); }
function esc_url($s) { return (string) $s; }
function trailingslashit($s) { return rtrim($s, '/') . '/'; }
function untrailingslashit($s) { return rtrim($s, '/'); }
function current_user_can($c) { return true; }
function self_admin_url($p) { return '/wp-admin/' . $p; }
function add_query_arg($k, $v, $u) { return $u . '?' . $k . '=' . $v; }
function wp_nonce_url($u, $a) { return $u . '&_wpnonce=x'; }
class FS { public $moved = array(); public $ok = true; function move($a, $b, $o) { $this->moved[] = array($a, $b); return $this->ok; } }
require __DIR__ . '/../includes/class-cn-updater.php';

$rel = function ($tag, $asset = true) { return array('code' => 200, 'body' => json_encode(array(
    'tag_name' => $tag, 'html_url' => 'https://github.com/x', 'body' => "## x\n- y", 'zipball_url' => 'https://api.github.com/zipball/' . $tag,
    'assets' => $asset ? array(array('name' => 'other.zip', 'browser_download_url' => 'https://dl/other.zip'), array('name' => 'carunit-network.zip', 'browser_download_url' => 'https://dl/carunit-network.zip')) : array()))); };

// version compare
$u = new CN_Updater();
ok(CN_Updater::normalize('v1.7.0') === '1.7.0' && CN_Updater::normalize('V2.0') === '2.0', 'strip leading v/V');
ok($u->is_newer('v1.6.3'), '1.6.3 > 1.6.2');
ok($u->is_newer('1.10.0'), '1.10.0 > 1.6.2 (not string compare)');
ok(!$u->is_newer('v1.6.2'), 'equal not newer');
ok(!$u->is_newer('v1.6.1'), 'older not newer');
ok(!$u->is_newer(''), 'empty not newer');

// asset + endpoint
$HTTP['res'] = $rel('v1.7.0');
$r = $u->get_remote();
ok($HTTP['last'][0] === 'https://api.github.com/repos/silent4time/carunit-network/releases/latest', 'releases/latest endpoint');
ok($r['version'] === '1.7.0' && $r['package'] === 'https://dl/carunit-network.zip', 'exact asset carunit-network.zip picked');
ok(!isset($HTTP['last'][1]['headers']['Authorization']), 'no token -> no auth header');
// cache
ok($T['cn_update_info']['ttl'] === 21600, 'success cached 6h');
$n = $CALLS; $u->get_remote(); ok($CALLS === $n, 'cached: no second request');
$u->get_remote(true); ok($CALLS === $n + 1, 'force bypasses cache');
// failure
delete_site_transient('cn_update_info'); $HTTP['res'] = array('code' => 403, 'body' => '{}');
ok($u->get_remote() === null && $T['cn_update_info']['ttl'] === 3600, 'failure cached 1h');
$n = $CALLS; ok($u->get_remote() === null && $CALLS === $n, 'cached failure no refetch');
// zipball fallback
$p = CN_Updater::parse_release(json_decode($rel('v1.7.0', false)['body'], true));
ok($p['package'] === 'https://api.github.com/zipball/v1.7.0', 'fallback zipball_url');
ok(CN_Updater::parse_release(array('x' => 1)) === null, 'bad payload -> null');
// token
define('CN_GITHUB_TOKEN', 'tok'); delete_site_transient('cn_update_info'); $HTTP['res'] = $rel('v1.7.0'); $u->get_remote();
ok(($HTTP['last'][1]['headers']['Authorization'] ?? '') === 'Bearer tok', 'token constant -> bearer header');

// inject
$b = 'carunit-network/carunit-network.php';
$t = $u->inject_update((object) array('response' => array(), 'no_update' => array()));
ok(isset($t->response[$b]) && $t->response[$b]->new_version === '1.7.0' && $t->response[$b]->slug === 'carunit-network', 'inject update when newer');
set_site_transient('cn_update_info', CN_Updater::parse_release(json_decode($rel('v1.6.2')['body'], true)), 1);
$t = $u->inject_update((object) array('response' => array($b => (object) array('new_version' => '1.7.0')), 'no_update' => array()));
ok(!isset($t->response[$b]) && isset($t->no_update[$b]), 'no update when equal');
// stale
$t = $u->drop_stale_update((object) array('response' => array($b => (object) array('new_version' => '1.6.2'))));
ok(!isset($t->response[$b]), 'stale notice dropped');
$t = $u->drop_stale_update((object) array('response' => array($b => (object) array('new_version' => '1.6.3'))));
ok(isset($t->response[$b]), 'real update kept');
// plugins_api
ok($u->plugins_api(false, 'plugin_information', (object) array('slug' => 'other')) === false, 'plugins_api ignores others');
$info = $u->plugins_api(false, 'plugin_information', (object) array('slug' => 'carunit-network'));
ok($info->version === '1.6.2' && strpos($info->sections['changelog'], 'dir="rtl"') !== false && strpos($info->sections['changelog'], 'Vazirmatn') !== false, 'plugins_api RTL changelog');

// folder rename
$GLOBALS['wp_filesystem'] = $fs = new FS();
$res = $u->fix_source_folder('/tmp/up/silent4time-carunit-network-abc123/', '/tmp/up', null, array('plugin' => $b));
ok($res === '/tmp/up/carunit-network/' && $fs->moved[0] === array('/tmp/up/silent4time-carunit-network-abc123', '/tmp/up/carunit-network'), 'zipball folder renamed to slug');
$fs->moved = array();
ok($u->fix_source_folder('/tmp/up/carunit-network/', '/tmp/up', null, array('plugin' => $b)) === '/tmp/up/carunit-network/' && !$fs->moved, 'already correct: untouched');
ok($u->fix_source_folder('/tmp/up/foo/', '/tmp/up', null, array('plugin' => 'foo/foo.php')) === '/tmp/up/foo/' && !$fs->moved, 'other plugin untouched');
$fs->ok = false;
ok($u->fix_source_folder('/tmp/up/x/', '/tmp/up', null, array('plugin' => $b)) instanceof WP_Error, 'move failure -> WP_Error');
$e = new WP_Error('e'); ok($u->fix_source_folder($e, '/tmp/up') === $e, 'error passthrough');

// purge
set_site_transient('cn_update_info', array('version' => '1'), 1);
$u->purge_cache(null, array('type' => 'theme')); ok(isset($T['cn_update_info']), 'theme upgrade keeps cache');
$u->purge_cache(null, array('type' => 'plugin')); ok(!isset($T['cn_update_info']), 'plugin upgrade clears cache');
// row meta
$m = $u->row_meta(array(), $b); ok(strpos($m['cn_check_update'], 'بررسی به‌روزرسانی') !== false && strpos($m['cn_check_update'], 'cn_check_update=1') !== false, 'row meta link');
ok($u->row_meta(array('a'), 'x/x.php') === array('a'), 'row meta other plugin untouched');
// notice
set_site_transient('cn_update_info', array('version' => '1.7.0'), 1);
ob_start(); $u->admin_notice(); $h = ob_get_clean();
ok(strpos($h, 'به‌روزرسانی</a>') !== false && strpos($h, 'Vazirmatn') !== false && strpos($h, 'rtl') !== false && strpos($h, 'upgrade-plugin') !== false, 'notice with update button');
set_site_transient('cn_update_info', array('version' => '1.6.2'), 1);
ob_start(); $u->admin_notice(); ok(ob_get_clean() === '', 'no notice when up to date');

echo "\n$PASS passed, $FAIL failed\n"; exit($FAIL ? 1 : 0);
