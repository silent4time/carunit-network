<?php
if (!defined('ABSPATH')) {
    exit;
}

class CN_Util {
    const GB = 1073741824;

    public static function gb_to_bytes($gb) {
        return (int) round((float) $gb * self::GB);
    }

    public static function uuid() {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }

    public static function random($len = 10, $chars = 'abcdefghjkmnpqrstuvwxyz23456789') {
        $out = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < $len; $i++) {
            $out .= $chars[random_int(0, $max)];
        }
        return $out;
    }

    public static function setting($key, $default = '') {
        $s = get_option('cn_settings', array());
        return isset($s[$key]) && $s[$key] !== '' ? $s[$key] : $default;
    }

    /** Username for a new account: prefix + order number + short random part. */
    public static function make_username($order_id) {
        $prefix = preg_replace('/[^a-z0-9_]/', '', strtolower(self::setting('username_prefix', 'cu')));
        return substr($prefix . '_' . (int) $order_id . '_' . self::random(4), 0, 32);
    }

    public static function fa_digits($s) {
        return strtr((string) $s, array('0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹'));
    }

    public static function bytes_label($bytes) {
        $bytes = (float) $bytes;
        if ($bytes <= 0) {
            return '۰';
        }
        if ($bytes >= self::GB) {
            $v = round($bytes / self::GB, 2);
            return self::fa_digits(rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.')) . ' گیگ';
        }
        return self::fa_digits((string) round($bytes / 1048576)) . ' مگ';
    }

    public static function gb_label($gb) {
        return (float) $gb > 0 ? self::fa_digits(rtrim(rtrim(number_format((float) $gb, 2, '.', ''), '0'), '.')) . ' گیگ' : 'نامحدود';
    }

    public static function days_label($days) {
        return (int) $days > 0 ? self::fa_digits((string) (int) $days) . ' روز' : 'نامحدود';
    }

    /** Expiry dates are stored in UTC. */
    public static function ts_to_db($ts) {
        return $ts > 0 ? gmdate('Y-m-d H:i:s', (int) $ts) : null;
    }

    public static function db_to_ts($mysql) {
        return $mysql ? (int) strtotime($mysql . ' UTC') : 0;
    }

    public static function date_label($mysql) {
        $ts = self::db_to_ts($mysql);
        if (!$ts) {
            return 'نامحدود';
        }
        return self::local_label(function_exists('wp_date') ? wp_date('Y-m-d', $ts) : gmdate('Y-m-d', $ts), false);
    }

    /** Site-local MySQL datetime → Persian-digit Jalali «۱۴۰۵/۰۷/۱۶ ۲۱:۳۰». */
    public static function local_label($mysql, $with_time = true) {
        if (!$mysql || !preg_match('/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}))?/', (string) $mysql, $m)) {
            return '—';
        }
        list($jy, $jm, $jd) = self::to_jalali((int) $m[1], (int) $m[2], (int) $m[3]);
        $out = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
        if ($with_time && isset($m[4]) && $m[4] !== '') {
            $out .= ' ' . $m[4] . ':' . $m[5];
        }
        return self::fa_digits($out);
    }

    public static function to_jalali($gy, $gm, $gd) {
        $g_d_m = array(0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334);
        $gy2 = $gm > 2 ? $gy + 1 : $gy;
        $days = 355666 + 365 * $gy + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
        $jy = -1595 + 33 * intdiv($days, 12053);
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        if ($days < 186) {
            return array($jy, 1 + intdiv($days, 31), 1 + $days % 31);
        }
        return array($jy, 7 + intdiv($days - 186, 30), 1 + ($days - 186) % 30);
    }

    public static function days_left($mysql) {
        $ts = self::db_to_ts($mysql);
        return $ts ? (int) max(0, ceil(($ts - time()) / DAY_IN_SECONDS)) : null;
    }

    public static function status_label($status) {
        $map = array(
            'active'   => 'فعال',
            'disabled' => 'غیرفعال',
            'expired'  => 'منقضی',
            'limited'  => 'حجم تمام شده',
            'on_hold'  => 'در انتظار اولین اتصال',
            'deleted'  => 'حذف‌شده',
            'failed'   => 'ناموفق',
            'pending'  => 'در انتظار ساخت',
        );
        return isset($map[$status]) ? $map[$status] : $status;
    }

    public static function log($message, array $ctx = array(), $level = 'info') {
        global $wpdb;
        $wpdb->insert(CN_DB::t('logs'), array(
            'level'      => $level,
            'panel_id'   => isset($ctx['panel_id']) ? (int) $ctx['panel_id'] : 0,
            'service_id' => isset($ctx['service_id']) ? (int) $ctx['service_id'] : 0,
            'order_id'   => isset($ctx['order_id']) ? (int) $ctx['order_id'] : 0,
            'message'    => mb_substr((string) $message, 0, 2000),
            'created_at' => CN_DB::now(),
        ));
    }

    /** Lock that survives concurrent requests (payment callback + thank-you page). */
    public static function lock($key, $ttl = 60) {
        global $wpdb;
        $name = 'cn_lock_' . md5($key);
        $now = time();
        $ok = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $name, (string) ($now + $ttl)
        ));
        if ($ok) {
            return true;
        }
        $until = (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
        if ($until < $now) {
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) ($now + $ttl), $name, (string) $until));
            return (bool) $wpdb->rows_affected;
        }
        return false;
    }

    public static function unlock($key) {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", 'cn_lock_' . md5($key)));
    }
}
