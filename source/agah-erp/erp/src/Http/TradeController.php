<?php
/** آداپتور HTTP مراحل ۷–۹: ورودی شمسی/تومان، PRG و idempotency مشترک؛ هیچ منطق مالی در نما نیست. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Access,Business,Ctx,Db,Jalali,Money,Request,Settings,ValidationException};
use Agah\Erp\Modules\Trade\InvoiceService;
use Agah\Erp\Modules\Inventory\StockService;
if (!defined('ABSPATH')) exit;
final class TradeController {
    public static function nav() {
        $out=[];foreach(InvoiceService::KINDS as $kind=>$label)$out['trade_'.$kind]=[$label,InvoiceService::resource($kind).'.view'];
        return FinanceController::nav()+$out+['warehouses'=>['انبارها','warehouse.view'],'stock_docs'=>['ورود، خروج و شمارش','stock.view'],'inventory'=>['موجودی و کاردکس','stock.view'],'trade_aging'=>['سررسید مطالبات','sale_invoice.view'],'trade_report'=>['گزارش فروش','sale_invoice.view'],'purchase_aging'=>['سررسید بدهی','purchase_invoice.view'],'purchase_report'=>['گزارش خرید','purchase_invoice.view']];
    }
    public static function actions() {
        $kind=Request::str('post','kind',24,'sale');if(!isset(InvoiceService::KINDS[$kind]))$kind='sale';
        $act=Request::str('post','act',40);
        if(in_array($act,['module_invoice_issue','module_invoice_void','module_invoice_official'],true))$kind=Business::row('invoices',Ctx::cid(),Request::int('post','id'))['kind'];
        $r=InvoiceService::resource($kind);
        return FinanceController::actions()+['module_invoice_official'=>$r.'.export','module_invoice_save'=>$r.(Request::int('post','id')?'.edit':'.create'),'module_invoice_issue'=>$r.'.create','module_invoice_void'=>$r.'.delete','module_invoice_convert'=>'','module_warehouse_save'=>Request::int('post','id')?'warehouse.edit':'warehouse.create','module_stock_issue'=>'stock.create','module_stock_void'=>'stock.delete','module_stock_config'=>'company_settings.edit','module_stock_minimum'=>'warehouse.edit'];
    }
    /** تبدیل، مجوز مقصد را در سرویس می‌سنجد؛ مقدار خالی در کنترلر مشترک مجاز نیست. */
    public static function convertCap($cid,$id) {
        $d=InvoiceService::get($cid,$id);$next=['proforma'=>'sale_order','sale_order'=>'sale','purchase_request'=>'purchase_order','purchase_order'=>'purchase','sale'=>'sale_return','purchase'=>'purchase_return'][$d['kind']]??'sale';return InvoiceService::resource($next).'.create';
    }
    public static function cap($p) {
        if(FinanceController::cap($p))return FinanceController::cap($p);
        if(isset(self::nav()[$p])) {
            if(in_array($p,['trade_report','trade_aging'],true))return InvoiceService::resource(Request::str('get','kind',24,'sale')).'.view';
            return self::nav()[$p][1];
        }
        if($p==='invoice_new') return InvoiceService::resource(Request::str('get','kind',24,'sale')).(Request::int('get','id')?'.edit':'.create');
        if($p==='invoice_view')return InvoiceService::resource(InvoiceService::get(Ctx::cid(),Request::int('get','id'))['kind']).'.view';
        return null;
    }
    public static function preflight($p,$cid) {
        if(FinanceController::preflight($p,$cid))return true;
        $cap=self::cap($p);if(!$cap)return false;Access::need($cid,$cap);
        if(in_array($p,['invoice_new','invoice_view'],true)&&Request::int('get','id')) {
            $d=InvoiceService::get($cid,Request::int('get','id'));
            if($p==='invoice_new'&&($d['kind']!==Request::str('get','kind',24,'sale')||$d['status']!=='draft'))throw new \Agah\Erp\Core\ConflictException('فقط پیش‌نویس هم‌نوع قابل ویرایش است.');
        }
        if($p==='warehouses'&&Request::int('get','edit'))Business::row('warehouses',$cid,Request::int('get','edit'));
        if($p==='inventory') {if(Request::int('get','warehouse_id'))Business::row('warehouses',$cid,Request::int('get','warehouse_id'));if(Request::int('get','item_id'))Business::row('items',$cid,Request::int('get','item_id'));}
        return true;
    }
    private static function rows($key) {
        if(isset($_POST[$key])&&!is_array($_POST[$key]))throw new ValidationException([$key=>'فهرست ردیف‌ها معتبر نیست.']);
        $rows=isset($_POST[$key])&&is_array($_POST[$key])?wp_unslash($_POST[$key]):[];
        if(count($rows)>80)throw new ValidationException([$key=>'حداکثر ۸۰ ردیف مجاز است.']);
        foreach($rows as $r){if(!is_array($r))throw new ValidationException([$key=>'ردیف معتبر نیست.']);foreach($r as $v)if(!is_scalar($v))throw new ValidationException([$key=>'ورودی معتبر نیست.']);}return $rows;
    }
    private static function amount($key) {return Money::fromInput(Request::str('post',$key,40),Ctx::unit());}
    public static function dispatch($act,$cid,$url) {
        $id=Request::int('post','id');
        switch($act) {
            case 'module_invoice_save':
                $in=['kind'=>Request::str('post','kind',24),'party_id'=>Request::int('post','party_id'),'parent_id'=>Request::int('post','parent_id'),'invoice_date'=>Jalali::parse(Request::str('post','invoice_date',10)),'due_date'=>Jalali::parse(Request::str('post','due_date',10)),'description'=>Request::str('post','description',500),'discount_amount'=>self::amount('discount_amount'),'extra_cost'=>self::amount('extra_cost'),'cost_basis'=>Request::str('post','cost_basis',10,'value'),'price_reason'=>Request::str('post','price_reason',300)];
                $lines=[];foreach(self::rows('lines') as $r){$r['qty_scaled']=Money::qtyScaled($r['qty']??'');$r['unit_price']=Money::fromInput($r['unit_price']??'',Ctx::unit());$r['discount_amount']=Money::fromInput($r['discount_amount']??'',Ctx::unit());$lines[]=$r;}
                $id=InvoiceService::save($cid,$id,$in,$lines,Request::int('post','revision'));break;
            case 'module_invoice_issue':
                $items=[];foreach(self::rows('payment_items') as $r){if(($r['amount']??'')==='')continue;$r['amount']=Money::fromInput($r['amount'],Ctx::unit());if(($r['method']??'')==='cheque')$r['due_date']=Jalali::parse($r['due_date']??'');$items[]=$r;}
                $adv=[];foreach(self::rows('advances') as $r){if(($r['amount']??'')==='')continue;$r['amount']=Money::fromInput($r['amount'],Ctx::unit());$adv[]=$r;}
                InvoiceService::issue($cid,$id,Request::int('post','revision'),$items,$adv);break;
            case 'module_invoice_convert':$id=InvoiceService::convert($cid,$id,Jalali::parse(Request::str('post','convert_date',10)));break;
            case 'module_invoice_void':InvoiceService::voidDocument($cid,$id,Request::str('post','reason',300));break;
            case 'module_warehouse_save':StockService::saveWarehouse($cid,$id,['code'=>Request::str('post','code',30),'name'=>Request::str('post','name',190),'is_active'=>Request::flag('post','is_active')]);return $url;
            case 'module_stock_config':StockService::configure($cid,Request::str('post','stock_cost_method',10),Request::str('post','purchase_goods_mode',12));return $url;
            case 'module_stock_minimum':StockService::setMinimum($cid,Request::int('post','warehouse_id'),Request::int('post','item_id'),Money::qtyScaled(Request::str('post','qty',40)));return $url;
            case 'module_stock_issue':
                $lines=[];foreach(self::rows('lines') as $r){$r['qty_scaled']=Money::qtyScaled($r['qty']??'');$r['cost']=Money::fromInput($r['cost']??'',Ctx::unit());$lines[]=$r;}
                StockService::issue($cid,['kind'=>Request::str('post','kind',20),'doc_date'=>Jalali::parse(Request::str('post','doc_date',10)),'description'=>Request::str('post','description',500)],$lines);return $url;
            case 'module_stock_void':StockService::voidDocument($cid,$id,Request::str('post','reason',300));return $url;
            default:return FinanceController::dispatch($act,$cid,$url);
        }
        return agah_erp_url(['c'=>$cid,'p'=>'invoice_view','id'=>$id]);
    }
    public static function view($p) {
        if(FinanceController::view($p))return FinanceController::view($p);
        if(strpos($p,'trade_')===0&&isset(InvoiceService::KINDS[substr($p,6)]))return 'trade-list';
        return ['invoice_new'=>'trade-form','invoice_view'=>'trade-document','warehouses'=>'stock-warehouses','inventory'=>'stock-inventory','stock_docs'=>'stock-docs','trade_report'=>'trade-report','trade_aging'=>'trade-aging','purchase_aging'=>'trade-aging','purchase_report'=>'trade-report'][$p]??null;
    }
    public static function official($cid,$id){
        Ctx::need('party.sensitive');$d=InvoiceService::get($cid,$id);Ctx::need(InvoiceService::resource($d['kind']).'.export');if($d['status']!=='issued')throw new ValidationException(['invoice'=>'چاپ مشخصات کامل فقط برای فاکتور صادرشده است.']);
        if(!\Agah\Erp\Security\RateLimiter::consume('invoice-print|'.Ctx::uid().'|'.$cid,20,3600))throw new ValidationException(['print'=>'سقف چاپ محرمانه در ساعت پر شده است.']);
        $print=json_decode($d['print_snapshot']??'',true)?:[];$identities=[];$incomplete=false;
        foreach(['company','party'] as $key){$person=$print[$key]??[];$identities[$key]=\Agah\Erp\Core\Crypto::decrypt($person['national_id_enc']??'');if($identities[$key]===''||empty($person['economic_code'])||empty($person['postal_code'])||empty($person['address']))$incomplete=true;}
        $fy=\Agah\Erp\Modules\Accounting\FiscalService::get($cid,(int)$d['fiscal_year_id']);
        $methods=Db::col('SELECT DISTINCT t.method FROM '.Db::t('settlements').' s JOIN '.Db::t('treasury_doc_items')." t ON t.company_id=s.company_id AND t.id=s.doc_item_id WHERE s.company_id=%d AND s.invoice_id=%d AND s.status='active'",$cid,$id);$labels=['cash'=>'نقد','card'=>'کارت','transfer'=>'حواله','cheque'=>'چک'];$methods=array_map(function($m)use($labels){return $labels[$m]??$m;},$methods);
        Db::tx(function()use($cid,$id,$incomplete){\Agah\Erp\Core\Audit::log('invoice.identity.print','invoice',$id,null,['incomplete'=>$incomplete],$cid);});
        header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');require AGAH_ERP_DIR.'/views/invoice-official.php';
    }
    public static function filters() {
        $f=['party_id'=>Request::int('get','party_id'),'created_by'=>Request::int('get','seller_id'),'status'=>Request::str('get','status',10)];
        foreach(['from','to'] as $key){$s=Request::str('get',$key,10);$f[$key]=$s?Jalali::parse($s):'';}return \Agah\Erp\Core\FiscalContext::range(Ctx::cid(),$f);
    }
    /** خواندن خروجی تمام صفحات در snapshot مشترک با مجوز export مستقل و جلوگیری از فرمول CSV. */
    public static function export($cid) {
        $type=Request::str('get','trade_export',20);$kind=Request::str('get','kind',24,'sale');
        $cap=in_array($type,['inventory','kardex'],true)?'stock.export':InvoiceService::resource($kind).'.export';Access::need($cid,$cap);
        if(!wp_verify_nonce(Request::str('get','_n',100),'agah_trade_export_'.$cid.'_'.$type.'_'.$kind))throw new ValidationException(['export'=>'اعتبار خروجی منقضی است.']);
        $reader=function($page)use($cid,$type,$kind){
            if($type==='inventory')return StockService::inventory($cid,$page);
            if($type==='kardex')return StockService::kardex($cid,Request::int('get','warehouse_id'),Request::int('get','item_id'),$page);
            if($type==='list')return InvoiceService::page($cid,$kind,self::filters(),$page);
            if($type==='aging')return InvoiceService::aging($cid,$kind,Jalali::today(),$page);
            if($type==='report'){$f=self::filters();return InvoiceService::report($cid,$kind,Request::str('get','group',10,'item'),$f['from']?:substr(Jalali::today(),0,4).'-01-01',$f['to']?:Jalali::today(),$page);}
            throw new ValidationException(['export'=>'نوع خروجی معتبر نیست.']);
        };
        Db::tx(function()use($reader,$type){
            $data=$reader(1);$columns=['inventory'=>['warehouse_name','code','item_name','qty','value','minimum'],'kardex'=>['move_date','source_type','source_id','qty_scaled','cost','balance_qty','balance_value'],'list'=>['number','kind','party_name','invoice_date','due_date','amount','status'],'aging'=>['number','party_name','due_date','outstanding','bucket'],'report'=>['group_key','label','net','tax','cost','qty','profit']][$type];
            if(($data['count']??0)>10000||$data['pages']>200)throw new ValidationException(['export'=>'بازه خروجی را کوتاه‌تر کنید.']);$rows=[];
            for($page=1;$page<=max(1,$data['pages']);$page++){ $current=$page===1?$data:$reader($page);foreach($current['rows'] as $r){$row=[];foreach($columns as $key)$row[]=\Agah\Erp\Core\Tabular::cell($key,$r[$key]??'');$rows[]=$row;}}
            \Agah\Erp\Core\Tabular::download('agah-'.$type,$columns,$rows);
        });
    }
    public static function exportLink($cid,$type,$kind='sale') {
        $cap=in_array($type,['inventory','kardex'],true)?'stock.export':InvoiceService::resource($kind).'.export';
        if(Ctx::can($cap))foreach(['csv'=>'CSV','xlsx'=>'Excel XLSX'] as $format=>$label)echo '<a class="btn btn-outline erp-no-print" href="'.esc_url(add_query_arg(['trade_export'=>$type,'kind'=>$kind,'format'=>$format,'_n'=>wp_create_nonce('agah_trade_export_'.$cid.'_'.$type.'_'.$kind),'pg'=>false])).'">دانلود '.$label.' ('.Money::unitLabel(Ctx::unit()).'، مقدار ×۱۰۰۰۰)</a>';
    }
}
