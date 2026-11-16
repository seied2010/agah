<?php
/**
 * ورودی درخواست — همهٔ خواندن‌ها از اینجا انجام می‌شود تا پاک‌سازی یک‌جا و یکسان باشد.
 * توجه: پاک‌سازی ≠ اعتبارسنجی. اعتبارسنجی (نوع، دامنه، مالکیت) در سرویس‌ها انجام می‌شود.
 */
namespace Agah\Erp\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Request {

	public static function isPost() {
		return isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
	}

	private static function raw( $src, $key ) {
		$arr = 'post' === $src ? $_POST : $_GET;
		if ('post' === $src && 'name' === $key) $key='erp_name'; // phpcs:ignore WordPress.Security.NonceVerification
		return isset( $arr[ $key ] ) ? wp_unslash( $arr[ $key ] ) : null;
	}

	/** رشتهٔ تک‌خطی پاک‌شده (برچسب HTML حذف، ي/ك عربی → ی/ک). */
	public static function str( $src, $key, $max = 255, $default = '' ) {
		$v = self::raw( $src, $key );
		return ( null === $v || is_array( $v ) ) ? $default : self::clean( $v, $max );
	}

	public static function clean( $v, $max = 255 ) {
		$v = str_replace( array( 'ي', 'ك' ), array( 'ی', 'ک' ), (string) $v );
		return mb_substr( sanitize_text_field( $v ), 0, $max );
	}

	public static function text( $src, $key, $max = 1000 ) {
		$v = self::raw( $src, $key );
		if ( null === $v || is_array( $v ) ) {
			return '';
		}
		return mb_substr( sanitize_textarea_field( str_replace( array( 'ي', 'ك' ), array( 'ی', 'ک' ), (string) $v ) ), 0, $max );
	}

	public static function int( $src, $key, $default = 0 ) {
		$v = self::raw( $src, $key );
		if ( null === $v || is_array( $v ) ) {
			return $default;
		}
		$v = Money::normDigits( $v );
		return preg_match( '/^-?\d{1,18}$/', $v ) ? (int) $v : $default;
	}

	public static function flag( $src, $key ) {
		$v = self::raw( $src, $key );
		return null !== $v && ! is_array( $v ) && in_array( (string) $v, array( '1', 'on', 'true', 'yes' ), true );
	}

	/** آی‌پی کلاینت. X-Forwarded-For فقط با ثابت AGAH_TRUSTED_PROXY=true معتبر شمرده می‌شود. */
	public static function ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		if ( defined( 'AGAH_TRUSTED_PROXY' ) && AGAH_TRUSTED_PROXY && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] );
			$cand  = trim( $parts[0] );
			if ( filter_var( $cand, FILTER_VALIDATE_IP ) ) {
				$ip = $cand;
			}
		}
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	public static function ua() {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 200 ) : '';
	}
}
