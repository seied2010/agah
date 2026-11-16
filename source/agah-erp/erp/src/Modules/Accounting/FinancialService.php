<?php
/** صورت‌های مالی از دفتر واقعی approved/final؛ اسناد مکانیکی بستن حذف می‌شوند و اختلاف هرگز پنهان نمی‌شود. */
namespace Agah\Erp\Modules\Accounting;
use Agah\Erp\Core\{Access,Business,Db,Money,ValidationException};
if(!defined('ABSPATH'))exit;
final class FinancialService {
    const TEMP=['revenue','contra_revenue','other_income','cogs','opex','other_expense','finance_cost','tax_expense'];
    const MECHANICAL=['year_profit','year_result','year_close'];
    public static function integer($v){return Money::integer($v);}
    private static function scope(){return "v.status IN ('approved','final') AND v.source_type NOT IN ('year_profit','year_result','year_close')";}
    /** صورت سود و زیان با بازه واقعی؛ افتتاحیه جزو درآمد/هزینه دوره نیست. */
    public static function profitInternal($cid,$from,$to){
        if(\Agah\Erp\Core\Ctx::cidOrZero()!==(int)$cid)throw new \Agah\Erp\Core\NotFoundException();Business::date($from);Business::date($to);if($from>$to)throw new ValidationException(['from'=>'بازه معکوس است.']);
        $rows=Db::rows('SELECT a.stmt_class,COALESCE(SUM(CAST(l.debit AS SIGNED)-CAST(l.credit AS SIGNED)),0) AS balance FROM '.Db::t('voucher_lines').' l JOIN '.Db::t('vouchers').' v ON v.company_id=l.company_id AND v.id=l.voucher_id JOIN '.Db::t('accounts').' a ON a.company_id=l.company_id AND a.id=l.account_id WHERE v.company_id=%d AND '.self::scope()." AND v.type<>'opening' AND v.voucher_date BETWEEN %s AND %s GROUP BY a.stmt_class",$cid,$from,$to);
        $b=array_fill_keys(self::TEMP,0);foreach($rows as $r)if(isset($b[$r['stmt_class']]))$b[$r['stmt_class']]=self::integer($r['balance']);
        $sales=-Money::add($b['revenue'],$b['contra_revenue']);$gross=Money::add($sales,-$b['cogs']);$operating=Money::add($gross,-$b['opex']);$net=$operating;
        foreach(['other_income','other_expense','finance_cost','tax_expense'] as $key)$net=Money::add($net,-$b[$key]);
        return ['sales'=>$sales,'cogs'=>$b['cogs'],'gross'=>$gross,'opex'=>$b['opex'],'operating'=>$operating,'other_income'=>-$b['other_income'],'other_expense'=>$b['other_expense'],'finance_cost'=>$b['finance_cost'],'tax_expense'=>$b['tax_expense'],'net'=>$net];
    }
    /** مانده ترازنامه از سال مالی مربوط به تاریخ و افتتاحیه همان سال؛ حمل سال قبل دوباره جمع نمی‌شود. */
    public static function balanceInternal($cid,$to){
        if(\Agah\Erp\Core\Ctx::cidOrZero()!==(int)$cid)throw new \Agah\Erp\Core\NotFoundException();Business::date($to);$fy=FiscalService::forDate($cid,$to);if(!$fy)throw new ValidationException(['to'=>'سال مالی تاریخ گزارش وجود ندارد.']);
        $rows=Db::rows('SELECT a.id,a.code,a.name,a.stmt_class,COALESCE(SUM(CAST(l.debit AS SIGNED)-CAST(l.credit AS SIGNED)),0) AS balance FROM '.Db::t('voucher_lines').' l JOIN '.Db::t('vouchers').' v ON v.company_id=l.company_id AND v.id=l.voucher_id JOIN '.Db::t('accounts').' a ON a.company_id=l.company_id AND a.id=l.account_id WHERE v.company_id=%d AND v.fiscal_year_id=%d AND v.voucher_date<=%s AND '.self::scope().' GROUP BY a.id,a.code,a.name,a.stmt_class ORDER BY a.code',$cid,$fy['id'],$to);
        $totals=['assets'=>0,'liabilities'=>0,'equity'=>0];foreach($rows as &$r){$r['balance']=self::integer($r['balance']);if(in_array($r['stmt_class'],['asset_current','asset_noncurrent','contra_asset'],true))$totals['assets']=Money::add($totals['assets'],$r['balance']);elseif(in_array($r['stmt_class'],['liability_current','liability_noncurrent'],true))$totals['liabilities']=Money::add($totals['liabilities'],-$r['balance']);elseif($r['stmt_class']==='equity')$totals['equity']=Money::add($totals['equity'],-$r['balance']);}unset($r);
        $profit=self::profitInternal($cid,$fy['start_date'],$to)['net'];$totals['current_profit']=$profit;$totals['difference']=Money::add($totals['assets'],-Money::add(Money::add($totals['liabilities'],$totals['equity']),$profit));
        return $totals+['rows'=>$rows,'fiscal_year'=>$fy,'to'=>$to];
    }
    private static function cashBalance($cid,$date,$before=false){
        $fy=FiscalService::forDate($cid,$date);if(!$fy)throw new ValidationException(['date'=>'سال مالی جریان نقد وجود ندارد.']);
        return self::integer(Db::val('SELECT COALESCE(SUM(CAST(l.debit AS SIGNED)-CAST(l.credit AS SIGNED)),0) FROM '.Db::t('voucher_lines').' l JOIN '.Db::t('vouchers').' v ON v.company_id=l.company_id AND v.id=l.voucher_id JOIN '.Db::t('accounts')." a ON a.company_id=l.company_id AND a.id=l.account_id WHERE v.company_id=%d AND v.fiscal_year_id=%d AND a.sys_key IN ('cash','bank') AND ".self::scope().($before?" AND (v.voucher_date<%s OR (v.type='opening' AND v.voucher_date=%s))":' AND v.voucher_date<=%s'),...($before?[$cid,$fy['id'],$date,$date]:[$cid,$fy['id'],$date])));
    }
    /** روش غیرمستقیم: سود + اقلام غیرنقدی + سرمایه در گردش؛ معاملات کاملاً غیرنقدی سرمایه‌ای جدا می‌شوند. */
    private static function cashflow($cid,$from,$to,$profit){
        $rows=Db::rows('SELECT v.id,a.stmt_class,a.sys_key,COALESCE(SUM(CAST(l.debit AS SIGNED)-CAST(l.credit AS SIGNED)),0) AS balance FROM '.Db::t('voucher_lines').' l JOIN '.Db::t('vouchers').' v ON v.company_id=l.company_id AND v.id=l.voucher_id JOIN '.Db::t('accounts')." a ON a.company_id=l.company_id AND a.id=l.account_id WHERE v.company_id=%d AND ".self::scope()." AND v.type<>'opening' AND v.voucher_date BETWEEN %s AND %s GROUP BY v.id,a.stmt_class,a.sys_key",$cid,$from,$to);
        $docs=[];foreach($rows as $r){$id=(int)$r['id'];if(!isset($docs[$id]))$docs[$id]=['cash'=>0,'profit'=>0,'contra'=>0,'working'=>0,'investing'=>0,'financing'=>0];$b=self::integer($r['balance']);$class=$r['stmt_class'];
            if(in_array($r['sys_key'],['cash','bank'],true))$key='cash';elseif(in_array($class,self::TEMP,true))$key='profit';elseif($class==='contra_asset')$key='contra';elseif(in_array($class,['asset_current','liability_current'],true))$key='working';elseif($class==='asset_noncurrent')$key='investing';elseif(in_array($class,['equity','liability_noncurrent'],true))$key='financing';else continue;
            $docs[$id][$key]=Money::add($docs[$id][$key],$key==='cash'?$b:-$b);
        }
        $contra=0;$working=0;$invest=0;$finance=0;$noncash=0;$review=0;$noncashDocs=0;
        foreach($docs as $d){$contra=Money::add($contra,$d['contra']);$working=Money::add($working,$d['working']);$i=$d['investing'];$f=$d['financing'];
            if(!$d['cash']&&($i||$f)){$noncash=Money::add($noncash,-Money::add(Money::add($d['profit'],$d['contra']),$d['working']));$i=0;$f=0;$noncashDocs++;}
            elseif($d['cash']&&count(array_filter([$d['investing'],$d['financing'],Money::add($d['profit'],$d['working'])]))>1)$review++;
            $invest=Money::add($invest,$i);$finance=Money::add($finance,$f);
        }
        $operating=Money::add(Money::add(Money::add($profit,$contra),$working),$noncash);$start=self::cashBalance($cid,$from,true);$end=self::cashBalance($cid,$to);$change=Money::add($end,-$start);$total=Money::add(Money::add($operating,$invest),$finance);
        return ['profit'=>$profit,'noncash_contra'=>$contra,'working_capital'=>$working,'noncash_capital_adjustment'=>$noncash,'operating'=>$operating,'investing'=>$invest,'financing'=>$finance,'start'=>$start,'end'=>$end,'change'=>$change,'difference'=>Money::add($change,-$total),'review_count'=>$review,'noncash_documents'=>$noncashDocs];
    }
    public static function statements($cid,$from,$to){Access::need($cid,'report.view');return Db::tx(function()use($cid,$from,$to){$p=self::profitInternal($cid,$from,$to);return ['profit'=>$p,'balance'=>self::balanceInternal($cid,$to),'cashflow'=>self::cashflow($cid,$from,$to,$p['net']),'from'=>$from,'to'=>$to];});}
    /** بدهکار و بستانکار تجاری از دفتر تفصیلی؛ دریافتنی و پرداختنی به هم تهاتر نمی‌شوند. */
    /** بدهکاران و بستانکاران تجاری در یک بازه: ماندهٔ اول، گردش بدهکار، گردش بستانکار و ماندهٔ پایان؛ مثبت یعنی بدهکار. */
    public static function parties($cid,$from,$to,$page=1){
        Access::need($cid,'report.view');Business::date($from);Business::date($to);if($from>$to)throw new ValidationException(['to'=>'پایان بازه قبل از شروع است.']);
        $fy=FiscalService::forDate($cid,$to);if(!$fy)throw new ValidationException(['to'=>'سال مالی وجود ندارد.']);if($from<$fy['start_date'])throw new ValidationException(['from'=>'شروع و پایان بازه باید در یک سال مالی باشند.']);$page=max(1,(int)$page);
        $query='SELECT p.id,p.code,p.name,COALESCE(SUM(IF(v.voucher_date<%s,CAST(l.debit AS SIGNED)-CAST(l.credit AS SIGNED),0)),0) AS opening,COALESCE(SUM(IF(v.voucher_date>=%s,l.debit,0)),0) AS debit,COALESCE(SUM(IF(v.voucher_date>=%s,l.credit,0)),0) AS credit FROM '.Db::t('parties').' p JOIN '.Db::t('voucher_lines').' l ON l.company_id=p.company_id AND l.detail_id=p.detail_id JOIN '.Db::t('vouchers').' v ON v.company_id=l.company_id AND v.id=l.voucher_id JOIN '.Db::t('accounts').' a ON a.company_id=l.company_id AND a.id=l.account_id WHERE p.company_id=%d AND v.fiscal_year_id=%d AND v.voucher_date<=%s AND a.sys_key IN (\'receivable\',\'notes_receivable\',\'payable\',\'notes_payable\') AND '.self::scope().' GROUP BY p.id,p.code,p.name HAVING opening<>0 OR debit<>0 OR credit<>0';
        $args=[$from,$from,$from,$cid,$fy['id'],$to];
        $n=(int)Db::val('SELECT COUNT(*) FROM ('.$query.') q',...$args);$rows=Db::rows($query.' ORDER BY p.code LIMIT 50 OFFSET %d',...array_merge($args,[($page-1)*50]));
        foreach($rows as &$r){foreach(['opening','debit','credit'] as $k)$r[$k]=self::integer($r[$k]);$r['closing']=Money::add($r['opening'],Money::add($r['debit'],-$r['credit']));}unset($r);
        return ['rows'=>$rows,'page'=>$page,'pages'=>(int)ceil($n/50),'from'=>$from,'to'=>$to];
    }
}
