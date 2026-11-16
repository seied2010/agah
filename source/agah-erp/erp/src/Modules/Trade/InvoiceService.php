<?php
/** فروش و خرید: پیش‌نویس، تبدیل، صدور و برگشت با snapshot قیمت/مالیات؛ سند، انبار و تسویه اتمیک‌اند. */
namespace Agah\Erp\Modules\Trade;
use Agah\Erp\Core\{Access,Audit,Business,Ctx,Db,Money,Request,Sequence,Settings,TradeMath,ValidationException,ConflictException,NotFoundException};
use Agah\Erp\Modules\Accounting\{AccountService,FiscalService,VoucherService};
use Agah\Erp\Modules\Catalog\CatalogService;
use Agah\Erp\Modules\Inventory\StockService;
use Agah\Erp\Modules\Treasury\TreasuryService;
if (!defined('ABSPATH')) exit;
final class InvoiceService {
    const KINDS=['proforma'=>'پیش‌فاکتور','sale_order'=>'سفارش فروش','sale'=>'فاکتور فروش','sale_return'=>'برگشت فروش','purchase_request'=>'درخواست خرید','purchase_order'=>'سفارش خرید','purchase'=>'فاکتور خرید','purchase_return'=>'برگشت خرید'];
    public static function resource($kind) {
        if(!isset(self::KINDS[$kind])) throw new ValidationException(['kind'=>'نوع فاکتور معتبر نیست.']);
        return ['sale'=>'sale_invoice','purchase'=>'purchase_invoice'][$kind]??$kind;
    }
    private static function financial($kind) { return in_array($kind,['sale','purchase','sale_return','purchase_return'],true); }
    private static function returned($kind) { return in_array($kind,['sale_return','purchase_return'],true); }
    private static function buying($kind) { return strpos($kind,'purchase')===0; }
    private static function snapshot($cid,$id,$lock=false) {
        $d=Business::row('invoices',$cid,$id,$lock);
        $d['lines']=Db::rows('SELECT * FROM '.Db::t('invoice_lines').' WHERE company_id=%d AND invoice_id=%d ORDER BY line_no',$cid,$id);
        return $d;
    }
    public static function get($cid,$id) { $d=self::snapshot($cid,$id);Access::need($cid,self::resource($d['kind']).'.view');return $d; }
    private static function revision($d,$rev) {
        if((int)$d['revision']!==$rev) throw new ConflictException('برگه تغییر کرده است؛ دوباره آن را باز کنید.');
        if($d['status']!=='draft') throw new ConflictException('فقط پیش‌نویس قابل تغییر است.');
    }
    /** مجموع برگشت‌های صادرشده؛ پیش‌نویس ذخیره‌شده هرگز موجودی یا سقف برگشت را مصرف نمی‌کند. */
    private static function returnedQty($cid,$line) {
        return (int)Db::val('SELECT COALESCE(SUM(l.qty_scaled),0) FROM '.Db::t('invoice_lines').' l JOIN '.Db::t('invoices')." i ON i.company_id=l.company_id AND i.id=l.invoice_id WHERE l.company_id=%d AND l.source_line_id=%d AND i.status='issued'",$cid,$line);
    }
    /** محاسبهٔ خطوط؛ برگشت از snapshot مبدأ و فاکتور عادی از نرخ تأییدشدهٔ تاریخ استفاده می‌کند. */
    private static function price($cid,$kind,$date,$parent,array $input,$discount,$extra,$basis) {
        if(count($input)<1||count($input)>60) throw new ValidationException(['lines'=>'بین یک تا ۶۰ ردیف وارد کنید.']);
        Business::amount($discount);Business::amount($extra);
        if(!in_array($basis,['value','quantity'],true)) throw new ValidationException(['cost_basis'=>'روش تسهیم معتبر نیست.']);
        if($extra && $kind!=='purchase') throw new ValidationException(['extra_cost'=>'هزینه جانبی فقط برای فاکتور خرید است.']);
        if(self::returned($kind) && ($discount||$extra)) throw new ValidationException(['discount_amount'=>'برگشت از قیمت و تخفیف مبدأ پیروی می‌کند.']);
        $rows=[];$seen=[];$weights=[];$netTotal=0;
        foreach($input as $idx=>$l) {
            if(!is_array($l)) throw new ValidationException(['lines'=>'ردیف معتبر نیست.']);
            $qty=$l['qty_scaled']??0;
            if(!is_int($qty)||$qty<1||$qty>Money::MAX) throw new ValidationException(['lines.'.$idx=>'مقدار مثبت تا چهار رقم اعشار لازم است.']);
            if(self::returned($kind)) {
                $source=Business::row('invoice_lines',$cid,Business::id($l['source_line_id']??0));
                if((int)$source['invoice_id']!==$parent) throw new NotFoundException();
                if(isset($seen[$source['id']])) throw new ValidationException(['lines'=>'خط مبدأ تکراری است.']);$seen[$source['id']]=true;
                $before=self::returnedQty($cid,(int)$source['id']);
                $row=['source_line_id'=>(int)$source['id'],'item_id'=>(int)$source['item_id'],'warehouse_id'=>$source['warehouse_id']?(int)$source['warehouse_id']:null,'item_name'=>$source['item_name'],'item_code'=>$source['item_code']??'','unit_name'=>$source['unit_name'],'qty_scaled'=>$qty,'unit_price'=>(int)$source['unit_price'],'tax_rate_id'=>$source['tax_rate_id'],'tax_name'=>$source['tax_name'],'tax_bp'=>(int)$source['tax_bp']];
                foreach(['gross_amount','discount_amount','header_discount','net_amount','tax_amount','extra_cost','total_cost'] as $key) $row[$key]=TradeMath::portion((int)$source[$key],$before,$qty,(int)$source['qty_scaled']);
                $rows[]=$row;continue;
            }
            $item=Business::row('items',$cid,Business::id($l['item_id']??0));
            if(!$item['is_active']) throw new ValidationException(['lines.'.$idx=>'کالا یا خدمت غیرفعال است.']);
            $wh=Business::id($l['warehouse_id']??0);
            if($item['kind']==='goods') { $w=Business::row('warehouses',$cid,$wh);if(!$w['is_active']) throw new ValidationException(['warehouse_id'=>'انبار غیرفعال است.']); }
            elseif($wh) throw new ValidationException(['warehouse_id'=>'خدمت انبار نمی‌پذیرد.']);
            $unit=Business::row('units',$cid,(int)$item['unit_id']);
            $price=Business::amount($l['unit_price']??0);$gross=Money::lineAmount($qty,$price);$lineDiscount=Business::amount($l['discount_amount']??0);
            if($lineDiscount>$gross) throw new ValidationException(['lines.'.$idx=>'تخفیف خط بیشتر از مبلغ خط است.']);
            $net=Money::add($gross,-$lineDiscount);$netTotal=Money::add($netTotal,$net);
            $rows[]=['item_id'=>(int)$item['id'],'warehouse_id'=>$wh?:null,'source_line_id'=>null,'item_name'=>$item['name'],'item_code'=>$item['code'],'unit_name'=>$unit['name'],'qty_scaled'=>$qty,'unit_price'=>$price,'gross_amount'=>$gross,'discount_amount'=>$lineDiscount,'header_discount'=>0,'net_amount'=>$net,'extra_cost'=>0,'total_cost'=>0,'tax_rate_id'=>array_key_exists('tax_rate_id',$l)&&$l['tax_rate_id']!=='item'?(Business::id($l['tax_rate_id']??0)?:null):$item['tax_rate_id']];
            $weights[]=$net;
        }
        if(!self::returned($kind)) {
            if($discount>$netTotal) throw new ValidationException(['discount_amount'=>'تخفیف کل از جمع خالص بیشتر است.']);
            $parts=TradeMath::allocate($discount,$weights);$costWeights=[];
            foreach($rows as $idx=>&$r) {
                $r['header_discount']=$parts[$idx];$r['net_amount']-=$parts[$idx];
                $tax=CatalogService::taxSnapshot($cid,$r['tax_rate_id'],$date,$r['net_amount']);$r=array_merge($r,$tax);
                $costWeights[]=$r['warehouse_id']?($basis==='quantity'?$r['qty_scaled']:$r['net_amount']):0;
            }unset($r);
            $costParts=TradeMath::allocate($extra,$costWeights);
            foreach($rows as $idx=>&$r) $r['extra_cost']=$costParts[$idx];unset($r);
        }
        $amount=0;foreach($rows as $r) $amount=Money::add($amount,Money::add(Money::add($r['net_amount'],$r['tax_amount']),$r['extra_cost']));
        if($amount<1) throw new ValidationException(['lines'=>'مبلغ نهایی برگه باید مثبت باشد.']);
        return [$rows,$amount];
    }
    /** ذخیرهٔ پیش‌نویس بدون شماره و اثر مالی؛ والد و تمام شناسه‌ها دوباره با شرکت تطبیق داده می‌شوند. */
    public static function save($cid,$id,array $in,array $input,$revision=0) {
        $kind=$in['kind']??'';$res=self::resource($kind);
        return Access::write($cid,$res.($id?'.edit':'.create'),function()use($cid,$id,$in,$input,$revision,$kind){
            $old=$id?self::snapshot($cid,$id,true):null;
            if($old) { self::revision($old,$revision);if($old['kind']!==$kind) throw new ConflictException('نوع برگه تغییر نمی‌کند.');FiscalService::assertOpen($cid,$old['invoice_date']); }
            $date=Business::date($in['invoice_date']??'');$due=Business::date($in['due_date']??$date);$fy=FiscalService::assertOpen($cid,$date);
            if($due<$date) throw new ValidationException(['due_date'=>'سررسید قبل از تاریخ برگه است.']);
            if(empty($in['party_id']))throw new ValidationException(['party_id'=>'شخص مورد نظر را انتخاب کنید.']);
            $party=Business::row('parties',$cid,Business::id($in['party_id']??0));
            if(!$party['is_active']||!$party[self::buying($kind)?'is_supplier':'is_customer']) throw new ValidationException(['party_id'=>'شخص فعال با نقش مناسب انتخاب کنید.']);
            $parent=Business::id($in['parent_id']??0);
            if($parent) {
                $source=self::get($cid,$parent);$pairs=['proforma'=>'sale_order','sale_order'=>'sale','purchase_request'=>'purchase_order','purchase_order'=>'purchase','sale'=>'sale_return','purchase'=>'purchase_return'];
                if(($pairs[$source['kind']]??'')!==$kind||$source['status']!=='issued'||(int)$source['party_id']!==(int)$party['id']||$source['invoice_date']>$date) throw new ConflictException('نوع، وضعیت، شخص یا تاریخ برگهٔ مبدأ سازگار نیست.');
                if(!self::returned($kind) && Db::val('SELECT id FROM '.Db::t('invoices')." WHERE company_id=%d AND parent_id=%d AND kind=%s AND status<>'void' AND id<>%d",$cid,$parent,$kind,$id)) throw new ConflictException('این برگه قبلاً تبدیل شده است.');
            } elseif(self::returned($kind)) throw new ValidationException(['parent_id'=>'برگشت باید به فاکتور اصلی مرتبط باشد.']);
            $discount=Business::amount($in['discount_amount']??0);$extra=Business::amount($in['extra_cost']??0);$basis=$in['cost_basis']??'value';
            [$rows,$amount]=self::price($cid,$kind,$date,$parent,$input,$discount,$extra,$basis);
            $deviations=SalesControlService::check($cid,$kind,$rows,$in['price_reason']??'');
            $new=['kind'=>$kind,'party_id'=>(int)$party['id'],'fiscal_year_id'=>(int)$fy['id'],'invoice_date'=>$date,'due_date'=>$due,'parent_id'=>$parent?:null,'description'=>Request::clean($in['description']??'',500),'discount_amount'=>$discount,'extra_cost'=>$extra,'cost_basis'=>$basis,'amount'=>$amount,'revision'=>(int)($old['revision']??0)+1];
            if(!$old) $new['created_by']=Ctx::uid();
            $id=Business::save('invoices',$cid,$id,$new);
            if($old) Db::delete('invoice_lines',['company_id'=>$cid,'invoice_id'=>$id]);
            foreach($rows as $idx=>$r) Business::save('invoice_lines',$cid,0,['invoice_id'=>$id,'line_no'=>$idx+1]+$r);
            SalesControlService::record($cid,$id,$deviations,$in['price_reason']??'');
            Audit::log('invoice.save','invoice',$id,$old?['revision'=>$old['revision']]:null,['kind'=>$kind,'amount'=>$amount],$cid);return $id;
        });
    }
    private static function line($cid,$sys,$amount,$debit,$detail=0) {
        return ['account_id'=>(int)AccountService::bySysKey($cid,$sys)['id'],'detail_id'=>$detail,'debit'=>$debit?$amount:0,'credit'=>$debit?0:$amount];
    }
    /** صدور مجدد همان پیش‌نویس رد می‌شود؛ idempotency در مرز HTTP کل تراکنش را پوشش می‌دهد. */
    public static function issue($cid,$id,$revision,array $paymentItems=[],array $advances=[]) {
        $initial=self::snapshot($cid,$id);$kind=$initial['kind'];
        return Access::write($cid,self::resource($kind).'.create',function()use($cid,$id,$revision,$paymentItems,$advances,$kind){
            $d=self::snapshot($cid,$id,true);self::revision($d,$revision);$fy=FiscalService::assertOpen($cid,$d['invoice_date']);
            $party=Business::row('parties',$cid,(int)$d['party_id']);
            if(!$party['is_active']||!$party[self::buying($kind)?'is_supplier':'is_customer']) throw new ConflictException('شخص غیرفعال یا نقش آن تغییر کرده است.');
            if($d['parent_id']) { $parent=self::get($cid,(int)$d['parent_id']);if($parent['status']!=='issued') throw new ConflictException('برگهٔ مبدأ صادرشده نیست.'); }
            // نرخ پیش‌نویس عادی در صدور بازبینی می‌شود؛ برگشت نرخ و گردکردن snapshot مبدأ را دارد.
            [$rows,$amount]=self::price($cid,$kind,$d['invoice_date'],(int)$d['parent_id'],array_map(function($l){foreach(['qty_scaled','unit_price','discount_amount'] as $k)$l[$k]=(int)$l[$k];return $l;},$d['lines']),(int)$d['discount_amount'],(int)$d['extra_cost'],$d['cost_basis']);
            if($amount!==(int)$d['amount'])throw new ConflictException('قیمت یا مالیات پیش‌نویس تغییر کرده است؛ ابتدا پیش‌نویس را دوباره ذخیره و بازبینی کنید.');
            foreach($rows as $idx=>$row)if((int)$row['tax_bp']!==(int)$d['lines'][$idx]['tax_bp'])throw new ConflictException('نرخ مالیات تغییر کرده است؛ پیش‌نویس را دوباره ذخیره کنید.');
            $mode=Settings::get('purchase_goods_mode',$cid);$journal=[];$buy=self::buying($kind);$return=self::returned($kind);$costTotal=0;
            foreach($rows as $idx=>$r) {
                $stored=$d['lines'][$idx];$item=Business::row('items',$cid,(int)$r['item_id']);
                if(!$item['is_active']) throw new ConflictException('کالای غیرفعال قابل صدور نیست.');
                $r['total_cost']=0;
                if($item['kind']==='goods' && self::financial($kind)) {
                    $incoming=in_array($kind,['purchase','sale_return'],true);
                    $cost=$kind==='sale_return'?$rows[$idx]['total_cost']:Money::add($r['net_amount'],$r['extra_cost']);
                    if($kind==='sale_return') $cost=self::priceReturnCost($cid,$r);
                    $r['total_cost']=StockService::move($cid,(int)$r['warehouse_id'],(int)$r['item_id'],$d['invoice_date'],$incoming?$r['qty_scaled']:-$r['qty_scaled'],$incoming?$cost:0,'invoice',$id,(int)$stored['id']);
                    $costTotal=Money::add($costTotal,$r['total_cost']);
                }
                Db::update('invoice_lines',$r,['company_id'=>$cid,'id'=>$stored['id']]);
                if(!self::financial($kind)) continue;
                $net=Money::add($r['net_amount'],$r['extra_cost']);
                if($buy) {
                    if($net) {
                        $account=$item['kind']==='goods'?AccountService::bySysKey($cid,$mode==='inventory'?'inventory':($return?'purchase_return':'purchases')):Business::row('accounts',$cid,(int)$item['purchase_account_id']);
                        $value=$item['kind']==='goods'&&$mode==='inventory'?$r['total_cost']:$net;
                        if($value) $journal[]=['account_id'=>(int)$account['id'],'debit'=>$return?0:$value,'credit'=>$return?$value:0];
                        if($return&&$mode==='inventory'&&$item['kind']==='goods'&&$net!==$value) $journal[]=self::line($cid,'cogs',abs($net-$value),$net<$value);
                    }
                } else {
                    if($return) { if($net)$journal[]=self::line($cid,'sales_return',$net,true); }
                    else {
                        if($r['gross_amount'])$journal[]=['account_id'=>(int)$item['sale_account_id'],'credit'=>$r['gross_amount'],'debit'=>0];
                        $disc=Money::add($r['discount_amount'],$r['header_discount']);if($disc)$journal[]=self::line($cid,'sales_discount',$disc,true);
                    }
                }
                if($r['tax_amount']) $journal[]=self::line($cid,$buy?'vat_input':'vat_output',$r['tax_amount'],$buy!==$return);
            }
            if(self::financial($kind)) {
                $journal[]=self::line($cid,$buy?'payable':'receivable',$amount,$buy===$return,(int)$party['detail_id']);
                if(!$buy && $mode==='inventory' && $costTotal) { $journal[]=self::line($cid,'cogs',$costTotal,!$return);$journal[]=self::line($cid,'inventory',$costTotal,$return); }
                $vid=VoucherService::postSystem(['voucher_date'=>$d['invoice_date'],'description'=>self::KINDS[$kind].' '.$d['description'],'source_type'=>'invoice_'.$kind,'source_id'=>$id],$journal);
            } else { if($paymentItems||$advances)throw new ValidationException(['payment'=>'برگهٔ غیرمالی تسویه نمی‌پذیرد.']);$vid=null; }
            $company=Db::row('SELECT * FROM '.Db::t('companies').' WHERE id=%d',$cid);
            $print=['company'=>array_intersect_key($company,array_flip(['name','legal_name','address','phone','economic_code','registration_no','postal_code','national_id_enc'])),'party'=>array_intersect_key($party,array_flip(['name','kind','address','economic_code','national_id_hint','national_id_enc','postal_code','registration_no','phone'])),'logo_id'=>(int)(get_option('agah_erp_logo',0)?:get_theme_mod('custom_logo',0))];
            Db::update('invoices',['print_snapshot'=>wp_json_encode($print),'status'=>'issued','number'=>Sequence::next($cid,'invoice_'.$kind,(int)$fy['id']),'voucher_id'=>$vid,'amount'=>$amount,'goods_mode'=>$mode,'cost_method'=>Settings::get('stock_cost_method',$cid),'revision'=>$revision+1],['company_id'=>$cid,'id'=>$id]);
            if($advances) self::applyAdvances($cid,$id,$advances);
            if($paymentItems) {
                $paid=0;foreach($paymentItems as $p)$paid=Money::add($paid,Business::amount($p['amount']??0,true));
                TreasuryService::issue($cid,['kind'=>in_array($kind,['sale','purchase_return'],true)?'receipt':'payment','doc_date'=>$d['invoice_date'],'party_id'=>(int)$party['id'],'description'=>'تسویه فاکتور '.$id],$paymentItems,[['invoice_id'=>$id,'amount'=>$paid]]);
            }
            \Agah\Erp\Modules\Moadian\MoadianService::autoSelect($cid,$id);
            Audit::log('invoice.issue','invoice',$id,['status'=>'draft'],['kind'=>$kind,'amount'=>$amount,'voucher_id'=>$vid],$cid);return $id;
        });
    }
    /** هزینهٔ برگشت فروش از مقدار تجمعی snapshot بهای تمام‌شدهٔ خط اصلی محاسبه می‌شود. */
    private static function priceReturnCost($cid,$r) {
        $s=Business::row('invoice_lines',$cid,(int)$r['source_line_id']);
        return TradeMath::portion((int)$s['total_cost'],self::returnedQty($cid,(int)$s['id']),$r['qty_scaled'],(int)$s['qty_scaled']);
    }
    /** تبدیل فقط یک بار و با پیوند مبدأ؛ برگشت مقدار مانده را پیشنهاد می‌دهد و پیش‌نویس قابل ویرایش است. */
    public static function convert($cid,$id,$date) {
        $d=self::get($cid,$id);$next=['proforma'=>'sale_order','sale_order'=>'sale','purchase_request'=>'purchase_order','purchase_order'=>'purchase','sale'=>'sale_return','purchase'=>'purchase_return'][$d['kind']]??'';
        if(!$next)throw new ConflictException('این نوع برگه تبدیل بعدی ندارد.');
        return Access::write($cid,self::resource($next).'.create',function()use($cid,$id,$date,$next){
            $d=self::get($cid,$id);$input=[];
            foreach($d['lines'] as $l) {
                $qty=(int)$l['qty_scaled'];if(self::returned($next))$qty-=self::returnedQty($cid,(int)$l['id']);if(!$qty)continue;
                $input[]=['item_id'=>(int)$l['item_id'],'warehouse_id'=>(int)$l['warehouse_id'],'qty_scaled'=>$qty,'unit_price'=>(int)$l['unit_price'],'discount_amount'=>(int)$l['discount_amount'],'tax_rate_id'=>$l['tax_rate_id'],'source_line_id'=>(int)$l['id']];
            }
            return self::save($cid,0,['kind'=>$next,'parent_id'=>$id,'party_id'=>(int)$d['party_id'],'invoice_date'=>$date,'due_date'=>max($date,$d['due_date']),'description'=>$d['description'],'discount_amount'=>self::returned($next)?0:(int)$d['discount_amount']],$input);
        });
    }
    /** خالص مبلغ برگشت/تسویه برای aging و کنترل سقف خزانه؛ برگهٔ برگشتی خودش نیز قابل تسویه است. */
    public static function outstanding($cid,$id) {
        if(Ctx::cidOrZero()!==(int)$cid)throw new NotFoundException();$d=Business::row('invoices',$cid,$id);
        $returns=(int)Db::val('SELECT COALESCE(SUM(amount),0) FROM '.Db::t('invoices')." WHERE company_id=%d AND parent_id=%d AND kind IN ('sale_return','purchase_return') AND status='issued'",$cid,$id);
        $settled=(int)Db::val('SELECT COALESCE(SUM(amount),0) FROM '.Db::t('settlements')." WHERE company_id=%d AND invoice_id=%d AND status='active'",$cid,$id);
        $adv=(int)Db::val('SELECT COALESCE(SUM(amount),0) FROM '.Db::t('invoice_advances')." WHERE company_id=%d AND invoice_id=%d AND status='active'",$cid,$id);
        if(self::returned($d['kind'])) {
            // فقط بخش اضافه‌پرداختِ فاکتور اصلی قابل استرداد نقدی است؛ باقی برگشت، بدهی را کاهش داده است.
            $parent=Business::row('invoices',$cid,(int)$d['parent_id']);
            $paid=(int)Db::val('SELECT COALESCE(SUM(amount),0) FROM '.Db::t('settlements')." WHERE company_id=%d AND invoice_id=%d AND status='active'",$cid,$parent['id']);
            $parentAdv=(int)Db::val('SELECT COALESCE(SUM(amount),0) FROM '.Db::t('invoice_advances')." WHERE company_id=%d AND invoice_id=%d AND status='active'",$cid,$parent['id']);
            $returned=(int)Db::val('SELECT COALESCE(SUM(amount),0) FROM '.Db::t('invoices')." WHERE company_id=%d AND parent_id=%d AND status='issued' AND kind=%s",$cid,$parent['id'],$d['kind']);
            $refunds=(int)Db::val('SELECT COALESCE(SUM(s.amount),0) FROM '.Db::t('settlements').' s JOIN '.Db::t('invoices')." i ON i.company_id=s.company_id AND i.id=s.invoice_id WHERE s.company_id=%d AND i.parent_id=%d AND i.status='issued' AND s.status='active'",$cid,$parent['id']);
            return max(0,min((int)$d['amount']-$settled,Money::add(Money::add($paid,$parentAdv),$returned)-(int)$parent['amount']-$refunds));
        }
        $refunds=(int)Db::val('SELECT COALESCE(SUM(s.amount),0) FROM '.Db::t('settlements').' s JOIN '.Db::t('invoices')." i ON i.company_id=s.company_id AND i.id=s.invoice_id WHERE s.company_id=%d AND i.parent_id=%d AND i.status='issued' AND i.kind IN ('sale_return','purchase_return') AND s.status='active'",$cid,$id);
        return max(0,Money::add(Money::add(Money::add((int)$d['amount'],-$returns),-Money::add($settled,$adv)),$refunds));
    }
    /** پیش‌دریافت/پرداخت واقعی با سند انتقال از حساب پیش‌پرداخت به حساب شخص تخصیص می‌یابد. */
    private static function applyAdvances($cid,$id,array $advances) {
        $d=Business::row('invoices',$cid,$id);if(!in_array($d['kind'],['sale','purchase'],true)||count($advances)>80)throw new ValidationException(['advance'=>'پیش‌پرداخت فقط برای فروش/خرید است.']);
        $buy=$d['kind']==='purchase';Ctx::need($buy?'payment.create':'receipt.create');$p=Business::row('parties',$cid,(int)$d['party_id']);$lines=[];
        foreach($advances as $a) {
            $doc=Business::row('treasury_docs',$cid,Business::id($a['doc_id']??0),true);$amount=Business::amount($a['amount']??0,true);
            if(!$doc['advance']||$doc['status']!=='issued'||$doc['kind']!==($buy?'payment':'receipt')||(int)$doc['party_id']!==(int)$d['party_id']||$doc['doc_date']>$d['invoice_date'])throw new ConflictException('برگهٔ پیش‌پرداخت با فاکتور سازگار نیست.');
            if(Db::val('SELECT id FROM '.Db::t('cheques')." WHERE company_id=%d AND doc_id=%d AND status<>'collected'",$cid,$doc['id']))throw new ConflictException('پیش‌پرداخت چکی باید ابتدا وصول شده باشد.');
            if($amount>TreasuryService::advanceRemaining($cid,(int)$doc['id'])||$amount>self::outstanding($cid,$id))throw new ValidationException(['advance'=>'تخصیص بیش از ماندهٔ پیش‌پرداخت یا فاکتور است.']);
            Business::save('invoice_advances',$cid,0,['invoice_id'=>$id,'doc_id'=>(int)$doc['id'],'amount'=>$amount]);
            $lines[]=self::line($cid,$buy?'prepayments':'customer_advances',$amount,!$buy,(int)$p['detail_id']);$lines[]=self::line($cid,$buy?'payable':'receivable',$amount,$buy,(int)$p['detail_id']);
        }
        VoucherService::postSystem(['voucher_date'=>$d['invoice_date'],'description'=>'اعمال پیش‌پرداخت فاکتور','source_type'=>'invoice_advance','source_id'=>$id],$lines);
    }
    /** ابطال تمام تسویه‌های وابسته را در همان تراکنش برمی‌گرداند؛ تسویهٔ مشترک ابتدا باید جداگانه ابطال شود. */
    public static function voidDocument($cid,$id,$reason) {
        $first=self::snapshot($cid,$id);
        return Access::write($cid,self::resource($first['kind']).'.delete',function()use($cid,$id,$reason){
            $d=self::snapshot($cid,$id,true);$reason=Business::reason($reason);
            if($d['status']==='void') throw new ConflictException('برگه قبلاً باطل شده است.');FiscalService::assertOpen($cid,$d['invoice_date']);
            if(Db::val('SELECT id FROM '.Db::t('invoices')." WHERE company_id=%d AND parent_id=%d AND status<>'void' LIMIT 1",$cid,$id)) throw new ConflictException('ابتدا برگه‌های وابسته/برگشت را باطل کنید.');
            \Agah\Erp\Modules\Moadian\MoadianService::beforeVoid($cid,$id);
            foreach(Db::rows('SELECT DISTINCT doc_id FROM '.Db::t('settlements')." WHERE company_id=%d AND invoice_id=%d AND status='active'",$cid,$id) as $s) {
                // استرداد ثبت‌شده از فروشگاه واقعی است و باطل نمی‌شود؛ فقط پیوندش با این برگشت برداشته و استرداد دوباره «در انتظار دریافت کالا» می‌شود.
                if($cr=Db::row('SELECT id FROM '.Db::t('connect_refunds').' WHERE company_id=%d AND treasury_doc_id=%d AND invoice_id=%d',$cid,(int)$s['doc_id'],$id)){
                    Db::exec('UPDATE '.Db::t('settlements')." SET status='reversed',updated_at=%s WHERE company_id=%d AND doc_id=%d AND invoice_id=%d AND status='active'",gmdate('Y-m-d H:i:s'),$cid,(int)$s['doc_id'],$id);
                    Db::update('connect_refunds',['status'=>'awaiting','invoice_id'=>null,'note'=>'برگشت فروش قبلی باطل شد؛ دریافت کالا دوباره ثبت شود.','updated_at'=>gmdate('Y-m-d H:i:s')],['company_id'=>$cid,'id'=>(int)$cr['id']]);continue;
                }
                if(Db::val('SELECT id FROM '.Db::t('settlements')." WHERE company_id=%d AND doc_id=%d AND invoice_id<>%d AND status='active' LIMIT 1",$cid,$s['doc_id'],$id)) throw new ConflictException('برگه خزانه چند فاکتور را تسویه کرده است؛ ابتدا آن برگه را جداگانه ابطال کنید.');
                TreasuryService::voidDocument($cid,(int)$s['doc_id'],$reason);
            }
            $advanceVoucher=Db::val('SELECT id FROM '.Db::t('vouchers')." WHERE company_id=%d AND source_type='invoice_advance' AND source_id=%d AND status<>'void'",$cid,$id);
            if($advanceVoucher)VoucherService::voidSystem((int)$advanceVoucher,$reason);
            Db::exec('UPDATE '.Db::t('invoice_advances')." SET status='void' WHERE company_id=%d AND invoice_id=%d",$cid,$id);
            StockService::reverseSource($cid,'invoice',$id);
            if($d['voucher_id'])VoucherService::voidSystem((int)$d['voucher_id'],$reason);
            Db::update('invoices',['status'=>'void','revision'=>(int)$d['revision']+1],['company_id'=>$cid,'id'=>$id]);Audit::log('invoice.void','invoice',$id,['status'=>$d['status']],['reason'=>$reason],$cid);
        });
    }
    /** فهرست و جمع‌ها با فیلتر سرور و ترتیب ثابت؛ خروجی‌ها از همین reader استفاده می‌کنند. */
    public static function page($cid,$kind,array $filters=[],$page=1) {
        Access::need($cid,self::resource($kind).'.view');$where='i.company_id=%d AND i.kind=%s';$args=[$cid,$kind];
        foreach(['party_id','created_by'] as $key)if(!empty($filters[$key])){$where.=' AND i.'.$key.'=%d';$args[]=Business::id($filters[$key]);}
        foreach(['from'=>'>=','to'=>'<='] as $key=>$op)if(!empty($filters[$key])){$where.=' AND i.invoice_date'.$op.'%s';$args[]=Business::date($filters[$key]);}
        if(!empty($filters['status'])) {if(!in_array($filters['status'],['draft','issued','void'],true))throw new ValidationException(['status'=>'وضعیت معتبر نیست.']);$where.=' AND i.status=%s';$args[]=$filters['status'];}
        $page=max(1,(int)$page);$count=(int)Db::val('SELECT COUNT(*) FROM '.Db::t('invoices').' i WHERE '.$where,...$args);
        $total=(int)Db::val('SELECT COALESCE(SUM(i.amount),0) FROM '.Db::t('invoices').' i WHERE '.$where,...$args);
        $rows=Db::rows('SELECT i.*,p.name AS party_name FROM '.Db::t('invoices').' i JOIN '.Db::t('parties').' p ON p.company_id=i.company_id AND p.id=i.party_id WHERE '.$where.' ORDER BY i.invoice_date DESC,i.id DESC LIMIT 50 OFFSET %d',...array_merge($args,[($page-1)*50]));
        return ['rows'=>$rows,'total'=>$total,'count'=>$count,'page'=>$page,'pages'=>(int)ceil($count/50)];
    }
    public static function aging($cid,$kind,$asOf,$page=1) {
        if(!in_array($kind,['sale','purchase'],true))throw new ValidationException(['kind'=>'نوع گزارش معتبر نیست.']);Business::date($asOf);
        $data=self::page($cid,$kind,['status'=>'issued','to'=>$asOf],$page);
        foreach($data['rows'] as &$r) { $r['outstanding']=self::outstanding($cid,(int)$r['id']);$r['days']=(int)(new \DateTimeImmutable($r['due_date']))->diff(new \DateTimeImmutable($asOf))->format('%r%a');$r['bucket']=$r['days']<=0?'سررسید نشده':($r['days']<=30?'۱ تا ۳۰ روز':($r['days']<=60?'۳۱ تا ۶۰ روز':($r['days']<=90?'۶۱ تا ۹۰ روز':'بیش از ۹۰ روز'))); }unset($r);
        return $data;
    }
    /** گزارش خالص فروش/خرید: برگشت‌ها منفی‌اند؛ سود ناخالص از snapshot هزینهٔ همان خط است. */
    public static function report($cid,$kind,$group,$from,$to,$page=1) {
        Access::need($cid,self::resource($kind).'.view');if(!in_array($kind,['sale','purchase'],true))throw new ValidationException(['kind'=>'نوع گزارش معتبر نیست.']);
        $groups=['party'=>'i.party_id','item'=>'l.item_id','seller'=>'i.created_by','period'=>"DATE_FORMAT(i.invoice_date,'%%Y-%%m')"];
        if(!isset($groups[$group]))throw new ValidationException(['group'=>'گروه گزارش معتبر نیست.']);Business::date($from);Business::date($to);if($from>$to)throw new ValidationException(['from'=>'بازه معکوس است.']);
        $expr=$groups[$group];$page=max(1,(int)$page);$sign="IF(i.kind=%s,1,-1)";
        $base=' FROM '.Db::t('invoices').' i JOIN '.Db::t('invoice_lines')." l ON l.company_id=i.company_id AND l.invoice_id=i.id WHERE i.company_id=%d AND i.status='issued' AND i.kind IN (%s,%s) AND i.invoice_date BETWEEN %s AND %s GROUP BY ".$expr;
        $rows=Db::rows('SELECT '.$expr.' AS group_key,SUM('.$sign.'*CAST(l.net_amount AS SIGNED)) AS net,SUM('.$sign.'*CAST(l.tax_amount AS SIGNED)) AS tax,SUM('.$sign.'*CAST(l.total_cost AS SIGNED)) AS cost,SUM('.$sign.'*CAST(l.qty_scaled AS SIGNED)) AS qty'.$base.' ORDER BY group_key LIMIT 50 OFFSET %d',$kind,$kind,$kind,$kind,$cid,$kind,$kind.'_return',$from,$to,($page-1)*50);
        foreach($rows as &$r){$r['label']=$r['group_key'];if($group==='party')$r['label']=Business::row('parties',$cid,Business::id($r['group_key']))['name'];if($group==='item')$r['label']=Business::row('items',$cid,Business::id($r['group_key']))['name'];if($group==='seller'){$u=get_userdata((int)$r['group_key']);$r['label']=$u?$u->display_name:'کاربر حذف‌شده';}$r['profit']=Money::add((int)$r['net'],-(int)$r['cost']);}unset($r);
        $count=(int)Db::val('SELECT COUNT(*) FROM (SELECT '.$expr.$base.') grouped',$cid,$kind,$kind.'_return',$from,$to);
        return ['rows'=>$rows,'page'=>$page,'pages'=>(int)ceil($count/50)];
    }
}
