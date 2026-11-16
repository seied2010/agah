<?php
/**
 * صفحهٔ سامانه (/accounting/) — مسیریابی، پردازش فرم‌ها (POST) و نمایش.
 *
 * جریان یک درخواست:
 *  ۱) template_redirect: اگر POST به صفحهٔ سامانه باشد → handlePost(): nonce، مجوز (Ctx::need)، سرویس، سپس ریدایرکت (الگوی PRG).
 *  ۲) شورتکد [agah_erp] → render(): ورود نکرده = فرم ورود؛ بدون شرکت = فهرست شرکت‌ها؛ با c=<id> = Ctx::boot و نمایش صفحهٔ p.
 * امنیت: هر POST دارای nonce جدا برای همان عمل است؛ شناسهٔ شرکت فقط از طریق Ctx::boot (عضویت) معتبر می‌شود؛ خروجی همه جا escape می‌شود.
 * صفحه هرگز کش نمی‌شود و noindex است.
 */
namespace Agah\Erp\Http;

use Agah\Erp\Core\AppException;
use Agah\Erp\Core\Audit;
use Agah\Erp\Core\Ctx;
use Agah\Erp\Core\Db;
use Agah\Erp\Core\Jalali;
use Agah\Erp\Core\Money;
use Agah\Erp\Core\NotFoundException;
use Agah\Erp\Core\Request;
use Agah\Erp\Core\Settings;
use Agah\Erp\Core\ValidationException;
use Agah\Erp\Modules\Accounting\AccountService;
use Agah\Erp\Modules\Accounting\ChartSeed;
use Agah\Erp\Modules\Accounting\FiscalService;
use Agah\Erp\Modules\Company\CompanyService;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Page {

	public static function register() {
		add_shortcode( 'agah_erp', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'isolate' ), 999 );
		add_filter( 'template_include', array( __CLASS__, 'shell' ), 99 );
		add_action( 'wp_head', array( __CLASS__, 'head' ), 1 );
		add_action( 'wp_login_failed', array( __CLASS__, 'loginFailed' ) );
		add_filter( 'authenticate', array( __CLASS__, 'loginBlank' ), 30, 3 );
	}

	private static function isErpPage() {
		$id = (int) get_option( 'agah_erp_page_id' );
		return $id && is_page( $id );
	}

	/** صفحهٔ سامانه با پوستهٔ خود افزونه نمایش داده می‌شود تا با هر قالبی یکسان باشد. */
	public static function shell( $template ) {
		return self::isErpPage() ? AGAH_ERP_DIR . '/views/shell.php' : $template;
	}

	/** فایل‌های ظاهری قالب فعال در صفحهٔ سامانه بارگذاری نمی‌شوند. */
	public static function isolate() {
		if ( ! self::isErpPage() ) {
			return;
		}
		$root = get_theme_root_uri();
		foreach ( wp_styles()->queue as $h ) {
			if ( 0 === strpos( (string) ( wp_styles()->registered[ $h ]->src ?? '' ), $root ) ) {
				wp_dequeue_style( $h );
			}
		}
		foreach ( wp_scripts()->queue as $h ) {
			if ( 0 === strpos( (string) ( wp_scripts()->registered[ $h ]->src ?? '' ), $root ) ) {
				wp_dequeue_script( $h );
			}
		}
	}

	public static function head() {
		if ( self::isErpPage() ) {
			echo '<meta name="robots" content="noindex,nofollow">' . "\n";
		}
	}

	public static function assets() {
		if ( self::isErpPage() ) {
			wp_enqueue_style( 'agah-erp', AGAH_ERP_URL.'/assets/erp.css', array(), AGAH_ERP_VERSION );
			wp_enqueue_script( 'agah-erp-ui', AGAH_ERP_URL.'/assets/ui.js', array(), AGAH_ERP_VERSION, true );
		}
	}

	// ------------------------------------------------------------ ورود ناموفق → برگشت به همین صفحه

	public static function loginFailed( $username ) {
		$ref = wp_get_referer();
		$pg  = agah_erp_url();
		if ( $ref && 0 === strpos( $ref, $pg ) ) {
			wp_safe_redirect( add_query_arg( 'login', 'failed', $pg ) );
			exit;
		}
	}

	public static function loginBlank( $user, $username, $password ) {
		if ( ( '' === $username || '' === $password ) && isset( $_POST['log'] ) ) {
			$ref = wp_get_referer();
			if ( $ref && 0 === strpos( $ref, agah_erp_url() ) ) {
				wp_safe_redirect( add_query_arg( 'login', 'failed', agah_erp_url() ) );
				exit;
			}
		}
		return $user;
	}

	// ------------------------------------------------------------ POST


	/** اجرای عمل؛ همیشه مجوز را سمت سرور کنترل می‌کند. @return string پیام موفقیت */
	public static function dispatch( $act, $cid ) {
		$uid = get_current_user_id();

		if ( 'company_create' === $act ) {
			$platform = current_user_can( 'manage_options' );
			if ( $platform ) {
				$login = Request::str( 'post', 'owner_login', 60 );
				$owner = $login ? get_user_by( 'login', $login ) : get_userdata( $uid );
				if ( ! $owner ) {
					throw new ValidationException( array( 'owner' => 'کاربری با این نام کاربری پیدا نشد.' ) );
				}
				$owner = (int) $owner->ID;
			} elseif ( Settings::flag( 'allow_self_company' ) ) {
				$owner = $uid;
			} else {
				throw new \Agah\Erp\Core\ForbiddenException( 'ساخت شرکت فقط توسط مدیر سامانه ممکن است.' );
			}
			$id = \Agah\Erp\Modules\Company\CompanyService::create( $owner, array(
				'activity_type'=>Request::str('post','activity_type',20,'trading'),
                'name' => Request::str( 'post', 'name' ), 'legal_name' => Request::str( 'post', 'legal_name' ),
				'kind' => Request::str( 'post', 'kind', 10 ), 'display_unit' => Request::str( 'post', 'display_unit', 8 ), 'jy' => Request::str( 'post', 'jy', 4 ),
			) );
			return 'شرکت ساخته شد (شناسه ' . Money::toFa( $id ) . ').';
		}

		Ctx::boot( $uid, $cid ); // عضویت را از پایگاه داده تأیید می‌کند

		switch ( $act ) {
			case 'account_create':
				Ctx::need( 'account.create' );
				AccountService::create( $cid, array(
					'parent_id' => Request::int( 'post', 'parent_id' ), 'code' => Request::str( 'post', 'code', 10 ), 'name' => Request::str( 'post', 'name' ),
					'detail_rule' => Request::str( 'post', 'detail_rule', 10, 'none' ),
				) );
				return 'حساب ساخته شد.';
			case 'account_toggle':
				Ctx::need( 'account.edit' );
				AccountService::setActive( $cid, Request::int( 'post', 'id' ), Request::flag( 'post', 'active' ) );
				return 'وضعیت حساب تغییر کرد.';
			case 'account_delete':
				Ctx::need( 'account.delete' );
				AccountService::delete( $cid, Request::int( 'post', 'id' ) );
				return 'حساب حذف شد.';
			case 'fy_create':
				Ctx::need( 'fiscal_year.create' );
				FiscalService::createYear( $cid, (int) Money::normDigits( Request::str( 'post', 'jy', 4 ) ) );
				return 'سال مالی ساخته شد.';
			case 'period_toggle':
				Ctx::need( 'fiscal_year.edit' );
				FiscalService::setPeriodStatus( $cid, Request::int( 'post', 'id' ), Request::flag( 'post', 'open' ) ? 'open' : 'closed' );
				return 'وضعیت دوره تغییر کرد.';
		}
		throw new AppException( 'عمل نامعتبر است.' );
	}

	// ------------------------------------------------------------ نمایش

	private static function nonce( $act ) {
		return '<input type="hidden" name="_idem" value="'.esc_attr(\Agah\Erp\Core\Idempotency::issue(get_current_user_id(), Ctx::cidOrZero(), $act)).'"><input type="hidden" name="_n" value="' . esc_attr( wp_create_nonce( 'agah_erp_' . $act ) ) . '"><input type="hidden" name="act" value="' . esc_attr( $act ) . '">';
	}

	private static function fa( $n ) {
		return Money::toFa( $n );
	}

	public static function shortcode() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		ob_start();
		$theme = get_option( 'agah_erp_theme', 'system' );
		if ( ! in_array( $theme, array( 'system', 'light', 'dark' ), true ) ) $theme = 'system';
		echo '<div class="erp" dir="rtl" lang="fa" data-theme="'.esc_attr($theme).'"'.(Ctx::cidOrZero()?' data-unit="'.esc_attr(Ctx::unit()).'"':'').'><header class="erp-brand erp-no-print"><button class="erp-mobile-nav" type="button" aria-label="باز کردن منوی سامانه" aria-expanded="false"><span></span><span></span><span></span></button><a class="erp-brand-home" href="'.esc_url(agah_erp_url()).'"><span class="erp-brand-mark" data-agah="agah_erp_logo">';$logo=(int)get_option('agah_erp_logo',0);if($logo)echo wp_get_attachment_image($logo,'thumbnail',false,['alt'=>'نشان سامانه']);else echo '<span aria-hidden="true">آ</span>';echo '</span><strong data-agah="agah_erp_title">'.esc_html(get_option('agah_erp_title','سامانه حسابداری')?:'سامانه حسابداری').'</strong></a><div class="erp-brand-actions"><button class="erp-theme-toggle" type="button" aria-label="تغییر حالت روشن و تیره" title="تغییر حالت روشن و تیره"><span aria-hidden="true">◐</span></button></div></header>';
		if ( ! is_user_logged_in() ) {
			self::viewLogin();
		} else {
			if (SecurityController::blocked() || Request::str('get','p')==='security') {
				SecurityController::render(); echo '</div>'; return ob_get_clean();
			}
			try {
				$cid = Request::int( 'get', 'c' );
				if ( $cid ) {
					Ctx::boot( get_current_user_id(), $cid );
					self::viewCompany( $cid );
				} else {
					self::viewHome();
				}
			} catch ( NotFoundException $e ) {
				status_header( 404 );
				echo '<div class="erp-card"><h2>پیدا نشد</h2><p>این شرکت وجود ندارد یا به آن دسترسی ندارید.</p><p><a class="btn btn-outline btn-sm" href="' . esc_url( agah_erp_url() ) . '">بازگشت</a></p></div>';
			} catch ( AppException $e ) {
				echo '<div class="erp-card"><p class="erp-err">' . esc_html( $e->getMessage() ) . '</p></div>';
			} catch ( \Throwable $e ) {
				error_log( '[agah-erp] view ' . get_class( $e ) . ': ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() );
				echo '<div class="erp-card"><p class="erp-err">خطای داخلی رخ داد؛ لطفاً بعداً تلاش کنید.</p></div>';
			}
		}
		echo '</div>';
		return ob_get_clean();
	}


	private static function viewLogin() {
		require AGAH_ERP_DIR.'/views/legacy-viewLogin.php';
	}

	private static function viewHome() {
		require AGAH_ERP_DIR.'/views/legacy-viewHome.php';
	}

	private static function viewCompany( $cid ) {
		require AGAH_ERP_DIR.'/views/legacy-viewCompany.php';
	}

	private static function pageDashboard( $cid ) {
		require AGAH_ERP_DIR.'/views/legacy-pageDashboard.php';
	}

	private static function pageAccounts( $cid ) {
		require AGAH_ERP_DIR.'/views/legacy-pageAccounts.php';
	}

	private static function pageFiscal( $cid ) {
		require AGAH_ERP_DIR.'/views/legacy-pageFiscal.php';
	}

	private static function pageAudit( $cid ) {
		require AGAH_ERP_DIR.'/views/legacy-pageAudit.php';
	}
}
