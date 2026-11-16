<?php
/**
 * محاسبات پولی و مقداری — بدون ممیز شناور در مسیر ثبت مالی.
 *  - پایهٔ ذخیره: «یک‌صدم ریال» به‌صورت عدد صحیح (BIGINT). تومان فقط برای ورودی/نمایش است (۱ تومان = ۱۰ ریال).
 *  - مقدار کالا تا ۴ رقم اعشار به‌صورت عدد صحیح مقیاس‌شده (×۱۰۰۰۰)؛ درصد تا ۲ رقم اعشار به‌صورت basis point.
 *  - گرد کردن: نیم به بالا (دورتر از صفر برای منفی‌ها). هر خط جداگانه گرد می‌شود و جمع فاکتور مجموع خطوط است.
 *  - سرریز int64 => استثنا (به‌جای نتیجهٔ اشتباه).
 */
namespace Agah\Erp\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Money {

	/** حداکثر مبلغ مجاز هر مقدار منفرد (ریال). */
	const MAX = 9000000000000000;
	const SCALE = 100;
	const QTY_SCALE = 10000;

	/** تبدیل ارقام فارسی/عربی به لاتین و حذف جداکننده‌ها. */
	public static function normDigits( $s ) {
		$map = array(
			'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
			'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			'٫' => '.', '٬' => '', '،' => '', ',' => '', ' ' => '', "\u{200C}" => '', "\u{200F}" => '', "\u{200E}" => '', '−' => '-', '–' => '-',
		);
		return strtr( (string) $s, $map );
	}

	/**
	 * ورودی کاربر → ریال (int).
	 * @param string $unit 'rial' | 'toman'
	 * @throws \InvalidArgumentException
	 */
	public static function fromInput( $s, $unit = 'rial' ) {
		$s = self::normDigits( $s );
		if ( '' === $s ) {
			return 0;
		}
		if ( ! preg_match( '/^(-?)(\d+)(?:\.(\d+))?$/', $s, $m ) ) {
			throw new \InvalidArgumentException( 'مبلغ نامعتبر است.' );
		}
		$neg  = '-' === $m[1];
		$int  = ltrim( $m[2], '0' );
		$frac = isset( $m[3] ) ? rtrim( $m[3], '0' ) : '';
        if (!in_array($unit, ['rial','toman'], true)) throw new \InvalidArgumentException('واحد مبلغ نامعتبر است.');
        $precision = $unit === 'toman' ? 3 : 2;
        if (strlen($frac) > $precision) throw new \InvalidArgumentException('مبلغ ریال تا دو و تومان تا سه رقم اعشار مجاز است.');
        $digits = $int . str_pad($frac, $precision, '0');
		$digits = ltrim( $digits, '0' );
		if ( '' === $digits ) {
			return 0;
		}
		if ( strlen( $digits ) > 16 || (int) $digits > self::MAX ) {
			throw new \InvalidArgumentException( 'مبلغ بیش از حد مجاز است.' );
		}
		return $neg ? -(int) $digits : (int) $digits;
	}

	/** مقدار کالا (رشته) → عدد مقیاس‌شده (×10000). */
	public static function qtyScaled( $s ) {
		$s = self::normDigits( $s );
		if ( '' === $s ) {
			return 0;
		}
		if ( ! preg_match( '/^(-?)(\d{1,12})(?:\.(\d{1,4}))?$/', $s, $m ) ) {
			throw new \InvalidArgumentException( 'مقدار نامعتبر است (حداکثر ۴ رقم اعشار).' );
		}
		$frac = isset( $m[3] ) ? str_pad( $m[3], 4, '0' ) : '0000';
		$v    = (int) ( $m[2] . $frac );
		return '-' === $m[1] ? -$v : $v;
	}

	/** عدد مقیاس‌شده → رشتهٔ DECIMAL(18,4): 12500 => "1.2500" */
	public static function qtyToDb( $scaled ) {
		$neg = $scaled < 0;
		$a   = abs( $scaled );
		return ( $neg ? '-' : '' ) . intdiv( $a, self::QTY_SCALE ) . '.' . str_pad( (string) ( $a % self::QTY_SCALE ), 4, '0', STR_PAD_LEFT );
	}

	/** درصد ("10" یا "9.5") → basis point (۱۰٪ = ۱۰۰۰). */
	public static function pctBp( $s ) {
		$s = self::normDigits( $s );
		if ( '' === $s ) {
			return 0;
		}
		if ( ! preg_match( '/^(\d{1,3})(?:\.(\d{1,2}))?$/', $s, $m ) ) {
			throw new \InvalidArgumentException( 'درصد نامعتبر است.' );
		}
		$bp = (int) ( $m[1] . ( isset( $m[2] ) ? str_pad( $m[2], 2, '0' ) : '00' ) );
		if ( $bp > 10000 ) {
			throw new \InvalidArgumentException( 'درصد نمی‌تواند بیش از ۱۰۰ باشد.' );
		}
		return $bp;
	}

	public static function lineAmount( $qtyScaled, $unitPrice ) {
		return self::mulDiv( $qtyScaled, $unitPrice, self::QTY_SCALE );
	}

	public static function percentOf( $amount, $bp ) {
		return self::mulDiv( $amount, $bp, 10000 );
	}

	/** (a × b) ÷ d با گرد کردن دورتر-از-صفر در نیمه؛ سرریز => استثنا. */
	public static function mulDiv( $a, $b, $d ) {
		$a = (int) $a;
		$b = (int) $b;
		if ( 0 === $a || 0 === $b ) {
			return 0;
		}
		$neg = ( ( $a < 0 ) xor ( $b < 0 ) );
		$a   = abs( $a );
		$b   = abs( $b );
		if ( $a > intdiv( PHP_INT_MAX - intdiv( $d, 2 ), $b ) ) {
			if ( function_exists( 'bcmul' ) ) {
				$p = bcadd( bcmul( (string) $a, (string) $b, 0 ), (string) intdiv( $d, 2 ), 0 );
				$q = bcdiv( $p, (string) $d, 0 );
				if ( bccomp( $q, (string) self::MAX ) > 0 ) {
					throw new \InvalidArgumentException( 'مبلغ محاسبه‌شده بیش از حد مجاز است.' );
				}
				$q = (int) $q;
				return $neg ? -$q : $q;
			}
			throw new \InvalidArgumentException( 'مبلغ محاسبه‌شده بیش از حد مجاز است.' );
		}
		$q = intdiv( $a * $b + intdiv( $d, 2 ), $d );
		if ( $q > self::MAX ) {
			throw new \InvalidArgumentException( 'مبلغ محاسبه‌شده بیش از حد مجاز است.' );
		}
		return $neg ? -$q : $q;
	}

    public static function integer($value){
        $s=(string)$value;$digits=ltrim($s,'-');if(!preg_match('/^-?\d+$/D',$s)||strlen($digits)>16||(int)$digits>self::MAX)throw new \OverflowException('مبلغ داخلی خارج از محدوده است.');return (int)$s;
    }

	public static function add( $a, $b ) {
		$r = $a + $b;
		if ( !is_int($a) || !is_int($b) || ! is_int( $r ) || $a>self::MAX || $a < -self::MAX || $b>self::MAX || $b < -self::MAX || $r>self::MAX || $r < -self::MAX ) {
			throw new \InvalidArgumentException( 'جمع مبالغ بیش از حد مجاز است.' );
		}
		return $r;
	}

	/** قالب نمایش مبلغ (ریال → رشتهٔ خوانا؛ منفی داخل پرانتز). */
    /** برچسب واحد پول. */
    public static function unitLabel($unit) { return $unit==='toman'?'تومان':'ریال'; }
    public static function fmt($amount, $unit='rial', $fa=true, $paren=true) {
        $raw=self::toInputValue((int)$amount,$unit);$negative=substr($raw,0,1)==='-';
        $parts=explode('.',ltrim($raw,'-'));$text=self::group($parts[0]).(isset($parts[1])?'.'.$parts[1]:'');
        if($fa)$text=self::toFa(str_replace([',','.'],['٬','٫'],$text));
        return $negative?($paren?'('.$text.')':'-'.$text):$text;
    }
    /** Internal integer is one hundredth Rial. No floats, including exports. */
    public static function toInputValue($amount,$unit='rial') {
        $amount=(int)$amount;$scale=$unit==='toman'?1000:100;$precision=$unit==='toman'?3:2;$a=abs($amount);
        $fraction=rtrim(str_pad((string)($a%$scale),$precision,'0',STR_PAD_LEFT),'0');
        return ($amount<0?'-':'').intdiv($a,$scale).($fraction!==''?'.'.$fraction:'');
    }

	public static function group( $digits ) {
		return preg_replace( '/\B(?=(\d{3})+(?!\d))/', ',', $digits );
	}

	public static function toFa( $s ) {
		return strtr( (string) $s, array( '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹' ) );
	}
}
