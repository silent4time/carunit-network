<?php
if (!defined('ABSPATH')) {
    exit;
}

class CN_DB {
    const DB_VERSION = '1';

    public static function t($name) {
        global $wpdb;
        return $wpdb->prefix . 'cn_' . $name;
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE " . self::t('panels') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            type varchar(30) NOT NULL,
            url varchar(255) NOT NULL DEFAULT '',
            settings longtext NULL,
            secrets longtext NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            last_check datetime NULL,
            last_check_ok tinyint(1) NOT NULL DEFAULT 0,
            last_check_msg varchar(255) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY type (type)
        ) $c;");

        dbDelta("CREATE TABLE " . self::t('plans') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            panel_id bigint(20) unsigned NOT NULL,
            name varchar(150) NOT NULL,
            volume_gb decimal(10,2) NOT NULL DEFAULT 0,
            days int(11) NOT NULL DEFAULT 0,
            users_limit int(11) NOT NULL DEFAULT 0,
            options longtext NULL,
            product_id bigint(20) unsigned NOT NULL DEFAULT 0,
            renew_mode varchar(30) NOT NULL DEFAULT 'add',
            status varchar(20) NOT NULL DEFAULT 'active',
            sort int(11) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY panel_id (panel_id),
            KEY product_id (product_id)
        ) $c;");

        dbDelta("CREATE TABLE " . self::t('services') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            panel_id bigint(20) unsigned NOT NULL,
            plan_id bigint(20) unsigned NOT NULL DEFAULT 0,
            order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            order_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            username varchar(100) NOT NULL,
            sub_url text NULL,
            data longtext NULL,
            volume_gb decimal(10,2) NOT NULL DEFAULT 0,
            days int(11) NOT NULL DEFAULT 0,
            expires_at datetime NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            usage_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
            limit_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
            synced_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY panel_user (panel_id, username),
            KEY order_id (order_id),
            KEY order_item_id (order_item_id),
            KEY user_id (user_id),
            KEY status (status)
        ) $c;");

        dbDelta("CREATE TABLE " . self::t('stock') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            plan_id bigint(20) unsigned NOT NULL,
            content text NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'free',
            service_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            sold_at datetime NULL,
            PRIMARY KEY  (id),
            KEY plan_status (plan_id, status)
        ) $c;");

        dbDelta("CREATE TABLE " . self::t('logs') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            level varchar(10) NOT NULL DEFAULT 'info',
            panel_id bigint(20) unsigned NOT NULL DEFAULT 0,
            service_id bigint(20) unsigned NOT NULL DEFAULT 0,
            order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            message text NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at)
        ) $c;");

        update_option('cn_db_version', self::DB_VERSION, false);
        update_option('cn_flush_rewrite', 1, false);
        CN_Cron::schedule();
    }

    public static function maybe_upgrade() {
        if (get_option('cn_db_version') !== self::DB_VERSION) {
            self::install();
        }
    }

    public static function now() {
        return current_time('mysql');
    }
}
