<?php
/**
 * تنظیمات کلید/مقدار. company_id = 0 یعنی تنظیمات پلتفرم؛ غیر از آن تنظیمات یک شرکت.
 * کلیدهای مجاز و پیش‌فرض‌ها فقط اینجا تعریف می‌شوند (جلوگیری از کلیدهای سرگردان).
 * رازها (کلید API پیامک و ...) هرگز اینجا ذخیره نمی‌شوند؛ فقط ثابت‌های wp-config.php یا متغیر محیطی.
 */
namespace Agah\Erp\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Settings {

	private static $cache = array();

	public static function defaults( $company = true ) {
		if ( $company ) {
			return array(
				'legal_vat_credit_mode'=>'review',
                'ratio_credit_sales'=>'none',
                'company_required_override'=>'',
                'insurance_workshop_code'=>'',
                'legal_vat_credit_note'=>'',
                'activity_type'=>'trading',
                'production_wip_account'=>'0','production_labor_account'=>'0','production_overhead_account'=>'0','production_loss_account'=>'0',
                'stock_cost_method' => 'average',
                'purchase_goods_mode' => 'periodic', // periodic: خرید کالا هزینه می‌شود | inventory: به حساب موجودی کالا
				'default_due_days'    => '30',
				'require_2fa'         => '0',
				'tax_confirmed'       => '0',        // مدیر شرکت نرخ‌های مالیاتی را بازبینی و تأیید کرده؟
				'invoice_footer'      => '',
				'voucher_prefix'      => '',
				'voucher_sign_1'      => 'حسابداری',
				'voucher_sign_2'      => 'مدیر مالی',
				'voucher_sign_3'      => 'مدیرعامل',
				'invoice_prefix'      => '',
				'persian_digits'      => '1',
			);
		}
		return array(
			'allow_self_company'     => '0',
			'max_companies_per_user' => '3',
			'idle_timeout_min'       => '30',
			'login_max_attempts'     => '5',
			'login_lock_min'         => '15',
			'max_upload_mb'          => '5',
		);
	}

	public static function get( $key, $companyId = 0 ) {
		$companyId = (int) $companyId;
		$defs      = self::defaults( $companyId > 0 );
		if ( ! array_key_exists( $key, $defs ) ) {
			throw new \InvalidArgumentException( 'unknown setting: ' . $key );
		}
		$ck = $companyId . ':' . $key;
		if ( array_key_exists( $ck, self::$cache ) ) {
			return self::$cache[ $ck ];
		}
		$v = Db::val( 'SELECT svalue FROM ' . Db::t( 'settings' ) . ' WHERE company_id = %d AND skey = %s', $companyId, $key );
		return self::$cache[ $ck ] = ( null === $v ) ? $defs[ $key ] : (string) $v;
	}

	public static function set( $key, $value, $companyId = 0 ) {
		$companyId = (int) $companyId;
		if ( ! array_key_exists( $key, self::defaults( $companyId > 0 ) ) ) {
			throw new \InvalidArgumentException( 'unknown setting: ' . $key );
		}
		Db::exec(
			'INSERT INTO ' . Db::t( 'settings' ) . ' (company_id, skey, svalue) VALUES (%d, %s, %s) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)',
			$companyId, $key, (string) $value
		);
		self::$cache[ $companyId . ':' . $key ] = (string) $value;
	}

	public static function int( $key, $companyId = 0 ) {
		return (int) self::get( $key, $companyId );
	}

	public static function flag( $key, $companyId = 0 ) {
		return '1' === self::get( $key, $companyId );
	}

	public static function flush() {
		self::$cache = array();
	}
}
