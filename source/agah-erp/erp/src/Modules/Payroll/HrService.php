<?php
/**
 * پروندهٔ پرسنلی و چارت سازمانی (PAY-01، PAY-02، PAY-09).
 * - کد ملی فقط رمزشده و با نمایهٔ کور (برای یکتایی) نگه داشته می‌شود؛ دیدن کامل آن مجوز «اطلاعات حساس حقوق» و ثبت در ممیزی می‌خواهد.
 * - انتساب کارمند به پست تاریخچه دارد: انتقال، انتساب قبلی را با تاریخ پایان می‌بندد و هرگز حذف نمی‌کند.
 */
namespace Agah\Erp\Modules\Payroll;
use Agah\Erp\Core\{Access,Audit,Business,ConflictException,Crypto,Ctx,Db,Jalali,Money,NotFoundException,Request,ValidationException};
use Agah\Erp\Modules\Parties\PartyService;
use Agah\Erp\Modules\Treasury\TreasuryService;
if(!defined('ABSPATH'))exit;
final class HrService {
    const STATUS=['active'=>'شاغل','leave'=>'مرخصی بدون حقوق','terminated'=>'قطع همکاری'];

    /** نمای عمومی کارمند: کد ملی فقط به‌صورت پوشیده. */
    public static function profile($cid,$id){Access::need($cid,'payroll.view');$e=Business::row('employees',$cid,$id);$e['national_hint']=Crypto::hint(Crypto::decrypt($e['national_id_enc']));unset($e['national_id_enc'],$e['national_id_idx']);return $e;}
    public static function revealNational($cid,$id){
        Access::need($cid,'payroll.sensitive');$e=Business::row('employees',$cid,$id);
        Audit::log('payroll.national.reveal','employee',$id,null,['by'=>Ctx::uid()],$cid);return Crypto::decrypt($e['national_id_enc']);
    }
    public static function saveProfile($cid,$id,array $in){
        return Access::write($cid,'payroll.edit',function()use($cid,$id,$in){
            $e=Business::row('employees',$cid,$id,true);$errors=[];$data=[];
            foreach(['personnel_no'=>30,'phone'=>40,'email'=>190,'education'=>100,'address'=>500,'insurance_no'=>20] as $k=>$max)$data[$k]=Request::clean($in[$k]??'',$max);
            if($data['email']!==''&&!is_email($data['email']))$errors['email']='ایمیل معتبر نیست.';
            $data['insurance_no']=Money::normDigits($data['insurance_no']);if($data['insurance_no']!==''&&!preg_match('/^[0-9]{6,20}$/D',$data['insurance_no']))$errors['insurance_no']='شمارهٔ بیمه فقط رقم باشد.';
            $data['dependents']=(int)($in['dependents']??0);if($data['dependents']<0||$data['dependents']>20)$errors['dependents']='تعداد افراد تحت تکفل معتبر نیست.';
            $status=$in['employment_status']??'active';if(!isset(self::STATUS[$status]))$errors['employment_status']='وضعیت معتبر نیست.';$data['employment_status']=$status;
            $iban=strtoupper(Money::normDigits(trim($in['iban']??'')));if($iban!==''&&!TreasuryService::validIban($iban))$errors['iban']='شمارهٔ شبا معتبر نیست.';$data['iban']=$iban;
            foreach(['hire_date','leave_date'] as $k){$v=$in[$k]??'';$data[$k]=$v===''||$v===null?null:Business::date($v);}
            if($data['hire_date']&&$data['leave_date']&&$data['leave_date']<$data['hire_date'])$errors['leave_date']='تاریخ پایان همکاری پیش از استخدام است.';
            $national=Money::normDigits(trim($in['national_id']??''));
            if($national!==''){if(!PartyService::validNational($national,'real'))$errors['national_id']='کد ملی معتبر نیست.';else{$idx=Crypto::blind('employee-national|'.$national);if(Db::val('SELECT id FROM '.Db::t('employees').' WHERE company_id=%d AND national_id_idx=%s AND id<>%d',$cid,$idx,$id))$errors['national_id']='این کد ملی برای کارمند دیگری ثبت شده است.';$data['national_id_enc']=Crypto::encrypt($national);$data['national_id_idx']=$idx;}}
            if(!empty($in['clear_national_id'])){$data['national_id_enc']='';$data['national_id_idx']='';}
            if($errors)throw new ValidationException($errors);
            if($status==='terminated'&&Db::val('SELECT id FROM '.Db::t('asset_custody')." WHERE company_id=%d AND holder_type='employee' AND holder_id=%d AND status='active' LIMIT 1",$cid,$id))throw new ConflictException('اموالی هنوز نزد این کارمند است؛ پیش از قطع همکاری، تسویهٔ اموال را انجام دهید.');
            Business::save('employees',$cid,$id,$data);
            $old=array_intersect_key($e,$data);unset($old['national_id_enc'],$old['national_id_idx']);$new=$data;unset($new['national_id_enc'],$new['national_id_idx']);$new['national_id_changed']=array_key_exists('national_id_enc',$data);
            Audit::log('payroll.employee.profile','employee',$id,$old,$new,$cid);return true;
        });
    }

    // ------------------------------------------------------------ چارت

    public static function units($cid){Access::need($cid,'payroll.view');return Db::rows('SELECT u.*,p.name AS parent_name FROM '.Db::t('org_units').' u LEFT JOIN '.Db::t('org_units').' p ON p.company_id=u.company_id AND p.id=u.parent_id WHERE u.company_id=%d ORDER BY u.code',$cid);}
    public static function posts($cid){Access::need($cid,'payroll.view');return Db::rows('SELECT p.*,u.name AS unit_name,s.title AS supervisor_title,(SELECT e.name FROM '.Db::t('post_assignments').' a JOIN '.Db::t('employees')." e ON e.company_id=a.company_id AND e.id=a.employee_id WHERE a.company_id=p.company_id AND a.post_id=p.id AND a.status='active' LIMIT 1) AS holder FROM ".Db::t('org_posts').' p JOIN '.Db::t('org_units').' u ON u.company_id=p.company_id AND u.id=p.unit_id LEFT JOIN '.Db::t('org_posts').' s ON s.company_id=p.company_id AND s.id=p.supervisor_post_id WHERE p.company_id=%d ORDER BY u.code,p.code',$cid);}
    private static function codeName($in,$nameKey='name'){$code=Request::clean(Money::normDigits($in['code']??''),30);$name=Request::clean($in[$nameKey]??'',190);if(!preg_match('/^[A-Za-z0-9_-]{1,30}$/D',$code)||mb_strlen($name)<2)throw new ValidationException(['code'=>'کد لاتین (تا ۳۰) و نام حداقل دو نویسه لازم است.']);return [$code,$name];}
    public static function saveUnit($cid,$id,array $in){
        return Access::write($cid,'payroll.edit',function()use($cid,$id,$in){
            [$code,$name]=self::codeName($in);$parent=(int)($in['parent_id']??0);$old=$id?Business::row('org_units',$cid,$id,true):null;
            if(Db::val('SELECT id FROM '.Db::t('org_units').' WHERE company_id=%d AND code=%s AND id<>%d',$cid,$code,(int)$id))throw new ValidationException(['code'=>'کد واحد تکراری است.']);
            if($parent){Business::row('org_units',$cid,$parent);for($p=$parent,$i=0;$p&&$i<50;$i++){if($id&&$p===(int)$id)throw new ValidationException(['parent_id'=>'واحد نمی‌تواند زیرمجموعهٔ خودش باشد.']);$p=(int)Db::val('SELECT parent_id FROM '.Db::t('org_units').' WHERE company_id=%d AND id=%d',$cid,$p);}}
            $data=['code'=>$code,'name'=>$name,'parent_id'=>$parent?:null,'is_active'=>array_key_exists('is_active',$in)&&empty($in['is_active'])?0:1];$id=Business::save('org_units',$cid,$id,$data);Audit::log('payroll.unit.save','org_unit',$id,$old,$data,$cid);return $id;
        });
    }
    public static function savePost($cid,$id,array $in){
        return Access::write($cid,'payroll.edit',function()use($cid,$id,$in){
            [$code,$title]=self::codeName($in,'title');$old=$id?Business::row('org_posts',$cid,$id,true):null;$unit=Business::row('org_units',$cid,Business::id($in['unit_id']??0));$sup=(int)($in['supervisor_post_id']??0);
            if(Db::val('SELECT id FROM '.Db::t('org_posts').' WHERE company_id=%d AND code=%s AND id<>%d',$cid,$code,(int)$id))throw new ValidationException(['code'=>'کد پست تکراری است.']);
            if($sup){Business::row('org_posts',$cid,$sup);for($p=$sup,$i=0;$p&&$i<50;$i++){if($id&&$p===(int)$id)throw new ValidationException(['supervisor_post_id'=>'سرپرستی چرخشی مجاز نیست.']);$p=(int)Db::val('SELECT supervisor_post_id FROM '.Db::t('org_posts').' WHERE company_id=%d AND id=%d',$cid,$p);}}
            $data=['unit_id'=>(int)$unit['id'],'code'=>$code,'title'=>$title,'supervisor_post_id'=>$sup?:null,'is_active'=>array_key_exists('is_active',$in)&&empty($in['is_active'])?0:1];$id=Business::save('org_posts',$cid,$id,$data);Audit::log('payroll.post.save','org_post',$id,$old,$data,$cid);return $id;
        });
    }
    /** حذف واحد/پست فقط اگر هیچ زیرمجموعه، پست یا انتسابی (حتی گذشته) نداشته باشد؛ وگرنه غیرفعال شود. */
    public static function deleteUnit($cid,$id){return Access::write($cid,'payroll.edit',function()use($cid,$id){$u=Business::row('org_units',$cid,$id,true);if(Db::val('SELECT id FROM '.Db::t('org_units').' WHERE company_id=%d AND parent_id=%d LIMIT 1',$cid,$id)||Db::val('SELECT id FROM '.Db::t('org_posts').' WHERE company_id=%d AND unit_id=%d LIMIT 1',$cid,$id))throw new ConflictException('واحد زیرمجموعه یا پست دارد؛ غیرفعالش کنید.');Db::delete('org_units',['company_id'=>$cid,'id'=>$id]);Audit::log('payroll.unit.delete','org_unit',$id,$u,null,$cid);return true;});}
    public static function deletePost($cid,$id){return Access::write($cid,'payroll.edit',function()use($cid,$id){$p=Business::row('org_posts',$cid,$id,true);if(Db::val('SELECT id FROM '.Db::t('post_assignments').' WHERE company_id=%d AND post_id=%d LIMIT 1',$cid,$id)||Db::val('SELECT id FROM '.Db::t('org_posts').' WHERE company_id=%d AND supervisor_post_id=%d LIMIT 1',$cid,$id))throw new ConflictException('پست سابقهٔ انتساب یا زیردست دارد؛ غیرفعالش کنید.');Db::delete('org_posts',['company_id'=>$cid,'id'=>$id]);Audit::log('payroll.post.delete','org_post',$id,$p,null,$cid);return true;});}
    /** انتساب یا انتقال کارمند به پست از تاریخ معین؛ انتساب جاری با تاریخ روز قبل بسته می‌شود. */
    public static function assign($cid,$employee,$post,$from,$note=''){
        return Access::write($cid,'payroll.edit',function()use($cid,$employee,$post,$from,$note){
            $e=Business::row('employees',$cid,$employee,true);$p=Business::row('org_posts',$cid,$post);if(!(int)$p['is_active'])throw new ValidationException(['post_id'=>'پست غیرفعال است.']);$from=Business::date($from);
            $cur=Db::row('SELECT * FROM '.Db::t('post_assignments')." WHERE company_id=%d AND employee_id=%d AND status='active' FOR UPDATE",$cid,$employee);
            if($cur&&(int)$cur['post_id']===(int)$post)throw new ConflictException('کارمند هم‌اکنون در همین پست است.');
            if($cur&&$from<=$cur['start_date'])throw new ValidationException(['start_date'=>'تاریخ انتقال باید پس از شروع انتساب فعلی ('.Jalali::format($cur['start_date']).') باشد.']);
            if($cur)Db::update('post_assignments',['status'=>'ended','active_slot'=>null,'end_date'=>Jalali::addDays($from,-1),'updated_at'=>gmdate('Y-m-d H:i:s')],['company_id'=>$cid,'id'=>$cur['id']]);
            $id=Business::save('post_assignments',$cid,0,['employee_id'=>(int)$employee,'post_id'=>(int)$post,'start_date'=>$from,'status'=>'active','active_slot'=>1,'note'=>Request::clean($note,300),'created_by'=>Ctx::uid()]);
            Audit::log($cur?'payroll.post.transfer':'payroll.post.assign','employee',$employee,$cur?['post'=>(int)$cur['post_id']]:null,['post'=>(int)$post,'from'=>$from],$cid);return $id;
        });
    }
    public static function assignments($cid,$employee=0){Access::need($cid,'payroll.view');return Db::rows('SELECT a.*,e.name AS employee_name,p.title AS post_title,u.name AS unit_name FROM '.Db::t('post_assignments').' a JOIN '.Db::t('employees').' e ON e.company_id=a.company_id AND e.id=a.employee_id JOIN '.Db::t('org_posts').' p ON p.company_id=a.company_id AND p.id=a.post_id JOIN '.Db::t('org_units').' u ON u.company_id=p.company_id AND u.id=p.unit_id WHERE a.company_id=%d'.($employee?' AND a.employee_id='.(int)$employee:'').' ORDER BY a.employee_id,a.start_date',$cid);}
}
