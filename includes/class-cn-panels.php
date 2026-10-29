<?php
if (!defined('ABSPATH')) {
    exit;
}

class CN_Panels {

    private static function hydrate($row) {
        if (!$row) {
            return null;
        }
        $row = (array) $row;
        $row['id'] = (int) $row['id'];
        $s = json_decode((string) $row['settings'], true);
        $row['settings'] = is_array($s) ? $s : array();
        $row['secrets'] = CN_Crypto::decrypt((string) $row['secrets']);
        return $row;
    }

    public static function all($only_active = false) {
        global $wpdb;
        $sql = 'SELECT * FROM ' . CN_DB::t('panels') . ($only_active ? " WHERE status = 'active'" : '') . ' ORDER BY id ASC';
        return array_map(array(__CLASS__, 'hydrate'), (array) $wpdb->get_results($sql, ARRAY_A));
    }

    public static function get($id) {
        global $wpdb;
        return self::hydrate($wpdb->get_row($wpdb->prepare('SELECT * FROM ' . CN_DB::t('panels') . ' WHERE id = %d', $id), ARRAY_A));
    }

    /** @return CN_Driver|null */
    public static function driver($panel) {
        if (!is_array($panel)) {
            $panel = self::get((int) $panel);
        }
        return $panel ? CN_Drivers::make($panel) : null;
    }

    /**
     * Save a panel. Secret fields left empty keep their stored value.
     * @return int|WP_Error panel id
     */
    public static function save(array $in, $id = 0) {
        global $wpdb;
        $type = isset($in['type']) ? sanitize_key($in['type']) : '';
        $cls = CN_Drivers::cls($type);
        if (!$cls) {
            return new WP_Error('cn_type', 'نوع پنل نامعتبر است.');
        }
        $name = trim(sanitize_text_field(isset($in['name']) ? $in['name'] : ''));
        if ($name === '') {
            return new WP_Error('cn_name', 'نام پنل را وارد کنید.');
        }
        $url = '';
        if ($cls::uses_url()) {
            $url = esc_url_raw(trim(isset($in['url']) ? $in['url'] : ''));
            if ($url === '' && $type !== 'connectix') {
                return new WP_Error('cn_url', 'آدرس پنل را وارد کنید.');
            }
            $url = rtrim($url, '/');
        }

        $old = $id ? self::get($id) : null;
        $old_secrets = ($old && $old['type'] === $type) ? $old['secrets'] : array();
        $settings = array();
        $secrets = array();
        $values = isset($in['f']) && is_array($in['f']) ? $in['f'] : array();
        foreach ($cls::fields() as $key => $f) {
            $raw = isset($values[$key]) ? wp_unslash($values[$key]) : '';
            if (!empty($f['secret'])) {
                $v = trim((string) $raw);
                if ($v === '' && isset($old_secrets[$key])) {
                    $v = $old_secrets[$key];
                }
                if ($v === '' && !empty($f['required'])) {
                    return new WP_Error('cn_req', 'فیلد «' . $f['label'] . '» الزامی است.');
                }
                $secrets[$key] = $v;
                continue;
            }
            $type_f = isset($f['type']) ? $f['type'] : 'text';
            if ($type_f === 'checkbox') {
                $v = empty($raw) ? 0 : 1;
            } elseif ($type_f === 'textarea') {
                $v = trim(sanitize_textarea_field($raw));
            } elseif ($type_f === 'number') {
                $v = $raw === '' ? '' : (string) (0 + $raw);
            } elseif ($type_f === 'url') {
                $v = esc_url_raw(trim($raw));
            } else {
                $v = trim(sanitize_text_field($raw));
            }
            if ($v === '' && !empty($f['required'])) {
                return new WP_Error('cn_req', 'فیلد «' . $f['label'] . '» الزامی است.');
            }
            if ($v !== '' && self::is_json_field($key, $f) && json_decode($v) === null) {
                return new WP_Error('cn_json', 'فیلد «' . $f['label'] . '» باید JSON معتبر باشد.');
            }
            $settings[$key] = $v;
        }

        $row = array(
            'name'     => $name,
            'type'     => $type,
            'url'      => $url,
            'settings' => wp_json_encode($settings),
            'secrets'  => CN_Crypto::encrypt($secrets),
            'status'   => (isset($in['status']) && $in['status'] === 'inactive') ? 'inactive' : 'active',
        );
        if ($id) {
            $wpdb->update(CN_DB::t('panels'), $row, array('id' => (int) $id));
            $saved_id = (int) $id;
        } else {
            $row['created_at'] = CN_DB::now();
            $wpdb->insert(CN_DB::t('panels'), $row);
            $saved_id = (int) $wpdb->insert_id;
        }

        // Auto-add origin to Polban allowlist if use_polban is enabled
        if (!empty($settings['use_polban']) && $url !== '') {
            self::add_to_polban_allowlist($url);
        }

        return $saved_id;
    }

    private static function add_to_polban_allowlist($panel_url) {
        $polban_url = rtrim(get_option('cn_polban_url', ''), '/');
        $polban_key = get_option('cn_polban_key', '');
        if (!$polban_url || !$polban_key) {
            return;
        }
        $origin = self::extract_origin($panel_url);
        if ($origin === '') {
            return;
        }
        $res = CN_Http::request('POST', $polban_url . '/v1/allowlist', array(
            'headers' => array(
                'X-Polban-Key' => $polban_key,
                'Content-Type' => 'application/json',
            ),
            'json' => array('origin' => $origin),
            'timeout' => 15,
            'verify' => true,
        ));
        if (empty($res['ok'])) {
            CN_Util::log('افزودن مبدأ به پل‌بان ناموفق: ' . (isset($res['error']) ? $res['error'] : ''), array(), 'error');
        }
    }

    private static function extract_origin($url) {
        $p = wp_parse_url($url);
        if (empty($p['scheme']) || empty($p['host'])) {
            return '';
        }
        $o = strtolower($p['scheme']) . '://' . strtolower($p['host']);
        if (!empty($p['port'])) {
            $o .= ':' . (int) $p['port'];
        }
        return $o;
    }

    public static function is_json_field($key, array $f) {
        return (isset($f['type']) && $f['type'] === 'textarea') && in_array($key, array('proxies', 'inbounds', 'services'), true);
    }

    public static function delete($id) {
        global $wpdb;
        $used = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . CN_DB::t('services') . " WHERE panel_id = %d AND status <> 'deleted'", $id));
        if ($used > 0) {
            return new WP_Error('cn_used', 'این پنل ' . CN_Util::fa_digits($used) . ' سرویس فعال دارد؛ به‌جای حذف، غیرفعالش کنید.');
        }
        foreach ($wpdb->get_col($wpdb->prepare('SELECT id FROM ' . CN_DB::t('plans') . ' WHERE panel_id = %d', $id)) as $plan_id) {
            CN_Plans::delete($plan_id);
            $wpdb->delete(CN_DB::t('stock'), array('plan_id' => (int) $plan_id));
        }
        $wpdb->delete(CN_DB::t('services'), array('panel_id' => (int) $id));
        $wpdb->delete(CN_DB::t('panels'), array('id' => (int) $id));
        return true;
    }

    public static function test($id) {
        global $wpdb;
        $d = self::driver($id);
        if (!$d) {
            return array('ok' => false, 'msg' => 'پنل پیدا نشد.');
        }
        try {
            $r = $d->test();
        } catch (Throwable $e) {
            $r = array('ok' => false, 'msg' => $e->getMessage());
        }
        $msg = isset($r['msg']) ? (string) $r['msg'] : '';
        $wpdb->update(CN_DB::t('panels'), array(
            'last_check'     => CN_DB::now(),
            'last_check_ok'  => empty($r['ok']) ? 0 : 1,
            'last_check_msg' => mb_substr($msg, 0, 250),
        ), array('id' => (int) $id));
        CN_Util::log('تست اتصال: ' . $msg, array('panel_id' => $id), empty($r['ok']) ? 'error' : 'info');
        return $r;
    }
}

