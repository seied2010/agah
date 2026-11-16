<?php
/** Monthly, integer-only payroll policy. All statutory rates and brackets are input. */
namespace Agah\Erp\Modules\Payroll;
use Agah\Erp\Core\{Business,Money,ValidationException};
if(!defined('ABSPATH'))exit;
final class PayrollMath {
    public static function integer($v,$min,$max){if(!is_int($v)||$v<$min||$v>$max)throw new ValidationException(['payroll'=>'عدد صحیح خارج از محدوده است.']);return $v;}
    public static function params(array $p){
        foreach(['employee_bp','employer_bp'] as $k)self::integer($p[$k]??null,0,10000);
        self::integer($p['overtime_bp']??null,0,50000);self::integer($p['monthly_minutes']??null,1,44640);
        foreach(['insurance_ceiling','tax_exemption'] as $k)Business::amount($p[$k]??null);
        if(!isset($p['brackets'])||!is_array($p['brackets'])||!count($p['brackets'])||count($p['brackets'])>20)throw new ValidationException(['brackets'=>'پلکان مالیات را تعریف کنید.']);
        if(isset($p['formula'])&&$p['formula']!=='ir_daily_v1')throw new ValidationException(['formula'=>'نسخه فرمول حقوق معتبر نیست.']);
        if(isset($p['insurance_tax_numerator'])){self::integer($p['insurance_tax_numerator'],0,100);self::integer($p['insurance_tax_denominator']??null,1,100);if($p['insurance_tax_numerator']>$p['insurance_tax_denominator'])throw new ValidationException(['insurance_tax_numerator'=>'سهم قابل کسر از بیمه بیش از کل بیمه است.']);}
        $last=0;foreach(array_values($p['brackets']) as $i=>$b){if(!is_array($b))throw new ValidationException(['brackets'=>'پلکان معتبر نیست.']);self::integer($b['rate_bp']??null,0,10000);$upper=$b['upper']??null;if($upper===null){if($i!==count($p['brackets'])-1)throw new ValidationException(['brackets'=>'پله نامحدود فقط آخرین پله است.']);}else{Business::amount($upper,true);if($upper<=$last)throw new ValidationException(['brackets'=>'سقف پله‌ها صعودی باشد.']);$last=$upper;}}
        if((end($p['brackets'])['upper']??null)!==null)throw new ValidationException(['brackets'=>'آخرین سقف باید null باشد.']);return $p;
    }
    public static function calculate(array $contract,array $a,array $p,$days){
        self::params($p);self::integer($days,28,31);$work=self::integer($a['work_days']??null,0,$days);$leave=self::integer($a['leave_days']??null,0,$days);if($work+$leave>$days)throw new ValidationException(['attendance'=>'کارکرد و مرخصی باحقوق از روزهای ماه بیشتر است.']);
        $minutes=self::integer($a['overtime_minutes']??null,0,20000);foreach(['base_salary','benefits'] as $k)Business::amount($contract[$k]??null);foreach(['extra_benefits','deductions'] as $k)Business::amount($a[$k]??null);
        $modern=($p['formula']??'legacy')==='ir_daily_v1';$paid=$work+$leave;
        // Contract base is a 30-day wage. Fixed monthly benefits use actual month length.
        $base=Money::mulDiv($contract['base_salary'],$paid,$modern?30:$days);
        $benefits=Money::add(Money::mulDiv($contract['benefits'],$paid,$days),$a['extra_benefits']);
        foreach(['benefits_insurance_exempt','benefits_tax_exempt','overtime_base_extra'] as $k){Business::amount($contract[$k]??0);if(($contract[$k]??0)>$contract['benefits'])throw new ValidationException([$k=>'جزء مزایا بیش از کل مزایای ثابت است.']);}
        foreach(['extra_insurance_exempt','extra_tax_exempt'] as $k){Business::amount($a[$k]??0);if(($a[$k]??0)>$a['extra_benefits'])throw new ValidationException([$k=>'بخش معاف بیش از مزایای متغیر است.']);}
        $overtimeBase=Money::add($contract['base_salary'],$modern?($contract['overtime_base_extra']??0):0);
        $overtime=Money::mulDiv($overtimeBase,$minutes*$p['overtime_bp'],$p['monthly_minutes']*10000);
        $gross=Money::add(Money::add($base,$benefits),$overtime);
        $insuranceExempt=$modern?Money::add(Money::mulDiv($contract['benefits_insurance_exempt']??0,$paid,$days),$a['extra_insurance_exempt']??0):0;
        $taxExempt=$modern?Money::add(Money::mulDiv($contract['benefits_tax_exempt']??0,$paid,$days),$a['extra_tax_exempt']??0):0;
        $insured=max(0,$gross-$insuranceExempt);$ceiling=$p['insurance_ceiling'];if($modern&&$ceiling)$ceiling=Money::mulDiv($ceiling,$paid,30);if($ceiling)$insured=min($insured,$ceiling);
        $employee=Money::percentOf($insured,$p['employee_bp']);$employer=Money::percentOf($insured,$p['employer_bp']);
        $insuranceDeduction=Money::mulDiv($employee,$p['insurance_tax_numerator']??1,$p['insurance_tax_denominator']??1);
        $taxable=max(0,$gross-$taxExempt-$insuranceDeduction-$p['tax_exemption']);$tax=0;$lower=0;
        foreach($p['brackets'] as $b){$upper=$b['upper']??$taxable;$part=max(0,min($taxable,$upper)-$lower);$tax=Money::add($tax,Money::percentOf($part,$b['rate_bp']));$lower=$upper;if($lower>=$taxable)break;}
        $deductions=$a['deductions'];$net=$gross-$employee-$tax-$deductions;if($net<0||$gross<1)throw new ValidationException(['net'=>'خالص حقوق منفی یا ناخالص صفر است.']);
        return ['base'=>$base,'benefits'=>$benefits,'overtime'=>$overtime,'gross'=>$gross,'insurance_employee'=>$employee,'insurance_employer'=>$employer,'tax'=>$tax,'deductions'=>$deductions,'net'=>$net,'taxable'=>$taxable,'insured'=>$insured];
    }
}
