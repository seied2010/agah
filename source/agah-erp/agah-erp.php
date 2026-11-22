<?php
/**
 * Plugin Name: حسابداری آگاه
 * Description: سامانهٔ حسابداری چندشرکتی آگاه؛ مستقل از قالب سایت. صفحهٔ سامانه در نشانی /accounting/ است.
 * Version: 0.28.0-alpha1
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: IDPA
 * Text Domain: agah-erp
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/** قالب قدیمی که ERP را داخل خودش دارد نباید هم‌زمان با افزونه بالا بیاید؛ وگرنه کلاس‌ها دوبار تعریف می‌شوند و سایت از کار می‌افتد. */
if ( file_exists( get_template_directory() . '/erp/bootstrap.php' ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p><strong>حسابداری آگاه:</strong> قالب فعلی نسخهٔ قدیمی است و حسابداری را داخل خودش دارد. ابتدا قالب را به نسخهٔ جدید به‌روز کنید؛ افزونه تا آن زمان کاری انجام نمی‌دهد.</p></div>';
	} );
	return;
}

define( 'AGAH_ERP_URL', plugins_url( 'erp', __FILE__ ) );
require_once __DIR__ . '/erp/bootstrap.php';

register_activation_hook( __FILE__, function () {
	if ( function_exists( 'agah_erp_activate' ) ) {
		agah_erp_activate();
	}
} );

/** غیرفعال‌سازی فقط کارهای زمان‌بندی‌شده را متوقف می‌کند و هیچ داده‌ای را حذف نمی‌کند. */
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'agah_erp_daily' );
} );
