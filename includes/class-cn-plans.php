<?php
if (!defined('ABSPATH')) {
    exit;
}

class CN_Plans {
    const META = '_cn_plan_id';

    private static function hydrate($row) {
        if (!$row) {
            return null;
        }
        $row = (array) $row;
        foreach (array('id', 'panel_id', 'days', 'users_limit', 'product_id', 'sort') as $k) {
            $row[$k] = (int) $row[$k];
        }
        $row['volume_gb'] = (float) $row['volume_gb'];
        $o = json_decode((string) $row['options'], true);
        $row['options'] = is_array($o) ? $o : array();
        return $row;
    }

    public static function all($panel_id = 0) {
        global $wpdb;
        $t = CN_DB::t('plans');
        $sql = $panel_id
            ? $wpdb->prepare("SELECT * FROM $t WHERE panel_id = %d ORDER BY sort, id", $panel_id)
            : "SELECT * FROM $t ORDER BY panel_id, sort, id";
        return array_map(array(__CLASS__, 'hydrate'), (array) $wpdb->get_results($sql, ARRAY_A));
    }

    public static function get($id) {
        global $wpdb;
        return self::hydrate($wpdb->get_row($wpdb->prepare('SELECT * FROM ' . CN_DB::t('plans') . ' WHERE id = %d', $id), ARRAY_A));
    }

    /** Plan attached to a product or variation (variation first, then its parent). */
    public static function for_product($product) {
        $product = is_numeric($product) ? wc_get_product($product) : $product;
        if (!$product) {
            return null;
        }
        $id = (int) $product->get_meta(self::META);
        if (!$id && $product->get_parent_id()) {
            $parent = wc_get_product($product->get_parent_id());
            $id = $parent ? (int) $parent->get_meta(self::META) : 0;
        }
        return $id ? self::get($id) : null;
    }

    /** @return int|WP_Error */
    public static function save(array $in, $id = 0) {
        global $wpdb;
        $panel = CN_Panels::get((int) (isset($in['panel_id']) ? $in['panel_id'] : 0));
        if (!$panel) {
            return new WP_Error('cn_panel', 'پنل را انتخاب کنید.');
        }
        $name = trim(sanitize_text_field(isset($in['name']) ? $in['name'] : ''));
        if ($name === '') {
            return new WP_Error('cn_name', 'نام پلن را وارد کنید.');
        }
        $cls = CN_Drivers::cls($panel['type']);
        $options = array();
        $vals = isset($in['o']) && is_array($in['o']) ? $in['o'] : array();
        foreach ($cls::plan_fields() as $key => $f) {
            $raw = isset($vals[$key]) ? wp_unslash($vals[$key]) : '';
            $v = (isset($f['type']) && $f['type'] === 'textarea') ? trim(sanitize_textarea_field($raw)) : trim(sanitize_text_field($raw));
            if ($v === '' && !empty($f['required'])) {
                return new WP_Error('cn_req', 'فیلد «' . $f['label'] . '» الزامی است.');
            }
            if ($v !== '' && CN_Panels::is_json_field($key, $f) && json_decode($v) === null) {
                return new WP_Error('cn_json', 'فیلد «' . $f['label'] . '» باید JSON معتبر باشد.');
            }
            if ($v !== '') {
                $options[$key] = $v;
            }
        }
        if (method_exists($cls, 'plan_suggestions') && isset($options['template'])) {
            $known = $cls::plan_suggestions($panel);
            if ($known && !in_array($options['template'], $known, true)) {
                return new WP_Error('cn_tpl', 'نوع پلن «' . $options['template'] . '» در تنظیمات این پنل تعریف نشده؛ یکی از این‌ها را بنویسید: ' . implode('، ', $known));
            }
        }
        $row = array(
            'panel_id'    => (int) $panel['id'],
            'name'        => $name,
            'volume_gb'   => max(0, round((float) (isset($in['volume_gb']) ? $in['volume_gb'] : 0), 2)),
            'days'        => max(0, (int) (isset($in['days']) ? $in['days'] : 0)),
            'users_limit' => max(0, (int) (isset($in['users_limit']) ? $in['users_limit'] : 0)),
            'options'     => wp_json_encode($options),
            'renew_mode'  => (isset($in['renew_mode']) && $in['renew_mode'] === 'reset') ? 'reset' : 'add',
            'status'      => (isset($in['status']) && $in['status'] === 'inactive') ? 'inactive' : 'active',
            'sort'        => (int) (isset($in['sort']) ? $in['sort'] : 0),
        );
        if ($id) {
            $wpdb->update(CN_DB::t('plans'), $row, array('id' => (int) $id));
            return (int) $id;
        }
        $row['created_at'] = CN_DB::now();
        $wpdb->insert(CN_DB::t('plans'), $row);
        return (int) $wpdb->insert_id;
    }

    public static function delete($id) {
        global $wpdb;
        $plan = self::get($id);
        if (!$plan) {
            return true;
        }
        if ($plan['product_id'] && ($p = wc_get_product($plan['product_id']))) {
            $p->delete_meta_data(self::META);
            $p->save();
        }
        $wpdb->delete(CN_DB::t('stock'), array('plan_id' => (int) $id, 'status' => 'free'));
        $wpdb->delete(CN_DB::t('plans'), array('id' => (int) $id));
        return true;
    }

    /** Link (or unlink with 0) a product to a plan; keeps plans.product_id in sync. */
    public static function link_product($product_id, $plan_id) {
        global $wpdb;
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }
        $old = (int) $product->get_meta(self::META);
        if ($plan_id) {
            $product->update_meta_data(self::META, (int) $plan_id);
        } else {
            $product->delete_meta_data(self::META);
        }
        $product->save();
        if ($old && $old !== (int) $plan_id) {
            $wpdb->update(CN_DB::t('plans'), array('product_id' => 0), array('id' => $old, 'product_id' => (int) $product_id));
        }
        if ($plan_id) {
            $wpdb->update(CN_DB::t('plans'), array('product_id' => (int) $product_id), array('id' => (int) $plan_id));
        }
    }

    /** Create a draft virtual product for a plan in the network category. */
    public static function create_product($plan_id, $price = '') {
        $plan = self::get($plan_id);
        if (!$plan) {
            return new WP_Error('cn_plan', 'پلن پیدا نشد.');
        }
        $p = new WC_Product_Simple();
        $p->set_name($plan['name']);
        $p->set_status('draft');
        $p->set_virtual(true);
        $p->set_sold_individually(false);
        if ($price !== '') {
            $p->set_regular_price(wc_format_decimal($price));
        }
        $p->set_short_description(self::summary($plan));
        $cat = get_term_by('slug', CN_Util::setting('category_slug', 'network'), 'product_cat');
        if ($cat) {
            $p->set_category_ids(array((int) $cat->term_id));
        }
        $p->update_meta_data(self::META, (int) $plan_id);
        $id = $p->save();
        self::link_product($id, $plan_id);
        return $id;
    }

    public static function summary(array $plan) {
        $parts = array('حجم: ' . CN_Util::gb_label($plan['volume_gb']), 'مدت: ' . CN_Util::days_label($plan['days']));
        if ($plan['users_limit'] > 0) {
            $parts[] = 'کاربر همزمان: ' . CN_Util::fa_digits($plan['users_limit']);
        }
        return implode(' · ', $parts);
    }
}
