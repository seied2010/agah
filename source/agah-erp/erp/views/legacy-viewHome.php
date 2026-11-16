<?php
/** نمای جداشدهٔ viewHome؛ زمینه و مجوز در Page بررسی می‌شوند. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Db,Ctx,Request,Money,Jalali,Crypto,Audit,Settings};
use Agah\Erp\Modules\Accounting\{AccountService,DetailService,FiscalService,VoucherService,ReportService,ChartSeed};
use Agah\Erp\Modules\Company\{CompanyService,MemberService};
if(!defined('ABSPATH')) exit;
		ModuleController::notices();
		$uid  = get_current_user_id();
		$list = CompanyService::forUser( $uid );
		echo '<p><a href="'.esc_url(agah_erp_url(['p'=>'security'])).'">امنیت حساب</a></p>';
		echo '<div class="erp-top"><h1>سامانهٔ حسابداری</h1><a class="btn btn-outline btn-sm" href="' . esc_url( wp_logout_url( agah_erp_url() ) ) . '">خروج</a></div>';
		echo '<div class="erp-card"><h2>شرکت‌های شما</h2>';
		if ( ! $list ) {
			echo '<p>شما هنوز عضو هیچ شرکتی نیستید. از مدیر سامانه بخواهید شرکتی برایتان بسازد یا شما را اضافه کند.</p>';
		} else {
			echo '<ul class="erp-list">';
			foreach ( $list as $c ) {
				echo '<li><a href="' . esc_url( agah_erp_url( array( 'c' => (int) $c['id'] ) ) ) . '"><b>' . esc_html( $c['name'] ) . '</b></a> <span class="muted">' . esc_html( $c['role_name'] ) . '</span></li>';
			}
			echo '</ul>';
		}
		echo '</div>';
		// شرکت‌های تعلیق‌شده یا در حال حذف و پاک‌سازی‌های اخیر؛ بازدید درخواست‌کننده یک نوبت از پاک‌سازی نیمه‌کاره را هم پیش می‌برد.
		$closed=\Agah\Erp\Modules\Company\CompanyLifecycleService::closedFor($uid);
		foreach($closed['purges'] as $p)if($p['status']==='running')\Agah\Erp\Modules\Company\CompanyLifecycleService::run((int)$p['id'],8);
		if($closed['purges'])$closed=\Agah\Erp\Modules\Company\CompanyLifecycleService::closedFor($uid);
		if($closed['companies']||$closed['purges']){
			$labels=['suspended'=>'تعلیق‌شده؛ بازگشایی با مدیر سامانه','purging'=>'در حال پاک‌سازی','deleting'=>'در حال حذف'];
			echo '<div class="erp-card"><h2>شرکت‌های غیرفعال</h2><ul class="erp-list">';
			foreach($closed['companies'] as $c)echo '<li><b>'.esc_html($c['name']).'</b> <span class="muted">'.esc_html($labels[$c['status']]??$c['status']).'</span></li>';
			foreach($closed['purges'] as $p){if($p['status']==='running')continue;echo '<li><b>'.esc_html($p['company_name']).'</b> <span class="muted">پاک‌سازی: '.esc_html(\Agah\Erp\Modules\Company\CompanyLifecycleService::STATUS[$p['status']]??$p['status']).($p['error']?' — '.esc_html($p['error']):'').'</span></li>';}
			echo '</ul></div>';
		}

		$platform = current_user_can( 'manage_options' );
		if ( $platform || Settings::flag( 'allow_self_company' ) ) {
			echo '<div class="erp-card"><h2>' . ( $platform ? 'ساخت شرکت (مدیر سامانه)' : 'ساخت شرکت جدید' ) . '</h2>';
			if ( $platform ) {
				echo '<p class="muted">شما به‌عنوان مدیر سامانه به داده‌های مالی شرکت‌ها دسترسی ندارید؛ فقط شرکت می‌سازید و مدیر آن را تعیین می‌کنید.</p>';
			}
			echo '<form method="post" class="erp-form">' . self::nonce( 'company_create' );
			ModuleController::input('name','نام شرکت','','text','required maxlength="190"');
			ModuleController::input('legal_name','نام حقوقی','','text','maxlength="190"');
			ModuleController::select('kind','نوع',['legal'=>'حقوقی','real'=>'حقیقی'],'legal');
			ModuleController::select('display_unit','واحد پول نمایش',['toman'=>'تومان','rial'=>'ریال'],'toman');
			ModuleController::select('jy','سال مالی اول (شمسی)',array_combine(\Agah\Erp\Core\FiscalContext::catalog(),\Agah\Erp\Core\FiscalContext::catalog()),Jalali::currentYear());
            ModuleController::select('activity_type','نوع فعالیت',['trading'=>'بازرگانی','manufacturing'=>'تولیدی','services'=>'خدماتی'],'trading');
			if ( $platform ) {
				ModuleController::input('owner_login','نام کاربری مدیر شرکت (خالی = خودتان)','','text','maxlength="60" dir="ltr"');
			}
			echo '<button class="btn btn-primary" type="submit">ساخت شرکت</button></form></div>';
		}
        if($platform)echo '<p><a href="'.esc_url(admin_url('admin.php?page=agah-access')).'">مدیریت دسترسی شرکت‌ها و سال‌ها</a></p>';
