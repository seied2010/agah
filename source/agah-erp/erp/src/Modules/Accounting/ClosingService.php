<?php
/** بستن/بازگشایی کنترل‌شده: تعدیل ادواری، حساب موقت، اختتامیه و افتتاحیه در یک تراکنش و با ردپای مستقل. */
namespace Agah\Erp\Modules\Accounting;
use Agah\Erp\Core\{Access,Audit,Business,Ctx,Db,Money,Settings,ValidationException,ConflictException};
if(!defined('ABSPATH'))exit;
final class ClosingService {
    /** مانده دفتر با حفظ تفصیلی؛ این snapshot همان چیزی است که به سال بعد منتقل می‌شود. */
    private static function balances($cid,$fy){
        return Db::rows('SELECT l.account_id,l.detail_id,a.stmt_class,a.sys_key,SUM(CAST(l.debit AS SIGNED)-CAST(l.credit AS SIGNED)) AS balance FROM '.Db::t('voucher_lines').' l JOIN '.Db::t('vouchers')." v ON v.company_id=l.company_id AND v.id=l.voucher_id JOIN ".Db::t('accounts')." a ON a.company_id=l.company_id AND a.id=l.account_id WHERE v.company_id=%d AND v.fiscal_year_id=%d AND v.status IN ('approved','final') GROUP BY l.account_id,l.detail_id,a.stmt_class,a.sys_key HAVING balance<>0 ORDER BY l.account_id,l.detail_id",$cid,$fy);
    }
    private static function line($account,$detail,$balance,$reverse=false){$b=$reverse?-$balance:$balance;return ['account_id'=>(int)$account,'detail_id'=>(int)$detail,'debit'=>max(0,$b),'credit'=>max(0,-$b)];}
    private static function post($cid,$run,$type,$date,$lines,$description){if(!$lines)return null;return VoucherService::postSystem(['voucher_date'=>$date,'type'=>$type==='year_open'?'opening':'closing','source_type'=>$type,'source_id'=>$run,'description'=>$description],$lines);}
    /** خالی بودن مقصد از تمام اسناد/فاکتور/هزینه/انبار لازم است؛ افتتاحیهٔ همین run تنها استثنای بازگشایی است. */
    private static function emptyTarget($cid,$fy,$opening=0){
        if(Db::val('SELECT id FROM '.Db::t('vouchers')." WHERE company_id=%d AND fiscal_year_id=%d AND id<>%d AND status<>'void' LIMIT 1",$cid,$fy,$opening))throw new ConflictException('سال جدید گردش دارد؛ ابتدا گردش‌های آن را برگردانید.');
        foreach(['production_orders','invoices','expense_docs','stock_docs','payslips'] as $table)if(Db::val('SELECT id FROM '.Db::t($table)." WHERE company_id=%d AND fiscal_year_id=%d AND status<>'void' LIMIT 1",$cid,$fy))throw new ConflictException('سال جدید دارای برگه است.');
        $year=FiscalService::get($cid,$fy);if(Db::val('SELECT id FROM '.Db::t('asset_events')." WHERE company_id=%d AND doc_date BETWEEN %s AND %s AND status='issued' LIMIT 1",$cid,$year['start_date'],$year['end_date']))throw new ConflictException('سال جدید دارای رویداد دارایی است.');
    }
    /** بستن با cap مستقل؛ هر شکست حتی در آخرین افتتاحیه کل تغییرات را برمی‌گرداند. */
    public static function close($cid,$year,$reason){
        return Access::write($cid,'closing.approve',function()use($cid,$year,$reason){
            $reason=Business::reason($reason);$fy=FiscalService::get($cid,$year);FiscalService::assertOpen($cid,$fy['end_date']);
            if(Db::val('SELECT id FROM '.Db::t('year_closings').' WHERE company_id=%d AND fiscal_year_id=%d AND active_slot=1',$cid,$year))throw new ConflictException('سال قبلاً با این فرایند بسته شده است.');
            foreach(['vouchers','invoices'] as $table)if(Db::val('SELECT id FROM '.Db::t($table)." WHERE company_id=%d AND fiscal_year_id=%d AND status='draft' LIMIT 1",$cid,$year))throw new ConflictException('سال دارای پیش‌نویس است؛ آن را تعیین تکلیف کنید.');
            if(Db::val('SELECT id FROM '.Db::t('fiscal_years')." WHERE company_id=%d AND end_date<%s AND status='open' LIMIT 1",$cid,$fy['start_date']))throw new ConflictException('ابتدا سال‌های پیشین باز را ببندید.');
            if(Db::val('SELECT id FROM '.Db::t('asset_events')." WHERE company_id=%d AND doc_date>%s AND status='issued' LIMIT 1",$cid,$fy['end_date']))throw new ConflictException('گردش دارایی پس از پایان سال باید تعیین تکلیف شود.');
            $next=Db::row('SELECT * FROM '.Db::t('fiscal_years').' WHERE company_id=%d AND jy=%d',$cid,(int)$fy['jy']+1);
            if(!$next){$nid=FiscalService::createYear($cid,(int)$fy['jy']+1);$next=FiscalService::get($cid,$nid);}self::emptyTarget($cid,(int)$next['id']);FiscalService::assertOpen($cid,$next['start_date']);
            if(Db::val('SELECT id FROM '.Db::t('stock_moves')." WHERE company_id=%d AND status='active' AND move_date>%s LIMIT 1",$cid,$fy['end_date']))throw new ConflictException('گردش انبار پس از پایان سال وجود دارد.');
            $run=Business::save('year_closings',$cid,0,['fiscal_year_id'=>$year,'next_year_id'=>(int)$next['id'],'periods_snapshot'=>wp_json_encode(FiscalService::periods($cid,$year)),'balance_snapshot'=>'[]','reason'=>$reason,'created_by'=>Ctx::uid()]);
            $stock=FinancialService::integer(Db::val('SELECT COALESCE(SUM(cost),0) FROM '.Db::t('stock_moves')." WHERE company_id=%d AND status='active' AND move_date<=%s",$cid,$fy['end_date']));$inventory=AccountService::bySysKey($cid,'inventory');$ledger=0;
            foreach(self::balances($cid,$year) as $b)if((int)$b['account_id']===(int)$inventory['id'])$ledger=Money::add($ledger,FinancialService::integer($b['balance']));
            $difference=Money::add($stock,-$ledger);$iv=null;
            if(Settings::get('purchase_goods_mode',$cid)==='inventory'&&$difference)throw new ConflictException('ارزش انبار و دفتر موجودی مغایرت دارند.');
            if($difference){$cogs=AccountService::bySysKey($cid,'cogs');$iv=self::post($cid,$run,'year_inventory',$fy['end_date'],[self::line($inventory['id'],0,$difference),self::line($cogs['id'],0,-$difference)],'تعدیل موجودی پایان دوره ادواری');}
            $temporary=[];$net=0;foreach(self::balances($cid,$year) as $b)if(in_array($b['stmt_class'],FinancialService::TEMP,true)){$amount=FinancialService::integer($b['balance']);$temporary[]=self::line($b['account_id'],$b['detail_id'],$amount,true);$net=Money::add($net,$amount);}
            $summary=AccountService::bySysKey($cid,'income_summary');if($net)$temporary[]=self::line($summary['id'],0,$net);
            $pv=self::post($cid,$run,'year_profit',$fy['end_date'],$temporary,'بستن حساب‌های موقت به خلاصه سود و زیان');
            $summaryBalance=0;foreach(self::balances($cid,$year) as $b)if($b['sys_key']==='income_summary')$summaryBalance=Money::add($summaryBalance,FinancialService::integer($b['balance']));
            $rv=null;if($summaryBalance){$retained=AccountService::bySysKey($cid,'retained_earnings');$rv=self::post($cid,$run,'year_result',$fy['end_date'],[self::line($summary['id'],0,$summaryBalance,true),self::line($retained['id'],0,$summaryBalance)],'انتقال نتیجه دوره به سود و زیان انباشته');}
            $carry=self::balances($cid,$year);$sum=0;$close=[];$open=[];
            foreach($carry as $b){if(in_array($b['stmt_class'],FinancialService::TEMP,true))throw new ConflictException('حساب موقت بسته نشده است.');$amount=FinancialService::integer($b['balance']);$sum=Money::add($sum,$amount);$close[]=self::line($b['account_id'],$b['detail_id'],$amount,true);$open[]=self::line($b['account_id'],$b['detail_id'],$amount);}
            if($sum)throw new ConflictException('دفتر پایان سال متوازن نیست.');
            $cv=self::post($cid,$run,'year_close',$fy['end_date'],$close,'اختتامیه سال مالی');$ov=self::post($cid,$run,'year_open',$next['start_date'],$open,'افتتاحیه از سال قبل');
            Db::update('year_closings',['inventory_voucher_id'=>$iv,'profit_voucher_id'=>$pv,'result_voucher_id'=>$rv,'closing_voucher_id'=>$cv,'opening_voucher_id'=>$ov,'balance_snapshot'=>wp_json_encode($carry)],['company_id'=>$cid,'id'=>$run]);
            Db::update('fiscal_years',['status'=>'closed','closing_voucher_id'=>$cv],['company_id'=>$cid,'id'=>$year]);Db::update('fiscal_periods',['status'=>'closed'],['company_id'=>$cid,'fiscal_year_id'=>$year]);Audit::log('closing.complete','year_closing',$run,null,['year'=>$year,'next'=>$next['id'],'carry_lines'=>count($carry)],$cid);return $run;
        });
    }
    /** بازگشایی تنها پیش از گردش مقصد؛ اسناد قطعی مرتبط و دوره بسته مقصد، rollback کامل می‌دهند. */
    public static function reopen($cid,$id,$reason){
        return Access::write($cid,'closing.approve',function()use($cid,$id,$reason){$reason=Business::reason($reason);$r=Business::row('year_closings',$cid,$id,true);if($r['status']!=='closed'||!(int)$r['active_slot'])throw new ConflictException('این بستن قبلاً بازگشایی شده است.');
            self::emptyTarget($cid,(int)$r['next_year_id'],(int)$r['opening_voucher_id']);if(Db::val('SELECT id FROM '.Db::t('year_closings').' WHERE company_id=%d AND fiscal_year_id=%d AND active_slot=1',$cid,$r['next_year_id']))throw new ConflictException('سال بعد نیز بسته شده است.');
            Db::update('fiscal_years',['status'=>'open'],['company_id'=>$cid,'id'=>$r['fiscal_year_id']]);Db::update('fiscal_periods',['status'=>'open'],['company_id'=>$cid,'fiscal_year_id'=>$r['fiscal_year_id']]);
            foreach(['opening_voucher_id','closing_voucher_id','result_voucher_id','profit_voucher_id','inventory_voucher_id'] as $key)if($r[$key])VoucherService::voidSystem((int)$r[$key],$reason);
            foreach(json_decode($r['periods_snapshot'],true) as $period)Db::update('fiscal_periods',['status'=>$period['status']],['company_id'=>$cid,'id'=>$period['id']]);
            Db::update('fiscal_years',['closing_voucher_id'=>null],['company_id'=>$cid,'id'=>$r['fiscal_year_id']]);Db::update('year_closings',['status'=>'reopened','active_slot'=>null,'reopen_reason'=>$reason],['company_id'=>$cid,'id'=>$id]);Audit::log('closing.reopen','year_closing',$id,['status'=>'closed'],['reason'=>$reason],$cid);
        });
    }
    public static function page($cid){Access::need($cid,'closing.approve');return Db::rows('SELECT * FROM '.Db::t('year_closings').' WHERE company_id=%d ORDER BY id DESC LIMIT 50',$cid);}
}
