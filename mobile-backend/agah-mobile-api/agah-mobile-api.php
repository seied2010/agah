<?php
/**
 * Plugin Name: آگاه — API اپلیکیشن اندروید
 * Description: درگاه کاربران موبایل؛ نیازمند افزونه حسابداری آنلاین آگاه.
 * Version: 0.4.0-rc1
 * Requires PHP: 8.0
 */
if (!defined('ABSPATH')) exit;
if(PHP_VERSION_ID<80000) {
    add_action('admin_notices',function () { echo '<div class="notice notice-error"><p>بستهٔ API موبایل و کتابخانهٔ PDF آن به PHP 8.0 یا جدیدتر نیاز دارند.</p></div>'; });
    return;
}
foreach (['Schema','Session','Policy','Operations','Payload','Files','Exports','Integration','Api'] as $file) require_once __DIR__.'/src/'.$file.'.php';
register_activation_hook(__FILE__, ['Agah\\Mobile\\Schema','install']);
add_action('plugins_loaded', function () {
    if (!class_exists('Agah\\Erp\\Core\\Ctx')) {
        add_action('admin_notices', function () { echo '<div class="notice notice-error"><p>API موبایل به افزونهٔ فعال حسابداری آگاه نیاز دارد.</p></div>'; });
        return;
    }
    if (!defined('AGAH_ERP_VERSION')||version_compare(AGAH_ERP_VERSION,'0.28.0-alpha1','<')) {
        add_action('admin_notices',function () { echo '<div class="notice notice-error"><p>این نسخهٔ API موبایل به حسابداری آگاه 0.28.0-alpha1 یا جدیدتر برای داشبورد بازه‌ای و یکپارچگی بازیابی نیاز دارد.</p></div>'; });
        return;
    }
    \Agah\Mobile\Api::register();
    \Agah\Mobile\Policy::registerAdmin();
    \Agah\Mobile\Integration::register();
});
