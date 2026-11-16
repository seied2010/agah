<?php
/**
 * دفتر ممیزی — فقط‌افزودنی و زنجیره‌ای.
 *  - هر رویداد مهم با: کاربر، زمان، آی‌پی، مقدار قبلی و جدید ثبت می‌شود.
 *  - هر ردیف hash ردیف قبلی همان شرکت را در hash خودش دارد (SHA-256)؛ دستکاری/حذف ردیف میانی با Audit::verify() آشکار می‌شود.
 *    (این «تشخیص» است نه «جلوگیری»؛ برای جلوگیری، حساب DB برنامه نباید UPDATE/DELETE روی این جدول داشته باشد.)
 *  - هیچ متدی برای ویرایش/حذف ردیف وجود ندارد. داده‌های حساس پیش از ذخیره پوشانده می‌شوند.
 *  - رویدادهای مالی داخل همان تراکنش عملیات ثبت می‌شوند (با برگشت عملیات، رکورد هم برمی‌گردد).
 */
namespace Agah\Erp\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Audit {

	private static $secret = array( 'password', 'pass', 'otp', 'token', 'secret', 'national_id', 'national_id_enc', 'national_id_idx', 'code_hash', 'backup_codes' );

	/**
	 * @param string   $event      مثل voucher.create
	 * @param int|null $companyId  null => شرکت جاری (Ctx) یا ۰
	 * @param int|null $userId     null => کاربر جاری
	 */
	public static function log( $event, $entityType = '', $entityId = 0, $old = null, $new = null, $companyId = null, $userId = null ) {
		$cid = null === $companyId ? Ctx::cidOrZero() : (int) $companyId;
		$uid = null === $userId ? ( Ctx::uid() ?: get_current_user_id() ) : (int) $userId;

		$oj = null === $old ? null : wp_json_encode( self::mask( $old ), JSON_UNESCAPED_UNICODE );
		$nj = null === $new ? null : wp_json_encode( self::mask( $new ), JSON_UNESCAPED_UNICODE );
		$ts = gmdate( 'Y-m-d H:i:s' );
		$ip = Request::ip();
		$ua = Request::ua();

		Db::tx(function() use($cid,$uid,$event,$entityType,$entityId,$oj,$nj,$ts,$ip,$ua) {
			Db::exec('INSERT INTO '.Db::t('audit_heads').' (company_id) VALUES (%d) ON DUPLICATE KEY UPDATE company_id=VALUES(company_id)', $cid);
			Db::row('SELECT company_id FROM '.Db::t('audit_heads').' WHERE company_id=%d FOR UPDATE', $cid);
			$prev = (string) Db::val( 'SELECT row_hash FROM ' . Db::t( 'audit_log' ) . ' WHERE company_id = %d ORDER BY id DESC LIMIT 1 FOR UPDATE', $cid );
			Db::insert( 'audit_log', array(
				'company_id' => $cid, 'user_id' => $uid, 'event' => mb_substr( $event, 0, 60 ),
				'entity_type' => mb_substr( $entityType, 0, 30 ), 'entity_id' => (int) $entityId,
				'old_json' => $oj, 'new_json' => $nj, 'ip' => $ip, 'ua' => $ua, 'created_at' => $ts,
				'prev_hash' => $prev, 'row_hash' => self::hash( $prev, $cid, $uid, $event, $entityType, (int) $entityId, $oj, $nj, $ts, $ip ),
			) );
		});
	}

	private static function hash( $prev, $cid, $uid, $event, $type, $id, $oj, $nj, $ts, $ip ) {
		return hash( 'sha256', implode( '|', array( $prev, $cid, $uid, $event, $type, $id, (string) $oj, (string) $nj, $ts, $ip ) ) );
	}

	private static function mask( $v ) {
		if ( is_array( $v ) ) {
			$out = array();
			foreach ( $v as $k => $x ) {
				$out[ $k ] = ( is_string( $k ) && in_array( strtolower( $k ), self::$secret, true ) ) ? '***' : self::mask( $x );
			}
			return $out;
		}
		return $v;
	}

	/** @return int|null شناسهٔ اولین ردیف خراب (null = زنجیره سالم) */
	public static function verify( $companyId ) {
		$prev = '';
		$last = 0;
		while ( true ) {
			$rows = Db::rows( 'SELECT * FROM ' . Db::t( 'audit_log' ) . ' WHERE company_id = %d AND id > %d ORDER BY id ASC LIMIT 500', $companyId, $last );
			if ( ! $rows ) {
				return null;
			}
			foreach ( $rows as $r ) {
				if ( $r['prev_hash'] !== $prev ) {
					return (int) $r['id'];
				}
				$h = self::hash( $r['prev_hash'], (int) $r['company_id'], (int) $r['user_id'], $r['event'], $r['entity_type'], (int) $r['entity_id'], $r['old_json'], $r['new_json'], $r['created_at'], $r['ip'] );
				if ( $h !== $r['row_hash'] ) {
					return (int) $r['id'];
				}
				$prev = $r['row_hash'];
				$last = (int) $r['id'];
			}
		}
	}
}
