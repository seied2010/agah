<?php
/**
 * شماره‌گذاری بدون فاصله و امن در برابر هم‌زمانی. هر نوع در هر سال مالی شمارندهٔ جدا دارد (جدول sequences).
 * شماره باید داخل همان تراکنشی گرفته شود که سند را می‌سازد: با ROLLBACK افزایش هم برمی‌گردد و فاصله نمی‌افتد.
 */
namespace Agah\Erp\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Sequence {

	/** @param int $fiscalYearId 0 = مستقل از سال مالی (مثل کد تفصیلی) */
	public static function next( $companyId, $kind, $fiscalYearId = 0 ) {
		if ( ! Db::inTx() ) {
			throw new \LogicException( 'Sequence::next must run inside a transaction' );
		}
		$t = Db::t( 'sequences' );
		Db::exec( "INSERT INTO $t (company_id, fiscal_year_id, kind, last_no) VALUES (%d, %d, %s, 0) ON DUPLICATE KEY UPDATE last_no=last_no", $companyId, $fiscalYearId, $kind );
		Db::exec( "UPDATE $t SET last_no = last_no + 1 WHERE company_id = %d AND fiscal_year_id = %d AND kind = %s", $companyId, $fiscalYearId, $kind );
		return (int) Db::val( "SELECT last_no FROM $t WHERE company_id = %d AND fiscal_year_id = %d AND kind = %s", $companyId, $fiscalYearId, $kind );
	}
}
