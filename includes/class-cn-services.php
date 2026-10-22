<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Service rows. `data` holds JSON: pub (driver data, not secret) and enc
 * (encrypted delivery configs: passwords, config files, links).
 */
class CN_Services {

    private static function hydrate($row) {
        if (!$row) {
            return null;
        }
        $row = (array) $row;
        foreach (array('id', 'panel_id', 'plan_id', 'order_id', 'order_item_id', 'user_id', 'days') as $k) {
            $row[$k] = (int) $row[$k];
        }
        $row['usage_bytes'] = (int) $row['usage_bytes'];
        $row['limit_bytes'] = (int) $row['limit_bytes'];
        $row['volume_gb'] = (float) $row['volume_gb'];
        $d = json_decode((string) $row['data'], true);
        $row['data'] = isset($d['pub']) && is_array($d['pub']) ? $d['pub'] : array();
        $row['enc'] = isset($d['enc']) ? (string) $d['enc'] : '';
        return $row;
    }

    private static function rows($where, array $args = array(), $limit = 0) {
        global $wpdb;
        $sql = 'SELECT * FROM ' . CN_DB::t('services') . ' WHERE ' . $where . ' ORDER BY id DESC' . ($limit ? ' LIMIT ' . (int) $limit : '');
        $res = $args ? $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        return array_map(array(__CLASS__, 'hydrate'), (array) $res);
    }

    public static function get($id) {
        $r = self::rows('id = %d', array((int) $id), 1);
        return $r ? $r[0] : null;
    }

    public static function for_user($user_id) {
        return self::rows("user_id = %d AND status <> 'deleted'", array((int) $user_id));
    }

    public static function for_order($order_id) {
        return self::rows('order_id = %d', array((int) $order_id));
    }

    public static function for_item($item_id) {
        return self::rows('order_item_id = %d', array((int) $item_id));
    }

    public static function search(array $f = array(), $limit = 100) {
        $where = array('1=1');
        $args = array();
        if (!empty($f['panel_id'])) {
            $where[] = 'panel_id = %d';
            $args[] = (int) $f['panel_id'];
        }
        if (!empty($f['status'])) {
            $where[] = 'status = %s';
            $args[] = $f['status'];
        }
        if (!empty($f['q'])) {
            global $wpdb;
            $where[] = '(username LIKE %s OR order_id = %d)';
            $args[] = '%' . $wpdb->esc_like($f['q']) . '%';
            $args[] = (int) $f['q'];
        }
        return self::rows(implode(' AND ', $where), $args, $limit);
    }

    public static function configs(array $svc) {
        $d = CN_Crypto::decrypt($svc['enc']);
        return isset($d['configs']) && is_array($d['configs']) ? $d['configs'] : array();
    }

    /** Shape passed to drivers. */
    public static function driver_view(array $svc) {
        return array(
            'id'          => $svc['id'],
            'username'    => $svc['username'],
            'sub_url'     => (string) $svc['sub_url'],
            'data'        => $svc['data'],
            'limit_bytes' => $svc['limit_bytes'],
            'expires_at'  => $svc['expires_at'],
        );
    }

    private static function pack(array $pub, array $configs) {
        return wp_json_encode(array('pub' => $pub, 'enc' => CN_Crypto::encrypt(array('configs' => array_values($configs)))));
    }

    private static function username_taken($panel_id, $username) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . CN_DB::t('services') . ' WHERE panel_id = %d AND username = %s', $panel_id, $username));
    }

    /**
     * Create an account on the plan's panel.
     * $ctx: order_id, item_id, user_id, note
     * @return int|WP_Error service id
     */
    public static function create(array $plan, array $ctx = array()) {
        global $wpdb;
        $ctx += array('order_id' => 0, 'item_id' => 0, 'user_id' => 0, 'note' => '');
        $panel = CN_Panels::get($plan['panel_id']);
        if (!$panel || $panel['status'] !== 'active') {
            return new WP_Error('cn_panel', 'پنل این پلن غیرفعال است یا وجود ندارد.');
        }
        $driver = CN_Drivers::make($panel);
        if (!$driver) {
            return new WP_Error('cn_driver', 'نوع پنل پشتیبانی نمی‌شود.');
        }
        $username = '';
        for ($i = 0; $i < 5; $i++) {
            $username = CN_Util::make_username($ctx['order_id']);
            if (!self::username_taken($panel['id'], $username)) {
                break;
            }
        }
        $expire = $plan['days'] > 0 ? time() + $plan['days'] * DAY_IN_SECONDS : 0;
        $bytes = CN_Util::gb_to_bytes($plan['volume_gb']);
        $args = array(
            'username' => $username,
            'bytes'    => $bytes,
            'expire'   => $expire,
            'gb'       => $plan['volume_gb'],
            'days'     => $plan['days'],
            'note'     => $ctx['note'] !== '' ? $ctx['note'] : ('order ' . $ctx['order_id']),
            'options'  => $plan['options'],
            'plan_id'  => $plan['id'],
        );
        try {
            $r = $driver->create($args);
        } catch (Throwable $e) {
            $r = array('ok' => false, 'msg' => $e->getMessage());
        }
        if (empty($r['ok'])) {
            $msg = isset($r['msg']) ? $r['msg'] : 'خطای نامشخص';
            CN_Util::log('ساخت اکانت ناموفق: ' . $msg, array('panel_id' => $panel['id'], 'order_id' => $ctx['order_id']), 'error');
            return new WP_Error('cn_create', $msg);
        }
        $now = CN_DB::now();
        $exp = isset($r['expire']) ? (int) $r['expire'] : $expire;
        $wpdb->insert(CN_DB::t('services'), array(
            'panel_id'      => $panel['id'],
            'plan_id'       => $plan['id'],
            'order_id'      => (int) $ctx['order_id'],
            'order_item_id' => (int) $ctx['item_id'],
            'user_id'       => (int) $ctx['user_id'],
            'username'      => (string) $r['username'],
            'sub_url'       => (string) (isset($r['sub_url']) ? $r['sub_url'] : ''),
            'data'          => self::pack(isset($r['data']) ? (array) $r['data'] : array(), isset($r['configs']) ? (array) $r['configs'] : array()),
            'volume_gb'     => $plan['volume_gb'],
            'days'          => $plan['days'],
            'expires_at'    => CN_Util::ts_to_db($exp),
            'status'        => 'active',
            'limit_bytes'   => isset($r['limit']) ? (int) $r['limit'] : $bytes,
            'created_at'    => $now,
            'updated_at'    => $now,
        ));
        $id = (int) $wpdb->insert_id;
        if (!$id) {
            CN_Util::log('اکانت در پنل ساخته شد ولی ذخیره در دیتابیس ناموفق بود: ' . $r['username'] . ' — ' . $wpdb->last_error, array('panel_id' => $panel['id'], 'order_id' => $ctx['order_id']), 'error');
            return new WP_Error('cn_db', 'ذخیرهٔ سرویس ناموفق بود.');
        }
        if (!empty($r['data']['stock_id'])) {
            CN_Stock::attach($r['data']['stock_id'], $id);
        }
        CN_Util::log('اکانت ساخته شد: ' . $r['username'], array('panel_id' => $panel['id'], 'service_id' => $id, 'order_id' => $ctx['order_id']));
        do_action('cn_service_created', $id, $ctx);
        return $id;
    }

    /** @return true|WP_Error */
    public static function renew(array $svc, array $plan, array $ctx = array()) {
        global $wpdb;
        $driver = CN_Panels::driver($svc['panel_id']);
        if (!$driver) {
            return new WP_Error('cn_panel', 'پنل این سرویس پیدا نشد.');
        }
        if (!$driver::supports('renew')) {
            return new WP_Error('cn_renew', 'تمدید در این نوع پنل پشتیبانی نمی‌شود.');
        }
        try {
            $r = $driver->renew(self::driver_view($svc), array(
                'gb'      => $plan['volume_gb'],
                'days'    => $plan['days'],
                'mode'    => $plan['renew_mode'],
                'options' => $plan['options'],
            ));
        } catch (Throwable $e) {
            $r = array('ok' => false, 'msg' => $e->getMessage());
        }
        $order_id = isset($ctx['order_id']) ? (int) $ctx['order_id'] : 0;
        if (empty($r['ok'])) {
            $msg = isset($r['msg']) ? $r['msg'] : 'خطای نامشخص';
            CN_Util::log('تمدید ناموفق: ' . $msg, array('panel_id' => $svc['panel_id'], 'service_id' => $svc['id'], 'order_id' => $order_id), 'error');
            return new WP_Error('cn_renew', $msg);
        }
        $wpdb->update(CN_DB::t('services'), array(
            'plan_id'     => $plan['id'],
            'volume_gb'   => $plan['volume_gb'],
            'days'        => $plan['days'],
            'limit_bytes' => isset($r['limit']) ? (int) $r['limit'] : $svc['limit_bytes'],
            'expires_at'  => CN_Util::ts_to_db(isset($r['expire']) ? (int) $r['expire'] : 0),
            'status'      => 'active',
            'updated_at'  => CN_DB::now(),
        ), array('id' => $svc['id']));
        CN_Util::log('تمدید شد: ' . $svc['username'], array('panel_id' => $svc['panel_id'], 'service_id' => $svc['id'], 'order_id' => $order_id));
        do_action('cn_service_renewed', $svc['id'], $ctx);
        return true;
    }

    /** Pull usage/expiry from the panel. */
    public static function refresh(array $svc) {
        global $wpdb;
        $driver = CN_Panels::driver($svc['panel_id']);
        if (!$driver) {
            return new WP_Error('cn_panel', 'پنل این سرویس پیدا نشد.');
        }
        try {
            $r = $driver->info(self::driver_view($svc));
        } catch (Throwable $e) {
            $r = array('ok' => false, 'msg' => $e->getMessage());
        }
        if (empty($r['ok'])) {
            $wpdb->update(CN_DB::t('services'), array('synced_at' => CN_DB::now()), array('id' => $svc['id']));
            return new WP_Error('cn_info', isset($r['msg']) ? $r['msg'] : 'خطا');
        }
        $row = array(
            'usage_bytes' => max(0, (int) $r['used']),
            'limit_bytes' => max(0, (int) $r['limit']),
            'expires_at'  => CN_Util::ts_to_db((int) $r['expire']),
            'status'      => sanitize_key($r['status']),
            'synced_at'   => CN_DB::now(),
        );
        if (!empty($r['sub_url'])) {
            $row['sub_url'] = $r['sub_url'];
        }
        $wpdb->update(CN_DB::t('services'), $row, array('id' => $svc['id']));
        return true;
    }

    /** Admin operations: enable, disable, reset, revoke, delete. */
    public static function action(array $svc, $op) {
        global $wpdb;
        $driver = CN_Panels::driver($svc['panel_id']);
        if (!$driver) {
            return new WP_Error('cn_panel', 'پنل این سرویس پیدا نشد.');
        }
        $view = self::driver_view($svc);
        try {
            switch ($op) {
                case 'enable':
                case 'disable':
                    $r = $driver->set_enabled($view, $op === 'enable');
                    break;
                case 'reset':
                    $r = $driver->reset($view);
                    break;
                case 'revoke':
                    $r = $driver->revoke($view);
                    break;
                case 'delete':
                    $r = $driver->delete($view);
                    break;
                default:
                    $r = array('ok' => false, 'msg' => 'عملیات نامعتبر');
            }
        } catch (Throwable $e) {
            $r = array('ok' => false, 'msg' => $e->getMessage());
        }
        if (empty($r['ok'])) {
            CN_Util::log('عملیات ' . $op . ' ناموفق: ' . (isset($r['msg']) ? $r['msg'] : ''), array('panel_id' => $svc['panel_id'], 'service_id' => $svc['id']), 'error');
            return new WP_Error('cn_op', isset($r['msg']) ? $r['msg'] : 'خطا');
        }
        $upd = array('updated_at' => CN_DB::now());
        if ($op === 'delete') {
            $upd['status'] = 'deleted';
        } elseif ($op === 'disable') {
            $upd['status'] = 'disabled';
        } elseif ($op === 'enable') {
            $upd['status'] = 'active';
        } elseif ($op === 'reset') {
            $upd['usage_bytes'] = 0;
        } elseif ($op === 'revoke' && !empty($r['sub_url'])) {
            $upd['sub_url'] = $r['sub_url'];
        }
        $wpdb->update(CN_DB::t('services'), $upd, array('id' => $svc['id']));
        CN_Util::log('عملیات ' . $op . ': ' . $svc['username'], array('panel_id' => $svc['panel_id'], 'service_id' => $svc['id']));
        return true;
    }

    public static function counts() {
        global $wpdb;
        $out = array();
        foreach ((array) $wpdb->get_results('SELECT status, COUNT(*) c FROM ' . CN_DB::t('services') . ' GROUP BY status', ARRAY_A) as $r) {
            $out[$r['status']] = (int) $r['c'];
        }
        return $out;
    }
}
