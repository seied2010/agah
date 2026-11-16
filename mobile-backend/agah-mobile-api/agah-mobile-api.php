<?php
/**
 * Plugin Name: آگاه — API اپلیکیشن اندروید
 * Description: درگاه کاربران موبایل؛ نیازمند افزونه حسابداری آنلاین آگاه.
 * Version: 0.1.1-test30
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;
foreach (['Schema','Session','Policy','Operations','Payload','Files','Exports','Integration','Api'] as $file) require_once __DIR__.'/src/'.$file.'.php';
register_activation_hook(__FILE__, ['Agah\\Mobile\\Schema','install']);
add_action('plugins_loaded', function () {
    if (!class_exists('Agah\\Erp\\Core\\Ctx')) {
        add_action('admin_notices', function () { echo '<div class="notice notice-error"><p>API موبایل به افزونهٔ فعال حسابداری آگاه نیاز دارد.</p></div>'; });
        return;
    }
    \Agah\Mobile\Api::register();
    \Agah\Mobile\Policy::registerAdmin();
    \Agah\Mobile\Integration::register();
});
