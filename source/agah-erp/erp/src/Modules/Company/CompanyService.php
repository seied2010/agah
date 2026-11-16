<?php
/**
 * شرکت‌ها: ساخت (همراه نقش‌ها، عضو مدیر، کدینگ پیش‌فرض و سال مالی)، فهرست شرکت‌های کاربر.
 *
 * جداسازی: «مدیر سامانه» (کاربر وردپرس با manage_options) می‌تواند شرکت بسازد و مدیر شرکت تعیین کند، اما خودش عضو آن نیست
 * و به داده‌های مالی آن دسترسی ندارد (Ctx::boot فقط عضویت را می‌پذیرد).
 * روابط: companies (۱) ← (n) roles، company_members، accounts، fiscal_years … ؛ company_members.user_id = شناسهٔ کاربر وردپرس.
 */
namespace Agah\Erp\Modules\Company;

use Agah\Erp\Core\Audit;
use Agah\Erp\Core\ConflictException;
use Agah\Erp\Core\Crypto;
use Agah\Erp\Core\Db;
use Agah\Erp\Core\Jalali;
use Agah\Erp\Core\Money;
use Agah\Erp\Core\Request;
use Agah\Erp\Core\Settings;
use Agah\Erp\Core\ValidationException;
use Agah\Erp\Modules\Accounting\AccountService;
use Agah\Erp\Modules\Accounting\FiscalService;
use Agah\Erp\Security\Caps;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CompanyService {

	/**
	 * @param int   $ownerUserId کاربر وردپرس که مدیر شرکت می‌شود
	 * @param array $in name, legal_name, kind(legal|real), display_unit(toman|rial), jy (سال مالی اول)
	 * @return int شناسهٔ شرکت
	 */
	public static function create( $ownerUserId, array $in ) {
        if(\Agah\Erp\Modules\Maintenance\BackupService::blocked())throw new \Agah\Erp\Core\ConflictException('بازیابی سامانه در حال اجراست.');
		$errors = array();
        $activity=$in['activity_type']??'trading';if(!in_array($activity,['trading','manufacturing','services'],true))$errors['activity_type']='نوع فعالیت معتبر نیست.';
		$name   = Request::clean( isset( $in['name'] ) ? $in['name'] : '', 190 );
		if ( mb_strlen( $name ) < 2 ) {
			$errors['name'] = 'نام شرکت را وارد کنید.';
		}
		$kind = isset( $in['kind'] ) && 'real' === $in['kind'] ? 'real' : 'legal';
		$unit = isset( $in['display_unit'] ) && 'rial' === $in['display_unit'] ? 'rial' : 'toman';
		$jy   = (int) Money::normDigits( isset( $in['jy'] ) ? $in['jy'] : Jalali::currentYear() );
		if ( $jy < 1300 || $jy > 1600 ) {
			$errors['jy'] = 'سال مالی نامعتبر است.';
		}
		if ( ! get_userdata( (int) $ownerUserId ) ) {
			$errors['owner'] = 'کاربر مدیر شرکت پیدا نشد.';
		}
		if ( $errors ) {
			throw new ValidationException( $errors );
		}
		$max = max( 1, Settings::int( 'max_companies_per_user' ) );
		if ( (int) Db::val( 'SELECT COUNT(*) FROM ' . Db::t( 'companies' ) . ' WHERE owner_user_id = %d', $ownerUserId ) >= $max ) {
			throw new ConflictException( 'سقف تعداد شرکت برای این کاربر پر شده است.' );
		}

		return Db::tx( function () use ( $ownerUserId, $in, $name, $kind, $unit, $jy, $activity ) {
			$now = gmdate( 'Y-m-d H:i:s' );
			$cid = Db::insert( 'companies', array(
				'uuid' => Crypto::uuid(), 'name' => $name, 'legal_name' => Request::clean( isset( $in['legal_name'] ) ? $in['legal_name'] : '', 190 ),
				'kind' => $kind, 'display_unit' => $unit, 'status' => 'active', 'owner_user_id' => (int) $ownerUserId,
				'created_at' => $now, 'updated_at' => $now,
			) );
			$adminRole = 0;
			foreach ( Caps::builtinRoles() as $key => $def ) {
				$rid = Db::insert( 'roles', array( 'company_id' => $cid, 'role_key' => $key, 'name' => $def[0], 'is_system' => 1, 'created_at' => $now ) );
				if ( 'company_admin' === $key ) {
					$adminRole = $rid;
				}
				$vals = array();
				$args = array();
				foreach ( array_unique( $def[1] ) as $cap ) {
					$vals[] = '(%d,%s)';
					$args[] = $rid;
					$args[] = $cap;
				}
				if ( $vals ) {
					Db::exec( 'INSERT INTO ' . Db::t( 'role_caps' ) . ' (role_id, cap) VALUES ' . implode( ',', $vals ), ...$args );
				}
			}
			Db::insert( 'company_members', array( 'company_id' => $cid, 'user_id' => (int) $ownerUserId, 'role_id' => $adminRole, 'status' => 'active', 'created_at' => $now ) );
			Settings::set('activity_type',$activity,$cid);
            AccountService::seedDefault( $cid );AccountService::seedActivity($cid,$activity);
			FiscalService::createYear( $cid, $jy );
			\Agah\Erp\Core\BusinessSchema::seed($cid);
			\Agah\Erp\Core\TradeSchema::seed($cid);
			Audit::log( 'company.create', 'company', $cid, null, array( 'name' => $name, 'owner' => (int) $ownerUserId ), $cid );
			return $cid;
		} );
	}

	/** شرکت‌های فعال کاربر همراه نام نقش. */
	public static function forUser( $userId ) {
		return Db::rows(
			'SELECT c.id, c.name, c.kind, r.name AS role_name FROM ' . Db::t( 'company_members' ) . ' m
			 JOIN ' . Db::t( 'companies' ) . ' c ON c.id = m.company_id
			 JOIN ' . Db::t( 'roles' ) . " r ON r.id = m.role_id AND r.company_id = m.company_id
			 WHERE m.user_id = %d AND m.status = 'active' AND c.status = 'active' ORDER BY c.name",
			(int) $userId
		);
	}

	/** فهرست همهٔ شرکت‌ها برای مدیر سامانه — فقط متادیتا، بدون هیچ داده مالی. */
	public static function listAllMeta() {
		return Db::rows( 'SELECT id, name, owner_user_id, status, created_at FROM ' . Db::t( 'companies' ) . ' ORDER BY id DESC LIMIT 200' );
	}
	/** ویرایش مشخصات و تنظیمات مجاز؛ شناسهٔ ملی هرگز آشکار در DB یا ممیزی ذخیره نمی‌شود. */
	public static function update($cid,array $in) {
		return \Agah\Erp\Core\Access::write($cid,'company_settings.edit',function()use($cid,$in){
			$old=Db::row('SELECT * FROM '.Db::t('companies').' WHERE id=%d',$cid);
			$name=Request::clean($in['name']??'',190);$kind=$in['kind']??'legal';$unit=$in['display_unit']??'toman';
			$errors=[];
            if(isset($in['activity_type'])&&$in['activity_type']!=='manufacturing'&&Db::val('SELECT id FROM '.Db::t('production_orders')." WHERE company_id=%d AND status IN ('draft','running') LIMIT 1",$cid))throw new \Agah\Erp\Core\ConflictException('ابتدا دستورهای تولید باز را تعیین تکلیف کنید.');
            if(isset($in['activity_type'])&&!in_array($in['activity_type'],['trading','manufacturing','services'],true))$errors['activity_type']='نوع فعالیت معتبر نیست.';
			if(mb_strlen($name)<2) $errors['name']='نام شرکت حداقل دو نویسه باشد.';
			if(!in_array($kind,['real','legal'],true)) $errors['kind']='نوع شرکت معتبر نیست.';
			if(!in_array($unit,['rial','toman'],true)) $errors['display_unit']='واحد پول معتبر نیست.';
			$national=Money::normDigits(trim($in['national_id']??''));
			if($national!=='' && !preg_match($kind==='real'?'/^[0-9]{10}$/D':'/^[0-9]{11}$/D',$national)) $errors['national_id']='شناسه باید برای شخص حقیقی ۱۰ و حقوقی ۱۱ رقم باشد.';
			elseif($national!=='' && !\Agah\Erp\Modules\Parties\PartyService::validNational($national,$kind==='real'?'real':'legal')) $errors['national_id']=$kind==='real'?'کد ملی معتبر نیست (رقم کنترل تطبیق ندارد).':'شناسهٔ ملی معتبر نیست (رقم کنترل تطبیق ندارد).';
			elseif($national==='' && empty($in['clear_national_id']) && (string)$old['national_id_enc']!=='' && $kind!==$old['kind'] && in_array($kind,['real','legal'],true) && !\Agah\Erp\Modules\Parties\PartyService::validNational(Crypto::decrypt($old['national_id_enc']),$kind)) $errors['national_id']='با تغییر نوع شرکت، شناسهٔ متناسب با نوع تازه را وارد کنید یا شناسهٔ قبلی را حذف کنید.';
			$postal=Money::normDigits(trim($in['postal_code']??''));
			if($postal!=='' && !preg_match('/^[0-9]{10}$/D',$postal)) $errors['postal_code']='کد پستی باید ۱۰ رقم باشد.';
			$new=['name'=>$name,'kind'=>$kind,'display_unit'=>$unit,'postal_code'=>$postal,'updated_at'=>gmdate('Y-m-d H:i:s')];
			foreach(['legal_name'=>190,'economic_code'=>30,'registration_no'=>30,'phone'=>40,'address'=>500] as $key=>$max) $new[$key]=Request::clean($in[$key]??'',$max);
			// فیلدهای الزامی سیاست مؤثر (مدیر سامانه یا مدیر شرکت با تفویض)؛ فقط برای نوع انتخاب‌شده.
			if(in_array($kind,['real','legal'],true)){
				$keepsId=$national!==''||(empty($in['clear_national_id'])&&(string)$old['national_id_enc']!=='');
				$errors+=CompanyPolicyService::missing($cid,$kind,$new+['national_id'=>$keepsId?'1':'']);
			}
			if($errors) throw new ValidationException($errors);
			if($national!=='') $new['national_id_enc']=Crypto::encrypt($national);
			if(!empty($in['clear_national_id'])) $new['national_id_enc']='';
			Db::update('companies',$new,['id'=>$cid]);
			$before=[];$after=[];
			foreach(['require_2fa','persian_digits','default_due_days','voucher_prefix','invoice_prefix','activity_type'] as $key) {
				if(!array_key_exists($key,$in)) continue;
				$value=(string)$in[$key];
				if(in_array($key,['require_2fa','persian_digits'],true) && !in_array($value,['0','1'],true)) throw new ValidationException([$key=>'مقدار نامعتبر است.']);
				if($key==='default_due_days' && (!ctype_digit($value) || (int)$value>3650)) throw new ValidationException([$key=>'مهلت بین صفر و ۳۶۵۰ روز باشد.']);
				if(strpos($key,'prefix')!==false) $value=Request::clean($value,20);
				$before[$key]=Settings::get($key,$cid);$after[$key]=$value;Settings::set($key,$value,$cid);
			}
			if(isset($in['activity_type']))AccountService::seedActivity($cid,$in['activity_type']);
            Audit::log('company.update','company',$cid,$old,$new,$cid);
			Audit::log('company.settings','company',$cid,$before,$after,$cid);
		});
	}

}
