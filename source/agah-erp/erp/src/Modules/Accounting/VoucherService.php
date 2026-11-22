<?php
/** اسناد و ردیف‌ها: همهٔ تغییرات، شماره‌گذاری و ممیزی در یک تراکنش با قفل شرکت/سند انجام می‌شوند. */
namespace Agah\Erp\Modules\Accounting;
use Agah\Erp\Core\{Db,Ctx,Access,Audit,Money,Crypto,Request,Sequence,ValidationException,ConflictException,NotFoundException};
if(!defined('ABSPATH')) exit;
final class VoucherService {
    const STATES=['draft'=>'پیش‌نویس','approved'=>'تأییدشده','final'=>'قطعی','void'=>'باطل'];
    public static function get($cid,$id) {
        Access::need($cid,'voucher.view');return self::snapshot($cid,$id);
    }
    /** snapshot داخلی همواره company_id را با id تطبیق می‌دهد؛ نبود و شرکت بیگانه یک پاسخ دارند. */
    private static function snapshot($cid,$id,$lock=false) {
        $v=Db::row('SELECT * FROM '.Db::t('vouchers').' WHERE company_id=%d AND id=%d'.($lock?' FOR UPDATE':''),$cid,$id);
        if(!$v) throw new NotFoundException();
        $v['lines']=Db::rows('SELECT l.*,a.code AS account_code,a.name AS account_name,d.code AS detail_code,d.name AS detail_name FROM '.Db::t('voucher_lines').' l JOIN '.Db::t('accounts').' a ON a.company_id=l.company_id AND a.id=l.account_id LEFT JOIN '.Db::t('details').' d ON d.company_id=l.company_id AND d.id=l.detail_id WHERE l.company_id=%d AND l.voucher_id=%d ORDER BY l.line_no',$cid,$id);
        return $v;
    }
    /** تاریخ ISO داخلی باید واقعی باشد؛ UI پیش از ورود به این سرویس Jalali::parse می‌کند. */
    private static function header(array $in) {
        $date=(string)($in['voucher_date']??'');$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if(!$d || $d->format('Y-m-d')!==$date) throw new ValidationException(['voucher_date'=>'تاریخ سند معتبر نیست.']);
        $type=$in['type']??'normal';
        if(!in_array($type,['normal','opening','closing','adjustment'],true)) throw new ValidationException(['type'=>'نوع سند معتبر نیست.']);
        return ['voucher_date'=>$date,'type'=>$type,'description'=>Request::clean($in['description']??'',500)];
    }
    /** مبلغ داخلی int ریال است؛ متن ورودی با Money::fromInput تبدیل می‌شود. هیچ float پذیرفته نمی‌شود. */
    private static function amount($value,$unit) {
        if(is_float($value) || is_array($value) || is_object($value)) throw new \InvalidArgumentException('مبلغ معتبر نیست.');
        $n=is_int($value)?$value:Money::fromInput((string)$value,$unit);
        if($n<0 || $n>Money::MAX) throw new \InvalidArgumentException('مبلغ خارج از محدوده است.');
        return $n;
    }
    /** حساب معین فعال و قاعدهٔ تفصیلی برای تک‌تک خطوط، زیر قفل شرکت دوباره خوانده می‌شوند. */
    private static function lines($cid,array $lines,$unit='rial',$limit=200) {
        if(count($lines)<1 || count($lines)>$limit) throw new ValidationException(['lines'=>'تعداد ردیف‌ها خارج از محدودهٔ مجاز است.']);
        $out=[];$errors=[];$debit=0;$credit=0;
        foreach(array_values($lines) as $i=>$l) {
            if(!is_array($l)) {$errors['lines.'.$i]='ردیف معتبر نیست.';continue;}
            try {
                $aid=self::id($l['account_id']??0);$did=self::id($l['detail_id']??0);
                $a=Db::row('SELECT * FROM '.Db::t('accounts').' WHERE company_id=%d AND id=%d',$cid,$aid);
                if(!$a) throw new NotFoundException();
                if((int)$a['level']!==3 || !(int)$a['is_active']) throw new \InvalidArgumentException('حساب باید معین و فعال باشد.');
                if($a['detail_rule']==='required' && !$did) throw new \InvalidArgumentException('تفصیلی این حساب الزامی است.');
                if($a['detail_rule']==='none' && $did) throw new \InvalidArgumentException('این حساب تفصیلی نمی‌پذیرد.');
                if($did) {
                    $d=Db::row('SELECT * FROM '.Db::t('details').' WHERE company_id=%d AND id=%d',$cid,$did);
                    if(!$d) throw new NotFoundException();
                    if(!$d['is_active'] || ($a['detail_kind'] && $a['detail_kind']!==$d['kind'])) throw new \InvalidArgumentException('نوع یا وضعیت تفصیلی با حساب سازگار نیست.');
                }
                $dr=self::amount($l['debit']??0,$unit);$cr=self::amount($l['credit']??0,$unit);
                if(($dr>0)===($cr>0)) throw new \InvalidArgumentException('هر ردیف فقط بدهکار یا فقط بستانکار و بزرگ‌تر از صفر باشد.');
                $debit=Money::add($debit,$dr);$credit=Money::add($credit,$cr);
                $out[]=['account_id'=>$aid,'detail_id'=>$did?:null,'description'=>Request::clean($l['description']??'',500),'debit'=>$dr,'credit'=>$cr];
            } catch(\InvalidArgumentException $e) {$errors['lines.'.$i]=$e->getMessage();}
        }
        if($errors) throw new ValidationException($errors);
        return [$out,$debit,$credit];
    }
    private static function id($v) {
        if(is_int($v) && $v>=0) return $v;
        if(is_string($v) && preg_match('/^[0-9]{1,18}$/D',$v)) return (int)$v;
        throw new \InvalidArgumentException('شناسه معتبر نیست.');
    }
    /** پیش‌نویس شماره ندارد؛ ویرایش سند شماره‌دار در سال دیگر و هر تغییر قطعی ممنوع است. */
    public static function save($cid,$id,array $in,array $lines,$revision=0,$unit='rial') {
        return Access::write($cid,$id?'voucher.edit':'voucher.create',function()use($cid,$id,$in,$lines,$revision,$unit){
            return self::persist($cid,$id,$in,$lines,$revision,$unit);
        });
    }
    private static function persist($cid,$id,$in,$lines,$revision,$unit,$source=null) {
        $old=$id?self::snapshot($cid,$id,true):null;
        if($old) {
            self::manual($old);self::revision($old,$revision);
            if(!in_array($old['status'],['draft','approved'],true)) throw new ConflictException('سند قطعی یا باطل قابل ویرایش نیست.');
            FiscalService::assertOpen($cid,$old['voucher_date']);
        }
        $head=self::header($in);$fy=FiscalService::assertOpen($cid,$head['voucher_date']);
        if($old && $old['number']!==null && (int)$fy['id']!==(int)$old['fiscal_year_id']) throw new ConflictException('سال سند شماره‌دار تغییر نمی‌کند.');
        [$rows,$dr,$cr]=self::lines($cid,$lines,$unit,$source?5000:200);
        if($old && $old['status']==='approved' && (count($rows)<2 || !$dr || $dr!==$cr)) throw new ValidationException(['lines'=>'سند تأییدشده باید متوازن بماند.']);
        $now=gmdate('Y-m-d H:i:s');$values=$head+['fiscal_year_id'=>$fy['id'],'total_debit'=>$dr,'total_credit'=>$cr,'updated_at'=>$now];
        if($id) {
            $values['revision']=(int)$old['revision']+1;
            Db::update('vouchers',$values,['company_id'=>$cid,'id'=>$id]);Db::delete('voucher_lines',['company_id'=>$cid,'voucher_id'=>$id]);
        } else {
            $values+=['uuid'=>Crypto::uuid(),'company_id'=>$cid,'created_by'=>Ctx::uid(),'created_at'=>$now,'revision'=>1];
            if($source) $values+=['source_type'=>$source['type'],'source_id'=>$source['id']];
            $id=Db::insert('vouchers',$values);
        }
        foreach($rows as $i=>$line) Db::insert('voucher_lines',$line+['company_id'=>$cid,'voucher_id'=>$id,'line_no'=>$i+1]);
        Audit::log($old?'voucher.update':'voucher.create','voucher',$id,$old,self::snapshot($cid,$id),$cid);
        return (int)$id;
    }
    /**
     * کپی سند: فقط یک پیش‌نویس تازه با ردیف‌ها و شرح می‌سازد. شماره، وضعیت، مبدأ خودکار و پیوست‌ها کپی نمی‌شوند.
     * تاریخ پیش‌نویس امروزِ سال مالی انتخاب‌شده است و حساب‌ها و دوره دوباره کنترل می‌شوند.
     */
    public static function copy($cid,$id) {
        return Access::write($cid,'voucher.create',function()use($cid,$id){
            Ctx::need('voucher.view');$v=self::snapshot($cid,$id);
            $lines=[];foreach($v['lines'] as $l)$lines[]=['account_id'=>(int)$l['account_id'],'detail_id'=>(int)$l['detail_id'],'description'=>$l['description'],'debit'=>(int)$l['debit'],'credit'=>(int)$l['credit']];
            $type=in_array($v['type'],['normal','adjustment'],true)?$v['type']:'normal';
            $new=self::persist($cid,0,['voucher_date'=>\Agah\Erp\Core\FiscalContext::today($cid),'type'=>$type,'description'=>$v['description']],$lines,0,'rial');
            Audit::log('voucher.copy','voucher',$new,null,['from_voucher'=>(int)$id],$cid);
            return $new;
        });
    }
    private static function manual($v) { if($v['source_type']!=='') throw new ConflictException('سند خودکار فقط از برگهٔ مبدأ قابل تغییر است.'); }
    private static function revision($v,$revision) { if((int)$v['revision']!== (int)$revision) throw new ConflictException('سند در نشست دیگری تغییر کرده؛ دوباره آن را باز کنید.'); }
    /** توازن از خود خطوط SQL محاسبه می‌شود و حساب‌ها پس از ذخیره دوباره اعتبارسنجی می‌شوند. */
    private static function assertBalanced($cid,$v) {
        self::lines($cid,$v['lines'],'rial',$v['source_type']?5000:200);
        $r=Db::row('SELECT COUNT(*) AS n,COALESCE(SUM(debit),0) AS d,COALESCE(SUM(credit),0) AS c FROM '.Db::t('voucher_lines').' WHERE company_id=%d AND voucher_id=%d',$cid,$v['id']);
        if((int)$r['n']<2 || (string)$r['d']!==(string)$r['c'] || (int)$r['d']<=0 || (int)$r['d']>Money::MAX || (int)$r['d']!==(int)$v['total_debit'] || (int)$r['c']!==(int)$v['total_credit']) throw new ConflictException('جمع خطوط سند نامتوازن یا با جمع ذخیره‌شده ناسازگار است.');
    }
    /** تغییر وضعیت دستی با دلیل و نسخهٔ خوش‌بینانه؛ شمارهٔ ابطال‌شده هرگز پس گرفته نمی‌شود. */
    public static function transition($cid,$id,$action,$reason='',$revision=0) {
        $caps=['approve'=>'voucher.approve','finalize'=>'voucher.finalize','reopen'=>'voucher.reopen','void'=>'voucher.void'];
        if(!isset($caps[$action])) throw new ValidationException(['action'=>'عمل سند معتبر نیست.']);
        return Access::write($cid,$caps[$action],function()use($cid,$id,$action,$reason,$revision){
            $v=self::snapshot($cid,$id,true);self::manual($v);self::revision($v,$revision);
            return self::change($cid,$v,$action,$reason);
        });
    }
    private static function change($cid,$v,$action,$reason) {
        FiscalService::assertOpen($cid,$v['voucher_date']);
        $now=gmdate('Y-m-d H:i:s');$new=['updated_at'=>$now,'revision'=>(int)$v['revision']+1];
        switch($action) {
            case 'approve':
                if($v['status']!=='draft') throw new ConflictException('فقط پیش‌نویس قابل تأیید است.');
                self::assertBalanced($cid,$v);
                $new+=['status'=>'approved','number'=>Sequence::next($cid,'voucher',(int)$v['fiscal_year_id']),'approved_by'=>Ctx::uid(),'approved_at'=>$now];break;
            case 'finalize':
                if($v['status']!=='approved') throw new ConflictException('فقط سند تأییدشده قطعی می‌شود.');
                self::assertBalanced($cid,$v);$new+=['status'=>'final','finalized_by'=>Ctx::uid(),'finalized_at'=>$now];break;
            case 'reopen':
                if($v['status']!=='final') throw new ConflictException('فقط سند قطعی بازگشایی می‌شود.');
                $new+=['status'=>'approved','status_reason'=>self::reason($reason),'finalized_by'=>0,'finalized_at'=>null];break;
            case 'void':
                if(!in_array($v['status'],['approved','final'],true)) throw new ConflictException('فقط سند شماره‌دار قابل ابطال است.');
                $new+=['status'=>'void','status_reason'=>self::reason($reason),'voided_by'=>Ctx::uid(),'voided_at'=>$now];break;
        }
        Db::update('vouchers',$new,['company_id'=>$cid,'id'=>$v['id']]);
        Audit::log('voucher.'.$action,'voucher',$v['id'],$v,self::snapshot($cid,$v['id']),$cid);
        return (int)$v['id'];
    }
    private static function reason($s) {
        $s=Request::clean($s,300);
        if(mb_strlen($s)<5) throw new ValidationException(['reason'=>'دلیل حداقل پنج نویسه باشد.']);
        return $s;
    }
    public static function delete($cid,$id,$revision) {
        Access::write($cid,'voucher.delete',function()use($cid,$id,$revision){
            $v=self::snapshot($cid,$id,true);self::manual($v);self::revision($v,$revision);
            if($v['status']!=='draft') throw new ConflictException('فقط پیش‌نویس حذف می‌شود.');
            FiscalService::assertOpen($cid,$v['voucher_date']);Db::delete('vouchers',['company_id'=>$cid,'id'=>$id]);Audit::log('voucher.delete','voucher',$id,$v,null,$cid);
        });
    }
    /** API داخلی ماژول‌ها؛ فعلاً مجوز ایجاد سند لازم است، منبع و ثبت هم‌زمان باید در همان Db::tx فراخواننده باشند. */
    public static function postSystem($header,$lines) {
        $cid=Ctx::cid();
        $source=$header['source_type']??'';
        $cap=['production_start'=>'production.approve','production_finish'=>'production.approve','treasury_receipt'=>'receipt.create','treasury_payment'=>'payment.create','treasury_transfer'=>'transfer.create','cheque_event'=>'cheque.approve','invoice_sale'=>'sale_invoice.create','invoice_purchase'=>'purchase_invoice.create','invoice_sale_return'=>'sale_return.create','invoice_purchase_return'=>'purchase_return.create','stock_doc'=>'stock.create','payroll'=>'payroll.approve','payroll_run'=>'payroll.approve','payroll_pay'=>'payroll.pay','asset_purchase'=>'asset.create','asset_depreciation'=>'asset.approve','asset_disposal'=>'asset.approve','expense_doc'=>'expense.create','year_inventory'=>'closing.approve','year_profit'=>'closing.approve','year_result'=>'closing.approve','year_close'=>'closing.approve','year_open'=>'closing.approve','invoice_advance'=>'voucher.create'][$source]??'voucher.create';
        if($source==='invoice_advance')$cap=\Agah\Erp\Modules\Trade\InvoiceService::resource(\Agah\Erp\Core\Business::row('invoices',$cid,(int)($header['source_id']??0))['kind']).'.create';
        return Access::write($cid,$cap,function()use($cid,$header,$lines){
            $type=$header['source_type']??'';$source=(int)($header['source_id']??0);
            if(!preg_match('/^[a-z][a-z0-9_]{1,23}$/D',$type) || $source<1) throw new ValidationException(['source'=>'منبع سند الزامی است.']);
            if(Db::val('SELECT id FROM '.Db::t('vouchers').' WHERE company_id=%d AND source_type=%s AND source_id=%d',$cid,$type,$source)) throw new ConflictException('برای این برگه قبلاً سند ساخته شده است.');
            if(strpos($type,'treasury_')===0) \Agah\Erp\Core\Business::row('treasury_docs',$cid,$source,true);
            if($type==='cheque_event') \Agah\Erp\Core\Business::row('cheque_events',$cid,$source,true);
            if(strpos($type,'invoice_')===0) { $invoice=\Agah\Erp\Core\Business::row('invoices',$cid,$source,true);if($type!=='invoice_advance'&&$type!=='invoice_'.$invoice['kind'])throw new ConflictException('نوع منبع فاکتور ناسازگار است.'); }
            if(in_array($type,['production_start','production_finish'],true))\Agah\Erp\Modules\Manufacturing\ProductionService::authorizeVoucher($cid,$source,$type);
            if($type==='stock_doc')\Agah\Erp\Core\Business::row('stock_docs',$cid,$source,true);
            if($type==='payroll')\Agah\Erp\Core\Business::row('payslips',$cid,$source,true);
            if(in_array($type,['payroll_run','payroll_pay'],true))\Agah\Erp\Core\Business::row('payroll_runs',$cid,$source,true);
            if(strpos($type,'asset_')===0){$e=\Agah\Erp\Core\Business::row('asset_events',$cid,$source,true);if($type!=='asset_'.$e['kind'])throw new ConflictException('نوع رویداد دارایی ناسازگار است.');}
            if($type==='expense_doc')\Agah\Erp\Core\Business::row('expense_docs',$cid,$source,true);
            if(strpos($type,'year_')===0)\Agah\Erp\Core\Business::row('year_closings',$cid,$source,true);
            $id=self::persist($cid,0,$header,$lines,0,'rial',['type'=>$type,'id'=>$source]);
            self::change($cid,self::snapshot($cid,$id,true),'approve','');return $id;
        });
    }
    public static function voidSystem($id,$reason) {
        $cid=Ctx::cid();
        $source=self::snapshot($cid,$id)['source_type'];
        $cap=['production_start'=>'production.delete','production_finish'=>'production.delete','treasury_receipt'=>'receipt.delete','treasury_payment'=>'payment.delete','treasury_transfer'=>'transfer.delete','cheque_event'=>'cheque.approve','invoice_sale'=>'sale_invoice.delete','invoice_purchase'=>'purchase_invoice.delete','invoice_sale_return'=>'sale_return.delete','invoice_purchase_return'=>'purchase_return.delete','stock_doc'=>'stock.delete','payroll'=>'payroll.delete','payroll_run'=>'payroll.delete','payroll_pay'=>'payroll.pay','asset_purchase'=>'asset.delete','asset_depreciation'=>'asset.delete','asset_disposal'=>'asset.delete','expense_doc'=>'expense.delete','year_inventory'=>'closing.approve','year_profit'=>'closing.approve','year_result'=>'closing.approve','year_close'=>'closing.approve','year_open'=>'closing.approve'][$source]??'voucher.void';
        if($source==='invoice_advance') { $v=self::snapshot($cid,$id);$cap=\Agah\Erp\Modules\Trade\InvoiceService::resource(\Agah\Erp\Core\Business::row('invoices',$cid,(int)$v['source_id'])['kind']).'.delete'; }
        if(\Agah\Erp\Modules\Trade\InvoiceService::allowsDependentReversal($cid,'voucher',$id))$cap='invoice_delete.delete';
        return Access::write($cid,$cap,function()use($cid,$id,$reason){
            $v=self::snapshot($cid,$id,true);
            if(in_array($v['source_type'],['production_start','production_finish'],true)&&\Agah\Erp\Core\Business::row('production_orders',$cid,(int)$v['source_id'],true)['status']!=='posting_void')throw new ConflictException('سند تولید فقط از ابطال دستور قابل برگشت است.');
            if(!$v['source_type'] || $v['status']!=='approved') throw new ConflictException('سند منبعِ قطعی‌نشده لازم است.');
            return self::change($cid,$v,'void',$reason);
        });
    }
}
