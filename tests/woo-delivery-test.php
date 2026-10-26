<?php
// Mock test: delivery hook order vs. WC emails, nested auto-complete, mailed-services dedupe, retry scheduling.
define('ABSPATH', '/'); define('CN_DIR', __DIR__ . '/../'); define('MINUTE_IN_SECONDS', 60); define('DAY_IN_SECONDS', 86400);
$H = array(); $FILTERS = array(); $LOG = array();
function add_action($h, $cb, $p = 10, $n = 1) { global $H; $H[$h][$p][] = array($cb, $n); }
function add_filter($h, $cb, $p = 10, $n = 1) { add_action($h, $cb, $p, $n); }
function has_action($h, $cb) { global $H; foreach ($H[$h] ?? array() as $p => $l) foreach ($l as $x) if ($x[0] === $cb) return $p; return false; }
function remove_action($h, $cb, $p = 10) { global $H; foreach ($H[$h][$p] ?? array() as $i => $x) if ($x[0] === $cb) unset($H[$h][$p][$i]); }
function do_action($h, ...$a) { global $H; if (empty($H[$h])) return; ksort($H[$h]); foreach ($H[$h] as $l) foreach ($l as $x) call_user_func_array($x[0], array_slice($a, 0, $x[1])); }
function apply_filters($h, $v, ...$a) { global $H; if (empty($H[$h])) return $v; ksort($H[$h]); foreach ($H[$h] as $l) foreach ($l as $x) $v = call_user_func_array($x[0], array_slice(array_merge(array($v), $a), 0, $x[1])); return $v; }
function is_wp_error($x) { return $x instanceof WP_Error; }
class WP_Error { public $m, $c; function __construct($c, $m) { $this->c = $c; $this->m = $m; } function get_error_code() { return $this->c; } function get_error_message() { return $this->m; } }
// Action Scheduler
$AS = array();
function as_schedule_single_action($t, $h, $a, $g) { global $AS; $AS[] = array('t' => $t, 'h' => $h, 'a' => $a, 'g' => $g, 'done' => false); return count($AS); }
function as_has_scheduled_action($h, $a, $g) { global $AS; foreach ($AS as $x) if (!$x['done'] && $x['h'] === $h && $x['a'] == $a) return true; return false; }
function run_as($h) { global $AS; $n = 0; foreach ($AS as $i => $x) if (!$x['done'] && $x['h'] === $h) { $AS[$i]['done'] = true; do_action($h, $x['a']['order_id']); $n++; break; } return $n; }
function pending_as($h) { global $AS; $o = array(); foreach ($AS as $x) if (!$x['done'] && $x['h'] === $h) $o[] = $x; return $o; }

class CN_Util {
    static $locks = array(); static $set = array('auto_complete' => '1');
    static function lock($k, $t = 60) { if (!empty(self::$locks[$k])) return false; return self::$locks[$k] = true; }
    static function unlock($k) { unset(self::$locks[$k]); }
    static function is_locked($k) { return !empty(self::$locks[$k]); }
    static function setting($k, $d = '') { return self::$set[$k] ?? $d; }
    static function fa_digits($s) { return (string) $s; }
}
class DrvFree { static function paid() { return false; } static function can_find() { return true; } }
class DrvPaid { static function paid() { return true; } static function can_find() { return false; } }
class CN_Panels { static $type = 'free'; static function get($id) { return array('id' => $id, 'name' => 'P' . $id, 'type' => self::$type); } }
class CN_Drivers { static function cls($t) { return $t === 'paid' ? 'DrvPaid' : 'DrvFree'; } }
class CN_Plans { static function get($id) { return array('id' => $id, 'panel_id' => 8); } static function for_product($p) { return null; } }
class CN_Services {
    static $db = array(); static $fail = 0; static $calls = 0; static $code = 'x';
    static function for_item($item) { return array_values(array_filter(self::$db, function ($s) use ($item) { return $s['order_item_id'] === $item; })); }
    static function for_order($o) { return array_values(array_filter(self::$db, function ($s) use ($o) { return $s['order_id'] === $o; })); }
    static function get($id) { return self::$db[$id] ?? null; }
    static function create($plan, $ctx) { self::$calls++; if (self::$fail > 0) { self::$fail--; return new WP_Error(self::$code, 'timeout'); } $id = count(self::$db) + 100; self::$db[$id] = array('id' => $id, 'order_id' => $ctx['order_id'], 'order_item_id' => $ctx['item_id'], 'username' => 'cu_' . $id, 'user_id' => 1); return $id; }
}
class WC_Order_Item { public $id, $meta = array('_cn_plan_id' => 7), $qty = 1; function __construct($id) { $this->id = $id; }
    function get_meta($k) { return $this->meta[$k] ?? ''; } function update_meta_data($k, $v) { $this->meta[$k] = $v; } function delete_meta_data($k) { unset($this->meta[$k]); }
    function save() {} function get_quantity() { return $this->qty; } function get_id() { return $this->id; } function get_product() { return null; } function get_name() { return 'VPN'; } }
class WC_Order {
    public $id, $status, $meta = array(), $items, $notes = array();
    function __construct($id, $status) { $this->id = $id; $this->status = $status; $this->items = array($id * 10 => new WC_Order_Item($id * 10)); }
    function get_id() { return $this->id; } function get_order_number() { return (string) $this->id; } function is_paid() { return in_array($this->status, array('processing', 'completed'), true); }
    function get_items() { return $this->items; } function get_qty_refunded_for_item($i) { return 0; } function get_customer_id() { return 1; }
    function get_meta($k) { return $this->meta[$k] ?? ''; } function update_meta_data($k, $v) { $this->meta[$k] = $v; } function delete_meta_data($k) { unset($this->meta[$k]); }
    function save() {} function save_meta_data() {}
    function add_order_note($n, $c = 0, $u = false) { $this->notes[] = array($n, $c); }
    function has_status($s) { return in_array($this->status, (array) $s, true); }
    function update_status($to) { $this->status = $to; do_action('woocommerce_order_status_' . $to, $this->id, $this, array()); }
}
$ORDERS = array();
function wc_get_order($id) { global $ORDERS; return $ORDERS[$id] ?? null; }
// WC_Emails at priority 10 builds the customer email body and fires woocommerce_email_order_details.
$MAILS = array();
class FakeEmail { public $id; function __construct($id) { $this->id = $id; } function is_customer_email() { return true; } }
foreach (array('processing' => 'customer_processing_order', 'completed' => 'customer_completed_order') as $st => $eid) {
    add_action('woocommerce_order_status_' . $st, function ($id, $order) use ($eid, &$MAILS) {
        if ($eid === 'customer_processing_order' && !apply_filters('woocommerce_email_enabled_customer_processing_order', true, $order)) return;
        global $MAILS; $MAILS[] = array($eid, count(CN_Woo::order_services($order)));
        do_action('woocommerce_email_order_details', $order, false, false, new FakeEmail($eid));
    }, 10, 2);
}
class Ready { public $sent = array(); function trigger($id, $o, $s) { $this->sent[] = array($id, count($s)); return true; } }
$READY = new Ready();
class Mailer { function get_emails() { global $READY; return array('CN_Email_Service_Ready' => $READY); } function customer_invoice($o) {} }
function WC() { return new class { function mailer() { return new Mailer(); } }; }
function wc_get_orders($a) { return array(); }
require __DIR__ . '/../includes/class-cn-woo.php';
CN_Woo::init();
$fail = 0; function ok($c, $m) { global $fail; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; if (!$c) $fail++; }

// 1 hook priorities
ok(has_action('woocommerce_order_status_completed', array('CN_Woo', 'deliver_on_status')) === 5, 'completed hook priority 5');
ok(has_action('woocommerce_order_status_processing', array('CN_Woo', 'deliver_on_status')) === 5, 'processing hook priority 5');
ok(has_action('woocommerce_payment_complete', array('CN_Woo', 'deliver')) === 5, 'payment_complete priority 5');

// 2 normal: processing -> deliver -> nested completed; completed email sees the service, processing email skipped
$ORDERS[1] = $o = new WC_Order(1, 'pending');
$o->update_status('processing');
ok($o->status === 'completed', 'auto-complete');
ok($MAILS === array(array('customer_completed_order', 1)), 'completed email built with 1 service; processing email skipped');
ok(CN_Woo::mailed_ids($o) === array(100), 'mailed ids recorded');
ok(count(pending_as('cn_send_ready_mail')) === 1, 'ready mail queued async');
run_as('cn_send_ready_mail');
ok($READY->sent === array(), 'dedupe: no ready email when completed email already showed it');
ok(!pending_as('cn_retry_delivery'), 'no retry on success');

// 3 order 11453 scenario: processing timeout -> retries at 2/10/30 min; admin completes meanwhile
$MAILS = array(); CN_Services::$fail = 1;
$ORDERS[2] = $o = new WC_Order(2, 'pending');
$o->update_status('processing');
ok($o->status === 'processing' && $o->get_meta('_cn_failed') == 1, 'failed delivery flagged');
$p = pending_as('cn_retry_delivery');
ok(count($p) === 1 && $p[0]['t'] - time() === 120 && $p[0]['g'] === 'carunit-network', 'retry #1 at 2 min');
// admin sets completed: deliver at prio 5 runs before completed email
$o->update_status('completed');
ok(end($MAILS) === array('customer_completed_order', 1), 'completed email (manual) built after delivery: service included');
run_as('cn_send_ready_mail'); ok($READY->sent === array(), 'no duplicate ready email');
ok($o->get_meta('_cn_failed') === '', 'failed flag cleared');
run_as('cn_retry_delivery'); ok(CN_Services::$calls === 3, 'stale retry does nothing after success');

// 4 retry chain: always failing -> 2,10,30 then stop
CN_Services::$fail = 99; CN_Services::$calls = 0; $MAILS = array();
$ORDERS[3] = $o = new WC_Order(3, 'pending');
$o->update_status('processing');
$delays = array();
for ($i = 0; $i < 5; $i++) { $p = pending_as('cn_retry_delivery'); if (!$p) break; $delays[] = $p[0]['t'] - time(); run_as('cn_retry_delivery'); }
ok($delays === array(120, 600, 1800), 'retry delays 2/10/30 min, then stop');
ok((int) $o->get_meta('_cn_retry_attempt') === 3 && CN_Services::$calls === 4, 'attempt counter 3, 4 create calls total');

// 5 late success via retry -> ready email + customer note, once
CN_Services::$fail = 1; $MAILS = array();
CN_Util::$set['auto_complete'] = '0';
$ORDERS[4] = $o = new WC_Order(4, 'pending');
$o->update_status('processing');
$o->status = 'completed'; // completed earlier (email went with pending line)
run_as('cn_retry_delivery');
ok(count(CN_Services::for_order(4)) === 1, 'retry delivered');
run_as('cn_send_ready_mail');
ok($READY->sent === array(array(4, 1)), 'ready email sent with the new service');
$cn = array_filter($o->notes, function ($n) { return $n[1] === 1 && strpos($n[0], CN_Woo::READY_NOTE) === 0; });
ok(count($cn) === 1, 'customer note added');
CN_Woo::queue_ready_mail(4); run_as('cn_send_ready_mail');
ok(count($READY->sent) === 1, 'second ready run deduped');
$em = new stdClass; $em->customer_note = CN_Woo::READY_NOTE . ' (x)';
ok(CN_Woo::skip_ready_note_email(true, $o, $em) === false, 'WC customer-note email suppressed for ready note');

// 6 lock respected: retry while delivery running reschedules without spending attempt
CN_Services::$fail = 1;
$ORDERS[5] = $o = new WC_Order(5, 'pending'); $o->update_status('processing');
CN_Util::lock('order_5');
run_as('cn_retry_delivery');
$p = pending_as('cn_retry_delivery');
ok((int) $o->get_meta('_cn_retry_attempt') === 0 && count($p) === 1 && $p[0]['t'] - time() === 60, 'locked: rescheduled +60s, no attempt used');
CN_Util::unlock('order_5');

// 7 action_deliver reuses ready mail
CN_Services::$fail = 1;
$ORDERS[6] = $o = new WC_Order(6, 'pending'); $o->update_status('processing'); $o->status = 'completed';
CN_Woo::action_deliver($o); run_as('cn_send_ready_mail');
ok(end($READY->sent) === array(6, 1), 'admin re-deliver -> ready email');

function pend_for($id) { return array_values(array_filter(pending_as('cn_retry_delivery'), function ($x) use ($id) { return $x['a']['order_id'] === $id; })); }
function run_for($id) { global $AS; foreach ($AS as $i => $x) if (!$x['done'] && $x['h'] === 'cn_retry_delivery' && $x['a']['order_id'] === $id) { $AS[$i]['done'] = true; do_action('cn_retry_delivery', $id); return 1; } return 0; }
// 8 v1.2.4: paid driver without lookup (connectix) -> no automatic retry, private manual-check note
CN_Panels::$type = 'paid'; CN_Services::$fail = 1; CN_Services::$calls = 0;
$ORDERS[7] = $o = new WC_Order(7, 'pending'); $o->update_status('processing');
$mn = array_values(array_filter($o->notes, function ($n) { return strpos($n[0], CN_Woo::MANUAL_NOTE) === 0; }));
ok(!pend_for(7), 'paid driver failure: no retry scheduled');
ok(count($mn) === 1 && $mn[0][1] === 0 && strpos($mn[0][0], '#70') !== false && strpos($mn[0][0], 'P8') !== false, 'private manual-check note with item/panel: ' . ($mn[0][0] ?? ''));
ok($o->items[70]->get_meta('_cn_manual_check') === 1, 'item flagged for manual check');
// 9 UNSURE on a non-paid driver -> manual too
CN_Panels::$type = 'free'; CN_Services::$fail = 1; CN_Services::$code = 'cn_unsure';
$ORDERS[8] = $o = new WC_Order(8, 'pending'); $o->update_status('processing');
ok(!pend_for(8) && $o->items[80]->get_meta('_cn_manual_check') === 1, 'cn_unsure -> no retry, flagged');
CN_Services::$code = 'x';
// 10 retry already scheduled by 1.2.3 for a connectix item fires -> skipped, no create call, note added
CN_Panels::$type = 'paid'; CN_Services::$calls = 0;
$ORDERS[9] = $o = new WC_Order(9, 'processing');
$o->meta['_cn_failed'] = 1; $o->items[90]->meta['_cn_error'] = 'پاسخی از کانکتیکس نرسید';
CN_Woo::schedule_retry($o); $o->notes = array();
run_for(9);
ok(CN_Services::$calls === 0, 'stale retry skips paid item (no purchase attempt)');
ok(!pend_for(9) && $o->items[90]->get_meta('_cn_manual_check') === 1, 'no further retry, item flagged');
ok(count(array_filter($o->notes, function ($n) { return strpos($n[0], CN_Woo::MANUAL_NOTE) === 0; })) === 1, 'manual note added once on skipped retry');
run_for(9); CN_Woo::schedule_retry($o); run_for(9);
ok(CN_Services::$calls === 0 && count(array_filter($o->notes, function ($n) { return strpos($n[0], CN_Woo::MANUAL_NOTE) === 0; })) === 1, 'flagged item skipped again, no duplicate note');
// 11 admin re-deliver still processes it and clears the flag
CN_Woo::action_deliver($o);
ok(CN_Services::$calls === 1 && $o->items[90]->get_meta('_cn_manual_check') === '' && count(CN_Services::for_item(90)) === 1, 'admin re-deliver creates and clears manual flag');
// 12 free driver failure still retries
CN_Panels::$type = 'free'; CN_Services::$fail = 1;
$ORDERS[10] = $o = new WC_Order(10, 'pending'); $o->update_status('processing');
ok(count(pend_for(10)) === 1, 'non-paid failure still scheduled for retry');
exit($fail ? 1 : 0);
