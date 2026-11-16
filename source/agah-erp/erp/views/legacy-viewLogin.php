<?php
/** نمای جداشدهٔ viewLogin؛ زمینه و مجوز در Page بررسی می‌شوند. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Db,Ctx,Request,Money,Jalali,Crypto,Audit,Settings};
use Agah\Erp\Modules\Accounting\{AccountService,DetailService,FiscalService,VoucherService,ReportService,ChartSeed};
use Agah\Erp\Modules\Company\{CompanyService,MemberService};
if(!defined('ABSPATH')) exit;
		$page = agah_erp_url();
		echo '<div class="erp-card erp-login"><h2>ورود به سامانهٔ حسابداری</h2><p>برای مشاهدهٔ اطلاعات مالی وارد شوید.</p>';
		if ( isset( $_GET['login'] ) && 'failed' === $_GET['login'] ) { // phpcs:ignore
			echo '<p class="erp-err" role="alert">نام کاربری یا گذرواژه درست نیست.</p>';
		}
		wp_login_form( array(
			'redirect' => $page, 'label_username' => 'نام کاربری یا ایمیل', 'label_password' => 'گذرواژه',
			'label_remember' => 'مرا به خاطر بسپار', 'label_log_in' => 'ورود', 'remember' => true,
		) );
		echo '<p><a href="' . esc_url( wp_lostpassword_url( $page ) ) . '">گذرواژه را فراموش کرده‌اید؟</a></p></div>';
