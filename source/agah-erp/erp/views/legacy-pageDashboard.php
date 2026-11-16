<?php
/** نمای جداشدهٔ pageDashboard؛ زمینه و مجوز در Page بررسی می‌شوند. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Db,Ctx,Request,Money,Jalali,Crypto,Audit,Settings};
use Agah\Erp\Modules\Accounting\{AccountService,DetailService,FiscalService,VoucherService,ReportService,ChartSeed};
use Agah\Erp\Modules\Company\{CompanyService,MemberService};
if(!defined('ABSPATH')) exit;
		// اعلان‌های فاز ۲: ارجاع‌های باز رسیده به کاربر و تغییر قیمت/تخفیفِ دیده‌نشده برای مدیر فروش (یک پیام شمارشی، نه پیام تکراری).
		$refOpen=\Agah\Erp\Modules\Trade\SalesControlService::openCount($cid);$unseen=count(\Agah\Erp\Modules\Trade\SalesControlService::unseen($cid));
		if($refOpen||$unseen){echo '<div class="erp-card erp-notices" role="status">';if($refOpen)echo '<p><a href="'.esc_url(agah_erp_url(['c'=>$cid,'p'=>'referrals'])).'">'.esc_html(Money::toFa((string)$refOpen).' ارجاع باز در کارتابل شما').'</a></p>';if($unseen&&Ctx::can('role.edit'))echo '<p><a href="'.esc_url(agah_erp_url(['c'=>$cid,'p'=>'sales_controls'])).'">'.esc_html(Money::toFa((string)$unseen).' تغییر قیمت یا تخفیف تازه برای بررسی').'</a></p>';elseif($unseen)echo '<p>'.esc_html(Money::toFa((string)$unseen).' تغییر قیمت یا تخفیف تازه ثبت شده است.').'</p>';echo '</div>';}
		$fy   = \Agah\Erp\Core\FiscalContext::selected($cid);
		$accN = (int) Db::val( 'SELECT COUNT(*) FROM ' . Db::t( 'accounts' ) . ' WHERE company_id = %d AND level = 3', $cid );
		echo '<div class="erp-grid">';
		echo '<div class="erp-card"><h3>سال مالی</h3><p class="big">' . esc_html( $fy ? $fy['title'] : '—' ) . '</p><p class="muted">' . esc_html( $fy ? Jalali::format( $fy['start_date'] ) . ' تا ' . Jalali::format( $fy['end_date'] ) : '' ) . '</p></div>';
		echo '<div class="erp-card"><h3>حساب‌های معین</h3><p class="big">' . esc_html( self::fa( $accN ) ) . '</p></div>';
		echo '<div class="erp-card"><h3>واحد پول</h3><p class="big">' . esc_html( 'rial' === Ctx::unit() ? 'ریال' : 'تومان' ) . '</p></div>';
		echo '</div>';
		echo '<div class="erp-card"><p>اسناد و دفاتر حسابداری فعال‌اند. اشخاص، فاکتور، خزانه و انبار در مراحل بعد تکمیل می‌شوند. این نسخهٔ آزمایشی پیش از استفادهٔ عملیاتی نیازمند بازبینی است.</p></div>';
