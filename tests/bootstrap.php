<?php
/**
 * Minimal WordPress / WooCommerce stand-ins so the plugin logic can run on plain PHP.
 * Database: in-memory tables that understand the small SQL subset the plugin uses.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('UTC');

define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('AUTH_KEY', 'test-auth-key');
define('SECURE_AUTH_SALT', 'test-salt');

/* ---------- hooks ---------- */
$GLOBALS['__hooks'] = array();
function add_filter($tag, $cb, $prio = 10, $args = 1) { $GLOBALS['__hooks'][$tag][$prio][] = $cb; return true; }
function add_action($tag, $cb, $prio = 10, $args = 1) { return add_filter($tag, $cb, $prio, $args); }
function apply_filters($tag, $value, ...$rest) {
    if (empty($GLOBALS['__hooks'][$tag])) { return $value; }
    ksort($GLOBALS['__hooks'][$tag]);
    foreach ($GLOBALS['__hooks'][$tag] as $cbs) { foreach ($cbs as $cb) { $value = call_user_func_array($cb, array_merge(array($value), $rest)); } }
    return $value;
}
function do_action($tag, ...$args) {
    if (empty($GLOBALS['__hooks'][$tag])) { return; }
    ksort($GLOBALS['__hooks'][$tag]);
    foreach ($GLOBALS['__hooks'][$tag] as $cbs) { foreach ($cbs as $cb) { call_user_func_array($cb, $args); } }
}
function remove_all_filters($tag) { unset($GLOBALS['__hooks'][$tag]); }
function register_activation_hook($f, $cb) {}
function register_deactivation_hook($f, $cb) {}
function plugin_dir_path($f) { return rtrim(dirname($f), '/\\') . '/'; }
function plugin_dir_url($f) { return 'https://example.test/wp-content/plugins/carunit-network/'; }

/* ---------- options / transients ---------- */
$GLOBALS['__opts'] = array();
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['__opts']) ? $GLOBALS['__opts'][$k] : $d; }
function update_option($k, $v, $autoload = null) { $GLOBALS['__opts'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['__opts'][$k]); return true; }
function get_transient($k) { return get_option('_t_' . $k); }
function set_transient($k, $v, $ttl = 0) { return update_option('_t_' . $k, $v); }
function delete_transient($k) { return delete_option('_t_' . $k); }

/* ---------- formatting ---------- */
function wp_json_encode($d, $o = 0) { return json_encode($d, $o | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
function current_time($t) { return gmdate('Y-m-d H:i:s'); }
function wp_date($f, $ts = null) { return gmdate($f, $ts === null ? time() : $ts); }
function mysql2date($f, $d) { return gmdate($f, strtotime($d)); }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function esc_url_raw($u) { return trim((string) $u); }
function esc_url($u) { return htmlspecialchars((string) $u, ENT_QUOTES); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_textarea($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function sanitize_file_name($s) { return preg_replace('/[^A-Za-z0-9._\-]/', '_', (string) $s); }
function sanitize_title($s) { return preg_replace('/[^a-z0-9\-]/', '-', strtolower((string) $s)); }
function wp_unslash($v) { return is_array($v) ? array_map('wp_unslash', $v) : stripslashes((string) $v); }
function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); }
function wp_generate_uuid4() { return bin2hex(random_bytes(8)); }
function selected($a, $b, $echo = true) { return (string) $a === (string) $b ? ' selected="selected"' : ''; }
function checked($a, $b, $echo = true) { return $a == $b ? ' checked="checked"' : ''; }
function disabled($a, $b, $echo = true) { return $a == $b ? ' disabled="disabled"' : ''; }
function add_query_arg($k, $v = null, $u = null) { if (is_array($k)) { $u = $v; $args = $k; } else { $args = array($k => $v); } return $u . (strpos((string) $u, '?') === false ? '?' : '&') . http_build_query($args); }
function wp_nonce_url($u, $a) { return $u . '&_wpnonce=test'; }
function wp_create_nonce($a) { return 'nonce-' . md5($a); }
function wp_verify_nonce($n, $a) { return $n === 'nonce-' . md5($a); }
function is_user_logged_in() { return !empty($GLOBALS['__user']); }
function get_current_user_id() { return (int) ($GLOBALS['__user'] ?? 0); }
function wp_style_is() { return true; }
function wp_register_style() {}
function wp_register_script() {}
function wp_enqueue_style() {}
function wp_enqueue_script() {}
function get_term_by() { return false; }
function get_term_link() { return ''; }
function wc_get_cart_url() { return 'https://example.test/cart/'; }
function wc_get_account_endpoint_url($e) { return 'https://example.test/my-account/' . $e . '/'; }
function wc_price($p) { return number_format((float) $p) . ' تومان'; }
function wc_add_notice($m, $t = 'success') { $GLOBALS['__notices'][] = array($t, $m); }

class WP_Error {
    private $c; private $m;
    public function __construct($c = '', $m = '') { $this->c = $c; $this->m = $m; }
    public function get_error_message() { return $this->m; }
    public function get_error_code() { return $this->c; }
}
function is_wp_error($x) { return $x instanceof WP_Error; }
class WP_Http_Cookie { public $name; public $value; public function __construct($a) { $this->name = $a['name']; $this->value = $a['value']; } }
function wp_remote_request($u, $a) { throw new RuntimeException('Real HTTP is disabled in tests: ' . $u); }

/* ---------- WooCommerce fakes ---------- */
class FakeItem {
    public $id; public $qty; public $meta = array(); public $product; public $name = 'محصول';
    public function __construct($id, $qty, array $meta = array()) { $this->id = $id; $this->qty = $qty; $this->meta = $meta; }
    public function get_id() { return $this->id; }
    public function get_quantity() { return $this->qty; }
    public function get_meta($k) { return $this->meta[$k] ?? ''; }
    public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
    public function add_meta_data($k, $v, $u = false) { $this->meta[$k] = $v; }
    public function delete_meta_data($k) { unset($this->meta[$k]); }
    public function save() {}
    public function get_product() { return $this->product; }
    public function get_name() { return $this->name; }
}
class WC_Order {
    public $id; public $items = array(); public $status = 'pending'; public $customer = 0; public $meta = array(); public $notes = array();
    public function __construct($id) { $this->id = $id; }
    public function get_id() { return $this->id; }
    public function get_items() { return $this->items; }
    public function is_paid() { return in_array($this->status, array('processing', 'completed'), true); }
    public function get_customer_id() { return $this->customer; }
    public function get_order_number() { return (string) $this->id; }
    public function add_order_note($n) { $this->notes[] = $n; }
    public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
    public function delete_meta_data($k) { unset($this->meta[$k]); }
    public function get_meta($k) { return $this->meta[$k] ?? ''; }
    public function save() {}
    public function has_status($s) { return $this->status === $s; }
    public function get_qty_refunded_for_item($id) { return 0; }
    public function update_status($s, $note = '') {
        $this->status = $s;
        if ($note) { $this->notes[] = $note; }
        do_action('woocommerce_order_status_' . $s, $this->id);
    }
    public function get_edit_order_url() { return 'https://example.test/wp-admin/post.php?post=' . $this->id; }
}
$GLOBALS['__orders'] = array();
function wc_get_order($id) { return $id instanceof WC_Order ? $id : ($GLOBALS['__orders'][(int) $id] ?? false); }
function wc_get_product($id) { return $GLOBALS['__products'][(int) $id] ?? false; }
function wc_get_orders($a) { return array(); }

/* ---------- in-memory wpdb ---------- */
class FakeWpdb {
    public $prefix = 'wp_';
    public $options = 'wp_options';
    public $insert_id = 0;
    public $rows_affected = 0;
    public $last_error = '';
    public $tables = array();
    private $seq = array();
    private $unique = array('wp_cn_services' => array('panel_id', 'username'), 'wp_options' => array('option_name'));

    public function reset() { $this->tables = array(); $this->seq = array(); }
    public function esc_like($s) { return addcslashes($s, '_%\\'); }
    public function get_charset_collate() { return ''; }

    public function prepare($q, ...$args) {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        $i = 0;
        return preg_replace_callback('/%[dsf]/', function ($m) use (&$i, $args) {
            $v = $args[$i++];
            if ($m[0] === '%d') { return (string) (int) $v; }
            if ($m[0] === '%f') { return (string) (float) $v; }
            return "'" . addslashes((string) $v) . "'";
        }, $q);
    }

    public function insert($t, array $data) {
        $this->tables[$t] = $this->tables[$t] ?? array();
        if (isset($this->unique[$t])) {
            foreach ($this->tables[$t] as $r) {
                $dup = true;
                foreach ($this->unique[$t] as $c) { if ((string) ($r[$c] ?? '') !== (string) ($data[$c] ?? '')) { $dup = false; } }
                if ($dup) { $this->last_error = 'Duplicate entry'; $this->insert_id = 0; return false; }
            }
        }
        $defaults = array(
            'wp_cn_panels'   => array('last_check' => null, 'last_check_ok' => 0, 'last_check_msg' => ''),
            'wp_cn_plans'    => array('product_id' => 0, 'users_limit' => 0, 'status' => 'active', 'sort' => 0, 'renew_mode' => 'add'),
            'wp_cn_services' => array('usage_bytes' => 0, 'limit_bytes' => 0, 'synced_at' => null, 'status' => 'active', 'plan_id' => 0, 'order_id' => 0, 'order_item_id' => 0, 'user_id' => 0),
            'wp_cn_stock'    => array('service_id' => 0, 'sold_at' => null),
        );
        $data += $defaults[$t] ?? array();
        $this->seq[$t] = ($this->seq[$t] ?? 0) + 1;
        $id = $this->seq[$t];
        $data = array('id' => $id) + $data;
        $this->tables[$t][$id] = array_map(function ($v) { return $v === null ? null : (string) $v; }, $data);
        $this->insert_id = $id;
        $this->rows_affected = 1;
        return 1;
    }

    private function match(array $row, array $where) {
        foreach ($where as $k => $v) { if ((string) ($row[$k] ?? '') !== (string) $v) { return false; } }
        return true;
    }

    public function update($t, array $data, array $where) {
        $n = 0;
        foreach ($this->tables[$t] ?? array() as $id => $r) {
            if ($this->match($r, $where)) {
                foreach ($data as $k => $v) { $this->tables[$t][$id][$k] = $v === null ? null : (string) $v; }
                $n++;
            }
        }
        $this->rows_affected = $n;
        return $n;
    }

    public function delete($t, array $where) {
        $n = 0;
        foreach ($this->tables[$t] ?? array() as $id => $r) {
            if ($this->match($r, $where)) { unset($this->tables[$t][$id]); $n++; }
        }
        $this->rows_affected = $n;
        return $n;
    }

    private static function lit($s) {
        $s = trim($s);
        if (strcasecmp($s, 'NULL') === 0) { return null; }
        if ($s !== '' && $s[0] === "'") { return stripslashes(substr($s, 1, -1)); }
        return $s;
    }

    private static function split_top($s, $sep) {
        $out = array(); $buf = ''; $q = false; $depth = 0; $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            if ($ch === "'" && ($i === 0 || $s[$i - 1] !== '\\')) { $q = !$q; }
            if (!$q && $ch === '(') { $depth++; }
            if (!$q && $ch === ')') { $depth--; }
            if (!$q && $depth === 0 && substr($s, $i, strlen($sep)) === $sep) { $out[] = $buf; $buf = ''; $i += strlen($sep) - 1; continue; }
            $buf .= $ch;
        }
        $out[] = $buf;
        return $out;
    }

    private function filter($t, $where) {
        $rows = array_values($this->tables[$t] ?? array());
        if ($where === null || trim($where) === '') { return $rows; }
        $conds = self::split_top(preg_replace('/\s+AND\s+/i', ' AND ', trim($where)), ' AND ');
        return array_values(array_filter($rows, function ($r) use ($conds) {
            foreach ($conds as $c) {
                $c = trim($c);
                if ($c === '1=1') { continue; }
                if (preg_match('/^(\w+)\s+IS\s+(NOT\s+)?NULL$/i', $c, $m)) {
                    if (($r[$m[1]] ?? null) === null ? !empty($m[2]) : empty($m[2])) { return false; }
                    continue;
                }
                if (!preg_match("/^(\w+)\s*(=|<>|<|>)\s*('(?:[^'\\\\]|\\\\.)*'|-?[\d.]+|NULL)$/is", $c, $m)) {
                    throw new RuntimeException('FakeWpdb: unsupported condition: ' . $c);
                }
                $a = $r[$m[1]] ?? null; $b = self::lit($m[3]);
                switch ($m[2]) {
                    case '=': if ((string) $a !== (string) $b) { return false; } break;
                    case '<>': if ((string) $a === (string) $b) { return false; } break;
                    case '<': if (!($a !== null && $a < $b)) { return false; } break;
                    case '>': if (!($a !== null && $a > $b)) { return false; } break;
                }
            }
            return true;
        }));
    }

    private function select($sql) {
        $sql = trim(preg_replace('/\s+/', ' ', $sql));
        if (!preg_match('/^SELECT (.+?) FROM (\w+)(?: WHERE (.+?))?(?: GROUP BY (\w+))?(?: ORDER BY (.+?))?(?: LIMIT (\d+))?$/i', $sql, $m)) {
            throw new RuntimeException('FakeWpdb: unsupported select: ' . $sql);
        }
        $cols = trim($m[1]); $t = $m[2];
        $rows = $this->filter($t, $m[3] ?? null);
        if (!empty($m[5])) {
            $first = trim(explode(',', $m[5])[0]);
            $desc = stripos($first, ' DESC') !== false;
            $col = trim(preg_replace('/\s+(ASC|DESC)$/i', '', $first));
            usort($rows, function ($x, $y) use ($col, $desc) {
                $a = $x[$col] ?? ''; $b = $y[$col] ?? '';
                $c = is_numeric($a) && is_numeric($b) ? ($a <=> $b) : strcmp((string) $a, (string) $b);
                return $desc ? -$c : $c;
            });
        }
        if (!empty($m[4])) {
            $g = array();
            foreach ($rows as $r) { $g[$r[$m[4]]] = ($g[$r[$m[4]]] ?? 0) + 1; }
            $out = array();
            foreach ($g as $k => $c) { $out[] = array($m[4] => $k, 'c' => $c); }
            return $out;
        }
        if (!empty($m[6])) { $rows = array_slice($rows, 0, (int) $m[6]); }
        if (stripos($cols, 'COUNT(*)') === 0) { return array(array('c' => count($rows))); }
        if ($cols !== '*') {
            $c = trim($cols);
            return array_map(function ($r) use ($c) { return array($c => $r[$c] ?? null); }, $rows);
        }
        return $rows;
    }

    public function get_results($sql, $o = ARRAY_A) { return $this->select($sql); }
    public function get_row($sql, $o = ARRAY_A) { $r = $this->select($sql); return $r ? $r[0] : null; }
    public function get_var($sql) { $r = $this->select($sql); return $r ? reset($r[0]) : null; }
    public function get_col($sql) { return array_map(function ($r) { return reset($r); }, $this->select($sql)); }

    public function query($sql) {
        $sql = trim(preg_replace('/\s+/', ' ', $sql));
        if (preg_match('/^INSERT IGNORE INTO (\w+) \(([^)]+)\) VALUES \((.+)\)$/i', $sql, $m)) {
            $cols = array_map('trim', explode(',', $m[2]));
            $vals = array_map(array(__CLASS__, 'lit'), self::split_top($m[3], ','));
            $r = $this->insert($m[1], array_combine($cols, $vals));
            return $r ? 1 : 0;
        }
        if (preg_match('/^UPDATE (\w+) SET (.+?) WHERE (.+)$/i', $sql, $m)) {
            $set = array();
            foreach (self::split_top($m[2], ',') as $p) { list($k, $v) = array_map('trim', explode('=', $p, 2)); $set[$k] = self::lit($v); }
            $n = 0;
            foreach ($this->filter($m[1], $m[3]) as $r) { $this->update($m[1], $set, array('id' => $r['id'])); $n++; }
            $this->rows_affected = $n;
            return $n;
        }
        if (preg_match('/^DELETE FROM (\w+) WHERE (.+)$/i', $sql, $m)) {
            $n = 0;
            foreach ($this->filter($m[1], $m[2]) as $r) { unset($this->tables[$m[1]][$r['id']]); $n++; }
            $this->rows_affected = $n;
            return $n;
        }
        throw new RuntimeException('FakeWpdb: unsupported query: ' . $sql);
    }
}
$GLOBALS['wpdb'] = new FakeWpdb();

/* ---------- HTTP mock router ---------- */
$GLOBALS['__http_log'] = array();
$GLOBALS['__http_routes'] = array();
function http_route($method, $pattern, $responder) { $GLOBALS['__http_routes'][] = array($method, $pattern, $responder); }
function http_reset() { $GLOBALS['__http_routes'] = array(); $GLOBALS['__http_log'] = array(); }
add_filter('cn_http_mock', function ($pre, $method, $url, $args, $o) {
    $GLOBALS['__http_log'][] = array('method' => $method, 'url' => $url, 'args' => $args, 'o' => $o);
    foreach (array_reverse($GLOBALS['__http_routes']) as $r) {
        if (($r[0] === '*' || $r[0] === $method) && preg_match($r[1], $url)) {
            $res = is_callable($r[2]) ? call_user_func($r[2], $method, $url, $args, $o) : $r[2];
            if (isset($res['json']) && !isset($res['body'])) { $res['body'] = json_encode($res['json']); unset($res['json']); }
            return $res + array('code' => 200);
        }
    }
    return array('code' => 599, 'error' => 'no mock route for ' . $method . ' ' . $url);
}, 10, 5);

require dirname(__DIR__) . '/carunit-network.php';
