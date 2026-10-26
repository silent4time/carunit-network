<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * «سرویس شما آماده شد» — sent when accounts are delivered after the order's status email already went out.
 * Dedupe lives in CN_Woo::send_ready_mail (_cn_mailed_services).
 */
class CN_Email_Service_Ready extends WC_Email {
    /** @var array list of [service, renewed] */
    public $services = array();
    /** @var array undelivered network lines [name, qty, error] (v1.2.5) */
    public $pending = array();

    public function __construct() {
        $this->id             = 'cn_service_ready';
        $this->customer_email = true;
        $this->title          = 'ابزار شبکه: سرویس شما آماده شد';
        $this->description    = 'وقتی اکانت سفارش بعد از ایمیل وضعیت سفارش آماده شود (مثلاً بعد از تلاش دوباره)، اطلاعات سرویس با این ایمیل برای مشتری فرستاده می‌شود.';
        $this->placeholders   = array('{order_number}' => '', '{order_date}' => '');
        parent::__construct();
    }

    public function get_default_subject() {
        return 'سرویس سفارش {order_number} آماده شد';
    }

    public function get_default_heading() {
        return 'سرویس شما آماده شد';
    }

    public function trigger($order_id, $order = false, array $services = array()) {
        $this->setup_locale();
        if ($order_id && !is_a($order, 'WC_Order')) {
            $order = wc_get_order($order_id);
        }
        $sent = false;
        if (is_a($order, 'WC_Order')) {
            $this->object    = $order;
            $this->recipient = $order->get_billing_email();
            $this->services  = (func_num_args() >= 3) ? $services : CN_Woo::order_services($order);
            $this->pending   = CN_Woo::pending_items($order);
            $this->placeholders['{order_number}'] = $order->get_order_number();
            $this->placeholders['{order_date}']   = wc_format_datetime($order->get_date_created());
            if ($this->is_enabled() && $this->get_recipient() && ($this->services || $this->pending)) {
                $sent = $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
            }
        }
        $this->restore_locale();
        return $sent;
    }

    private function account_url() {
        return wc_get_account_endpoint_url(CN_Account::ENDPOINT);
    }

    public function get_content_html() {
        ob_start();
        do_action('woocommerce_email_header', $this->get_heading(), $this);
        echo '<div dir="rtl" lang="fa" style="font-family:Tahoma,Arial,sans-serif;direction:rtl;text-align:right">';
        $no = esc_html($this->object->get_order_number());
        echo '<p style="margin:0 0 16px">' . ($this->pending ? 'سلام، بخشی از سفارش ' . $no . ' آماده شد و در ادامه آمده است؛ موارد «در حال انجام» به‌زودی ارسال می‌شوند.' : 'سلام، اطلاعات سرویس سفارش ' . $no . ' آماده شد و در ادامه آمده است.') . '</p>';
        foreach ($this->services as $s) {
            echo CN_Account::email_service($s[0], $s[1]);
        }
        // Other CarUnit plugins (license manager) add their delivered/pending boxes here.
        do_action('cn_ready_mail_extra', $this->object, false, $this);
        foreach ($this->pending as $p) {
            echo CN_Woo::pending_box_html($p[0], $p[1]);
        }
        echo '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:4px 0 8px"><tr><td align="center" bgcolor="#0D0D0D" style="border-radius:8px">';
        echo '<a href="' . esc_url($this->account_url()) . '" target="_blank" style="display:block;padding:12px 16px;color:#F5C518;background:#0D0D0D;border-radius:8px;font-weight:bold;font-size:14px;text-decoration:none;text-align:center">رفتن به سرویس‌های من</a></td></tr></table>';
        echo '<p style="color:#6E6E6E;font-size:12px;margin:6px 0 0">حجم مصرفی، تاریخ انقضا، فایل‌های اتصال و تمدید همیشه در حساب کاربری شما در دسترس است.</p>';
        echo '</div>';
        $additional = $this->get_additional_content();
        if ($additional) {
            echo '<div dir="rtl" style="text-align:right">' . wp_kses_post(wpautop(wptexturize($additional))) . '</div>';
        }
        do_action('woocommerce_email_footer', $this);
        return ob_get_clean();
    }

    public function get_content_plain() {
        $out = "==== " . $this->get_heading() . " ====\n\n";
        foreach ($this->services as $s) {
            $out .= CN_Account::plain_service($s[0], $s[1]) . "\n\n";
        }
        ob_start();
        do_action('cn_ready_mail_extra', $this->object, true, $this);
        $out .= ob_get_clean();
        foreach ($this->pending as $p) {
            $out .= '[در حال انجام] ' . $p[0] . ' (' . (int) $p[1] . " عدد) — به‌زودی برایتان ارسال می‌شود.\n";
        }
        $out .= 'سرویس‌های من (مصرف، فایل‌ها و تمدید): ' . esc_url_raw($this->account_url()) . "\n";
        return $out;
    }
}
