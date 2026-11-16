<?php
/**
 * فیلدهای الزامی مشخصات شرکت.
 * - سیاست اصلی را مدیر سامانه (manage_options) برای «حقیقی» و «حقوقی» تعیین می‌کند.
 * - فقط اگر مدیر سامانه اجازه دهد، مدیر هر شرکت می‌تواند برای شرکت خودش سیاست جدا بگذارد.
 * - فیلدی که برای نوع شرکت نمایش داده نمی‌شود هرگز الزامی نیست. همهٔ تغییرها ممیزی می‌شوند.
 */
namespace Agah\Erp\Modules\Company;
use Agah\Erp\Core\{Access,Audit,Db,ForbiddenException,Settings,ValidationException};
if(!defined('ABSPATH'))exit;
final class CompanyPolicyService {
    /** فیلد => [برچسب، نوع‌هایی که فیلد برایشان معنا دارد] */
    const FIELDS=['legal_name'=>['نام ثبتی / نام کامل',['real','legal']],'national_id'=>['کد ملی (حقیقی) / شناسهٔ ملی (حقوقی)',['real','legal']],'economic_code'=>['شماره اقتصادی',['real','legal']],'registration_no'=>['شماره ثبت',['legal']],'phone'=>['تلفن',['real','legal']],'address'=>['نشانی',['real','legal']],'postal_code'=>['کد پستی',['real','legal']]];
    const KINDS=['legal'=>'حقوقی','real'=>'حقیقی'];

    private static function clean($policy){
        $out=['legal'=>[],'real'=>[]];
        foreach(self::KINDS as $kind=>$label)foreach((array)($policy[$kind]??[]) as $field)if(isset(self::FIELDS[$field])&&in_array($kind,self::FIELDS[$field][1],true))$out[$kind][]=$field;
        foreach($out as &$list)$list=array_values(array_unique($list));unset($list);return $out;
    }
    public static function platform(){return self::clean((array)get_option('agah_company_required',[]));}
    public static function delegated(){return get_option('agah_company_policy_delegate','0')==='1';}
    /** سیاست مؤثر یک شرکت: سیاست خود شرکت فقط وقتی تفویض فعال و سیاستی ثبت شده باشد. */
    public static function effective($cid){
        if(self::delegated()){$own=json_decode((string)Settings::get('company_required_override',$cid),true);if(is_array($own))return self::clean($own);}
        return self::platform();
    }
    public static function required($cid,$kind){return self::effective($cid)[$kind==='real'?'real':'legal'];}

    public static function savePlatform(array $policy,$delegate){
        if(!current_user_can('manage_options'))throw new ForbiddenException();
        $old=['policy'=>self::platform(),'delegate'=>self::delegated()];$new=['policy'=>self::clean($policy),'delegate'=>(bool)$delegate];
        update_option('agah_company_required',$new['policy'],false);update_option('agah_company_policy_delegate',$delegate?'1':'0',false);
        Audit::log('company.policy.platform','platform',0,$old,$new,0);
    }
    public static function saveCompany($cid,array $policy,$useOwn){
        return Access::write($cid,'company_settings.edit',function()use($cid,$policy,$useOwn){
            if(!self::delegated())throw new ForbiddenException('تعیین فیلدهای الزامی شرکت به مدیر شرکت تفویض نشده است.');
            $old=Settings::get('company_required_override',$cid);$new=$useOwn?wp_json_encode(self::clean($policy)):'';
            Settings::set('company_required_override',$new,$cid);Audit::log('company.policy.company','company',$cid,['override'=>$old],['override'=>$new],$cid);return true;
        });
    }
    /** خطای فیلدهای الزامی خالی برای نوع انتخاب‌شده؛ $values مقدار مؤثر هر فیلد پس از ذخیره است. */
    public static function missing($cid,$kind,array $values){
        $errors=[];foreach(self::required($cid,$kind) as $field)if(trim((string)($values[$field]??''))==='')$errors[$field]='«'.self::FIELDS[$field][0].'» برای شرکت '.self::KINDS[$kind].' الزامی است.';
        return $errors;
    }
}
