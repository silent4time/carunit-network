<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Pre-made accounts sold from the stock table (one row per account). */
class CN_Driver_Manual extends CN_Driver {

    public static function label() {
        return 'فروش دستی (انبار اکانت آماده)';
    }

    public static function uses_url() {
        return false;
    }

    /** Toggle/delete only change the status on the site; the account itself lives on your own server. */
    public static function caps() {
        return array('toggle', 'delete');
    }

    public function set_enabled(array $svc, $enabled) {
        return $this->ok(array('msg' => 'فقط وضعیت در سایت عوض شد؛ اکانت دستی را در سرور خودتان هم مدیریت کنید.'));
    }

    public function delete(array $svc) {
        return $this->ok(array('msg' => 'فقط از سایت حذف شد؛ اکانت دستی را در سرور خودتان هم حذف کنید.'));
    }

    public function test() {
        return $this->ok(array('msg' => 'فروش دستی به اتصال نیاز ندارد.'));
    }

    public function create(array $a) {
        $plan_id = isset($a['plan_id']) ? (int) $a['plan_id'] : 0;
        $row = CN_Stock::take($plan_id, isset($a['service_ref']) ? $a['service_ref'] : 0);
        if (!$row) {
            return $this->fail('موجودی انبار این پلن تمام شده است.');
        }
        return $this->ok(array(
            'username' => $a['username'],
            'sub_url'  => preg_match('#^https?://\S+$#i', trim($row['content'])) ? trim($row['content']) : '',
            'configs'  => array(array('اطلاعات اکانت', $row['content'], 'note')),
            'data'     => array('stock_id' => (int) $row['id']),
            'limit'    => (int) $a['bytes'],
            'expire'   => (int) $a['expire'],
        ));
    }

    public function info(array $svc) {
        $expire = CN_Util::db_to_ts(isset($svc['expires_at']) ? $svc['expires_at'] : '');
        return $this->ok(array(
            'status'  => ($expire && $expire < time()) ? 'expired' : 'active',
            'used'    => 0,
            'limit'   => (int) (isset($svc['limit_bytes']) ? $svc['limit_bytes'] : 0),
            'expire'  => $expire,
            'online'  => null,
            'sub_url' => isset($svc['sub_url']) ? $svc['sub_url'] : '',
            'configs' => array(),
        ));
    }
}
