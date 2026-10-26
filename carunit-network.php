<?php
/**
 * Plugin Name: کاریونیت — ابزار شبکه
 * Description: اتصال به پنل‌های اکانت شبکه (مرزبان، پاسارگارد، مرزنشین، ربکا، 3x-ui، علیرضا، هیدیفای، s-ui، WGDashboard، IBSng، میکروتیک، میرزا، ایلان، کانکتیکس و فروش دستی)، ساخت خودکار اکانت بعد از پرداخت ووکامرس، تمدید و مدیریت سرویس‌ها.
 * Version: 1.2.5
 * Author: CarUnit
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Text Domain: carunit-network
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CN_VERSION', '1.2.5');
define('CN_FILE', __FILE__);
define('CN_DIR', plugin_dir_path(__FILE__));
define('CN_URL', plugin_dir_url(__FILE__));

require_once CN_DIR . 'includes/class-cn-db.php';
require_once CN_DIR . 'includes/class-cn-crypto.php';
require_once CN_DIR . 'includes/class-cn-http.php';
require_once CN_DIR . 'includes/class-cn-util.php';
require_once CN_DIR . 'includes/drivers/class-cn-driver.php';
require_once CN_DIR . 'includes/class-cn-panels.php';
require_once CN_DIR . 'includes/class-cn-plans.php';
require_once CN_DIR . 'includes/class-cn-services.php';
require_once CN_DIR . 'includes/class-cn-stock.php';
require_once CN_DIR . 'includes/class-cn-woo.php';
require_once CN_DIR . 'includes/class-cn-account.php';
require_once CN_DIR . 'includes/class-cn-cron.php';
require_once CN_DIR . 'includes/class-cn-polban.php';
require_once CN_DIR . 'includes/admin/class-cn-admin.php';

add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

register_activation_hook(__FILE__, array('CN_DB', 'install'));
register_deactivation_hook(__FILE__, array('CN_Cron', 'unschedule'));

add_action('plugins_loaded', function () {
    CN_DB::maybe_upgrade();
    if (!class_exists('WooCommerce')) {
        if (is_admin()) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p>افزونهٔ «کاریونیت — ابزار شبکه» به ووکامرس نیاز دارد.</p></div>';
            });
        }
        return;
    }
    CN_Woo::init();
    CN_Account::init();
    CN_Cron::init();
    if (is_admin()) {
        CN_Admin::init();
    }
});
