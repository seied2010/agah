<?php
/**
 * کدینگ حساب‌ها (گروه ← کل ← معین). accounts.parent_id → accounts.id (کلید خارجی ترکیبی هم‌شرکتی).
 * voucher_lines.account_id → accounts.id (ماژول سند، نسخهٔ بعد). کد پس از استفاده در سند تغییر نمی‌کند؛ حساب دارای گردش حذف نمی‌شود.
 */
namespace Agah\Erp\Modules\Accounting;

use Agah\Erp\Core\Audit;
use Agah\Erp\Core\ConflictException;
use Agah\Erp\Core\Db;
use Agah\Erp\Core\Money;
use Agah\Erp\Core\NotFoundException;
use Agah\Erp\Core\Request;
use Agah\Erp\Core\ValidationException;

if ( ! defined( 'ABSPATH' ) ) exit;

final class AccountService {

	/** ساخت کدینگ پیش‌فرض برای شرکت جدید (داخل تراکنش بیرونی فراخوانی شود). */
	public static function seedDefault( $companyId ) {
		$now     = gmdate( 'Y-m-d H:i:s' );
		$classes = ChartSeed::classes();
		$ids     = array();
		$gcls    = array();
		foreach ( ChartSeed::groups() as $g ) {
			$gcls[ $g[0] ] = $g[2];
			$ids[ $g[0] ]  = Db::insert( 'accounts', array(
				'company_id' => $companyId, 'parent_id' => null, 'level' => 1, 'code' => $g[0], 'name' => $g[1],
				'nature' => $classes[ $g[2] ][1], 'stmt_class' => $g[2], 'is_system' => 1, 'created_at' => $now,
			) );
		}
		foreach ( ChartSeed::kols() as $k ) {
			$cls = $gcls[ $k[2] ];
			$ids[ $k[0] ] = Db::insert( 'accounts', array(
				'company_id' => $companyId, 'parent_id' => $ids[ $k[2] ], 'level' => 2, 'code' => $k[0], 'name' => $k[1],
				'nature' => $classes[ $cls ][1], 'stmt_class' => $cls, 'is_system' => 1, 'created_at' => $now,
			) );
		}
		foreach ( ChartSeed::moins() as $m ) {
			Db::insert( 'accounts', array(
				'company_id' => $companyId, 'parent_id' => $ids[ substr( $m[0], 0, 3 ) ], 'level' => 3, 'code' => $m[0], 'name' => $m[1],
				'nature' => $classes[ $m[2] ][1], 'stmt_class' => $m[2], 'sys_key' => $m[3],
				'detail_rule' => $m[4], 'detail_kind' => $m[5], 'is_system' => $m[3] ? 1 : 0, 'created_at' => $now,
			) );
		}
	}

    /** Add activity-specific accounts without renaming, deleting or reclassifying existing accounts. */
    public static function seedActivity($cid,$activity){
        $templates=[
            'trading'=>[['10310','کالای بازرگانی','asset_current'],['70110','هزینه حمل کالای بازرگانی','cogs']],
            'services'=>[['60110','درآمد خدمات و پروژه‌ها','revenue'],['70120','بهای خدمات ارائه‌شده','cogs']],
            'manufacturing'=>[['10320','موجودی مواد اولیه','asset_current'],['10321','کالای در جریان ساخت','asset_current'],['10322','محصول ساخته‌شده','asset_current'],['70130','دستمزد مستقیم تولید','cogs'],['70131','سربار تولید','cogs']]
        ];
        foreach($templates[$activity]??[] as [$code,$name,$class]){
            if(Db::val('SELECT id FROM '.Db::t('accounts').' WHERE company_id=%d AND code=%s',$cid,$code))continue;
            $parent=Db::val('SELECT id FROM '.Db::t('accounts').' WHERE company_id=%d AND code=%s',$cid,substr($code,0,3));
            if(!$parent)throw new ConflictException('حساب کل لازم برای الگوی فعالیت موجود نیست.');
            Db::insert('accounts',['company_id'=>$cid,'parent_id'=>$parent,'level'=>3,'code'=>$code,'name'=>$name,'nature'=>ChartSeed::classes()[$class][1],'stmt_class'=>$class,'detail_rule'=>'optional','detail_kind'=>'','is_system'=>0,'created_at'=>gmdate('Y-m-d H:i:s')]);
        }
    }

	public static function all( $companyId ) {
		return Db::rows( 'SELECT * FROM ' . Db::t( 'accounts' ) . ' WHERE company_id = %d ORDER BY code', $companyId );
	}

	public static function get( $companyId, $id ) {
		$r = Db::row( 'SELECT * FROM ' . Db::t( 'accounts' ) . ' WHERE company_id = %d AND id = %d', $companyId, $id );
		if ( ! $r ) {
			throw new NotFoundException();
		}
		return $r;
	}

	public static function bySysKey( $companyId, $key ) {
		$r = Db::row( 'SELECT * FROM ' . Db::t( 'accounts' ) . ' WHERE company_id = %d AND sys_key = %s', $companyId, $key );
		if ( ! $r ) {
			throw new ConflictException( 'حساب سیستمی «' . $key . '» در کدینگ یافت نشد.' );
		}
		return $r;
	}

	public static function isUsed( $companyId, $accountId ) {
		return (int) Db::val( 'SELECT COUNT(*) FROM ' . Db::t( 'voucher_lines' ) . ' WHERE company_id = %d AND account_id = %d', $companyId, $accountId ) > 0;
	}

	/** @param array $in code,name,parent_id,stmt_class(اختیاری),detail_rule,detail_kind */
	public static function create( $companyId, array $in ) {
		$parent = self::get( $companyId, (int) ( isset( $in['parent_id'] ) ? $in['parent_id'] : 0 ) );
		if ( (int) $parent['level'] >= 3 ) {
			throw new ValidationException( array( 'parent_id' => 'زیر «معین» نمی‌توان حساب ساخت.' ) );
		}
		$level = (int) $parent['level'] + 1;
		$clean = self::validate( $companyId, $in, $level, $parent, 0 );
		return Db::tx( function () use ( $companyId, $parent, $level, $clean ) {
			Db::row('SELECT id FROM '.Db::t('companies').' WHERE id=%d FOR UPDATE',$companyId);
			$id = Db::insert( 'accounts', array_merge( $clean, array(
				'company_id' => $companyId, 'parent_id' => $parent['id'], 'level' => $level, 'is_system' => 0, 'created_at' => gmdate( 'Y-m-d H:i:s' ),
			) ) );
			Audit::log( 'account.create', 'account', $id, null, $clean, $companyId );
			return $id;
		} );
	}

	public static function setActive( $companyId, $id, $active ) {
		$a = self::get( $companyId, $id );
		if ( $a['sys_key'] && ! $active ) {
			throw new ConflictException( 'حساب سیستمی غیرفعال نمی‌شود.' );
		}
		Db::tx( function () use ( $companyId, $id, $active, $a ) {
			Db::row('SELECT id FROM '.Db::t('companies').' WHERE id=%d FOR UPDATE',$companyId);
			Db::update( 'accounts', array( 'is_active' => $active ? 1 : 0 ), array( 'id' => $id, 'company_id' => $companyId ) );
			Audit::log( 'account.' . ( $active ? 'activate' : 'deactivate' ), 'account', $id, array( 'is_active' => $a['is_active'] ), array( 'is_active' => $active ? 1 : 0 ), $companyId );
		} );
	}

	public static function delete( $companyId, $id ) {
		$a = self::get( $companyId, $id );
		if ( $a['is_system'] || $a['sys_key'] ) {
			throw new ConflictException( 'حساب سیستمی حذف نمی‌شود.' );
		}
		if ( (int) Db::val( 'SELECT COUNT(*) FROM ' . Db::t( 'accounts' ) . ' WHERE company_id = %d AND parent_id = %d', $companyId, $id ) > 0 ) {
			throw new ConflictException( 'این حساب زیرمجموعه دارد.' );
		}
		if ( self::isUsed( $companyId, $id ) ) {
			throw new ConflictException( 'این حساب در سند استفاده شده است؛ به‌جای حذف آن را غیرفعال کنید.' );
		}
		Db::tx( function () use ( $companyId, $id, $a ) {
			Db::row('SELECT id FROM '.Db::t('companies').' WHERE id=%d FOR UPDATE',$companyId);
			Db::delete( 'accounts', array( 'id' => $id, 'company_id' => $companyId ) );
			Audit::log( 'account.delete', 'account', $id, array( 'code' => $a['code'], 'name' => $a['name'] ), null, $companyId );
		} );
	}

	private static function checkCode( $companyId, $code, $level, $parent, $selfId ) {
		$code = trim( Money::normDigits( $code ) );
		$len  = array( 1 => 1, 2 => 3, 3 => 5 );
		if ( ! preg_match( '/^\d+$/', $code ) || strlen( $code ) !== $len[ $level ] ) {
			throw new ValidationException( array( 'code' => 'کد این سطح باید ' . Money::toFa( $len[ $level ] ) . ' رقم باشد.' ) );
		}
		if ( $parent && 0 !== strpos( $code, $parent['code'] ) ) {
			throw new ValidationException( array( 'code' => 'کد باید با کد حساب بالادستی («' . Money::toFa( $parent['code'] ) . '») شروع شود.' ) );
		}
		if ( Db::val( 'SELECT id FROM ' . Db::t( 'accounts' ) . ' WHERE company_id = %d AND code = %s AND id <> %d', $companyId, $code, $selfId ) ) {
			throw new ValidationException( array( 'code' => 'این کد قبلاً استفاده شده است.' ) );
		}
		return $code;
	}

	private static function validate( $companyId, array $in, $level, $parent, $selfId ) {
		$errors  = array();
		$name    = Request::clean( isset( $in['name'] ) ? $in['name'] : '', 190 );
		if ( mb_strlen( $name ) < 2 ) {
			$errors['name'] = 'نام حساب را وارد کنید.';
		}
		$classes = ChartSeed::classes();
		$cls     = isset( $in['stmt_class'] ) && '' !== $in['stmt_class'] ? (string) $in['stmt_class'] : ( $parent ? $parent['stmt_class'] : '' );
		if ( ! isset( $classes[ $cls ] ) ) {
			$errors['stmt_class'] = 'طبقهٔ صورت مالی نامعتبر است.';
		}
		$rule = isset( $in['detail_rule'] ) ? (string) $in['detail_rule'] : 'none';
		$kind = isset( $in['detail_kind'] ) ? (string) $in['detail_kind'] : '';
		if ( 3 !== $level ) {
			$rule = 'none';
			$kind = '';
		}
		if ( ! in_array( $rule, array( 'none', 'optional', 'required' ), true ) ) {
			$errors['detail_rule'] = 'قاعدهٔ تفصیلی نامعتبر است.';
		}
		$code = '';
		try {
			$code = self::checkCode( $companyId, isset( $in['code'] ) ? $in['code'] : '', $level, $parent, $selfId );
		} catch ( ValidationException $e ) {
			$errors += $e->errors;
		}
		if ( $errors ) {
			throw new ValidationException( $errors );
		}
		return array( 'code' => $code, 'name' => $name, 'stmt_class' => $cls, 'nature' => $classes[ $cls ][1], 'detail_rule' => $rule, 'detail_kind' => $kind );
	}
	/** تغییر نام آزاد است؛ تغییر ساختار حساب دارای گردش یا حساب سیستمی ممنوع است. */
	public static function update($cid,$id,array $in) {
		return \Agah\Erp\Core\Access::write($cid,'account.edit',function()use($cid,$id,$in){
			$old=self::get($cid,$id);
			$parent=$old['parent_id']?self::get($cid,$old['parent_id']):null;
			$new=self::validate($cid,array_merge($old,$in),(int)$old['level'],$parent,$id);
			if(!in_array($new['detail_kind'],['','party','bank','cash','cost_center','project','other'],true)) throw new ValidationException(['detail_kind'=>'نوع تفصیلی معتبر نیست.']);
			if($new['detail_rule']==='none') $new['detail_kind']='';
			foreach(['code','stmt_class','nature','detail_rule','detail_kind'] as $key) {
				if($new[$key]!==$old[$key] && ($old['is_system'] || self::isUsed($cid,$id))) throw new ConflictException('ساختار حساب سیستمی یا دارای گردش تغییر نمی‌کند.');
			}
			if($new['code']!==$old['code'] && Db::val('SELECT COUNT(*) FROM '.Db::t('accounts').' WHERE company_id=%d AND parent_id=%d',$cid,$id)) throw new ConflictException('کد حساب دارای زیرمجموعه تغییر نمی‌کند.');
			Db::update('accounts',$new,['company_id'=>$cid,'id'=>$id]);Audit::log('account.update','account',$id,$old,$new,$cid);
		});
	}

}
