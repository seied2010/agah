<?php
/**
 * رمزنگاری داده‌های حساس (کد ملی/شناسه ملی) در حالت سکون.
 *  - رمزنگاری: XSalsa20-Poly1305 (sodium_crypto_secretbox)؛ خروجی «v1:base64(nonce+cipher)».
 *  - کلید: از ثابت AGAH_ENC_KEY در wp-config.php (یا متغیر محیطی AGAH_ENC_KEY) با HKDF مشتق می‌شود.
 *    در alpha9 کلید پایدار از داشبورد تنظیم می‌شود؛ پیش از آن کلید از saltهای وردپرس مشتق می‌شود.
 *  - ایندکس کور (blind index): HMAC-SHA256 با کلید جدا، برای جستجوی دقیق بدون ذخیرهٔ متن آشکار.
 */
namespace Agah\Erp\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Crypto {

	public static function hasConfiguredKey() {
		return '' !== self::master( false );
	}

	private static function master( $fallback = true ) {
		$k = '';
		if ( defined( 'AGAH_ENC_KEY' ) && AGAH_ENC_KEY ) {
			$k = (string) AGAH_ENC_KEY;
		} elseif ( getenv( 'AGAH_ENC_KEY' ) ) {
			$k = (string) getenv( 'AGAH_ENC_KEY' );
		}
        if(''===$k&&class_exists(KeySetup::class))$k=KeySetup::storedValue();
		if ( '' !== $k ) {
			$raw = base64_decode( $k, true );
			$master=( false !== $raw && strlen( $raw ) >= 32 ) ? $raw : $k;
            if($fallback&&class_exists(KeySetup::class))KeySetup::assertFallback($master);
            return $master;
		}
        if(!$fallback)return '';
        $raw=wp_salt('secure_auth').'|'.wp_salt('auth');
        if(class_exists(KeySetup::class))KeySetup::assertFallback($raw);
        return $raw;
	}

	private static function key( $purpose ) {
		return hash_hkdf( 'sha256', self::master(), 32, 'agah-erp-' . $purpose );
	}

	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key( 'enc' ) ) );
	}

	/** @return string متن آشکار؛ در صورت خراب بودن/کلید اشتباه رشتهٔ خالی. */
	public static function decrypt( $blob ) {
		$blob = (string) $blob;
		if ( '' === $blob || 0 !== strpos( $blob, 'v1:' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $blob, 3 ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$pt = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key( 'enc' ) );
		return false === $pt ? '' : $pt;
	}

	public static function blind( $plain ) {
		$plain = Money::normDigits( (string) $plain );
		return '' === $plain ? '' : hash_hmac( 'sha256', $plain, self::key( 'idx' ) );
	}

	/** نمایش پوشیده: ****1234 */
	public static function hint( $plain ) {
		$plain = Money::normDigits( (string) $plain );
		return '' === $plain ? '' : str_repeat( '*', max( 0, strlen( $plain ) - 4 ) ) . substr( $plain, -4 );
	}

	public static function token( $bytes = 20 ) {
		return bin2hex( random_bytes( $bytes ) );
	}

	public static function uuid() {
		$d    = random_bytes( 16 );
		$d[6] = chr( ( ord( $d[6] ) & 0x0f ) | 0x40 );
		$d[8] = chr( ( ord( $d[8] ) & 0x3f ) | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $d ), 4 ) );
	}
}
