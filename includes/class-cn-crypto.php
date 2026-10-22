<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Panel credentials are stored encrypted (AES-256-GCM) with a key derived from
 * the site's own salts, so a database dump alone does not expose them.
 */
class CN_Crypto {
    const PREFIX = 'cn1:';

    private static function key() {
        $material = (defined('AUTH_KEY') ? AUTH_KEY : '') . (defined('SECURE_AUTH_SALT') ? SECURE_AUTH_SALT : '') . 'carunit-network';
        return hash('sha256', $material, true);
    }

    public static function encrypt(array $data) {
        $plain = wp_json_encode($data);
        if (!function_exists('openssl_encrypt')) {
            return 'b64:' . base64_encode($plain);
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt($value) {
        if (!is_string($value) || $value === '') {
            return array();
        }
        if (strpos($value, 'b64:') === 0) {
            $d = json_decode(base64_decode(substr($value, 4)), true);
            return is_array($d) ? $d : array();
        }
        if (strpos($value, self::PREFIX) !== 0 || !function_exists('openssl_decrypt')) {
            return array();
        }
        $raw = base64_decode(substr($value, strlen(self::PREFIX)));
        if (strlen($raw) < 29) {
            return array();
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        $d = is_string($plain) ? json_decode($plain, true) : null;
        return is_array($d) ? $d : array();
    }
}
