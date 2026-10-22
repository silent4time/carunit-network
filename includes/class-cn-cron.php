<?php
if (!defined('ABSPATH')) {
    exit;
}

class CN_Cron {
    const HOOK = 'cn_sync_services';
    const BATCH = 25;

    public static function init() {
        add_action(self::HOOK, array(__CLASS__, 'run'));
        if (!wp_next_scheduled(self::HOOK)) {
            self::schedule();
        }
    }

    public static function schedule() {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 300, 'hourly', self::HOOK);
        }
    }

    public static function unschedule() {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /** Refresh the least recently synced services and prune old logs. */
    public static function run() {
        global $wpdb;
        if (CN_Util::setting('sync_enabled', '1') !== '1') {
            return;
        }
        if (!CN_Util::lock('cron', 900)) {
            return;
        }
        try {
            $t = CN_DB::t('services');
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT s.id FROM $t s JOIN " . CN_DB::t('panels') . " p ON p.id = s.panel_id
                 WHERE s.status NOT IN ('deleted') AND p.status = 'active' AND p.type <> 'manual'
                 ORDER BY s.synced_at IS NOT NULL, s.synced_at ASC LIMIT %d",
                self::BATCH
            ));
            foreach ($ids as $id) {
                $svc = CN_Services::get($id);
                if ($svc) {
                    CN_Services::refresh($svc);
                }
            }
            $days = max(7, (int) CN_Util::setting('log_days', 60));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . CN_DB::t('logs') . ' WHERE created_at < %s', gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)));
        } finally {
            CN_Util::unlock('cron');
        }
    }
}
