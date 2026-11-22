<?php
/**
 * ثبت مجوزها (Capabilities) و نقش‌های پیش‌فرض.
 * قالب هر مجوز: «<منبع>.<عمل>» مثل voucher.create یا sale_invoice.approve.
 * عمل‌های استاندارد: view، create، edit، delete، approve، export (+ اعمال ویژهٔ سند: finalize/reopen/void).
 * هر مجوز در سرور و برای هر درخواست بررسی می‌شود (Ctx::need). مخفی‌بودن دکمه در UI فقط راحتی کاربر است.
 * نقش‌ها برای هر شرکت جداگانه ساخته (کپی از الگوها) و قابل ویرایش‌اند: roles + role_caps.
 */
namespace Agah\Erp\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Caps {

	/** منبع => [برچسب فارسی, گروه, عمل‌ها] */
	public static function resources() {
		$std = array( 'view', 'create', 'edit', 'delete', 'approve', 'export' );
		return array(
			'dashboard'         => array( 'داشبورد', 'general', array( 'view' ) ),
			'company_settings'  => array( 'تنظیمات شرکت', 'company', array( 'view', 'edit', 'delete' ) ),
			'member'            => array( 'کاربران شرکت', 'company', array( 'view', 'create', 'edit', 'delete' ) ),
			'role'              => array( 'نقش‌ها و دسترسی‌ها', 'company', array( 'view', 'edit' ) ),
            'invoice_delete' => array('حذف فاکتور؛ ویرایش = تفویض دسترسی','company',array('delete','edit')),
			'fiscal_year'       => array( 'سال و دورهٔ مالی', 'company', array( 'view', 'create', 'edit', 'approve' ) ),
			'audit'             => array( 'گزارش تغییرات (ممیزی)', 'company', array( 'view', 'export' ) ),
			'account'           => array( 'کدینگ حساب‌ها', 'accounting', array( 'view', 'create', 'edit', 'delete', 'export' ) ),
			'detail'            => array( 'حساب‌های تفصیلی', 'accounting', array( 'view', 'create', 'edit', 'delete' ) ),
			'voucher'           => array( 'اسناد حسابداری', 'accounting', array( 'view', 'create', 'edit', 'delete', 'approve', 'export', 'finalize', 'reopen', 'void' ) ),
			'closing'           => array( 'بستن حساب‌ها و سال نو', 'accounting', array( 'approve' ) ),
			'party'             => array( 'اشخاص', 'party', array( 'view', 'create', 'edit', 'delete', 'export', 'sensitive' ) ),
			'item'              => array( 'کالا و خدمات', 'catalog', array( 'view', 'create', 'edit', 'delete', 'export' ) ),
			'tax_rate'          => array( 'نرخ‌های مالیات', 'catalog', array( 'view', 'edit', 'confirm' ) ),
			'treasury_account'  => array( 'حساب‌های بانکی و صندوق', 'treasury', array( 'view', 'create', 'edit', 'delete' ) ),
			'receipt'           => array( 'دریافت‌ها', 'treasury', $std ),
			'payment'           => array( 'پرداخت‌ها', 'treasury', $std ),
			'transfer'          => array( 'انتقال بین حساب‌ها', 'treasury', $std ),
			'cheque'            => array( 'چک‌ها', 'treasury', array( 'view', 'create', 'edit', 'approve', 'export' ) ),
			'warehouse' => array('انبارها', 'inventory', array('view','create','edit')),
            'stock' => array('گردش و شمارش انبار', 'inventory', array('view','create','delete','export')),
            'bom'=>array('فرمول ساخت','production',array('view','create','edit')),
            'production'=>array('تولید و بهای تمام‌شده','production',$std),
            'proforma'          => array( 'پیش‌فاکتور', 'sales', $std ),
			'sale_order'        => array( 'سفارش فروش', 'sales', $std ),
			'sale_invoice'      => array( 'فاکتور فروش', 'sales', $std ),
			'sale_return'       => array( 'برگشت از فروش', 'sales', $std ),
			'purchase_request'  => array( 'درخواست خرید', 'purchase', $std ),
			'purchase_order'    => array( 'سفارش خرید', 'purchase', $std ),
			'purchase_invoice'  => array( 'فاکتور خرید', 'purchase', $std ),
			'purchase_return'   => array( 'برگشت از خرید', 'purchase', $std ),
			'expense'           => array( 'هزینه و درآمد', 'expense', $std ),
			'report'            => array( 'گزارش‌ها', 'report', array( 'view', 'export' ) ),
			'legal'=>array('گزارش‌های قانونی','report',array('view','edit','export')),
            'health'=>array('سلامت سامانه','company',array('view')),
            'backup'=>array('پشتیبان‌گیری شرکت','company',array('view','export')),
            'payroll' => array('حقوق و دستمزد','payroll',array_merge($std,array('sensitive','pay'))),
            'asset' => array('دارایی ثابت','assets',$std),
            'file'              => array( 'پیوست‌ها', 'general', array( 'view', 'create', 'delete' ) ),
            'connect'           => array( 'اتصال فروشگاه‌ها', 'company', array( 'view', 'edit' ) ),
            'moadian'           => array( 'سامانهٔ مؤدیان', 'report', array( 'view', 'edit', 'approve' ) ),
		);
	}

	/** @return string[] همهٔ مجوزهای معتبر */
	public static function all() {
		$out = array();
		foreach ( self::resources() as $res => $def ) {
			foreach ( $def[2] as $act ) {
				$out[] = $res . '.' . $act;
			}
		}
		return $out;
	}

	public static function isValid( $cap ) {
		return in_array( $cap, self::all(), true );
	}

	private static function pick( array $resources, array $actions ) {
		$out = array();
		foreach ( $resources as $r ) {
			foreach ( $actions as $a ) {
				if ( in_array( $a, self::resources()[ $r ][2], true ) ) {
					$out[] = $r . '.' . $a;
				}
			}
		}
		return $out;
	}

	/** الگوی نقش‌ها: کلید => [نام, مجوزها]. «مدیر سامانه» (پلتفرم) یک نقش شرکتی نیست. */
	public static function builtinRoles() {
		$all  = self::all();
		$acct = array_diff( $all, array(
			'party.sensitive', 'tax_rate.confirm', 'member.view', 'member.create', 'member.edit', 'member.delete', 'role.view', 'role.edit', 'company_settings.edit', 'company_settings.delete', 'payroll.sensitive', 'payroll.pay',
			'fiscal_year.approve', 'voucher.reopen', 'voucher.void', 'audit.view', 'audit.export', 'closing.approve', 'health.view', 'backup.view', 'backup.export', 'connect.edit', 'moadian.approve',
            'invoice_delete.delete', 'invoice_delete.edit',
		) );
		return array(
			'company_admin' => array( 'مدیر شرکت', $all ),
            'shop_manager' => array('مدیر فروشگاه',array_merge(
                array('dashboard.view','file.view','file.create','invoice_delete.delete','warehouse.view','stock.view'),
                self::pick(array('party','item'),array('view','create','edit')),
                self::pick(array('proforma','sale_order','sale_invoice','sale_return','purchase_request','purchase_order','purchase_invoice','purchase_return'),array('view','create','edit','delete','export'))
            )),
			'accountant'    => array( 'حسابدار', array_values( $acct ) ),
			'cashier'       => array( 'صندوقدار', array_merge(
				array( 'dashboard.view', 'file.view', 'file.create' ),
				self::pick( array( 'party', 'treasury_account' ), array( 'view' ) ),
				self::pick( array( 'receipt', 'payment', 'transfer' ), array( 'view', 'create', 'edit' ) ),
				self::pick( array( 'cheque' ), array( 'view', 'create', 'edit' ) ),
				self::pick( array( 'sale_invoice', 'purchase_invoice' ), array( 'view' ) )
			) ),
			'storekeeper'   => array( 'انباردار', array_merge(
				array( 'dashboard.view', 'file.view', 'warehouse.view','stock.view','stock.create','stock.export' ),
				self::pick( array( 'item' ), array( 'view', 'create', 'edit', 'export' ) ),
				self::pick( array( 'party' ), array( 'view' ) ),
				self::pick( array( 'purchase_request', 'purchase_order' ), array( 'view', 'create', 'edit' ) )
			) ),
			'salesperson'   => array( 'فروشنده', array_merge(
				array( 'dashboard.view', 'file.view', 'file.create' ),
				self::pick( array( 'party' ), array( 'view', 'create', 'edit' ) ),
				self::pick( array( 'item' ), array( 'view' ) ),
				self::pick( array( 'proforma', 'sale_order', 'sale_invoice', 'sale_return' ), array( 'view', 'create', 'edit' ) )
			) ),
			'viewer'        => array( 'کاربر فقط‌خواندنی', array_values( array_filter( $all, function ( $c ) {
				return '.view' === substr( $c, -5 ) && 0 !== strpos( $c, 'member.' ) && 0 !== strpos( $c, 'role.' ) && 0 !== strpos( $c, 'audit.' ) && 0 !== strpos($c,'payroll.') && 0 !== strpos($c,'legal.') && 0 !== strpos($c,'health.') && 0 !== strpos($c,'connect.');
			} ) ) ),
		);
	}
}
