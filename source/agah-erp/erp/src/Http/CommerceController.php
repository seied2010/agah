<?php
/** اتصال مراحل ۴ تا ۶ به کنترلر PRG مشترک؛ هیچ ورودی شرکت/مجوز از دادهٔ فرم استنتاج نمی‌شود. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Ctx,Request,Money,Jalali,Business,Db,ValidationException,NotFoundException};
use Agah\Erp\Modules\Parties\PartyService;
use Agah\Erp\Modules\Catalog\CatalogService;
use Agah\Erp\Modules\Treasury\TreasuryService;
if(!defined('ABSPATH')) exit;
final class CommerceController {
    public static function nav(){return ProductionController::nav()+TradeController::nav()+['parties'=>['اشخاص','party.view'],'items'=>['کالا و خدمات','item.view'],'units'=>['واحدها','item.view'],'groups'=>['گروه کالا','item.view'],'taxes'=>['نرخ مالیات','tax_rate.view'],'treasury_accounts'=>['بانک و صندوق','treasury_account.view'],'receipts'=>['دریافت','receipt.view'],'payments'=>['پرداخت','payment.view'],'transfers'=>['انتقال وجه','transfer.view'],'cheques'=>['چک‌ها','cheque.view'],'bank_reconciliation'=>['مغایرت‌گیری بانک','treasury_account.view'],'cash_forecast'=>['گردش و پیش‌بینی نقدینگی','treasury_account.view']];}
    /** مجوز متناسب نوع برگه/داده، پیش از claim نیز کنترل می‌شود. */
    public static function actions(){
        $type=Request::str('post','entity',10);$kind=Request::str('post','kind',10);if(!isset(TreasuryService::KINDS[$kind]))$kind='receipt';
        return array_merge(ProductionController::actions(),TradeController::actions(), ['module_party_save'=>Request::int('post','id')?'party.edit':'party.create','module_party_delete'=>'party.delete','module_party_reveal'=>'party.sensitive','module_catalog_save'=>$type==='tax'?'tax_rate.edit':(Request::int('post','id')?'item.edit':'item.create'),'module_catalog_delete'=>$type==='tax'?'tax_rate.edit':'item.delete','module_treasury_account_save'=>Request::int('post','id')?'treasury_account.edit':'treasury_account.create','module_treasury_account_delete'=>'treasury_account.delete','module_treasury_issue'=>$kind.'.create','module_treasury_void'=>$kind.'.delete','module_cheque_transition'=>'cheque.approve','module_bank_reconcile'=>'treasury_account.edit']);
    }
    public static function preflight($p,$cid){if(ProductionController::preflight($p,$cid))return true;if(TradeController::preflight($p,$cid))return true;$id=Request::int('get','id');$edit=Request::int('get','edit');
        if($p==='party_view'){Ctx::need('party.view');PartyService::get($cid,$id);return true;}
        if($p==='treasury_new'){$kind=Request::str('get','kind',10,'receipt');if(!isset(TreasuryService::KINDS[$kind]))throw new NotFoundException();Ctx::need($kind.'.create');return true;}
        if($p==='treasury_view'){TreasuryService::get($cid,$id);return true;}
        if($p==='cheque_print'){TreasuryService::cheque($cid,$id);return true;}
        $map=['items'=>'item','units'=>'unit','groups'=>'group','taxes'=>'tax'];if(isset($map[$p])&&$edit)CatalogService::get($cid,$map[$p],$edit);
        if($p==='parties'&&$edit)PartyService::get($cid,$edit);
        if($p==='treasury_accounts'&&$edit)TreasuryService::account($cid,$edit);
        if($p==='bank_reconciliation'&&$id)TreasuryService::reconciliation($cid,$id);
        return isset(self::nav()[$p]);
    }
    private static function fields($keys){$in=[];foreach($keys as $k)$in[$k]=Request::str('post',$k,500);return $in;}
    private static function amount($key){return Money::fromInput(Request::str('post',$key,40),Ctx::unit());}
    public static function dispatch($act,$cid,$url){if(isset(ProductionController::actions()[$act]))return ProductionController::dispatch($act,$cid,$url);$id=Request::int('post','id');
        switch($act){
            case 'module_party_save':$in=self::fields(['name','kind','national_id','economic_code','phone','email','address','postal_code','registration_no']);foreach(['is_customer','is_supplier','is_employee','is_active','clear_national_id'] as $k)$in[$k]=Request::flag('post',$k);$in['credit_limit']=self::amount('credit_limit');PartyService::save($cid,$id,$in);return $url;
            case 'module_party_delete':PartyService::delete($cid,$id);return $url;
            case 'module_catalog_save':
                $type=Request::str('post','entity',10);$in=self::fields(['name','code','kind','barcode']);$in['is_active']=Request::flag('post','is_active');
                if($type==='tax'){$in['bp']=Money::pctBp(Request::str('post','percent',10));$in['valid_from']=Jalali::parse(Request::str('post','valid_from',10));$in['valid_to']=Jalali::parse(Request::str('post','valid_to',10));$in['confirmed']=Request::flag('post','confirmed');}
                if($type==='item'){foreach(['unit_id','group_id','tax_rate_id','sale_account_id','purchase_account_id'] as $k)$in[$k]=Request::int('post',$k);$in['sale_price']=self::amount('sale_price');$in['purchase_price']=self::amount('purchase_price');$in['trade_price']=self::amount('trade_price');$in['attrs']=Request::text('post','attrs',4000);}
                CatalogService::save($cid,$type,$id,$in);return $url;
            case 'module_catalog_delete':CatalogService::delete($cid,Request::str('post','entity',10),$id);return $url;
            case 'module_treasury_account_save':$in=self::fields(['name','code','kind','bank_name','account_no','iban']);$in['is_active']=Request::flag('post','is_active');TreasuryService::saveAccount($cid,$id,$in);return $url;
            case 'module_treasury_account_delete':TreasuryService::deleteAccount($cid,$id);return $url;
            case 'module_treasury_issue':
                $in=self::fields(['kind','description']);$in['doc_date']=Jalali::parse(Request::str('post','doc_date',10));foreach(['party_id','source_account_id','target_account_id'] as $k)$in[$k]=Request::int('post',$k);$in['advance']=Request::flag('post','advance');$in['amount']=self::amount('amount');
                $raw=self::rows('items');$items=[];foreach($raw as $row){if(($row['amount']??'')===''&&empty($row['treasury_account_id']))continue;$row['amount']=Money::fromInput($row['amount']??'',Ctx::unit());$row['treasury_account_id']=Business::id($row['treasury_account_id']??'0');if(($row['method']??'')==='cheque')$row['due_date']=Jalali::parse($row['due_date']??'');$items[]=$row;}
                $alloc=[];foreach(self::rows('allocations') as $r){if(empty($r['invoice_id'])&&($r['amount']??'')==='')continue;$alloc[]=['invoice_id'=>Business::id($r['invoice_id']??'0'),'amount'=>Money::fromInput($r['amount']??'',Ctx::unit())];}
                $doc=TreasuryService::issue($cid,$in,$items,$alloc);return agah_erp_url(['c'=>$cid,'p'=>'treasury_view','id'=>$doc]);
            case 'module_treasury_void':TreasuryService::voidDocument($cid,$id,Request::str('post','reason',300));return $url;
            case 'module_cheque_transition':TreasuryService::chequeTransition($cid,$id,Request::str('post','status',10),Jalali::parse(Request::str('post','event_date',10)),Request::str('post','reason',300),Request::int('post','revision'));return $url;
            case 'module_bank_reconcile':
                $matches=isset($_POST['matched'])&&is_array($_POST['matched'])?wp_unslash($_POST['matched']):[];
                $rid=TreasuryService::reconcile($cid,$id,['treasury_account_id'=>Request::int('post','treasury_account_id'),'as_of_date'=>Jalali::parse(Request::str('post','as_of_date',10)),'statement_balance'=>self::amount('statement_balance'),'notes'=>Request::str('post','notes',500)],$matches,Request::int('post','revision'));return agah_erp_url(['c'=>$cid,'p'=>'bank_reconciliation','id'=>$rid]);
        }
        return TradeController::dispatch($act,$cid,$url);
    }
    /** آرایه‌ها با سقف و scalar validation خوانده می‌شوند؛ ورودی nested مخرب پذیرفته نمی‌شود. */
    private static function rows($key){$rows=isset($_POST[$key])&&is_array($_POST[$key])?wp_unslash($_POST[$key]):[];if(count($rows)>100)throw new ValidationException([$key=>'حداکثر صد ردیف مجاز است.']);foreach($rows as $r){if(!is_array($r))throw new ValidationException([$key=>'ردیف معتبر نیست.']);foreach($r as $v)if(!is_scalar($v))throw new ValidationException([$key=>'مقدار معتبر نیست.']);}return $rows;}
    /** نماهای جدید در همان scope کنترلر مشترک اجرا می‌شوند تا helperهای فرم/خطا حفظ شوند. */
    public static function view($p){if(ProductionController::view($p))return ProductionController::view($p);if(TradeController::view($p))return TradeController::view($p);$m=['parties'=>'business-parties','party_view'=>'business-party','items'=>'business-catalog','units'=>'business-catalog','groups'=>'business-catalog','taxes'=>'business-catalog','treasury_accounts'=>'business-treasury-accounts','receipts'=>'business-docs','payments'=>'business-docs','transfers'=>'business-docs','treasury_new'=>'business-treasury-form','treasury_view'=>'business-treasury-document','cheques'=>'business-cheques','cheque_print'=>'business-cheque-print','bank_reconciliation'=>'business-reconciliation','cash_forecast'=>'business-cash'];return $m[$p]??null;}
    /** خروجی CSV تمام صفحات با مجوز مستقل؛ شناسهٔ ملی آشکار یا ciphertext هرگز صادر نمی‌شود. */
    public static function export($cid){
        $type=Request::str('get','business_export',30);
        $cap=['parties'=>'party.export','items'=>'item.export','units'=>'item.export','groups'=>'item.export','receipts'=>'receipt.export','payments'=>'payment.export','transfers'=>'transfer.export','cheques'=>'cheque.export'][$type]??'';
        if(!$cap)throw new ValidationException(['export'=>'نوع خروجی معتبر نیست.']);Ctx::need($cap);
        if(!wp_verify_nonce(Request::str('get','_n',100),'agah_business_export_'.$cid.'_'.$type))throw new ValidationException(['form'=>'اعتبار خروجی پایان یافته است.']);
        $reader=function($page)use($cid,$type){
            if($type==='parties')return PartyService::page($cid,$page,Request::str('get','q',100));
            if(isset(['items'=>1,'units'=>1,'groups'=>1][$type]))return CatalogService::page($cid,['items'=>'item','units'=>'unit','groups'=>'group'][$type],$page,Request::str('get','q',100));
            if($type==='cheques')return TreasuryService::cheques($cid,$page,Request::str('get','status',10));
            return TreasuryService::documents($cid,['receipts'=>'receipt','payments'=>'payment','transfers'=>'transfer'][$type],$page,(int)(\Agah\Erp\Core\FiscalContext::selected($cid)['id']??0));
        };
        Db::tx(function()use($type,$reader){
            $data=$reader(1);$columns=['parties'=>['code','name','kind','national_id_hint','phone','email','credit_limit','is_customer','is_supplier','is_employee','is_active'],'items'=>['code','name','kind','barcode','sale_price','purchase_price','is_active'],'units'=>['code','name','is_active'],'groups'=>['code','name','is_active'],'receipts'=>['number','doc_date','description','amount','status'],'payments'=>['number','doc_date','description','amount','status'],'transfers'=>['number','doc_date','description','amount','status'],'cheques'=>['cheque_no','direction','due_date','amount','status']][$type];
            if(($data['count']??0)>10000||$data['pages']>200)throw new ValidationException(['export'=>'بازه خروجی را کوتاه‌تر کنید.']);$rows=[];
            for($page=1;$page<=max(1,$data['pages']);$page++)foreach(($page===1?$data:$reader($page))['rows'] as $r){$line=[];foreach($columns as $col)$line[]=\Agah\Erp\Core\Tabular::cell($col,$r[$col]??'');$rows[]=$line;}
            \Agah\Erp\Core\Tabular::download('agah-'.$type,$columns,$rows);
        });
    }
    public static function exportLink($cid,$type){$cap=['parties'=>'party.export','items'=>'item.export','units'=>'item.export','groups'=>'item.export','receipts'=>'receipt.export','payments'=>'payment.export','transfers'=>'transfer.export','cheques'=>'cheque.export'][$type]??'';if($cap&&Ctx::can($cap))foreach(['csv'=>'CSV','xlsx'=>'Excel XLSX'] as $format=>$label)echo '<a class="btn btn-outline erp-no-print" href="'.esc_url(add_query_arg(['business_export'=>$type,'format'=>$format,'_n'=>wp_create_nonce('agah_business_export_'.$cid.'_'.$type),'pg'=>false])).'">'.$label.' تمام ردیف‌ها (مبالغ '.Money::unitLabel(Ctx::unit()).')</a>' ;}

}
