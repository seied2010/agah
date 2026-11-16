<?php
/**
 * لایهٔ دسترسی به پایگاه داده (روی $wpdb).
 *  ۱) نام جدول‌ها یک‌جا ساخته می‌شود: Db::t('vouchers') => wp_agah_vouchers
 *  ۲) هر خطای SQL به استثنا تبدیل می‌شود (در $wpdb خطا به‌صورت پیش‌فرض بی‌صدا رد می‌شود؛ در سیستم مالی یعنی عملیات نیمه‌کاره بدون اطلاع).
 *  ۳) تراکنش: Db::tx(fn) — تو در تو امن است (فقط بیرونی COMMIT می‌کند). همهٔ عملیات چندمرحله‌ای مالی باید داخل tx باشند.
 *  ۴) همهٔ کوئری‌ها prepared هستند؛ ورودی کاربر هرگز به رشتهٔ SQL الحاق نمی‌شود.
 */
namespace Agah\Erp\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Db {

	/** @var int عمق تراکنش جاری */
	private static $depth = 0;

	public static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'agah_' . $name;
	}

	public static function inTx() {
		return self::$depth > 0;
	}

	/** اجرای تابع داخل تراکنش. هر استثنا => ROLLBACK و پرتاب دوباره. */
	public static function tx( callable $fn ) {
		global $wpdb;
		if ( self::$depth > 0 ) {
			$savepoint='agah_sp_'.self::$depth;
			self::raw('SAVEPOINT '.$savepoint);
			self::$depth++;
			try {
				$r=$fn();
				self::$depth--;
				self::raw('RELEASE SAVEPOINT '.$savepoint);
				return $r;
			} catch (\Throwable $e) {
				self::$depth--;
				$old=$wpdb->suppress_errors(true);
				$wpdb->query('ROLLBACK TO SAVEPOINT '.$savepoint);
				$wpdb->query('RELEASE SAVEPOINT '.$savepoint);
				$wpdb->suppress_errors($old);$wpdb->last_error='';
				if(class_exists(Settings::class)) Settings::flush();
				throw $e;
			}
		}
		self::raw( 'START TRANSACTION' );
		self::$depth = 1;
		try {
			$r = $fn();
			self::$depth = 0;
			self::raw( 'COMMIT' );
			return $r;
		} catch ( \Throwable $e ) {
			self::$depth = 0;
			$wpdb->query( 'ROLLBACK' );
			if(class_exists(Settings::class)) Settings::flush(); // عمداً بدون بررسی خطا
			throw $e;
		}
	}

	public static function raw( $sql ) {
		global $wpdb;
		$wpdb->last_error = '';
		$r = $wpdb->query( $sql );
		self::check( $sql );
		return $r;
	}

	public static function exec( $sql, ...$args ) {
		global $wpdb;
		$wpdb->last_error = '';
		$q = $args ? $wpdb->prepare( $sql, ...$args ) : $sql;
		$r = $wpdb->query( $q );
		self::check( $q );
		return $r;
	}

	public static function row( $sql, ...$args ) {
		global $wpdb;
		$wpdb->last_error = '';
		$q = $args ? $wpdb->prepare( $sql, ...$args ) : $sql;
		$r = $wpdb->get_row( $q, ARRAY_A );
		self::check( $q );
		return $r ?: null;
	}

	public static function rows( $sql, ...$args ) {
		global $wpdb;
		$wpdb->last_error = '';
		$q = $args ? $wpdb->prepare( $sql, ...$args ) : $sql;
		$r = $wpdb->get_results( $q, ARRAY_A );
		self::check( $q );
		return $r ?: array();
	}

	public static function val( $sql, ...$args ) {
		global $wpdb;
		$wpdb->last_error = '';
		$q = $args ? $wpdb->prepare( $sql, ...$args ) : $sql;
		$r = $wpdb->get_var( $q );
		self::check( $q );
		return $r;
	}

	public static function col( $sql, ...$args ) {
		global $wpdb;
		$wpdb->last_error = '';
		$q = $args ? $wpdb->prepare( $sql, ...$args ) : $sql;
		$r = $wpdb->get_col( $q );
		self::check( $q );
		return $r ?: array();
	}

	/** @return int شناسهٔ ردیف جدید */
	public static function insert( $table, array $data ) {
		global $wpdb;
		$wpdb->last_error = '';
		$ok = $wpdb->insert( self::t( $table ), $data );
		self::check( 'INSERT ' . $table );
		if ( false === $ok ) {
			throw new \RuntimeException( 'insert failed: ' . $table );
		}
		return (int) $wpdb->insert_id;
	}

	public static function update( $table, array $data, array $where ) {
		global $wpdb;
		$wpdb->last_error = '';
		$r = $wpdb->update( self::t( $table ), $data, $where );
		self::check( 'UPDATE ' . $table );
		if ( false === $r ) {
			throw new \RuntimeException( 'update failed: ' . $table );
		}
		return (int) $r;
	}

	public static function delete( $table, array $where ) {
		global $wpdb;
		$wpdb->last_error = '';
		$r = $wpdb->delete( self::t( $table ), $where );
		self::check( 'DELETE ' . $table );
		if ( false === $r ) {
			throw new \RuntimeException( 'delete failed: ' . $table );
		}
		return (int) $r;
	}

	/** برای IN(...) از آرایهٔ اعداد صحیح (ایمن): Db::in([1,2]) => "1,2" */
	public static function in( array $ids ) {
		$ids = array_map( 'intval', $ids );
		return $ids ? implode( ',', $ids ) : '0';
	}

	private static function check( $sql ) {
		global $wpdb;
		if ( '' !== $wpdb->last_error ) {
			$msg = $wpdb->last_error;
			error_log( '[agah-erp] SQL error: ' . $msg . ' | ' . substr( (string) $sql, 0, 300 ) );
			$wpdb->last_error = '';
			throw new \RuntimeException( 'database error' );
		}
	}
}
