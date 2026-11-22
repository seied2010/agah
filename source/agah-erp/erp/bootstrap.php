<?php
/**
 * نقطهٔ ورود سامانهٔ حسابداری (ERP). همهٔ فایل‌های erp/ از اینجا بارگذاری می‌شوند.
 * استقلال: این پوشه به هیچ تابع قالب (agah_*) وابسته نیست؛ برای تبدیل به افزونه فقط همین پوشه + یک هدر افزونه لازم است.
 * ثابت‌های لازم: ABSPATH (وردپرس). مسیر پایه از AGAH_ERP_DIR گرفته می‌شود.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'AGAH_ERP_DIR', __DIR__ );
define( 'AGAH_ERP_VERSION', '0.28.0-alpha1' );

if ( version_compare( PHP_VERSION, '7.4', '<' ) || ! extension_loaded( 'sodium' ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p>سامانهٔ حسابداری آگاه به PHP نسخهٔ ۷٫۴ یا بالاتر و افزونهٔ sodium نیاز دارد؛ سامانه غیرفعال شد.</p></div>';
	} );
	return;
}

foreach ( array(
	'Core/KeySetup', 'Core/Tabular', 'Core/FiscalContext', 'Core/PrecisionMigration', 'Core/Errors', 'Core/Db', 'Core/Money', 'Core/Jalali', 'Core/Crypto', 'Core/Request', 'Core/Settings', 'Core/Sequence',
	'Core/ManufacturingSchema', 'Core/ConnectSchema', 'Core/Phase2Schema', 'Core/Business', 'Core/BusinessSchema', 'Core/TradeSchema', 'Core/FinanceSchema', 'Core/OperationsSchema', 'Core/ReleaseSetup', 'Core/TradeMath', 'Core/Schema', 'Core/Access', 'Core/Ctx', 'Core/Audit', 'Core/Idempotency', 'Security/Caps',
	'Security/RateLimiter', 'Security/Totp', 'Security/TwoFactor', 'Security/SmsProvider', 'Security/SmsOtp', 'Security/Auth', 'Http/SecurityController',
	'Modules/Accounting/ChartSeed', 'Modules/Accounting/FiscalService', 'Modules/Accounting/AccountService',
	'Modules/Maintenance/BackupService', 'Modules/Maintenance/PortableBackupService', 'Modules/Maintenance/HealthService', 'Modules/Legal/LegalService', 'Modules/Files/FileService', 'Modules/Payroll/PayrollMath', 'Modules/Payroll/PayrollService', 'Modules/Payroll/PayItemService', 'Modules/Payroll/HrService', 'Modules/Payroll/PayrollRunService', 'Modules/Assets/AssetMath', 'Modules/Assets/AssetService', 'Modules/Assets/AssetCustodyService', 'Modules/Dashboard/DashboardService', 'Modules/Expenses/ExpenseService', 'Modules/Accounting/FinancialService', 'Modules/Accounting/ClosingService', 'Modules/Inventory/StockService', 'Modules/Trade/InvoiceService', 'Modules/Trade/SalesControlService', 'Modules/Notify/NotificationService', 'Modules/Subscription/SubscriptionService', 'Core/CsvReader', 'Modules/Import/MigrationService', 'Modules/Moadian/MoadianService','Modules/Parties/PartyService', 'Modules/Catalog/CatalogService', 'Modules/Treasury/TreasuryService', 'Modules/Connect/ConnectService', 'Modules/Accounting/RatioService', 'Core/XlsxReader', 'Modules/Import/ImportService', 'Modules/Import/BankMatchService', 'Http/Phase2Controller', 'Http/ConnectController', 'Http/ConnectApi',
	'Modules/Manufacturing/ProductionService', 'Http/ProductionController', 'Modules/Company/CompanyService', 'Modules/Company/MemberService', 'Modules/Company/CompanyPolicyService', 'Modules/Company/CompanyLifecycleService', 'Http/CompanyAdmin', 'Modules/Accounting/DetailService', 'Modules/Accounting/VoucherService', 'Modules/Accounting/ReportService', 'Http/LegalController', 'Http/OperationsController', 'Http/Navigation', 'Http/FinanceController', 'Http/TradeController', 'Http/TradeEntryController', 'Http/CommerceController', 'Http/ModuleController', 'Http/Page', 'Http/SiteAdmin',
) as $f ) {
	require_once AGAH_ERP_DIR . '/src/' . $f . '.php';
}

/** نشانی صفحهٔ سامانه. */
function agah_erp_url( $args = array() ) {
	$id  = (int) get_option( 'agah_erp_page_id' );
	$url = $id ? get_permalink( $id ) : '';
	$url = $url ? $url : home_url( '/accounting/' );
	return $args ? add_query_arg( $args, $url ) : $url;
}

/** ساخت پایگاه داده و صفحهٔ /accounting/ هنگام فعال‌سازی افزونه. */
function agah_erp_activate() {
	\Agah\Erp\Core\Schema::install();
	agah_erp_ensure_page();
}

function agah_erp_ensure_page() {
	$id = (int) get_option( 'agah_erp_page_id' );
	if ( $id && 'page' === get_post_type( $id ) && 'trash' !== get_post_status( $id ) ) {
		return;
	}
	$existing = get_page_by_path( 'accounting' );
	if ( $existing ) {
		$id = (int) $existing->ID;
		if ( false === strpos( $existing->post_content, '[agah_erp]' ) ) {
			wp_update_post( array( 'ID' => $id, 'post_content' => $existing->post_content . "\n[agah_erp]" ) );
		}
	} else {
		$id = (int) wp_insert_post( array(
			'post_title' => 'سامانه حسابداری', 'post_name' => 'accounting', 'post_status' => 'publish', 'post_type' => 'page', 'post_content' => '[agah_erp]',
		) );
	}
	if ( $id ) {
		update_option( 'agah_erp_page_id', $id, false );
	}
}

/** ارتقای خودکار ساختار پایگاه داده (فقط وقتی نسخه عوض شده باشد). */
add_action( 'init', array( '\Agah\Erp\Core\Schema', 'maybeUpgrade' ), 5 );
add_action( 'init', 'agah_erp_ensure_page', 6 );

/** هشدار مدیریتی: موتور غیر InnoDB یا نبود کلیدهای خارجی = خطر ناسازگاری داده. */
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$eng = (array) get_option( 'agah_erp_engine_error', array() );
	$fk  = \Agah\Erp\Core\Schema::missingForeignKeys();
	if ( $eng ) {
		echo '<div class="notice notice-error"><p><strong>حسابداری آگاه:</strong> جدول‌های زیر InnoDB نیستند و تراکنش مالی امن نیست: <code>' . esc_html( implode( ', ', $eng ) ) . '</code>. موتور پایگاه داده را به InnoDB تبدیل کنید.</p></div>';
	}
	if ( $fk ) {
		echo '<div class="notice notice-warning"><p><strong>حسابداری آگاه:</strong> برخی کلیدهای خارجی ساخته نشد: <code>' . esc_html( implode( ', ', $fk ) ) . '</code>. جزئیات در لاگ خطای سرور.</p></div>';
	}
    if ( ! \Agah\Erp\Core\Crypto::hasConfiguredKey() ) {
        echo '<div class="notice notice-warning"><p><strong>حسابداری آگاه:</strong> کلید پایدار رمزنگاری هنوز فعال نیست. از <a href="'.esc_url(admin_url('admin.php?page=agah-access#agah-crypto-security')).'">مدیریت حسابداری ← امنیت رمزنگاری</a> دکمهٔ فعال‌سازی خودکار را بزنید؛ نیازی به ویرایش فایل هاست نیست.</p></div>';
	}
} );

require_once AGAH_ERP_DIR . '/customizer.php';

\Agah\Erp\Http\Page::register();

\Agah\Erp\Security\Auth::register();
\Agah\Erp\Http\SecurityController::register();
add_action('agah_erp_daily', ['\Agah\Erp\Core\Idempotency', 'cleanup']);
add_action('init', function(){ if(!wp_next_scheduled('agah_erp_daily')) wp_schedule_event(time()+3600, 'daily', 'agah_erp_daily'); });

\Agah\Erp\Http\ModuleController::register();

\Agah\Erp\Modules\Maintenance\BackupService::register();
\Agah\Erp\Modules\Maintenance\PortableBackupService::register();

\Agah\Erp\Http\SiteAdmin::register();
\Agah\Erp\Http\CompanyAdmin::register();
add_action( 'template_redirect', array( '\Agah\Erp\Http\Phase2Controller', 'publicShare' ), 1 );

/** اشتراک: ووکامرس فقط سفارش و پرداخت؛ فعال‌سازی پس از «پرداخت کامل» تأییدشدهٔ درگاه یا تأیید مدیر برای پرداخت در محل. */
$agah_sub_order = function ( $order ) {
	if ( ! is_object( $order ) || ! (int) $order->get_meta( '_agah_subscription' ) ) return null;
	return array( (int) $order->get_meta( '_agah_subscription' ), 'woo:' . $order->get_id(), \Agah\Erp\Core\Money::fromInput( (string) $order->get_total(), 'IRR' === $order->get_currency() ? 'rial' : 'toman' ) );
};
add_action( 'woocommerce_payment_complete', function ( $id ) use ( $agah_sub_order ) { $s = $agah_sub_order( wc_get_order( $id ) ); if ( $s ) { try { \Agah\Erp\Modules\Subscription\SubscriptionService::confirmPayment( $s[0], $s[1], $s[2], 'gateway' ); } catch ( \Throwable $e ) { error_log( '[agah-subscription] ' . get_class( $e ) ); } } } );
add_action( 'woocommerce_order_status_processing', function ( $id, $order ) use ( $agah_sub_order ) { if ( ! is_object( $order ) || 'cod' !== $order->get_payment_method() ) return; $s = $agah_sub_order( $order ); if ( $s ) { try { \Agah\Erp\Modules\Subscription\SubscriptionService::confirmPayment( $s[0], $s[1], $s[2], 'cod' ); } catch ( \Throwable $e ) { error_log( '[agah-subscription] ' . get_class( $e ) ); } } }, 10, 2 );
foreach ( array( 'failed', 'cancelled' ) as $agah_status ) add_action( 'woocommerce_order_status_' . $agah_status, function ( $id ) use ( $agah_sub_order ) { $s = $agah_sub_order( wc_get_order( $id ) ); if ( $s ) \Agah\Erp\Modules\Subscription\SubscriptionService::failPayment( $s[0], $s[1] ); } );
add_action( 'agah_erp_daily', array( '\Agah\Erp\Modules\Subscription\SubscriptionService', 'notifyExpiry' ) );
add_action( 'agah_erp_hourly', array( '\Agah\Erp\Modules\Notify\NotificationService', 'drain' ) );
add_action( 'agah_erp_share_sms_queue', array( '\Agah\Erp\Modules\Notify\NotificationService', 'drain' ) );
add_action( 'init', function () { if ( ! wp_next_scheduled( 'agah_erp_hourly' ) ) wp_schedule_event( time() + 600, 'hourly', 'agah_erp_hourly' ); } );

\Agah\Erp\Http\ConnectApi::register();
