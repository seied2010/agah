<?php
/**
 * تقویم شمسی: تبدیل دوطرفه با میلادی، اعتبارسنجی، قالب‌بندی.
 * قرارداد ذخیره‌سازی: همهٔ تاریخ‌ها در پایگاه داده «میلادی» و از نوع DATE ذخیره می‌شوند؛ تبدیل به شمسی فقط در ورودی/نمایش.
 * الگوریتم: دورهٔ ۳۳ ساله (مانند jalaali-js) برای سال‌های ۱ تا ۳۱۷۸ هجری شمسی.
 */
namespace Agah\Erp\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Jalali {

	private static $breaks = array( -61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178 );

	const MONTHS   = array( '', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند' );
	const WEEKDAYS = array( 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه' );

	private static function cal( $jy ) {
		$b  = self::$breaks;
		$bl = count( $b );
		if ( $jy < $b[0] || $jy >= $b[ $bl - 1 ] ) {
			throw new \InvalidArgumentException( 'سال شمسی خارج از محدوده است.' );
		}
		$gy    = $jy + 621;
		$leapJ = -14;
		$jp    = $b[0];
		$jump  = 0;
		for ( $i = 1; $i < $bl; $i++ ) {
			$jm   = $b[ $i ];
			$jump = $jm - $jp;
			if ( $jy < $jm ) {
				break;
			}
			$leapJ += intdiv( $jump, 33 ) * 8 + intdiv( $jump % 33, 4 );
			$jp     = $jm;
		}
		$n      = $jy - $jp;
		$leapJ += intdiv( $n, 33 ) * 8 + intdiv( ( $n % 33 ) + 3, 4 );
		if ( 4 === $jump % 33 && 4 === $jump - $n ) {
			$leapJ++;
		}
		$leapG = intdiv( $gy, 4 ) - intdiv( ( intdiv( $gy, 100 ) + 1 ) * 3, 4 ) - 150;
		$march = 20 + $leapJ - $leapG;
		if ( $jump - $n < 6 ) {
			$n = $n - $jump + intdiv( $jump + 4, 33 ) * 33;
		}
		$leap = ( ( $n + 1 ) % 33 - 1 ) % 4;
		if ( -1 === $leap ) {
			$leap = 4;
		}
		return array( 'leap' => $leap, 'gy' => $gy, 'march' => $march );
	}

	private static function g2d( $gy, $gm, $gd ) {
		$d = intdiv( ( $gy + intdiv( $gm - 8, 6 ) + 100100 ) * 1461, 4 ) + intdiv( 153 * ( ( $gm + 9 ) % 12 ) + 2, 5 ) + $gd - 34840408;
		return $d - intdiv( intdiv( $gy + 100100 + intdiv( $gm - 8, 6 ), 100 ) * 3, 4 ) + 752;
	}

	private static function d2g( $jdn ) {
		$j  = 4 * $jdn + 139361631;
		$j += intdiv( intdiv( 4 * $jdn + 183187720, 146097 ) * 3, 4 ) * 4 - 3908;
		$i  = intdiv( $j % 1461, 4 ) * 5 + 308;
		$gd = intdiv( $i % 153, 5 ) + 1;
		$gm = intdiv( $i, 153 ) % 12 + 1;
		$gy = intdiv( $j, 1461 ) - 100100 + intdiv( 8 - $gm, 6 );
		return array( $gy, $gm, $gd );
	}

	private static function j2d( $jy, $jm, $jd ) {
		$r = self::cal( $jy );
		return self::g2d( $r['gy'], 3, $r['march'] ) + ( $jm - 1 ) * 31 - intdiv( $jm, 7 ) * ( $jm - 7 ) + $jd - 1;
	}

	private static function d2j( $jdn ) {
		$gy   = self::d2g( $jdn )[0];
		$jy   = $gy - 621;
		$r    = self::cal( $jy );
		$jdn1 = self::g2d( $gy, 3, $r['march'] );
		$k    = $jdn - $jdn1;
		if ( $k >= 0 ) {
			if ( $k <= 185 ) {
				return array( $jy, 1 + intdiv( $k, 31 ), $k % 31 + 1 );
			}
			$k -= 186;
		} else {
			$jy--;
			$k += 179;
			if ( 1 === $r['leap'] ) {
				$k++;
			}
		}
		return array( $jy, 7 + intdiv( $k, 30 ), $k % 30 + 1 );
	}

	public static function toJalali( $gy, $gm, $gd ) {
		return self::d2j( self::g2d( (int) $gy, (int) $gm, (int) $gd ) );
	}

	public static function toGregorian( $jy, $jm, $jd ) {
		return self::d2g( self::j2d( (int) $jy, (int) $jm, (int) $jd ) );
	}

	public static function isLeap( $jy ) {
		return 0 === self::cal( (int) $jy )['leap'];
	}

	public static function monthLength( $jy, $jm ) {
		if ( $jm <= 6 ) {
			return 31;
		}
		if ( $jm <= 11 ) {
			return 30;
		}
		return self::isLeap( $jy ) ? 30 : 29;
	}

	public static function valid( $jy, $jm, $jd ) {
		return $jy >= 1200 && $jy <= 1700 && $jm >= 1 && $jm <= 12 && $jd >= 1 && $jd <= self::monthLength( $jy, $jm );
	}

	/**
	 * ورودی کاربر → 'Y-m-d' میلادی. فرمت‌ها: 1405/07/26 ، 1405-7-26 ، ۱۴۰۵/۰۷/۲۶ ، و میلادی 2026-10-18
	 * @throws \InvalidArgumentException
	 */
	public static function parse( $input ) {
		$s = Money::normDigits( trim( (string) $input ) );
		$s = str_replace( array( '-', '.', '\\' ), '/', $s );
		if ( ! preg_match( '#^(\d{4})/(\d{1,2})/(\d{1,2})$#', $s, $m ) ) {
			throw new \InvalidArgumentException( 'تاریخ نامعتبر است؛ قالب درست: ۱۴۰۵/۰۷/۲۶' );
		}
		$y = (int) $m[1];
		$mo = (int) $m[2];
		$d = (int) $m[3];
		if ( $y >= 1700 ) {
			if ( ! checkdate( $mo, $d, $y ) ) {
				throw new \InvalidArgumentException( 'تاریخ نامعتبر است.' );
			}
			return sprintf( '%04d-%02d-%02d', $y, $mo, $d );
		}
		if ( ! self::valid( $y, $mo, $d ) ) {
			throw new \InvalidArgumentException( 'تاریخ شمسی نامعتبر است.' );
		}
		$g = self::toGregorian( $y, $mo, $d );
		return sprintf( '%04d-%02d-%02d', $g[0], $g[1], $g[2] );
	}

	public static function fromDb( $gdate ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', (string) $gdate, $m ) ) {
			throw new \InvalidArgumentException( 'تاریخ نامعتبر است.' );
		}
		return self::toJalali( (int) $m[1], (int) $m[2], (int) $m[3] );
	}

	/** رشتهٔ میلادی → «۱۴۰۵/۰۷/۲۶» */
	public static function format( $gdate, $fa = true, $sep = '/' ) {
		if ( null === $gdate || '' === $gdate || '0000-00-00' === $gdate ) {
			return '';
		}
		$j = self::fromDb( $gdate );
		$s = sprintf( '%04d%s%02d%s%02d', $j[0], $sep, $j[1], $sep, $j[2] );
		return $fa ? Money::toFa( $s ) : $s;
	}

	/** «۲۶ مهر ۱۴۰۵» */
	public static function formatLong( $gdate, $fa = true ) {
		if ( ! $gdate ) {
			return '';
		}
		$j = self::fromDb( $gdate );
		$s = $j[2] . ' ' . self::MONTHS[ $j[1] ] . ' ' . $j[0];
		return $fa ? Money::toFa( $s ) : $s;
	}

	/** امروز به وقت تهران ('Y-m-d' میلادی). */
	public static function today() {
		return ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'Asia/Tehran' ) ) )->format( 'Y-m-d' );
	}

	public static function currentYear() {
		return self::fromDb( self::today() )[0];
	}

	public static function addDays( $gdate, $days ) {
		$d = new \DateTimeImmutable( $gdate . ' 00:00:00', new \DateTimeZone( 'UTC' ) );
		return $d->modify( ( $days >= 0 ? '+' : '' ) . (int) $days . ' day' )->format( 'Y-m-d' );
	}

	public static function diffDays( $a, $b ) {
		$da = new \DateTimeImmutable( $a . ' 00:00:00', new \DateTimeZone( 'UTC' ) );
		$db = new \DateTimeImmutable( $b . ' 00:00:00', new \DateTimeZone( 'UTC' ) );
		return (int) $da->diff( $db )->format( '%r%a' );
	}

	/** بازهٔ سال مالی شمسی: [شروع میلادی، پایان میلادی]. */
	public static function yearRange( $jy ) {
		$s = self::toGregorian( $jy, 1, 1 );
		$e = self::toGregorian( $jy, 12, self::monthLength( $jy, 12 ) );
		return array( sprintf( '%04d-%02d-%02d', $s[0], $s[1], $s[2] ), sprintf( '%04d-%02d-%02d', $e[0], $e[1], $e[2] ) );
	}

	/** ۱۲ ماه یک سال شمسی: [ ['no','name','start','end'] ... ] */
	public static function monthsOf( $jy ) {
		$out = array();
		for ( $m = 1; $m <= 12; $m++ ) {
			$s = self::toGregorian( $jy, $m, 1 );
			$e = self::toGregorian( $jy, $m, self::monthLength( $jy, $m ) );
			$out[] = array(
				'no' => $m, 'name' => self::MONTHS[ $m ],
				'start' => sprintf( '%04d-%02d-%02d', $s[0], $s[1], $s[2] ), 'end' => sprintf( '%04d-%02d-%02d', $e[0], $e[1], $e[2] ),
			);
		}
		return $out;
	}
}
