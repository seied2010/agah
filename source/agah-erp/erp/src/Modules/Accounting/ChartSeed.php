<?php
/**
 * کدینگ پیشنهادی حساب‌ها برای شرکت‌های بازرگانی/خدماتی ایران — فقط «نقطهٔ شروع» است و باید توسط حسابدار مسئول بازبینی شود.
 * ساختار کد: گروه = ۱ رقم، کل = ۳ رقم، معین = ۵ رقم. فقط سطح معین در سند قابل‌ثبت است.
 * حساب‌های دارای sys_key برای ثبت خودکار اسناد (ماژول‌های بعدی) لازم‌اند و حذف نمی‌شوند.
 * قالب معین: [کد, نام, طبقهٔ صورت مالی, کلید سیستمی, قاعدهٔ تفصیلی (none|optional|required), نوع تفصیلی]
 */
namespace Agah\Erp\Modules\Accounting;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ChartSeed {

	/** طبقه => [برچسب, ماهیت پیش‌فرض] */
	public static function classes() {
		return array(
			'asset_current' => array( 'دارایی جاری', 'debit' ), 'asset_noncurrent' => array( 'دارایی غیرجاری', 'debit' ),
			'contra_asset' => array( 'کاهندهٔ دارایی (استهلاک انباشته)', 'credit' ),
			'liability_current' => array( 'بدهی جاری', 'credit' ), 'liability_noncurrent' => array( 'بدهی غیرجاری', 'credit' ),
			'equity' => array( 'حقوق صاحبان سرمایه', 'credit' ), 'revenue' => array( 'درآمد عملیاتی', 'credit' ),
			'contra_revenue' => array( 'کاهندهٔ درآمد (برگشت/تخفیف فروش)', 'debit' ), 'cogs' => array( 'بهای تمام‌شده', 'debit' ),
			'opex' => array( 'هزینه عملیاتی', 'debit' ), 'other_income' => array( 'درآمد غیرعملیاتی', 'credit' ),
			'other_expense' => array( 'هزینه غیرعملیاتی', 'debit' ), 'finance_cost' => array( 'هزینه مالی', 'debit' ),
			'tax_expense' => array( 'مالیات بر درآمد', 'debit' ), 'memo' => array( 'انتظامی', 'debit' ),
		);
	}

	public static function groups() {
		return array(
			array( '1', 'دارایی‌های جاری', 'asset_current' ), array( '2', 'دارایی‌های غیرجاری', 'asset_noncurrent' ),
			array( '3', 'بدهی‌های جاری', 'liability_current' ), array( '4', 'بدهی‌های غیرجاری', 'liability_noncurrent' ),
			array( '5', 'حقوق صاحبان سرمایه', 'equity' ), array( '6', 'درآمدها', 'revenue' ),
			array( '7', 'بهای تمام‌شده و هزینه‌ها', 'opex' ), array( '8', 'حساب‌های انتظامی', 'memo' ),
			array( '9', 'حساب‌های افتتاحیه و اختتامیه', 'equity' ),
		);
	}

	/** [کد, نام, کد گروه] */
	public static function kols() {
		return array(
			array( '101', 'موجودی نقد و بانک', '1' ), array( '102', 'حساب‌ها و اسناد دریافتنی', '1' ), array( '103', 'موجودی کالا', '1' ), array( '104', 'مالیات و پیش‌پرداخت‌ها', '1' ),
			array( '201', 'دارایی‌های ثابت مشهود', '2' ), array( '202', 'استهلاک انباشته', '2' ), array( '203', 'سایر دارایی‌های غیرجاری', '2' ),
			array( '301', 'حساب‌ها و اسناد پرداختنی', '3' ), array( '302', 'سایر پرداختنی‌ها', '3' ), array( '303', 'مالیات‌های پرداختنی', '3' ), array( '304', 'پیش‌دریافت‌ها', '3' ),
			array( '401', 'تسهیلات مالی بلندمدت', '4' ),
			array( '501', 'سرمایه', '5' ), array( '502', 'اندوخته‌ها', '5' ), array( '503', 'سود (زیان) انباشته', '5' ), array( '504', 'جاری شرکا', '5' ),
			array( '601', 'فروش و خدمات', '6' ), array( '602', 'سایر درآمدها', '6' ),
			array( '701', 'بهای تمام‌شده', '7' ), array( '702', 'هزینه‌های عملیاتی', '7' ), array( '703', 'هزینه‌های مالی و مالیات', '7' ),
			array( '801', 'حساب‌های انتظامی', '8' ), array( '901', 'افتتاحیه و اختتامیه', '9' ),
		);
	}

	public static function moins() {
		return array(
			array( '10101', 'صندوق', 'asset_current', 'cash', 'required', 'cash' ),
			array( '10102', 'بانک‌ها', 'asset_current', 'bank', 'required', 'bank' ),
			array( '10103', 'تنخواه‌گردان', 'asset_current', null, 'optional', '' ),
			array( '10201', 'حساب‌های دریافتنی تجاری (مشتریان)', 'asset_current', 'receivable', 'required', 'party' ),
			array( '10202', 'اسناد دریافتنی (چک‌های دریافتی)', 'asset_current', 'notes_receivable', 'optional', 'party' ),
			array( '10203', 'سایر حساب‌های دریافتنی', 'asset_current', null, 'optional', 'party' ),
			array( '10301', 'موجودی کالا', 'asset_current', 'inventory', 'none', '' ),
			array( '10401', 'مالیات بر ارزش افزوده خرید (قابل استرداد)', 'asset_current', 'vat_input', 'none', '' ),
			array( '10402', 'پیش‌پرداخت‌ها', 'asset_current', 'prepayments', 'optional', 'party' ),
			array( '20101', 'زمین', 'asset_noncurrent', null, 'none', '' ), array( '20102', 'ساختمان', 'asset_noncurrent', null, 'none', '' ),
			array( '20103', 'تأسیسات و تجهیزات', 'asset_noncurrent', null, 'none', '' ), array( '20104', 'وسایل نقلیه', 'asset_noncurrent', null, 'none', '' ),
			array( '20105', 'اثاثیه و منصوبات', 'asset_noncurrent', null, 'none', '' ),
			array( '20201', 'استهلاک انباشتهٔ دارایی‌های ثابت', 'contra_asset', 'accum_depreciation', 'none', '' ),
			array( '20301', 'سپرده‌ها و ودایع', 'asset_noncurrent', null, 'optional', '' ),
			array( '30101', 'حساب‌های پرداختنی تجاری (تأمین‌کنندگان)', 'liability_current', 'payable', 'required', 'party' ),
			array( '30102', 'اسناد پرداختنی (چک‌های پرداختی)', 'liability_current', 'notes_payable', 'optional', 'party' ),
			array( '30201', 'حقوق و دستمزد پرداختنی', 'liability_current', 'salary_payable', 'optional', '' ),
			array( '30202', 'بیمه و مالیات حقوق پرداختنی', 'liability_current', null, 'none', '' ),
			array( '30203', 'سایر حساب‌های پرداختنی', 'liability_current', null, 'optional', 'party' ),
			array( '30301', 'مالیات بر ارزش افزوده فروش (پرداختنی)', 'liability_current', 'vat_output', 'none', '' ),
			array( '30302', 'مالیات بر درآمد پرداختنی', 'liability_current', null, 'none', '' ),
			array( '30401', 'پیش‌دریافت از مشتریان', 'liability_current', 'customer_advances', 'optional', 'party' ),
			array( '40101', 'وام‌های بلندمدت', 'liability_noncurrent', null, 'none', '' ),
			array( '50101', 'سرمایه', 'equity', 'capital', 'none', '' ), array( '50201', 'اندوختهٔ قانونی', 'equity', null, 'none', '' ),
			array( '50301', 'سود (زیان) انباشته', 'equity', 'retained_earnings', 'none', '' ),
			array( '50302', 'خلاصهٔ سود و زیان', 'equity', 'income_summary', 'none', '' ),
			array( '50401', 'جاری شرکا', 'equity', null, 'optional', 'party' ),
			array( '60101', 'فروش کالا', 'revenue', 'sales_goods', 'none', '' ), array( '60102', 'درآمد خدمات', 'revenue', 'sales_services', 'none', '' ),
			array( '60103', 'برگشت از فروش', 'contra_revenue', 'sales_return', 'none', '' ), array( '60104', 'تخفیفات فروش', 'contra_revenue', 'sales_discount', 'none', '' ),
			array( '60201', 'سایر درآمدها', 'other_income', 'other_income', 'optional', 'cost_center' ), array( '60202', 'سود سپرده بانکی', 'other_income', null, 'none', '' ),
			array( '70101', 'بهای تمام‌شدهٔ کالای فروش‌رفته', 'cogs', 'cogs', 'none', '' ), array( '70102', 'خرید کالا (روش ادواری)', 'cogs', 'purchases', 'none', '' ),
			array( '70103', 'برگشت از خرید', 'cogs', 'purchase_return', 'none', '' ), array( '70104', 'تخفیفات خرید', 'cogs', 'purchase_discount', 'none', '' ),
			array( '70201', 'حقوق و دستمزد', 'opex', 'salary_expense', 'optional', 'cost_center' ), array( '70202', 'اجاره', 'opex', null, 'optional', 'cost_center' ),
			array( '70203', 'آب، برق، گاز و تلفن', 'opex', null, 'optional', 'cost_center' ), array( '70204', 'ملزومات مصرفی', 'opex', null, 'optional', 'cost_center' ),
			array( '70205', 'حمل و نقل', 'opex', null, 'optional', 'cost_center' ), array( '70206', 'تبلیغات و بازاریابی', 'opex', null, 'optional', 'cost_center' ),
			array( '70207', 'استهلاک', 'opex', 'depreciation_expense', 'optional', 'cost_center' ), array( '70208', 'تعمیر و نگهداری', 'opex', null, 'optional', 'cost_center' ),
			array( '70209', 'کارمزد بانکی', 'opex', null, 'optional', 'cost_center' ), array( '70210', 'سایر هزینه‌ها', 'opex', 'other_expense', 'optional', 'cost_center' ),
			array( '70301', 'هزینهٔ بهره', 'finance_cost', null, 'none', '' ), array( '70302', 'مالیات بر درآمد', 'tax_expense', 'income_tax_expense', 'none', '' ),
			array('70303','زیان واگذاری دارایی ثابت','other_expense','asset_loss','none',''),
            array( '80101', 'حساب‌های انتظامی', 'memo', null, 'optional', '' ),
			array( '90101', 'حساب افتتاحیه', 'equity', 'opening_balance', 'none', '' ), array( '90102', 'حساب اختتامیه', 'equity', 'closing_account', 'none', '' ),
		);
	}
}
