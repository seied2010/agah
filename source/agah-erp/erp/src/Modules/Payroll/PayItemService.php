<?php
/**
 * اقلام مزد و کسور و تعرفه‌های سالانه، هر دو نسخه‌دار با تاریخ اثر (PAY-04).
 * - هر ویرایش یک نسخهٔ تازه با «از تاریخ» می‌سازد؛ نسخهٔ قبلی برای ماه‌های پیش‌تر معتبر می‌ماند و لیست قطعی‌شده دست نمی‌خورد.
 * - مقدار پیش‌فرض اقلام صرفاً نمونه است؛ مشمول مالیات/بیمه بودن و سقف‌ها را کاربر طبق قانون روز تعیین می‌کند.
 */
namespace Agah\Erp\Modules\Payroll;
use Agah\Erp\Core\{Access,Audit,Business,ConflictException,Ctx,Db,Money,Request,ValidationException};
if(!defined('ABSPATH'))exit;
final class PayItemService {
    const KINDS=['earning'=>'مزد و مزایا','deduction'=>'کسور'];
    const CALCS=['prorated'=>'ماهانه، به نسبت روزهای حقوق‌پذیر','fixed'=>'ماهانه ثابت','per_child'=>'برای هر فرزند تحت تکفل، به نسبت روزها'];
    /** پیشنهاد اولیه (قابل ویرایش): PAY-04. */
    const DEFAULTS=[['CHILD','حق اولاد','earning','per_child'],['HOUSING','کمک‌هزینهٔ مسکن','earning','prorated'],['BON','بن کارگری','earning','prorated'],['WELFARE','رفاهی','earning','prorated'],['INCENTIVE','انگیزشی','earning','fixed'],['ATTRACT','حق جذب','earning','prorated'],['JOB','حق شغل','earning','prorated'],['SPECIAL','فوق‌العاده','earning','fixed'],['OTHER','سایر مزایا','earning','fixed'],['LOAN','قسط وام','deduction','fixed']];

    public static function exists($code){return (bool)Db::val('SELECT id FROM '.Db::t('pay_items').' WHERE company_id=%d AND code=%s LIMIT 1',Ctx::cid(),$code);}
    public static function items($cid){Access::need($cid,'payroll.view');return Db::rows('SELECT * FROM '.Db::t('pay_items').' WHERE company_id=%d ORDER BY code,effective_from',$cid);}
    /** نسخهٔ مؤثر هر کد در تاریخ داده‌شده (آخرین نسخهٔ با تاریخ اثر ≤ تاریخ). */
    public static function effective($cid,$date){
        $out=[];foreach(Db::rows('SELECT * FROM '.Db::t('pay_items').' WHERE company_id=%d AND effective_from<=%s ORDER BY code,effective_from',$cid,$date) as $r)$out[$r['code']]=$r;
        return array_filter($out,fn($r)=>(int)$r['is_active']===1);
    }
    public static function seedDefaults($cid,$from){
        return Access::write($cid,'payroll.edit',function()use($cid,$from){$from=Business::date($from);$n=0;
            foreach(self::DEFAULTS as [$code,$name,$kind,$calc])if(!Db::val('SELECT id FROM '.Db::t('pay_items').' WHERE company_id=%d AND code=%s',$cid,$code)){Business::save('pay_items',$cid,0,['code'=>$code,'name'=>$name,'kind'=>$kind,'calc'=>$calc,'taxable'=>1,'insurable'=>$kind==='earning'?1:0,'ceiling'=>0,'effective_from'=>$from,'version'=>1,'is_active'=>1,'created_by'=>Ctx::uid()]);$n++;}
            Audit::log('payroll.items.seed','pay_item',0,null,['count'=>$n,'from'=>$from],$cid);return $n;});
    }
    /** نسخهٔ تازهٔ یک قلم (یا قلم تازه) از تاریخ اثر. غیرفعال‌کردن هم یک نسخه است. */
    public static function saveVersion($cid,array $in){
        return Access::write($cid,'payroll.edit',function()use($cid,$in){
            $code=strtoupper(Request::clean($in['code']??'',30));$from=Business::date($in['effective_from']??'');
            if(!preg_match('/^[A-Z0-9_]{1,30}$/D',$code))throw new ValidationException(['code'=>'کد قلم فقط حروف لاتین بزرگ، عدد و زیرخط.']);
            $name=Request::clean($in['name']??'',190);$kind=$in['kind']??'earning';$calc=$in['calc']??'prorated';
            if(mb_strlen($name)<2||!isset(self::KINDS[$kind])||!isset(self::CALCS[$calc]))throw new ValidationException(['name'=>'نام، نوع یا روش محاسبهٔ قلم معتبر نیست.']);
            $prev=Db::row('SELECT * FROM '.Db::t('pay_items').' WHERE company_id=%d AND code=%s ORDER BY effective_from DESC LIMIT 1 FOR UPDATE',$cid,$code);
            if($prev&&$from<=$prev['effective_from'])throw new ValidationException(['effective_from'=>'تاریخ اثر نسخهٔ تازه باید پس از نسخهٔ قبلی باشد.']);
            if($prev&&$prev['kind']!==$kind)throw new ConflictException('نوع قلم (مزد/کسر) در نسخه‌ها عوض نمی‌شود؛ قلم تازه بسازید.');
            $data=['code'=>$code,'name'=>$name,'kind'=>$kind,'calc'=>$calc,'taxable'=>empty($in['taxable'])?0:1,'insurable'=>empty($in['insurable'])?0:1,'ceiling'=>Business::amount($in['ceiling']??0),'effective_from'=>$from,'version'=>$prev?(int)$prev['version']+1:1,'is_active'=>array_key_exists('is_active',$in)&&empty($in['is_active'])?0:1,'created_by'=>Ctx::uid()];
            $id=Business::save('pay_items',$cid,0,$data);Audit::log('payroll.item.version','pay_item',$id,$prev,$data,$cid);return $id;
        });
    }
    /** حذف فقط برای نسخه‌ای که هیچ لیست حقوقی از تاریخ اثرش استفاده نکرده است. */
    public static function deleteVersion($cid,$id){
        return Access::write($cid,'payroll.edit',function()use($cid,$id){
            $r=Business::row('pay_items',$cid,$id,true);
            if(Db::val('SELECT id FROM '.Db::t('payroll_run_lines').' WHERE company_id=%d AND calc LIKE %s LIMIT 1',$cid,'%"item_version":'.(int)$id.'%'))throw new ConflictException('این نسخه در لیست حقوق به کار رفته است؛ نسخهٔ تازه با «غیرفعال» بسازید.');
            if(!Db::val('SELECT id FROM '.Db::t('pay_items').' WHERE company_id=%d AND code=%s AND id<>%d LIMIT 1',$cid,$r['code'],$id)&&Db::val('SELECT id FROM '.Db::t('contracts').' WHERE company_id=%d AND items_json LIKE %s LIMIT 1',$cid,'%"'.$r['code'].'"%'))throw new ConflictException('این قلم در قرارداد آمده است.');
            Db::delete('pay_items',['company_id'=>$cid,'id'=>$id]);Audit::log('payroll.item.delete','pay_item',$id,$r,null,$cid);return true;
        });
    }

    // ------------------------------------------------------------ تعرفه‌ها

    public static function tariffs($cid,$year){Access::need($cid,'payroll.view');return Db::rows('SELECT * FROM '.Db::t('payroll_tariffs').' WHERE company_id=%d AND fiscal_year_id=%d ORDER BY effective_from',$cid,(int)$year);}
    /** نسخهٔ تازهٔ تعرفهٔ سال از تاریخ اثر؛ همان اعتبارسنجی و حساب‌های پارامترهای حقوق. */
    public static function saveTariff($cid,$year,$from,array $p){
        return Access::write($cid,'payroll.edit',function()use($cid,$year,$from,$p){
            $fy=Business::row('fiscal_years',$cid,$year);$from=Business::date($from);if($from<$fy['start_date']||$from>$fy['end_date'])throw new ValidationException(['effective_from'=>'تاریخ اثر باید داخل همان سال مالی باشد.']);
            $p['formula']='ir_daily_v1';$p['insurance_tax_numerator']=$p['insurance_tax_numerator']??1;$p['insurance_tax_denominator']=$p['insurance_tax_denominator']??1;$p=PayrollMath::params($p);
            $prev=Db::row('SELECT * FROM '.Db::t('payroll_tariffs').' WHERE company_id=%d AND fiscal_year_id=%d ORDER BY effective_from DESC LIMIT 1 FOR UPDATE',$cid,$year);
            if($prev&&$from<=$prev['effective_from'])throw new ValidationException(['effective_from'=>'تاریخ اثر نسخهٔ تازه باید پس از نسخهٔ قبلی باشد.']);
            foreach(['expense_account_id','salary_account_id','insurance_account_id','tax_account_id','deduction_account_id'] as $k)$p[$k]=(int)($p[$k]??0);
            $id=Business::save('payroll_tariffs',$cid,0,['fiscal_year_id'=>(int)$year,'effective_from'=>$from,'version'=>$prev?(int)$prev['version']+1:1,'params_json'=>wp_json_encode($p),'created_by'=>Ctx::uid()]);
            Audit::log('payroll.tariff.version','payroll_tariff',$id,$prev?['version'=>(int)$prev['version']]:null,['from'=>$from,'params'=>$p],$cid);return $id;
        });
    }
    /** پارامترهای مؤثر ماه: آخرین نسخهٔ تعرفه با تاریخ اثر ≤ پایان ماه؛ وگرنه پارامتر سالانهٔ قبلی (نسخهٔ ۰). حساب‌ها از پارامتر سالانه. */
    public static function params($cid,$year,$end){
        $base=Db::val('SELECT params_json FROM '.Db::t('payroll_params').' WHERE company_id=%d AND fiscal_year_id=%d',$cid,$year);$base=$base?json_decode($base,true):null;
        $t=Db::row('SELECT id,version,params_json FROM '.Db::t('payroll_tariffs').' WHERE company_id=%d AND fiscal_year_id=%d AND effective_from<=%s ORDER BY effective_from DESC LIMIT 1',$cid,$year,$end);
        if(!$t)return $base?$base+['tariff_version'=>0]:null;
        $p=json_decode($t['params_json'],true);foreach(['expense_account_id','salary_account_id','insurance_account_id','tax_account_id','deduction_account_id'] as $k)if(empty($p[$k])&&$base)$p[$k]=$base[$k]??0;
        return $p+['tariff_version'=>(int)$t['version'],'tariff_id'=>(int)$t['id']];
    }
}
