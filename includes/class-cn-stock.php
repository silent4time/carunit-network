<?php
if (!defined('ABSPATH')) {
    exit;
}

class CN_Stock {

    /** Add accounts; each block separated by a line containing only "---". */
    public static function add($plan_id, $text) {
        global $wpdb;
        $text = str_replace("\r\n", "\n", (string) $text);
        $blocks = preg_split('/^\s*---\s*$/m', $text);
        $n = 0;
        foreach ($blocks as $b) {
            $b = trim($b);
            if ($b === '') {
                continue;
            }
            $wpdb->insert(CN_DB::t('stock'), array(
                'plan_id'    => (int) $plan_id,
                'content'    => CN_Crypto::encrypt(array('c' => $b)),
                'status'     => 'free',
                'created_at' => CN_DB::now(),
            ));
            $n++;
        }
        return $n;
    }

    public static function content($row) {
        $d = CN_Crypto::decrypt(is_array($row) ? $row['content'] : (string) $row);
        return isset($d['c']) ? (string) $d['c'] : '';
    }

    /** Atomically reserve one free account for a plan. */
    public static function take($plan_id, $service_ref = 0) {
        global $wpdb;
        $t = CN_DB::t('stock');
        for ($i = 0; $i < 5; $i++) {
            $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE plan_id = %d AND status = 'free' ORDER BY id LIMIT 1", $plan_id));
            if (!$id) {
                return null;
            }
            $wpdb->query($wpdb->prepare(
                "UPDATE $t SET status = 'sold', service_id = %d, sold_at = %s WHERE id = %d AND status = 'free'",
                (int) $service_ref, CN_DB::now(), $id
            ));
            if ($wpdb->rows_affected) {
                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $id), ARRAY_A);
                $row['content'] = self::content($row);
                return $row;
            }
        }
        return null;
    }

    public static function attach($stock_id, $service_id) {
        global $wpdb;
        $wpdb->update(CN_DB::t('stock'), array('service_id' => (int) $service_id), array('id' => (int) $stock_id));
    }

    public static function release($stock_id) {
        global $wpdb;
        $wpdb->update(CN_DB::t('stock'), array('status' => 'free', 'service_id' => 0, 'sold_at' => null), array('id' => (int) $stock_id));
    }

    public static function count_free($plan_id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . CN_DB::t('stock') . " WHERE plan_id = %d AND status = 'free'", $plan_id));
    }

    public static function rows($plan_id = 0, $status = '', $limit = 200) {
        global $wpdb;
        $t = CN_DB::t('stock');
        $where = array('1=1');
        $args = array();
        if ($plan_id) {
            $where[] = 'plan_id = %d';
            $args[] = (int) $plan_id;
        }
        if ($status !== '') {
            $where[] = 'status = %s';
            $args[] = $status;
        }
        $args[] = (int) $limit;
        return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE " . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT %d', $args), ARRAY_A);
    }

    public static function delete($id) {
        global $wpdb;
        $wpdb->delete(CN_DB::t('stock'), array('id' => (int) $id, 'status' => 'free'));
    }
}
