<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin wrapper around the WordPress HTTP API.
 * Tests (and debugging) can short-circuit any request with the `cn_http_mock` filter.
 */
class CN_Http {

    /**
     * @param string $method GET|POST|PUT|PATCH|DELETE
     * @param array  $o      headers, json, form, body, auth (user, pass), bearer, cookies, timeout, verify
     * @return array ok, code, body, json, error, cookies
     */
    public static function request($method, $url, array $o = array()) {
        $headers = isset($o['headers']) ? $o['headers'] : array();
        $body = null;
        if (array_key_exists('json', $o)) {
            $body = wp_json_encode($o['json']);
            $headers['Content-Type'] = 'application/json';
        } elseif (array_key_exists('form', $o)) {
            $body = $o['form'];
        } elseif (array_key_exists('body', $o)) {
            $body = $o['body'];
        }
        if (!isset($headers['Accept'])) {
            $headers['Accept'] = 'application/json';
        }
        if (!empty($o['bearer'])) {
            $headers['Authorization'] = 'Bearer ' . $o['bearer'];
        }
        if (!empty($o['auth'])) {
            $headers['Authorization'] = 'Basic ' . base64_encode($o['auth'][0] . ':' . $o['auth'][1]);
        }

        $args = array(
            'method'      => strtoupper($method),
            'headers'     => $headers,
            'timeout'     => isset($o['timeout']) ? (int) $o['timeout'] : 20,
            'sslverify'   => !empty($o['verify']),
            'redirection' => isset($o['redirection']) ? (int) $o['redirection'] : 3,
            'cookies'     => isset($o['cookies']) ? self::cookie_objects($o['cookies']) : array(),
        );
        if ($body !== null) {
            $args['body'] = $body;
        }

        $mock = apply_filters('cn_http_mock', null, $args['method'], $url, $args, $o);
        if (is_array($mock)) {
            return self::normalize($mock);
        }

        $res = wp_remote_request($url, $args);
        if (is_wp_error($res)) {
            return self::normalize(array('error' => $res->get_error_message()));
        }
        $cookies = array();
        foreach ((array) wp_remote_retrieve_cookies($res) as $ck) {
            if (is_object($ck) && isset($ck->name)) {
                $cookies[$ck->name] = $ck->value;
            }
        }
        $h = wp_remote_retrieve_headers($res);
        $h = is_object($h) && method_exists($h, 'getAll') ? $h->getAll() : (array) $h;
        return self::normalize(array(
            'code'    => (int) wp_remote_retrieve_response_code($res),
            'body'    => (string) wp_remote_retrieve_body($res),
            'cookies' => $cookies,
            'headers' => array_change_key_case($h, CASE_LOWER),
        ));
    }

    private static function normalize(array $r) {
        $r += array('code' => 0, 'body' => '', 'error' => '', 'cookies' => array(), 'headers' => array());
        if (!isset($r['json'])) {
            $j = json_decode((string) $r['body'], true);
            $r['json'] = is_array($j) ? $j : null;
        }
        $r['ok'] = $r['error'] === '' && $r['code'] >= 200 && $r['code'] < 300;
        return $r;
    }

    private static function cookie_objects($cookies) {
        $out = array();
        foreach ((array) $cookies as $name => $value) {
            if (is_object($value)) {
                $out[] = $value;
            } elseif (class_exists('WP_Http_Cookie')) {
                $out[] = new WP_Http_Cookie(array('name' => $name, 'value' => $value));
            }
        }
        return $out;
    }

    /** Short human message for a failed response. */
    public static function error_text(array $r) {
        if ($r['error'] !== '') {
            $e = $r['error'];
            if (preg_match('/timed?\s*out|cURL error 28|Connection timed out|Failed to connect/i', $e)) {
                return 'سرور سایت به آدرس پنل نرسید (timeout یا قطع شبکه). آدرس را از روی همین هاست باز کنید یا فایروال پنل/هاست را بررسی کنید.';
            }
            return $e;
        }
        $j = is_array($r['json']) ? $r['json'] : array();
        foreach (array('detail', 'msg', 'message', 'error') as $k) {
            if (!empty($j[$k])) {
                return is_string($j[$k]) ? $j[$k] : wp_json_encode($j[$k]);
            }
        }
        return 'HTTP ' . $r['code'];
    }
}
