<?php
/**
 * سیزده نسبت مالی مصوب (R01 تا R13) از اسناد تأییدشده و قطعی.
 *
 * - هر عدد با منبعش برگردانده می‌شود تا حسابدار بتواند آن را از دفاتر تطبیق دهد.
 * - میانگین هر قلم مانده‌ای = (ماندهٔ ابتدای دوره + ماندهٔ پایان دوره) ÷ ۲؛ روزها = تعداد واقعی روزهای دوره.
 * - اگر داده یا مخرج کافی نباشد، نسبت «غیرقابل محاسبه» با دلیل است؛ هرگز عدد ساختگی یا صفر جایگزین نمی‌شود.
 * - نگاشت پیش‌فرض از طبقهٔ حساب و کلید سیستمی است؛ حسابدار می‌تواند هر حساب جاری را صریحاً برچسب بزند.
 * - رابط کاربری فرمولی حساب نمی‌کند؛ همهٔ محاسبه این‌جاست (ریاضی دقیق با bcmath، بدون float).
 */
namespace Agah\Erp\Modules\Accounting;
use Agah\Erp\Core\{Access,Audit,Business,Ctx,Db,Money,Settings,ValidationException,ConflictException};
if(!defined('ABSPATH'))exit;
final class RatioService {
    /** برچسب‌های قابل انتخاب برای حساب‌ها؛ خالی یعنی نگاشت پیش‌فرض. */
    const TAGS=['cash_equivalent'=>'وجه نقد و معادل نقد','short_investment'=>'سرمایه‌گذاری کوتاه‌مدت نقدشونده','trade_receivable'=>'دریافتنی تجاری خالص','inventory'=>'موجودی کالا','interest_expense'=>'هزینهٔ بهره','exclude'=>'خارج از اقلام نسبت‌ها'];
    const CREDIT_MODES=['none'=>'تفکیک نشده (نسبت‌های مطالبات محاسبه نمی‌شوند)','all_sales'=>'همهٔ فروش خالص نسیه است'];
    const DEFS=[
        'R01'=>['نسبت جاری','دارایی جاری ÷ بدهی جاری','times'],
        'R02'=>['نسبت آنی','(وجه نقد + معادل نقد + سرمایه‌گذاری کوتاه‌مدت + دریافتنی تجاری خالص) ÷ بدهی جاری','times'],
        'R03'=>['سرمایه در گردش خالص','دارایی جاری − بدهی جاری','money'],
        'R04'=>['گردش مطالبات تجاری','فروش نسیهٔ خالص ÷ میانگین دریافتنی تجاری','turns'],
        'R05'=>['دوره وصول مطالبات','روزهای دوره ÷ گردش مطالبات','days'],
        'R06'=>['گردش موجودی کالا','بهای تمام‌شدهٔ کالای فروش‌رفته ÷ میانگین موجودی کالا','turns'],
        'R07'=>['دوره گردش موجودی','روزهای دوره ÷ گردش موجودی','days'],
        'R08'=>['حاشیه سود خالص','سود خالص ÷ فروش خالص × ۱۰۰','percent'],
        'R09'=>['گردش دارایی‌ها','فروش خالص ÷ میانگین کل دارایی‌ها','turns'],
        'R10'=>['بازده دارایی‌ها (ROA)','سود خالص ÷ میانگین کل دارایی‌ها × ۱۰۰','percent'],
        'R11'=>['بازده حقوق صاحبان سرمایه (ROE)','سود خالص ÷ میانگین حقوق صاحبان سرمایه × ۱۰۰','percent'],
        'R12'=>['نسبت بدهی به دارایی','کل بدهی‌ها ÷ کل دارایی‌ها × ۱۰۰','percent'],
        'R13'=>['دفعات پوشش هزینهٔ بهره','سود قبل از بهره و مالیات ÷ هزینهٔ بهرهٔ دوره','times'],
    ];
    const DESCRIPTIONS=[
        'R01'=>'توان شرکت در پرداخت بدهی‌های کوتاه‌مدت با کل دارایی‌های جاری را نشان می‌دهد.',
        'R02'=>'توان پرداخت فوری بدهی‌های جاری را بدون اتکا به فروش موجودی کالا می‌سنجد.',
        'R03'=>'مازاد دارایی جاری پس از کسر بدهی جاری و حاشیهٔ نقدینگی عملیات است.',
        'R04'=>'نشان می‌دهد مطالبات تجاری در طول دوره چند بار به وجه نقد تبدیل شده‌اند.',
        'R05'=>'میانگین تعداد روز لازم برای وصول مطالبات از مشتریان را نشان می‌دهد.',
        'R06'=>'نشان می‌دهد موجودی کالا در دوره چند بار فروخته و جایگزین شده است.',
        'R07'=>'میانگین مدت نگهداری کالا تا فروش را بر حسب روز نشان می‌دهد.',
        'R08'=>'سهم سود خالص از هر صد واحد فروش خالص را نشان می‌دهد.',
        'R09'=>'کارایی استفاده از دارایی‌ها برای ایجاد فروش را اندازه‌گیری می‌کند.',
        'R10'=>'میزان سودآوری شرکت نسبت به متوسط دارایی‌های به‌کارگرفته‌شده را نشان می‌دهد.',
        'R11'=>'بازده ایجادشده برای صاحبان سرمایه نسبت به متوسط حقوق آنان را نشان می‌دهد.',
        'R12'=>'سهم دارایی‌های تأمین‌شده از محل بدهی و میزان اتکای شرکت به بدهی را نشان می‌دهد.',
        'R13'=>'توان سود عملیاتی برای پوشش هزینهٔ بهرهٔ دوره را نشان می‌دهد.',
    ];
    const UNITS=['times'=>'برابر','turns'=>'بار','days'=>'روز','percent'=>'درصد','money'=>''];

    private static function scope(){return "v.status IN ('approved','final') AND v.source_type NOT IN ('year_profit','year_result','year_close')";}

    /** مانده‌های ترازنامه‌ای در یک نقطه از سال مالی. $before یعنی پیش از روز $date (به‌جز سند افتتاحیهٔ همان روز). */
    private static function point($cid,array $fy,$date,$before){
        $cond=$before?"(v.voucher_date<%s OR (v.type='opening' AND v.voucher_date=%s))":'v.voucher_date<=%s';$args=$before?[$date,$date]:[$date];
        $rows=Db::rows('SELECT a.stmt_class,a.sys_key,a.ratio_tag,COALESCE(SUM(CAST(l.debit AS SIGNED)-CAST(l.credit AS SIGNED)),0) AS b FROM '.Db::t('voucher_lines').' l JOIN '.Db::t('vouchers').' v ON v.company_id=l.company_id AND v.id=l.voucher_id JOIN '.Db::t('accounts').' a ON a.company_id=l.company_id AND a.id=l.account_id WHERE v.company_id=%d AND v.fiscal_year_id=%d AND '.self::scope().' AND '.$cond.' GROUP BY a.stmt_class,a.sys_key,a.ratio_tag',...array_merge([$cid,(int)$fy['id']],$args));
        $p=array_fill_keys(['current_assets','current_liabilities','cash','short_investment','receivable','inventory','total_assets','total_liabilities','equity'],0);
        foreach($rows as $r){
            $b=FinancialService::integer($r['b']);$class=$r['stmt_class'];$tag=(string)$r['ratio_tag'];$sys=(string)$r['sys_key'];
            if(in_array($class,['asset_current','asset_noncurrent','contra_asset'],true))$p['total_assets']=Money::add($p['total_assets'],$b);
            if(in_array($class,['liability_current','liability_noncurrent'],true))$p['total_liabilities']=Money::add($p['total_liabilities'],-$b);
            if($class==='equity')$p['equity']=Money::add($p['equity'],-$b);
            if($class==='asset_current')$p['current_assets']=Money::add($p['current_assets'],$b);
            if($class==='liability_current')$p['current_liabilities']=Money::add($p['current_liabilities'],-$b);
            if($tag==='exclude')continue;
            $item=$tag!==''?['cash_equivalent'=>'cash','short_investment'=>'short_investment','trade_receivable'=>'receivable','inventory'=>'inventory'][$tag]??null:(in_array($sys,['cash','bank'],true)?'cash':(in_array($sys,['receivable','notes_receivable'],true)?'receivable':($sys==='inventory'?'inventory':null)));
            if($item)$p[$item]=Money::add($p[$item],$b);
        }
        // سود و زیان بسته‌نشدهٔ سال جزو حقوق صاحبان سرمایه است.
        $start=$fy['start_date'];$end=$before?date('Y-m-d',strtotime($date.' -1 day')):$date;
        if($end>=$start)$p['equity']=Money::add($p['equity'],FinancialService::profitInternal($cid,$start,$end)['net']);
        return $p;
    }
    /** هزینهٔ بهرهٔ دوره: طبقهٔ «هزینهٔ مالی» یا حساب برچسب‌خورده؛ حساب «خارج از نسبت‌ها» شمرده نمی‌شود. */
    private static function interest($cid,$from,$to){
        return FinancialService::integer(Db::val('SELECT COALESCE(SUM(CAST(l.debit AS SIGNED)-CAST(l.credit AS SIGNED)),0) FROM '.Db::t('voucher_lines').' l JOIN '.Db::t('vouchers').' v ON v.company_id=l.company_id AND v.id=l.voucher_id JOIN '.Db::t('accounts').' a ON a.company_id=l.company_id AND a.id=l.account_id WHERE v.company_id=%d AND '.self::scope()." AND v.type<>'opening' AND v.voucher_date BETWEEN %s AND %s AND ((a.stmt_class='finance_cost' AND (a.ratio_tag IS NULL OR a.ratio_tag='')) OR a.ratio_tag='interest_expense')",$cid,$from,$to));
    }

    // ---- ریاضی دقیق: مقدارها رشتهٔ دهدهی با دو رقم اعشار، گرد نیم به بالا.
    private static function div($a,$b,$mul=1){
        $q=bcdiv(bcmul((string)$a,(string)($mul*1000)),(string)$b,0); // سه رقم اضافه برای گردکردن
        $neg=$q[0]==='-';$q=ltrim($q,'-');$q=str_pad($q,4,'0',STR_PAD_LEFT);
        $r=bcdiv(bcadd($q,'5'),'10',0);$r=str_pad($r,3,'0',STR_PAD_LEFT);
        $out=substr($r,0,-2).'.'.substr($r,-2);return ($neg&&$out!=='0.00'?'-':'').$out;
    }
    private static function avg($a,$b){return bcdiv(bcadd((string)$a,(string)$b),'2',1);}
    private static function na($reason){return ['value'=>null,'reason'=>$reason];}
    private static function ok($value){return ['value'=>$value,'reason'=>''];}

    /** محاسبهٔ دوره؛ شروع و پایان باید در یک سال مالی باشند. */
    public static function compute($cid,$from,$to){
        Access::need($cid,'report.view');Business::date($from);Business::date($to);
        if($from>$to)throw new ValidationException(['to'=>'پایان دوره قبل از شروع است.']);
        $fy=FiscalService::forDate($cid,$to);if(!$fy||$from<$fy['start_date'])throw new ValidationException(['from'=>'شروع و پایان دوره باید در یک سال مالی باشند.']);
        return Db::tx(function()use($cid,$from,$to,$fy){
            $open=self::point($cid,$fy,$from,true);$close=self::point($cid,$fy,$to,false);
            $pl=FinancialService::profitInternal($cid,$from,$to);$interest=self::interest($cid,$from,$to);
            $days=(int)((strtotime($to)-strtotime($from))/86400)+1;
            $mode=Settings::get('ratio_credit_sales',$cid);$credit=$mode==='all_sales'?$pl['sales']:null;
            $ebit=Money::add(Money::add($pl['net'],$interest),$pl['tax_expense']);
            $avgAR=self::avg($open['receivable'],$close['receivable']);$avgInv=self::avg($open['inventory'],$close['inventory']);$avgTA=self::avg($open['total_assets'],$close['total_assets']);$avgEq=self::avg($open['equity'],$close['equity']);
            $cl=$close['current_liabilities'];$quick=Money::add(Money::add($close['cash'],$close['short_investment']),$close['receivable']);
            $r=[];
            $r['R01']=$cl>0?self::ok(self::div($close['current_assets'],$cl)):self::na('بدهی جاری پایان دوره صفر یا منفی است.');
            $r['R02']=$cl>0?self::ok(self::div($quick,$cl)):self::na('بدهی جاری پایان دوره صفر یا منفی است.');
            $r['R03']=self::ok((string)Money::add($close['current_assets'],-$cl));
            if($credit===null)$r['R04']=self::na('تفکیک فروش نسیه تعریف نشده است؛ در تنظیمات نسبت‌ها روش فروش نسیه را تعیین کنید.');
            elseif(bccomp($avgAR,'0',1)<=0)$r['R04']=self::na('میانگین دریافتنی تجاری صفر است.');
            else $r['R04']=self::ok(self::div($credit,$avgAR));
            $r['R05']=$r['R04']['value']!==null&&bccomp($r['R04']['value'],'0',2)>0?self::ok(self::div($days,$r['R04']['value'])):self::na($r['R04']['value']===null?'گردش مطالبات قابل محاسبه نیست.':'گردش مطالبات صفر است.');
            $r['R06']=bccomp($avgInv,'0',1)>0?self::ok(self::div($pl['cogs'],$avgInv)):self::na('میانگین موجودی کالا صفر است.');
            $r['R07']=$r['R06']['value']!==null&&bccomp($r['R06']['value'],'0',2)>0?self::ok(self::div($days,$r['R06']['value'])):self::na($r['R06']['value']===null?'گردش موجودی قابل محاسبه نیست.':'بهای تمام‌شدهٔ کالای فروش‌رفته در دوره صفر است.');
            $r['R08']=$pl['sales']>0?self::ok(self::div($pl['net'],$pl['sales'],100)):self::na('فروش خالص دوره صفر است.');
            $r['R09']=bccomp($avgTA,'0',1)>0?self::ok(self::div($pl['sales'],$avgTA)):self::na('میانگین کل دارایی‌ها صفر است.');
            $r['R10']=bccomp($avgTA,'0',1)>0?self::ok(self::div($pl['net'],$avgTA,100)):self::na('میانگین کل دارایی‌ها صفر است.');
            $r['R11']=bccomp($avgEq,'0',1)>0?self::ok(self::div($pl['net'],$avgEq,100)):self::na('میانگین حقوق صاحبان سرمایه صفر یا منفی است.');
            $r['R12']=$close['total_assets']>0?self::ok(self::div($close['total_liabilities'],$close['total_assets'],100)):self::na('کل دارایی‌های پایان دوره صفر است.');
            $r['R13']=$interest>0?self::ok(self::div($ebit,$interest)):self::na('هزینهٔ بهره‌ای در دوره ثبت نشده است.');
            $out=[];foreach(self::DEFS as $code=>$d)$out[$code]=['code'=>$code,'name'=>$d[0],'formula'=>$d[1],'unit'=>$d[2]]+$r[$code];
            return ['ratios'=>$out,'from'=>$from,'to'=>$to,'days'=>$days,'credit_mode'=>$mode,'computed_at'=>gmdate('c'),
                'inputs'=>['opening'=>$open,'closing'=>$close,'sales'=>$pl['sales'],'credit_sales'=>$credit,'cogs'=>$pl['cogs'],'net_profit'=>$pl['net'],'interest'=>$interest,'tax_expense'=>$pl['tax_expense'],'ebit'=>$ebit,'average'=>['receivable'=>$avgAR,'inventory'=>$avgInv,'total_assets'=>$avgTA,'equity'=>$avgEq]]];
        });
    }
    /** همان دوره در سال مالی قبل، اگر آن سال وجود داشته باشد. */
    public static function previous($cid,$from,$to){
        $pf=date('Y-m-d',strtotime($from.' -1 year'));$pt=date('Y-m-d',strtotime($to.' -1 year'));
        $fy=FiscalService::forDate($cid,$pt);if(!$fy||$pf<$fy['start_date'])return null;
        try{return self::compute($cid,$pf,$pt);}catch(ValidationException $e){return null;}
    }

    // ---- تنظیمات و snapshot
    public static function configure($cid,$mode,array $tags){
        return Access::write($cid,'company_settings.edit',function()use($cid,$mode,$tags){
            if(!isset(self::CREDIT_MODES[$mode]))throw new ValidationException(['credit_mode'=>'روش فروش نسیه معتبر نیست.']);
            $old=Settings::get('ratio_credit_sales',$cid);Settings::set('ratio_credit_sales',$mode,$cid);
            foreach($tags as $id=>$tag){$tag=(string)$tag;if($tag!==''&&!isset(self::TAGS[$tag]))throw new ValidationException(['tags'=>'برچسب حساب معتبر نیست.']);$a=Business::row('accounts',$cid,(int)$id);if((int)$a['level']!==3)throw new ValidationException(['tags'=>'فقط حساب معین برچسب می‌گیرد.']);Db::update('accounts',['ratio_tag'=>$tag===''?null:$tag],['company_id'=>$cid,'id'=>(int)$a['id']]);}
            Audit::log('ratio.configure','company',$cid,['credit_mode'=>$old],['credit_mode'=>$mode,'tags'=>$tags],$cid);return true;
        });
    }
    public static function saveSnapshot($cid,$from,$to){
        $data=self::compute($cid,$from,$to);
        return Access::write($cid,'report.export',function()use($cid,$data){$id=Business::save('ratio_snapshots',$cid,0,['date_from'=>$data['from'],'date_to'=>$data['to'],'payload'=>wp_json_encode($data,JSON_UNESCAPED_UNICODE),'created_by'=>Ctx::uid()]);Audit::log('ratio.snapshot','ratio_snapshot',$id,null,['from'=>$data['from'],'to'=>$data['to']],$cid);return $id;});
    }
    public static function snapshots($cid){Access::need($cid,'report.view');return Db::rows('SELECT id,date_from,date_to,created_at FROM '.Db::t('ratio_snapshots').' WHERE company_id=%d ORDER BY id DESC LIMIT 50',$cid);}
    public static function snapshot($cid,$id){Access::need($cid,'report.view');$r=Business::row('ratio_snapshots',$cid,$id);return json_decode($r['payload'],true);}
    public static function deleteSnapshot($cid,$id){return Access::write($cid,'report.export',function()use($cid,$id){$r=Business::row('ratio_snapshots',$cid,$id,true);Db::delete('ratio_snapshots',['company_id'=>$cid,'id'=>(int)$id]);Audit::log('ratio.snapshot.delete','ratio_snapshot',$id,['from'=>$r['date_from'],'to'=>$r['date_to']],null,$cid);return true;});}
}
