<?php
/**
 * سال مالی و دوره‌های مالی (ماه‌های شمسی). fiscal_years (۱) ← (n) fiscal_periods.
 * قاعدهٔ طلایی: هیچ عملیات مالی‌ای بدون عبور از FiscalService::assertOpen(تاریخ) ثبت نمی‌شود.
 */
namespace Agah\Erp\Modules\Accounting;

use Agah\Erp\Core\Audit;
use Agah\Erp\Core\ConflictException;
use Agah\Erp\Core\Db;
use Agah\Erp\Core\Jalali;
use Agah\Erp\Core\NotFoundException;
use Agah\Erp\Core\ValidationException;

if ( ! defined( 'ABSPATH' ) ) exit;

final class FiscalService {

	/** ساخت سال مالی شمسی با ۱۲ دوره. @return int شناسه */
	public static function createYear( $companyId, $jy, $title = '' ) {
		$jy = (int) $jy;
		if ( $jy < 1300 || $jy > 1600 ) {
			throw new ValidationException( array( 'jy' => 'سال مالی نامعتبر است.' ) );
		}
		list( $start, $end ) = Jalali::yearRange( $jy );
		if ( (int) Db::val( 'SELECT COUNT(*) FROM ' . Db::t( 'fiscal_years' ) . ' WHERE company_id = %d AND start_date <= %s AND end_date >= %s', $companyId, $end, $start ) > 0 ) {
			throw new ConflictException( 'این سال مالی قبلاً ساخته شده یا با سال دیگری هم‌پوشانی دارد.' );
		}
		return Db::tx( function () use ( $companyId, $jy, $title, $start, $end ) {
            Db::row('SELECT id FROM '.Db::t('companies').' WHERE id=%d FOR UPDATE',$companyId);
            if(Db::val('SELECT id FROM '.Db::t('fiscal_years').' WHERE company_id=%d AND start_date<=%s AND end_date>=%s',$companyId,$end,$start))throw new ConflictException('این سال مالی قبلاً ساخته شده است.');
			$id = Db::insert( 'fiscal_years', array(
				'company_id' => $companyId, 'title' => $title ?: ( 'سال مالی ' . $jy ), 'jy' => $jy,
				'start_date' => $start, 'end_date' => $end, 'status' => 'open', 'created_at' => gmdate( 'Y-m-d H:i:s' ),
			) );
			foreach ( Jalali::monthsOf( $jy ) as $m ) {
				Db::insert( 'fiscal_periods', array(
					'company_id' => $companyId, 'fiscal_year_id' => $id, 'period_no' => $m['no'], 'name' => $m['name'] . ' ' . $jy,
					'start_date' => $m['start'], 'end_date' => $m['end'], 'status' => 'open',
				) );
			}
			Audit::log( 'fiscal_year.create', 'fiscal_year', $id, null, array( 'jy' => $jy ), $companyId );
			return $id;
		} );
	}

	public static function all( $companyId ) {
		return Db::rows( 'SELECT * FROM ' . Db::t( 'fiscal_years' ) . ' WHERE company_id = %d ORDER BY start_date DESC', $companyId );
	}

	public static function get( $companyId, $id ) {
		$r = Db::row( 'SELECT * FROM ' . Db::t( 'fiscal_years' ) . ' WHERE company_id = %d AND id = %d', $companyId, $id );
		if ( ! $r ) {
			throw new NotFoundException();
		}
		return $r;
	}

	public static function periods( $companyId, $fyId ) {
		return Db::rows( 'SELECT * FROM ' . Db::t( 'fiscal_periods' ) . ' WHERE company_id = %d AND fiscal_year_id = %d ORDER BY period_no', $companyId, $fyId );
	}

	public static function forDate( $companyId, $date ) {
		return Db::row( 'SELECT * FROM ' . Db::t( 'fiscal_years' ) . ' WHERE company_id = %d AND start_date <= %s AND end_date >= %s', $companyId, $date, $date );
	}

	/** @throws ConflictException اگر تاریخ در سال/دورهٔ باز نباشد. @return array سال مالی */
	public static function assertOpen( $companyId, $date ) {
		$fy = self::forDate( $companyId, $date );
		if ( ! $fy ) {
			throw new ConflictException( 'برای این تاریخ سال مالی تعریف نشده است.' );
		}
		if ( 'open' !== $fy['status'] ) {
			throw new ConflictException( 'سال مالی این تاریخ بسته است.' );
		}
		$p = Db::row( 'SELECT status FROM ' . Db::t( 'fiscal_periods' ) . ' WHERE company_id = %d AND fiscal_year_id = %d AND start_date <= %s AND end_date >= %s', $companyId, $fy['id'], $date, $date );
		if ( ! $p || 'open' !== $p['status'] ) {
			throw new ConflictException( 'دورهٔ مالی این تاریخ بسته است.' );
		}
		return $fy;
	}

	public static function setPeriodStatus( $companyId, $periodId, $status ) {
		if ( ! in_array( $status, array( 'open', 'closed' ), true ) ) {
			throw new ValidationException( array( '_' => 'وضعیت نامعتبر است.' ) );
		}
		$p = Db::row( 'SELECT * FROM ' . Db::t( 'fiscal_periods' ) . ' WHERE company_id = %d AND id = %d', $companyId, $periodId );
		if ( ! $p ) {
			throw new NotFoundException();
		}
		if(Db::val('SELECT id FROM '.Db::t('year_closings').' WHERE company_id=%d AND fiscal_year_id=%d AND active_slot=1',$companyId,$p['fiscal_year_id']))throw new ConflictException('دورهٔ سال بسته‌شده فقط با بازگشایی کنترل‌شده تغییر می‌کند.');
		Db::tx( function () use ( $companyId, $periodId, $status, $p ) {
			Db::row('SELECT id FROM '.Db::t('companies').' WHERE id=%d FOR UPDATE',$companyId);
            if(Db::val('SELECT id FROM '.Db::t('year_closings').' WHERE company_id=%d AND fiscal_year_id=%d AND active_slot=1',$companyId,$p['fiscal_year_id']))throw new ConflictException('دورهٔ سال بسته‌شده فقط با بازگشایی کنترل‌شده تغییر می‌کند.');
			Db::update( 'fiscal_periods', array( 'status' => $status ), array( 'id' => $periodId, 'company_id' => $companyId ) );
			Audit::log( 'fiscal_period.' . $status, 'fiscal_period', $periodId, array( 'status' => $p['status'] ), array( 'status' => $status ), $companyId );
		} );
	}
	/** بستن/بازگشایی سال با مجوز ویژه و دلیل؛ سال دارای پیش‌نویس بسته نمی‌شود. بستن حساب‌ها مرحلهٔ ۱۱ است. */
	public static function setYearStatus($cid,$id,$status,$reason) {
		return \Agah\Erp\Core\Access::write($cid,'fiscal_year.approve',function()use($cid,$id,$status,$reason){
			$old=self::get($cid,$id);
            if(Db::val('SELECT id FROM '.Db::t('year_closings').' WHERE company_id=%d AND fiscal_year_id=%d AND active_slot=1',$cid,$id))throw new ConflictException('این سال با اسناد مالی بسته شده است؛ از بازگشایی کنترل‌شده استفاده کنید.');
			if(!in_array($status,['open','closed'],true)) throw new ValidationException(['status'=>'وضعیت معتبر نیست.']);
			$reason=\Agah\Erp\Core\Request::clean($reason,300);
			if(mb_strlen($reason)<5) throw new ValidationException(['reason'=>'دلیل حداقل پنج نویسه باشد.']);
			if($status==='closed' && Db::val('SELECT COUNT(*) FROM '.Db::t('vouchers')." WHERE company_id=%d AND fiscal_year_id=%d AND status='draft'",$cid,$id)) throw new ConflictException('سال دارای سند پیش‌نویس بسته نمی‌شود.');
			Db::update('fiscal_years',['status'=>$status],['company_id'=>$cid,'id'=>$id]);
			Audit::log('fiscal_year.'.$status,'fiscal_year',$id,$old,['status'=>$status,'reason'=>$reason],$cid);
		});
	}

    /** Never delete a year carrying any financial record, closing relationship or allocated number. */
    public static function deleteUnused($cid,$id){
        return \Agah\Erp\Core\Access::write($cid,'fiscal_year.approve',function()use($cid,$id){
            $year=self::get($cid,$id);
            foreach(['production_orders','vouchers','treasury_docs','invoices','stock_docs','expense_docs','attendance','payroll_params','payslips','year_closings','sequences'] as $t)
                if(Db::val('SELECT company_id FROM '.Db::t($t).' WHERE company_id=%d AND fiscal_year_id=%d LIMIT 1',$cid,$id))throw new ConflictException('سال دارای سابقه حذف نمی‌شود؛ آن را ببندید.');
            if(Db::val('SELECT id FROM '.Db::t('year_closings').' WHERE company_id=%d AND next_year_id=%d',$cid,$id))throw new ConflictException('سال به اختتامیه مرتبط است.');
            Db::delete('fiscal_periods',['company_id'=>$cid,'fiscal_year_id'=>$id]);Db::delete('fiscal_years',['company_id'=>$cid,'id'=>$id]);Audit::log('fiscal_year.delete','fiscal_year',$id,$year,null,$cid);
        });
    }

}
