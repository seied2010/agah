<?php
/** قرارداد آداپتور پیامک؛ ارائه‌دهنده فقط از راه فیلتر سرور ثبت می‌شود، کلید API فقط env/wp-config. */
namespace Agah\Erp\Security;
if (!defined('ABSPATH')) exit;
interface SmsProvider {
    /** ارسال کد به شمارهٔ از پیش تأییدشده؛ خطا استثنا می‌شود و کد نباید لاگ شود. */
    public function send($phone,$code);
}
