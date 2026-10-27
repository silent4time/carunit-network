<?php
// v1.2.9: Eylan (Shayan) start-on-first-connection -> on_hold + «N روز از اولین اتصال».
define('ABSPATH', '/'); define('MINUTE_IN_SECONDS', 60); define('HOUR_IN_SECONDS', 3600); define('DAY_IN_SECONDS', 86400);
function add_filter($t, $c) { global $F; $F[$t] = $c; } function apply_filters($t, $v, ...$a) { global $F; return isset($F[$t]) ? $F[$t]($v, ...$a) : $v; }
function add_action() {} function do_action() {} function is_admin() { return false; } function get_option($k, $d = '') { return $d; }
function wp_json_encode($x) { return json_encode($x); } function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function get_transient($k) { return false; } function set_transient() {} function delete_transient() {}
function is_wp_error($x) { return $x instanceof WP_Error; }
class WP_Error { function __construct($c, $m) {} }
$base = __DIR__ . '/../includes/';
require $base . 'class-cn-http.php'; require $base . 'class-cn-crypto.php'; require $base . 'class-cn-util.php'; require $base . 'drivers/class-cn-driver.php';
CN_Drivers::load();
class T extends CN_Driver_Eylan {
    public $sent = array(); public $user = array();
    protected function profile(array $a) { return array('ok' => true, 'flags' => array('enable_openvpn' => true, 'enable_l2tp' => false, 'enable_cisco' => false, 'enable_wg1' => false, 'enable_singbox' => false), 'max_clients' => 5, 'wg' => array()); }
    protected function api($m, $p, array $o = array()) { if ($m === 'POST') { $this->sent = $o['json']; } return array('ok' => true, 'code' => 200, 'json' => $m === 'GET' ? $this->user : array('success' => true)); }
    protected function delivery($u, array $f, array $s) { return array(); }
    protected function sub_url($u) { return ''; }
    protected function get_user($u) { return $this->api('GET', ''); }
}
$fail = 0; $ok = function ($c, $m) use (&$fail) { echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; if (!$c) $fail++; };
$d = new T(array('id' => 9, 'settings' => array(), 'secrets' => array()));
$r = $d->create(array('username' => 'u', 'bytes' => 20 * CN_Util::GB, 'expire' => time() + 31 * DAY_IN_SECONDS, 'days' => 31, 'note' => ''));
$ok($d->sent['activation_type'] === 'flexible_days' && $d->sent['pending_activation_days'] === 31 && !isset($d->sent['expiry_days']), 'create sends flexible_days 31');
$ok($r['status'] === 'on_hold' && $r['expire'] === 0, 'create returns on_hold, no expiry');
$ok(CN_Util::expiry_label(array('status' => 'on_hold', 'days' => 31, 'expires_at' => null)) === '۳۱ روز از اولین اتصال', 'label');
$d->user = array('username' => 'u', 'is_active' => true, 'data_limit' => 20, 'download_bytes' => 0, 'activation_type' => 'flexible_days');
$i = $d->info(array('username' => 'u', 'status' => 'on_hold'));
$ok($i['status'] === 'on_hold', 'info keeps on_hold before first connection');
$d->user['expiry_date'] = date('Y-m-d', time() + 30 * DAY_IN_SECONDS);
$ok($d->info(array('username' => 'u', 'status' => 'on_hold'))['status'] === 'active', 'info active after first connection');
$f = new T(array('id' => 9, 'settings' => array('activation' => 'fixed'), 'secrets' => array()));
$r = $f->create(array('username' => 'u', 'bytes' => 0, 'expire' => time() + 31 * DAY_IN_SECONDS, 'days' => 31));
$ok($f->sent['activation_type'] === 'fixed_date' && $f->sent['expiry_days'] === 31 && $r['status'] === 'active', 'fixed mode unchanged');
exit($fail ? 1 : 0);
