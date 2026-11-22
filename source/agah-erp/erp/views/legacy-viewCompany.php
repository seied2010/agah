<?php
/** نمای جداشدهٔ viewCompany؛ زمینه و مجوز در Page بررسی می‌شوند. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Db,Ctx,Request,Money,Jalali,Crypto,Audit,Settings};
use Agah\Erp\Modules\Accounting\{AccountService,DetailService,FiscalService,VoucherService,ReportService,ChartSeed};
use Agah\Erp\Modules\Company\{CompanyService,MemberService};
if(!defined('ABSPATH')) exit;
		$co  = Ctx::company();
		$p   = Request::str( 'get', 'p', 30, 'dashboard' );
		$nav = array(
			'dashboard' => array( 'داشبورد', 'dashboard.view' ),
			'accounts'  => array( 'کدینگ حساب‌ها', 'account.view' ),
			'fiscal'    => array( 'سال و دورهٔ مالی', 'fiscal_year.view' ),
			'audit'     => array( 'گزارش تغییرات', 'audit.view' ),
		);
		$nav=array_merge($nav, ModuleController::nav());
		$sub=['voucher_new'=>'voucher.create','voucher_edit'=>'voucher.edit','voucher_view'=>'voucher.view','party_view'=>'party.view','treasury_new'=>Request::str('get','kind',10,'receipt').'.create','treasury_view'=>($p==='treasury_view'?\Agah\Erp\Modules\Treasury\TreasuryService::get($cid,Request::int('get','id'))['kind']:'receipt').'.view','cheque_print'=>'cheque.view'];
		$tradeCap=ProductionController::cap($p)?:TradeController::cap($p);
        if($tradeCap) Ctx::need($tradeCap);
        elseif(isset($sub[$p])) Ctx::need($sub[$p]);
		elseif(!isset($nav[$p])) throw new NotFoundException();
		else Ctx::need($nav[$p][1]);
		echo '<div class="erp-top"><div class="erp-page-identity"><span class="erp-eyebrow">شرکت فعال</span><h1>' . esc_html( $co['name'] ) . '</h1><span class="muted">' . esc_html((string)Db::val('SELECT r.name FROM '.Db::t('company_members').' m JOIN '.Db::t('roles').' r ON r.company_id=m.company_id AND r.id=m.role_id WHERE m.company_id=%d AND m.user_id=%d',$cid,get_current_user_id())) . '</span></div><div class="erp-top-actions">';
        \Agah\Erp\Core\FiscalContext::render($cid,true);
        echo '<a class="btn btn-outline btn-sm" href="' . esc_url( agah_erp_url() ) . '">تغییر شرکت</a>'
			. '<a class="btn btn-outline btn-sm" href="' . esc_url( wp_logout_url( agah_erp_url() ) ) . '">خروج</a></div></div>';
        echo '<div class="erp-workspace-layout erp-menu-'.esc_attr(Navigation::mode()).'">';
        Navigation::render($nav,$p,$cid);
        echo '<div class="erp-workspace-content" id="agah-work-content" tabindex="-1">';
		Navigation::breadcrumbs($nav,$p,$cid);
		if ( ! Ctx::can( 'dashboard.view' ) && 'dashboard' === $p ) {
			echo '<div class="erp-card"><p>مجوز مشاهدهٔ هیچ بخشی برای شما تعریف نشده است.</p></div>';
            echo '</div></div>';
			return;
		}
		ModuleController::notices();
		if(ModuleController::render($p,$cid)){echo '</div></div>';return;}
		self::{'page'.ucfirst($p)}($cid);

        echo '</div></div>';
