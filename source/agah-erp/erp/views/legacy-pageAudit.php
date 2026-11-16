<?php
/** نمای جداشدهٔ pageAudit؛ زمینه و مجوز در Page بررسی می‌شوند. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Db,Ctx,Request,Money,Jalali,Crypto,Audit,Settings};
use Agah\Erp\Modules\Accounting\{AccountService,DetailService,FiscalService,VoucherService,ReportService,ChartSeed};
use Agah\Erp\Modules\Company\{CompanyService,MemberService};
if(!defined('ABSPATH')) exit;
		$bad = Audit::verify( $cid );
		echo '<div class="erp-card"><h2>گزارش تغییرات (ممیزی)</h2><p class="' . ( null === $bad ? 'erp-ok' : 'erp-err' ) . '">' . ( null === $bad ? 'زنجیرهٔ ممیزی سالم است.' : 'هشدار: زنجیرهٔ ممیزی از ردیف ' . esc_html( self::fa( $bad ) ) . ' دست‌کاری‌شده به نظر می‌رسد.' ) . '</p>';
		echo '<div class="erp-scroll"><table class="erp-table"><thead><tr><th>زمان</th><th>کاربر</th><th>رویداد</th><th>مورد</th><th>آی‌پی</th></tr></thead><tbody>';
		foreach ( Db::rows( 'SELECT * FROM ' . Db::t( 'audit_log' ) . ' WHERE company_id = %d ORDER BY id DESC LIMIT 100', $cid ) as $r ) {
			$u = get_userdata( (int) $r['user_id'] );
			echo '<tr><td>' . esc_html( Jalali::format( substr( $r['created_at'], 0, 10 ) ) . ' ' . substr( $r['created_at'], 11, 5 ) . ' UTC' ) . '</td><td>' . esc_html( $u ? $u->display_name : '—' ) . '</td><td dir="ltr">' . esc_html( $r['event'] ) . '</td><td>' . esc_html( $r['entity_type'] . ' #' . $r['entity_id'] ) . '</td><td dir="ltr">' . esc_html( $r['ip'] ) . '</td></tr>';
		}
		echo '</tbody></table></div></div>';
