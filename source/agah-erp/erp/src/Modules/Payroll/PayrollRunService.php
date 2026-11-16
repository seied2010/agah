<?php
/**
 * لیست حقوق دومرحله‌ای (PAY-03..08).
 * مرحلهٔ ۱: محاسبهٔ اولیه → «قفل و ارسال» (نسخهٔ قفل‌شده با اثر انگشت؛ خروجی مالیات/بیمه از همین نسخه).
 * مرحلهٔ ۲: ورود مالیات اعلامی و تعدیل‌ها به تفکیک فرد با مجوز و دلیل → محاسبهٔ دوباره در نسخهٔ تازه (نسخهٔ قبلی دست نمی‌خورد)
 *           → مقایسهٔ نسخه‌ها → تأیید نهایی: فیش هر نفر و یک سند حقوق با منبع یکتا → پرداخت با سند جدا.
 * سند یا فیش قطعی‌شده بازنویسی نمی‌شود؛ اصلاح فقط با «برگشت» ثبت‌شده و لیست تازهٔ همان ماه.
 */
namespace Agah\Erp\Modules\Payroll;
use Agah\Erp\Core\{Access,Audit,Business,ConflictException,Crypto,Ctx,Db,Jalali,Money,NotFoundException,Request,Sequence,Settings,ValidationException};
use Agah\Erp\Modules\Accounting\{FiscalService,VoucherService};
use Agah\Erp\Modules\Treasury\TreasuryService;
if(!defined('ABSPATH'))exit;
final class PayrollRunService {
    const STATUS=['calculated'=>'محاسبهٔ اولیه','sent'=>'قفل و ارسال‌شده (مرحلهٔ ۱)','adjusting'=>'تعدیل (مرحلهٔ ۲)','final'=>'تأیید نهایی و سند','paid'=>'پرداخت‌شده','reversed'=>'برگشت‌خورده'];
    const LABELS=['BASE'=>'حقوق/مزد پایه','BENEFITS'=>'مزایای ثابت قرارداد','OVERTIME'=>'اضافه‌کاری','ADJ'=>'تعدیل افزایشی','ADJ_DED'=>'تعدیل کاهشی','ATT_DED'=>'کسور ثبت‌شده در کارکرد'];

    public static function runs($cid){Access::need($cid,'payroll.view');return Db::rows('SELECT * FROM '.Db::t('payroll_runs').' WHERE company_id=%d ORDER BY id DESC LIMIT 60',$cid);}
    public static function run($cid,$id){Access::need($cid,'payroll.view');return Business::row('payroll_runs',$cid,$id);}
    public static function lines($cid,$run,$version){Access::need($cid,'payroll.view');return Db::rows('SELECT l.*,e.name AS employee_name,e.code AS employee_code FROM '.Db::t('payroll_run_lines').' l JOIN '.Db::t('employees').' e ON e.company_id=l.company_id AND e.id=l.employee_id WHERE l.company_id=%d AND l.run_id=%d AND l.version=%d ORDER BY e.name',$cid,(int)$run,(int)$version);}
    public static function adjustments($cid,$run){Access::need($cid,'payroll.view');return Db::rows('SELECT a.*,e.name AS employee_name FROM '.Db::t('payroll_run_adjustments').' a JOIN '.Db::t('employees').' e ON e.company_id=a.company_id AND e.id=a.employee_id WHERE a.company_id=%d AND a.run_id=%d ORDER BY a.id',$cid,(int)$run);}

    /** محاسبهٔ یک نفر: ناخالص تا خالص با خط به خط اقلام. مبالغ صحیح (۱/۱۰۰ ریال)، بدون اعشار. */
    public static function calculate(array $e,array $c,array $a,array $p,$days,array $items,?array $adj=null){
        PayrollMath::params($p);$type=$c['contract_type']?:'full';
        $work=(int)$a['work_days'];$leave=(int)$a['leave_days'];$mission=(int)($a['mission_days']??0);$absence=(int)($a['absence_days']??0);
        if($work+$leave+$mission+$absence>$days)throw new ValidationException(['attendance'=>'جمع کارکرد، مرخصی، مأموریت و غیبت بیش از روزهای ماه است.']);
        $paid=$work+$leave+$mission;$L=[];$gross=0;$insurable=0;$taxGross=0;$deduct=0;
        // پایه بر حسب نوع قرارداد
        if($type==='hourly'){$base=Money::mulDiv((int)$c['hourly_rate'],(int)$a['work_minutes'],60);$basis='ساعت کار: '.intdiv((int)$a['work_minutes'],60).':'.str_pad((string)((int)$a['work_minutes']%60),2,'0',STR_PAD_LEFT);}
        elseif($type==='project'){$base=Money::mulDiv((int)$c['project_amount'],(int)$a['project_permille'],1000);$basis='پیشرفت پروژه: '.((int)$a['project_permille']/10).'٪';}
        else{$base=Money::mulDiv((int)$c['base_salary'],$paid,30);$basis='روز حقوق‌پذیر: '.$paid;}
        $L[]=['code'=>'BASE','label'=>self::LABELS['BASE'],'kind'=>'earning','amount'=>$base,'taxable'=>$base,'insurable'=>$base,'basis'=>$basis];$gross=Money::add($gross,$base);$insurable=Money::add($insurable,$base);$taxGross=Money::add($taxGross,$base);
        // مزایای ثابت قرارداد با بخش‌های معاف (سازگار با محاسبهٔ پیشین)
        $ben=Money::add(Money::mulDiv((int)$c['benefits'],$paid,$days),(int)$a['extra_benefits']);
        $insEx=Money::add(Money::mulDiv((int)$c['benefits_insurance_exempt'],$paid,$days),(int)$a['extra_insurance_exempt']);
        $taxEx=Money::add(Money::mulDiv((int)$c['benefits_tax_exempt'],$paid,$days),(int)$a['extra_tax_exempt']);
        if($ben>0){$L[]=['code'=>'BENEFITS','label'=>self::LABELS['BENEFITS'],'kind'=>'earning','amount'=>$ben,'taxable'=>$ben-$taxEx,'insurable'=>$ben-$insEx];$gross=Money::add($gross,$ben);$insurable=Money::add($insurable,$ben-$insEx);$taxGross=Money::add($taxGross,$ben-$taxEx);}
        // اقلام قرارداد با نسخهٔ مؤثر ماه
        foreach((array)json_decode((string)($c['items_json']??''),true) as $code=>$amount){
            $it=$items[$code]??null;if(!$it)continue;$amount=(int)$amount;
            $v=$it['calc']==='fixed'?$amount:Money::mulDiv($it['calc']==='per_child'?$amount*(int)($e['dependents']??0):$amount,$paid,$days);
            if((int)$it['ceiling']>0)$v=min($v,(int)$it['ceiling']);if($v<=0)continue;
            if($it['kind']==='deduction'){$deduct=Money::add($deduct,$v);$L[]=['code'=>$code,'label'=>$it['name'],'kind'=>'deduction','amount'=>$v,'item_version'=>(int)$it['id']];continue;}
            $L[]=['code'=>$code,'label'=>$it['name'],'kind'=>'earning','amount'=>$v,'taxable'=>(int)$it['taxable']?$v:0,'insurable'=>(int)$it['insurable']?$v:0,'item_version'=>(int)$it['id']];
            $gross=Money::add($gross,$v);if((int)$it['insurable'])$insurable=Money::add($insurable,$v);if((int)$it['taxable'])$taxGross=Money::add($taxGross,$v);
        }
        // اضافه‌کاری
        $min=(int)$a['overtime_minutes'];$ot=0;
        if($min>0&&$type==='hourly')$ot=Money::mulDiv((int)$c['hourly_rate'],$min*(int)$p['overtime_bp'],60*10000);
        elseif($min>0&&$type!=='project'){$monthly=$type==='part'&&(int)$c['weekly_minutes']?intdiv((int)$c['weekly_minutes']*52,12):(int)$p['monthly_minutes'];$ot=Money::mulDiv(Money::add((int)$c['base_salary'],(int)$c['overtime_base_extra']),$min*(int)$p['overtime_bp'],$monthly*10000);}
        if($ot>0){$L[]=['code'=>'OVERTIME','label'=>self::LABELS['OVERTIME'],'kind'=>'earning','amount'=>$ot,'taxable'=>$ot,'insurable'=>$ot,'basis'=>'دقیقه: '.$min];$gross=Money::add($gross,$ot);$insurable=Money::add($insurable,$ot);$taxGross=Money::add($taxGross,$ot);}
        $adjE=(int)($adj['earning']??0);$adjD=(int)($adj['deduction']??0);
        if($adjE>0){$L[]=['code'=>'ADJ','label'=>self::LABELS['ADJ'],'kind'=>'earning','amount'=>$adjE,'taxable'=>$adjE,'insurable'=>$adjE,'reason'=>$adj['reason']??''];$gross=Money::add($gross,$adjE);$insurable=Money::add($insurable,$adjE);$taxGross=Money::add($taxGross,$adjE);}
        if((int)$a['deductions']>0){$deduct=Money::add($deduct,(int)$a['deductions']);$L[]=['code'=>'ATT_DED','label'=>self::LABELS['ATT_DED'],'kind'=>'deduction','amount'=>(int)$a['deductions']];}
        if($adjD>0){$deduct=Money::add($deduct,$adjD);$L[]=['code'=>'ADJ_DED','label'=>self::LABELS['ADJ_DED'],'kind'=>'deduction','amount'=>$adjD,'reason'=>$adj['reason']??''];}
        // بیمه و مالیات
        $ceiling=(int)$p['insurance_ceiling'];if($ceiling&&in_array($type,['full','part'],true))$ceiling=Money::mulDiv($ceiling,$paid,30);
        $insured=max(0,$insurable);if($ceiling)$insured=min($insured,$ceiling);
        $employee=Money::percentOf($insured,(int)$p['employee_bp']);$employer=Money::percentOf($insured,(int)$p['employer_bp']);
        $insDed=Money::mulDiv($employee,(int)($p['insurance_tax_numerator']??1),(int)($p['insurance_tax_denominator']??1));
        $taxable=max(0,$taxGross-$insDed-(int)$p['tax_exemption']);$computed=0;$lower=0;
        foreach($p['brackets'] as $b){$upper=$b['upper']??$taxable;$part=max(0,min($taxable,$upper)-$lower);$computed=Money::add($computed,Money::percentOf($part,(int)$b['rate_bp']));$lower=$upper;if($lower>=$taxable)break;}
        $declared=isset($adj['tax_override'])&&$adj['tax_override']!==null;$tax=$declared?(int)$adj['tax_override']:$computed;
        $net=$gross-$employee-$tax-$deduct;if($gross<1||$net<0)throw new ValidationException(['net'=>'ناخالص صفر یا خالص منفی است.']);
        return ['gross'=>$gross,'insurance_employee'=>$employee,'insurance_employer'=>$employer,'tax'=>$tax,'deductions'=>$deduct,'net'=>$net,
            'calc'=>['lines'=>$L,'type'=>$type,'paid_days'=>$paid,'days'=>$days,'insurable'=>$insurable,'insured'=>$insured,'ceiling'=>$ceiling,'taxable_gross'=>$taxGross,'insurance_tax_deduction'=>$insDed,'taxable'=>$taxable,'computed_tax'=>$computed,'tax_source'=>$declared?'declared':'brackets','tariff_version'=>(int)($p['tariff_version']??0)]];
    }

    private static function fiscal($cid,$start,$end){$fy=FiscalService::assertOpen($cid,$end);if($start<$fy['start_date'])throw new ValidationException(['period_key'=>'ماه باید داخل یک سال مالی باشد.']);return $fy;}
    private static function latestAdjustments($cid,$run){$out=[];foreach(Db::rows('SELECT * FROM '.Db::t('payroll_run_adjustments').' WHERE company_id=%d AND run_id=%d ORDER BY id',$cid,$run) as $r)$out[(int)$r['employee_id']]=$r;return $out;}
    /** محاسبهٔ همهٔ کارکنان یک نسخه؛ کسی که قرارداد یا کارکرد ندارد با «خطا» و دلیل می‌آید و در سند وارد نمی‌شود. */
    private static function compute($cid,array $run,$version){
        [$key,$start,$end,$days]=PayrollService::period($run['period_key']);$p=PayItemService::params($cid,(int)$run['fiscal_year_id'],$end);
        if(!$p)throw new ValidationException(['params'=>'پارامتر یا تعرفهٔ حقوق این سال تعریف نشده است.']);
        $items=PayItemService::effective($cid,$end);$adj=self::latestAdjustments($cid,(int)$run['id']);$ok=0;
        $employees=Db::rows('SELECT e.* FROM '.Db::t('employees').' e WHERE e.company_id=%d AND e.is_active=1 AND EXISTS (SELECT 1 FROM '.Db::t('contracts').' c WHERE c.company_id=e.company_id AND c.employee_id=e.id AND c.start_date<=%s AND c.end_date>=%s) ORDER BY e.id',$cid,$end,$start);
        foreach($employees as $e){
            $line=['run_id'=>(int)$run['id'],'version'=>(int)$version,'employee_id'=>(int)$e['id'],'status'=>'error'];
            try{
                if(Db::val('SELECT id FROM '.Db::t('payslips')." WHERE company_id=%d AND employee_id=%d AND period_key=%s AND status='issued' AND run_id IS NULL",$cid,$e['id'],$key))throw new ValidationException(['payroll'=>'برای این ماه فیش جداگانه صادر شده است.']);
                $cs=Db::rows('SELECT * FROM '.Db::t('contracts').' WHERE company_id=%d AND employee_id=%d AND start_date<=%s AND end_date>=%s',$cid,$e['id'],$end,$start);
                if(count($cs)>1)throw new ValidationException(['payroll'=>'دو نسخهٔ قرارداد در یک ماه؛ نسخهٔ تازه را از ابتدای ماه بعد شروع کنید.']);
                $a=Db::row('SELECT * FROM '.Db::t('attendance').' WHERE company_id=%d AND employee_id=%d AND period_key=%s',$cid,$e['id'],$key);if(!$a)throw new ValidationException(['payroll'=>'کارکرد این ماه ثبت نشده است.']);
                $line+=['contract_id'=>(int)$cs[0]['id'],'attendance_id'=>(int)$a['id']];
                $r=self::calculate($e,$cs[0],$a,$p,$days,$items,isset($adj[(int)$e['id']])?$adj[(int)$e['id']]:null);
                $line=array_merge($line,array_intersect_key($r,array_flip(['gross','insurance_employee','insurance_employer','tax','deductions','net'])),['status'=>'ok','calc'=>wp_json_encode($r['calc'],JSON_UNESCAPED_UNICODE)]);$ok++;
            }catch(ValidationException $x){$line['error']=mb_substr(implode(' ',$x->errors),0,500);}
            Business::save('payroll_run_lines',$cid,0,$line);
        }
        return $ok;
    }
    private static function hash($cid,$run,$version){$s=[];foreach(Db::rows('SELECT employee_id,status,gross,insurance_employee,insurance_employer,tax,deductions,net,calc FROM '.Db::t('payroll_run_lines').' WHERE company_id=%d AND run_id=%d AND version=%d ORDER BY employee_id',$cid,$run,$version) as $l)$s[]=$l;return hash('sha256',wp_json_encode($s));}

    public static function create($cid,$period){
        return Access::write($cid,'payroll.edit',function()use($cid,$period){
            [$key,$start,$end]=PayrollService::period($period);$fy=self::fiscal($cid,$start,$end);
            if($old=Db::val('SELECT id FROM '.Db::t('payroll_runs').' WHERE company_id=%d AND period_key=%s AND active_slot=1',$cid,$key))throw new ConflictException('برای این ماه لیست حقوق فعال (شمارهٔ '.(int)$old.') وجود دارد.');
            $id=Business::save('payroll_runs',$cid,0,['fiscal_year_id'=>(int)$fy['id'],'period_key'=>$key,'status'=>'calculated','version'=>1,'active_slot'=>1,'created_by'=>Ctx::uid()]);
            $ok=self::compute($cid,Business::row('payroll_runs',$cid,$id),1);if(!$ok&&!Db::val('SELECT id FROM '.Db::t('payroll_run_lines').' WHERE company_id=%d AND run_id=%d LIMIT 1',$cid,$id))throw new ValidationException(['payroll'=>'هیچ کارمندی با قرارداد فعال در این ماه نیست.']);
            Audit::log('payroll.run.create','payroll_run',$id,null,['period'=>$key,'ok'=>$ok],$cid);return $id;
        });
    }
    /** محاسبهٔ دوباره: پیش از قفل همان نسخه بازسازی می‌شود؛ پس از قفل همیشه نسخهٔ تازه ساخته می‌شود. */
    public static function recalculate($cid,$id){
        return Access::write($cid,'payroll.edit',function()use($cid,$id){
            $r=Business::row('payroll_runs',$cid,$id,true);
            if($r['status']==='calculated'){Db::exec('DELETE FROM '.Db::t('payroll_run_lines').' WHERE company_id=%d AND run_id=%d AND version=%d',$cid,$id,$r['version']);$v=(int)$r['version'];}
            elseif(in_array($r['status'],['sent','adjusting'],true)){$v=(int)$r['version']+1;Db::update('payroll_runs',['version'=>$v,'status'=>'adjusting','updated_at'=>gmdate('Y-m-d H:i:s')],['company_id'=>$cid,'id'=>$id]);}
            else throw new ConflictException('لیست قطعی یا برگشت‌خورده دوباره محاسبه نمی‌شود.');
            $ok=self::compute($cid,Business::row('payroll_runs',$cid,$id),$v);Db::update('payroll_runs',['pending'=>0],['company_id'=>$cid,'id'=>$id]);
            Audit::log('payroll.run.recalculate','payroll_run',$id,['version'=>(int)$r['version']],['version'=>$v,'ok'=>$ok],$cid);return $v;
        });
    }
    /** پایان مرحلهٔ ۱: نسخهٔ جاری قفل می‌شود؛ خروجی مالیات و بیمه از همین نسخه گرفته می‌شود. */
    public static function send($cid,$id){
        return Access::write($cid,'payroll.approve',function()use($cid,$id){
            $r=Business::row('payroll_runs',$cid,$id,true);if($r['status']!=='calculated')throw new ConflictException('فقط لیست در مرحلهٔ محاسبهٔ اولیه قفل می‌شود.');
            if(!Db::val('SELECT id FROM '.Db::t('payroll_run_lines')." WHERE company_id=%d AND run_id=%d AND version=%d AND status='ok' LIMIT 1",$cid,$id,$r['version']))throw new ValidationException(['payroll'=>'هیچ ردیف سالمی برای ارسال نیست.']);
            $hash=self::hash($cid,$id,(int)$r['version']);Db::update('payroll_runs',['status'=>'sent','sent_version'=>(int)$r['version'],'sent_hash'=>$hash,'sent_by'=>Ctx::uid(),'updated_at'=>gmdate('Y-m-d H:i:s')],['company_id'=>$cid,'id'=>$id]);
            Audit::log('payroll.run.send','payroll_run',$id,null,['version'=>(int)$r['version'],'hash'=>$hash],$cid);return true;
        });
    }
    /** ورود دستی پاسخ مالیات یا تعدیل برای یک نفر (با دلیل)؛ تا محاسبهٔ دوباره، تأیید نهایی ممکن نیست. */
    public static function adjust($cid,$id,$employee,array $in){
        return Access::write($cid,'payroll.approve',function()use($cid,$id,$employee,$in){
            $r=Business::row('payroll_runs',$cid,$id,true);if(!in_array($r['status'],['sent','adjusting'],true))throw new ConflictException('تعدیل فقط پس از قفل مرحلهٔ ۱ و پیش از تأیید نهایی است.');
            if(!Db::val('SELECT id FROM '.Db::t('payroll_run_lines').' WHERE company_id=%d AND run_id=%d AND employee_id=%d LIMIT 1',$cid,$id,$employee))throw new NotFoundException();
            $reason=Business::reason($in['reason']??'');$tax=$in['tax_override']??null;if($tax!==null&&$tax!=='')Business::amount($tax);else $tax=null;
            $data=['run_id'=>(int)$id,'employee_id'=>(int)$employee,'tax_override'=>$tax,'earning'=>Business::amount($in['earning']??0),'deduction'=>Business::amount($in['deduction']??0),'reason'=>$reason,'created_by'=>Ctx::uid()];
            $aid=Business::save('payroll_run_adjustments',$cid,0,$data);Db::update('payroll_runs',['status'=>'adjusting','pending'=>1,'updated_at'=>gmdate('Y-m-d H:i:s')],['company_id'=>$cid,'id'=>$id]);
            Audit::log('payroll.run.adjust','payroll_run',$id,null,$data,$cid);return $aid;
        });
    }
    /** مقایسهٔ دو نسخه به تفکیک نفر. */
    public static function compare($cid,$id,$v1,$v2){
        Access::need($cid,'payroll.view');$out=[];
        foreach([1=>$v1,2=>$v2] as $slot=>$v)foreach(self::lines($cid,$id,$v) as $l){$out[(int)$l['employee_id']]['name']=$l['employee_name'];$out[(int)$l['employee_id']]['v'.$slot]=$l;}
        foreach($out as &$o)foreach(['gross','tax','net'] as $k)$o['diff_'.$k]=(int)($o['v2'][$k]??0)-(int)($o['v1'][$k]??0);unset($o);return $out;
    }
    private static function account($cid,$id,array $classes,$detail=false){$a=Business::row('accounts',$cid,Business::id($id));if(!$a['is_active']||(int)$a['level']!==3||!in_array($a['stmt_class'],$classes,true)||($detail?($a['detail_rule']==='none'||$a['detail_kind']!==''):$a['detail_rule']==='required'))throw new ValidationException(['accounts'=>'حساب‌های حقوق را در پارامتر سالانه درست تعیین کنید.']);return (int)$a['id'];}
    /** تأیید نهایی: فیش هر نفر (با شمارهٔ ترتیبی) و یک سند حقوق با منبع یکتای همین لیست. */
    public static function finalize($cid,$id,$date){
        return Access::write($cid,'payroll.approve',function()use($cid,$id,$date){
            $r=Business::row('payroll_runs',$cid,$id,true);if(!in_array($r['status'],['sent','adjusting'],true))throw new ConflictException('فقط لیست قفل‌شدهٔ مرحلهٔ ۱ یا ۲ تأیید نهایی می‌شود.');
            if((int)$r['pending'])throw new ConflictException('پس از آخرین تعدیل، لیست را دوباره محاسبه کنید.');
            [$key,$start,$end]=PayrollService::period($r['period_key']);$date=Business::date($date);$post=FiscalService::assertOpen($cid,$date);if($date<$end||(int)$post['id']!==(int)$r['fiscal_year_id'])throw new ValidationException(['doc_date'=>'تاریخ سند پس از پایان ماه و در همان سال مالی باشد.']);
            $p=PayItemService::params($cid,(int)$r['fiscal_year_id'],$end);$exp=self::account($cid,$p['expense_account_id']??0,['opex']);$sal=self::account($cid,$p['salary_account_id']??0,['liability_current'],true);$acc=[];foreach(['insurance_account_id','tax_account_id','deduction_account_id'] as $k)$acc[$k]=self::account($cid,$p[$k]??0,['liability_current']);
            $lines=Db::rows('SELECT l.*,e.detail_id,e.name FROM '.Db::t('payroll_run_lines').' l JOIN '.Db::t('employees')." e ON e.company_id=l.company_id AND e.id=l.employee_id WHERE l.company_id=%d AND l.run_id=%d AND l.version=%d AND l.status='ok' ORDER BY e.id",$cid,$id,$r['version']);
            if(!$lines)throw new ValidationException(['payroll'=>'ردیف سالمی برای تأیید نیست.']);
            $sum=['gross'=>0,'insurance_employee'=>0,'insurance_employer'=>0,'tax'=>0,'deductions'=>0];$vl=[];$slips=[];
            foreach($lines as $l){foreach($sum as $k=>$v)$sum[$k]=Money::add($v,(int)$l[$k]);$vl[]=['account_id'=>$sal,'detail_id'=>(int)$l['detail_id'],'credit'=>(int)$l['net'],'description'=>'خالص حقوق '.$l['name']];
                $calc=json_decode($l['calc'],true);
                $sid=Business::save('payslips',$cid,0,['employee_id'=>(int)$l['employee_id'],'contract_id'=>(int)$l['contract_id'],'attendance_id'=>(int)$l['attendance_id'],'fiscal_year_id'=>(int)$r['fiscal_year_id'],'period_key'=>$key,'number'=>Sequence::next($cid,'payroll',(int)$r['fiscal_year_id']),'doc_date'=>$date,'gross'=>(int)$l['gross'],'insurance_employee'=>(int)$l['insurance_employee'],'insurance_employer'=>(int)$l['insurance_employer'],'tax'=>(int)$l['tax'],'deductions'=>(int)$l['deductions'],'net'=>(int)$l['net'],'run_id'=>(int)$id,'snapshot'=>wp_json_encode(['company'=>array_intersect_key(Ctx::company(),array_flip(['name','legal_name','economic_code'])),'employee'=>['id'=>(int)$l['employee_id'],'name'=>$l['name']],'contract'=>Db::row('SELECT * FROM '.Db::t('contracts').' WHERE company_id=%d AND id=%d',$cid,$l['contract_id']),'attendance'=>Db::row('SELECT * FROM '.Db::t('attendance').' WHERE company_id=%d AND id=%d',$cid,$l['attendance_id']),'run'=>['id'=>(int)$id,'version'=>(int)$r['version']],'calculation'=>$calc],JSON_UNESCAPED_UNICODE)]);
                foreach(['gross','insurance_employee','insurance_employer','tax','deductions','net'] as $k)Business::save('payslip_items',$cid,0,['payslip_id'=>$sid,'kind'=>$k,'amount'=>(int)$l[$k]]);
                foreach(['taxable','insured'] as $k)if(isset($calc[$k]))Business::save('payslip_items',$cid,0,['payslip_id'=>$sid,'kind'=>$k,'amount'=>(int)$calc[$k]]);$slips[]=$sid;}
            array_unshift($vl,['account_id'=>$exp,'debit'=>Money::add($sum['gross'],$sum['insurance_employer'])]);
            foreach([['insurance_account_id',Money::add($sum['insurance_employee'],$sum['insurance_employer'])],['tax_account_id',$sum['tax']],['deduction_account_id',$sum['deductions']]] as [$k,$amount])if($amount)$vl[]=['account_id'=>$acc[$k],'credit'=>$amount];
            $vid=VoucherService::postSystem(['voucher_date'=>$date,'description'=>'لیست حقوق '.$key.' (نسخهٔ '.$r['version'].')','source_type'=>'payroll_run','source_id'=>(int)$id],$vl);
            Db::update('payroll_runs',['status'=>'final','doc_date'=>$date,'voucher_id'=>$vid,'approved_by'=>Ctx::uid(),'updated_at'=>gmdate('Y-m-d H:i:s')],['company_id'=>$cid,'id'=>$id]);
            Audit::log('payroll.run.finalize','payroll_run',$id,null,['version'=>(int)$r['version'],'voucher'=>$vid,'payslips'=>$slips,'totals'=>$sum],$cid);return $vid;
        });
    }
    /** کنترل‌های پیش از فایل بانک و پرداخت: شبا، تکرار، جمع. */
    public static function bankCheck($cid,$id){
        Access::need($cid,'payroll.view');$r=Business::row('payroll_runs',$cid,$id);$rows=[];$errors=[];$seen=[];$total=0;
        foreach(Db::rows('SELECT l.employee_id,l.net,e.name,e.iban,e.code FROM '.Db::t('payroll_run_lines').' l JOIN '.Db::t('employees')." e ON e.company_id=l.company_id AND e.id=l.employee_id WHERE l.company_id=%d AND l.run_id=%d AND l.version=%d AND l.status='ok' ORDER BY e.id",$cid,$id,$r['version']) as $l){
            if($l['iban']===''||!TreasuryService::validIban($l['iban']))$errors[]='شبای «'.$l['name'].'» ثبت نشده یا معتبر نیست.';elseif(isset($seen[$l['iban']]))$errors[]='شبای «'.$l['name'].'» با «'.$seen[$l['iban']].'» تکراری است.';else $seen[$l['iban']]=$l['name'];
            $total=Money::add($total,(int)$l['net']);$rows[]=['iban'=>$l['iban'],'amount'=>(int)$l['net'],'name'=>$l['name'],'code'=>$l['code']];
        }
        $sum=0;foreach($rows as $x)$sum=Money::add($sum,$x['amount']);if($sum!==$total)$errors[]='جمع فایل با جمع خالص لیست برابر نیست.';
        return ['rows'=>$rows,'total'=>$total,'errors'=>$errors];
    }
    /** پرداخت: سند جدا (بدهکار حقوق پرداختنی هر نفر، بستانکار بانک)؛ فقط اگر کنترل‌های فایل بانک بی‌خطا باشد. */
    public static function pay($cid,$id,$treasuryAccount,$date){
        return Access::write($cid,'payroll.pay',function()use($cid,$id,$treasuryAccount,$date){
            $r=Business::row('payroll_runs',$cid,$id,true);if($r['status']!=='final')throw new ConflictException('فقط لیست تأییدشدهٔ پرداخت‌نشده پرداخت می‌شود.');
            $chk=self::bankCheck($cid,$id);if($chk['errors'])throw new ValidationException(['bank'=>implode(' ',$chk['errors'])]);
            $t=Business::row('treasury_accounts',$cid,Business::id($treasuryAccount));if($t['kind']!=='bank'||!(int)$t['is_active'])throw new ValidationException(['treasury_account_id'=>'حساب بانکی فعال انتخاب کنید.']);
            $date=Business::date($date);if($date<$r['doc_date'])throw new ValidationException(['date'=>'تاریخ پرداخت پیش از سند حقوق است.']);FiscalService::assertOpen($cid,$date);
            [, , $end]=PayrollService::period($r['period_key']);$p=PayItemService::params($cid,(int)$r['fiscal_year_id'],$end);$sal=self::account($cid,$p['salary_account_id']??0,['liability_current'],true);
            $vl=[];foreach(Db::rows('SELECT l.net,e.detail_id,e.name FROM '.Db::t('payroll_run_lines').' l JOIN '.Db::t('employees')." e ON e.company_id=l.company_id AND e.id=l.employee_id WHERE l.company_id=%d AND l.run_id=%d AND l.version=%d AND l.status='ok'",$cid,$id,$r['version']) as $l)$vl[]=['account_id'=>$sal,'detail_id'=>(int)$l['detail_id'],'debit'=>(int)$l['net'],'description'=>'پرداخت حقوق '.$l['name']];
            $vl[]=['account_id'=>(int)$t['account_id'],'detail_id'=>(int)$t['detail_id'],'credit'=>$chk['total']];
            $vid=VoucherService::postSystem(['voucher_date'=>$date,'description'=>'پرداخت لیست حقوق '.$r['period_key'],'source_type'=>'payroll_pay','source_id'=>(int)$id],$vl);
            Db::update('payroll_runs',['status'=>'paid','pay_voucher_id'=>$vid,'pay_account_id'=>(int)$t['id'],'paid_by'=>Ctx::uid(),'updated_at'=>gmdate('Y-m-d H:i:s')],['company_id'=>$cid,'id'=>$id]);
            Audit::log('payroll.run.pay','payroll_run',$id,null,['voucher'=>$vid,'total'=>$chk['total'],'bank'=>(int)$t['id']],$cid);return $vid;
        });
    }
    /** برگشت لیست تأییدشده (اصلاحیه): سند باطل و فیش‌ها باطل می‌شوند؛ سابقه می‌ماند و لیست تازهٔ همان ماه ممکن می‌شود. */
    public static function reverse($cid,$id,$reason){
        return Access::write($cid,'payroll.delete',function()use($cid,$id,$reason){
            $r=Business::row('payroll_runs',$cid,$id,true);$reason=Business::reason($reason);
            if($r['status']==='paid')throw new ConflictException('لیست پرداخت‌شده برنمی‌گردد؛ ابتدا سند پرداخت را با مجوز باطل کنید.');
            if(in_array($r['status'],['final'],true)){VoucherService::voidSystem((int)$r['voucher_id'],$reason);Db::exec('UPDATE '.Db::t('payslips')." SET status='void',active_slot=NULL,updated_at=%s WHERE company_id=%d AND run_id=%d AND status='issued'",gmdate('Y-m-d H:i:s'),$cid,$id);}
            elseif($r['status']==='reversed')throw new ConflictException('این لیست قبلاً برگشت خورده است.');
            Db::update('payroll_runs',['status'=>'reversed','active_slot'=>null,'reverse_reason'=>$reason,'updated_at'=>gmdate('Y-m-d H:i:s')],['company_id'=>$cid,'id'=>$id]);
            Audit::log('payroll.run.reverse','payroll_run',$id,['status'=>$r['status']],['reason'=>$reason],$cid);return true;
        });
    }
    /** ردیف‌های فایل‌های آزمایشی بیمه و مالیات از یک نسخه (پیش‌فرض: نسخهٔ قفل‌شدهٔ مرحلهٔ ۱). شامل کد ملی؛ مجوز اطلاعات حساس لازم است. */
    public static function officialRows($cid,$id,$kind,$version=0){
        Access::need($cid,'payroll.sensitive');Access::need($cid,'payroll.export');$r=Business::row('payroll_runs',$cid,$id);$version=$version?:((int)$r['sent_version']?:(int)$r['version']);
        $errors=[];$rows=[];$workshop=(string)Settings::get('insurance_workshop_code',$cid);if($kind==='insurance'&&!preg_match('/^[0-9]{5,15}$/D',$workshop))$errors[]='کد کارگاه بیمه در «اقلام و تعرفه‌های حقوق» تعیین نشده است.';
        foreach(Db::rows('SELECT l.*,e.name,e.code,e.national_id_enc,e.insurance_no FROM '.Db::t('payroll_run_lines').' l JOIN '.Db::t('employees')." e ON e.company_id=l.company_id AND e.id=l.employee_id WHERE l.company_id=%d AND l.run_id=%d AND l.version=%d AND l.status='ok' ORDER BY e.id",$cid,$id,$version) as $l){
            $c=json_decode($l['calc'],true);$nid=Crypto::decrypt($l['national_id_enc']);if($nid==='')$errors[]='کد ملی «'.$l['name'].'» ثبت نشده است.';
            if($kind==='insurance'){if($l['insurance_no']==='')$errors[]='شمارهٔ بیمهٔ «'.$l['name'].'» ثبت نشده است.';$rows[]=[$workshop,$l['insurance_no'],$nid,$l['name'],(int)$c['paid_days'],(int)$c['insured'],(int)$l['insurance_employee'],(int)$l['insurance_employer']];}
            else $rows[]=[$nid,$l['name'],(int)$l['gross'],(int)$c['taxable'],(int)$l['tax'],$c['tax_source']==='declared'?'اعلامی':'محاسبه‌شده'];
        }
        Audit::log('payroll.export.'.$kind,'payroll_run',$id,null,['version'=>$version,'rows'=>count($rows)],$cid);
        return ['version'=>$version,'rows'=>$rows,'errors'=>array_values(array_unique($errors))];
    }
}
