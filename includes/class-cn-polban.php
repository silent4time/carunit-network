<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Polban settings section for the Network Tools plugin.
 */
class CN_Polban {

    public static function init() {
        if (!is_admin()) {
            return;
        }
        add_action('admin_post_cn_save_settings', array(__CLASS__, 'save'), 5);
        add_action('admin_footer', array(__CLASS__, 'inject_if_needed'));
    }

    public static function save() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        if (!isset($_POST['cn_polban_url']) && !isset($_POST['cn_polban_key'])) {
            return;
        }
        if (isset($_POST['cn_polban_url'])) {
            update_option('cn_polban_url', esc_url_raw(wp_unslash($_POST['cn_polban_url'])));
        }
        if (isset($_POST['cn_polban_key']) && $_POST['cn_polban_key'] !== '') {
            update_option('cn_polban_key', sanitize_text_field(wp_unslash($_POST['cn_polban_key'])));
        }
    }

    public static function render() {
        $url = get_option('cn_polban_url', '');
        $key = get_option('cn_polban_key', '');
        ?>
        <div class="cn-box" style="margin-top:20px;">
            <h2>تنظیمات پل‌بان</h2>
            <div class="cn-row">
                <label for="cn_polban_url">آدرس سرویس پل‌بان</label>
                <div class="cn-row__in">
                    <input type="url" id="cn_polban_url" name="cn_polban_url" value="<?php echo esc_attr($url); ?>" dir="ltr" placeholder="https://polban.example.com">
                    <p class="cn-help">آدرس کامل سرویس پل‌بان (بدون اسلش در انتها).</p>
                </div>
            </div>
            <div class="cn-row">
                <label for="cn_polban_key">کلید API پل‌بان</label>
                <div class="cn-row__in">
                    <input type="password" id="cn_polban_key" name="cn_polban_key" value="" autocomplete="new-password" dir="ltr" placeholder="<?php echo $key ? '•••••• ذخیره شده — برای تغییر مقدار جدید وارد کنید' : ''; ?>">
                    <p class="cn-help">کلید API ساخته‌شده در پنل پل‌بان.</p>
                </div>
            </div>
        </div>
        <?php
    }

    public static function inject_if_needed() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || strpos($screen->id, 'cn-settings') === false) {
            return;
        }
        ?>
        <script>
        (function(){
            var form = document.querySelector('.cn-admin form.cn-form');
            if (!form || form.querySelector('#cn_polban_url')) return;
            var box = document.createElement('div');
            box.innerHTML = <?php echo json_encode(self::get_render_html()); ?>;
            var submit = form.querySelector('.cn-formbar');
            if (submit) {
                submit.parentNode.insertBefore(box, submit);
            } else {
                form.appendChild(box);
            }
        })();
        </script>
        <?php
    }

    private static function get_render_html() {
        ob_start();
        self::render();
        return ob_get_clean();
    }
}

CN_Polban::init();
