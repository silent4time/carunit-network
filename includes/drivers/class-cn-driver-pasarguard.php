<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * PasarGuard panel driver (Marzban-compatible with groups / v1 API).
 */
class CN_Driver_Pasarguard extends CN_Driver_Marzban {

    protected function v1() {
        return true;
    }

    public static function label() {
        return 'پاسارگارد';
    }

    public static function url_help() {
        return 'همان آدرس ورود به پنل پاسارگارد، مثل https://panel.example.com:8000/dashboard';
    }

    protected function groups() {
        $r = $this->api('GET', '/api/groups');
        if (!$r['ok'] || !is_array($r['json'])) {
            return array();
        }
        $out = array();
        foreach ((array) $r['json'] as $g) {
            if (isset($g['id'], $g['name'])) {
                $out[$g['id']] = $g['name'];
            }
        }
        return $out;
    }
}
