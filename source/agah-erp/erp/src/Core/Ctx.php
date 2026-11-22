<?php
/**
 * زمینهٔ درخواست: «چه کسی» در «کدام شرکت» کار می‌کند و چه مجوزهایی دارد.
 * تنها راه ورود به داده‌های یک شرکت: Ctx::boot($userId, $companyId) که عضویت فعال را از جدول company_members می‌خواند.
 * شناسهٔ شرکت در URL فقط «درخواست» است؛ اگر کاربر عضو نباشد ۴۰۴ می‌گیرد (نه ۴۰۳) تا وجود شرکت‌های دیگر لو نرود.
 * مدیر سایت وردپرس (administrator) خودکار عضو هیچ شرکتی نیست — جداسازی مدیر سامانه از مدیر شرکت.
 */
namespace Agah\Erp\Core;

use Agah\Erp\Security\Caps;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Ctx {

	private static $uid = 0;
	private static $cid = 0;
	private static $company = null;
	private static $caps = array();
	private static $roleKey = '';

	public static function reset() {
		self::$uid = 0; self::$cid = 0; self::$company = null; self::$caps = array(); self::$roleKey = '';
	}

	/** @throws NotFoundException|ForbiddenException */
	public static function boot( $userId, $companyId, $current = false ) {
		self::reset();
		$userId = (int) $userId;
		$companyId = (int) $companyId;
		if ( $userId <= 0 || $companyId <= 0 ) {
			throw new NotFoundException();
		}
		$m = Db::row(
			'SELECT m.role_id, m.invoice_delete_override, m.status AS mstatus, c.status AS cstatus, r.role_key FROM ' . Db::t( 'company_members' ) . ' m
			 JOIN ' . Db::t( 'companies' ) . ' c ON c.id = m.company_id
			 JOIN ' . Db::t( 'roles' ) . ' r ON r.id = m.role_id AND r.company_id = m.company_id
			 WHERE m.company_id = %d AND m.user_id = %d' . ($current ? ' LOCK IN SHARE MODE' : ''),
			$companyId, $userId
		);
		if ( ! $m ) {
			throw new NotFoundException();
		}
		if ( 'active' !== $m['mstatus'] || 'active' !== $m['cstatus'] ) {
			throw new ForbiddenException( 'دسترسی شما به این شرکت غیرفعال است.' );
		}
		\Agah\Erp\Security\Auth::assertCompany($userId, $companyId);
		self::$uid     = $userId;
		self::$cid     = $companyId;
		self::$roleKey = (string) $m['role_key'];
		foreach ( Db::col( 'SELECT cap FROM ' . Db::t( 'role_caps' ) . ' WHERE role_id = %d' . ($current ? ' LOCK IN SHARE MODE' : ''), (int) $m['role_id'] ) as $cap ) {
			if ( Caps::isValid( $cap ) ) {
				self::$caps[ $cap ] = true;
			}
		}
        // Only this dedicated capability has an individual override; membership and company checks always run first.
        if((int)$m['invoice_delete_override']===1)self::$caps['invoice_delete.delete']=true;
        elseif((int)$m['invoice_delete_override']===0)unset(self::$caps['invoice_delete.delete']);
		self::$company = Db::row( 'SELECT * FROM ' . Db::t( 'companies' ) . ' WHERE id = %d' . ($current ? ' LOCK IN SHARE MODE' : ''), $companyId );
	}

	public static function uid() { return self::$uid; }
	public static function roleKey() { return self::$roleKey; }

	public static function cid() {
		if ( self::$cid <= 0 ) {
			throw new \LogicException( 'no company context' );
		}
		return self::$cid;
	}

	public static function cidOrZero() { return self::$cid; }
	public static function company() { return self::$company ?: array(); }

	public static function can( $cap ) {
        if(preg_match('/^(proforma|sale_order|sale_invoice|sale_return|purchase_request|purchase_order|purchase_invoice|purchase_return)\.delete$/D',$cap) && empty(self::$caps['invoice_delete.delete']))return false;
		return ! empty( self::$caps[ $cap ] );
	}

	/** @throws ForbiddenException */
	public static function need( $cap ) {
		if ( ! Caps::isValid( $cap ) ) {
			throw new \LogicException( 'unknown capability ' . $cap );
		}
		if ( ! self::can( $cap ) ) {
			throw new ForbiddenException();
		}
	}

	public static function unit() {
		$c = self::company();
		return ( isset( $c['display_unit'] ) && 'rial' === $c['display_unit'] ) ? 'rial' : 'toman';
	}

	public static function fa() {
		return self::$cid > 0 ? Settings::flag( 'persian_digits', self::$cid ) : true;
	}
}
