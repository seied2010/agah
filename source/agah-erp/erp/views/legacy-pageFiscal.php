<?php
/** نمای جداشدهٔ pageFiscal؛ زمینه و مجوز در Page بررسی می‌شوند. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Db,Ctx,Request,Money,Jalali,Crypto,Audit,Settings};
use Agah\Erp\Modules\Accounting\{AccountService,DetailService,FiscalService,VoucherService,ReportService,ChartSeed};
use Agah\Erp\Modules\Company\{CompanyService,MemberService};
if(!defined('ABSPATH')) exit;
		echo '<div class="erp-card"><h2>سال‌های مالی</h2>';
		foreach ( FiscalService::all( $cid ) as $fy ) {
			echo '<h3>' . esc_html( $fy['title'] ) . ' <span class="muted">(' . esc_html( Jalali::format( $fy['start_date'] ) . ' تا ' . Jalali::format( $fy['end_date'] ) ) . ' — ' . ( 'open' === $fy['status'] ? 'باز' : 'بسته' ) . ')</span></h3><div class="erp-scroll"><table class="erp-table"><thead><tr><th>دوره</th><th>از</th><th>تا</th><th>وضعیت</th><th></th></tr></thead><tbody>';
			foreach ( FiscalService::periods( $cid, $fy['id'] ) as $per ) {
				$open = 'open' === $per['status'];
				echo '<tr><td>' . esc_html( $per['name'] ) . '</td><td>' . esc_html( Jalali::format( $per['start_date'] ) ) . '</td><td>' . esc_html( Jalali::format( $per['end_date'] ) ) . '</td><td>' . ( $open ? 'باز' : 'بسته' ) . '</td><td>';
				if ( Ctx::can( 'fiscal_year.edit' ) ) {
					echo '<form method="post" class="inline">' . self::nonce( 'period_toggle' ) . '<input type="hidden" name="c" value="' . (int) $cid . '"><input type="hidden" name="back" value="fiscal"><input type="hidden" name="id" value="' . (int) $per['id'] . '"><input type="hidden" name="open" value="' . ( $open ? '0' : '1' ) . '"><button class="btn btn-outline btn-sm" type="submit">' . ( $open ? 'بستن' : 'بازکردن' ) . '</button></form>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</div>';
		if ( Ctx::can( 'fiscal_year.create' ) ) {
			echo '<div class="erp-card"><h3>ساخت سال مالی جدید</h3><form method="post" class="erp-form">' . self::nonce( 'fy_create' ) . '<input type="hidden" name="c" value="' . (int) $cid . '"><input type="hidden" name="back" value="fiscal"><label>سال شمسی<input name="jy" required inputmode="numeric" maxlength="4" dir="ltr"></label><button class="btn btn-primary" type="submit">ساخت</button></form></div>';
		}

		if(Ctx::can('fiscal_year.approve')) foreach(FiscalService::all($cid) as $fy) {
			echo '<section class="erp-card"><h3>کنترل وضعیت '.esc_html($fy['title']).'</h3><form method="post" class="erp-form">';
			ModuleController::form('module_year_status',$cid,'fiscal',$fy['id']);
			ModuleController::select('status','وضعیت سال',['open'=>'باز','closed'=>'بسته'],$fy['status']);
			ModuleController::input('reason','دلیل تغییر وضعیت','','text','required minlength="5" maxlength="300"');
			echo '<p>سال دارای پیش‌نویس بسته نمی‌شود. این عمل بستن حساب‌ها یا ساخت اختتامیه نیست؛ آن فرایند در مرحلهٔ ۱۱ پیاده می‌شود.</p><button type="submit" class="btn btn-outline">اعمال وضعیت سال</button></form></section>';
		}

// end-of-list controls are rendered without deleting years with records.

if(Ctx::can('fiscal_year.approve'))foreach(FiscalService::all($cid) as $fy){echo '<form method="post" class="erp-inline-form">';ModuleController::form('module_year_delete',$cid,'fiscal',$fy['id']);echo '<button type="submit" class="btn btn-outline">حذف سال خالی '.esc_html($fy['jy']).'</button></form>';}
