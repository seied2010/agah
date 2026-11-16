<?php
/** HTTP مراحل۱۰–۱۲؛ همهٔ تغییرات از nonce/idempotency مشترک و سرویس‌های شرکت‌محور عبور می‌کنند. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Business,Ctx,Db,Jalali,Money,Request,ValidationException};
use Agah\Erp\Modules\Expenses\ExpenseService;
use Agah\Erp\Modules\Accounting\{FinancialService,ClosingService};
if(!defined('ABSPATH'))exit;
final class FinanceController {
    private static $upload=null;
    public static function nav(){return OperationsController::nav()+['expenses'=>['هزینه و درآمد','expense.view'],'expense_categories'=>['سرفصل هزینه و درآمد','expense.view'],'cost_dimensions'=>['پروژه، شعبه و مرکز هزینه','expense.view'],'financials'=>['صورت‌های مالی','report.view'],'financial_parties'=>['بدهکاران و بستانکاران','report.view'],'year_closing'=>['بستن حساب‌ها و سال جدید','closing.approve'],'global_search'=>['جست‌وجوی سراسری','dashboard.view'],'notifications'=>['مرکز اعلان‌ها','dashboard.view']];}
    public static function actions(){return OperationsController::actions()+['module_expense_lookup'=>'expense.edit','module_expense_issue'=>'expense.create','module_expense_pay'=>'expense.edit','module_expense_void'=>'expense.delete','module_expense_image'=>'expense.edit','module_year_close'=>'closing.approve','module_year_reopen'=>'closing.approve'];}
    public static function cap($p){if(OperationsController::cap($p))return OperationsController::cap($p);if($p==='dashboard')return 'dashboard.view';if($p==='expense_view')return 'expense.view';return self::nav()[$p][1]??null;}
    public static function preflight($p,$cid){if(OperationsController::preflight($p,$cid))return true;$cap=self::cap($p);if(!$cap)return false;Ctx::need($cap);if($p==='expense_view')ExpenseService::get($cid,Request::int('get','id'));if(in_array($p,['expense_categories','cost_dimensions'],true)&&Request::int('get','edit'))Business::row($p==='cost_dimensions'?'cost_dimensions':'expense_categories',$cid,Request::int('get','edit'));return true;}
    public static function view($p){if(OperationsController::view($p))return OperationsController::view($p);return ['dashboard'=>'finance-dashboard','expenses'=>'finance-expenses','expense_view'=>'finance-expense-document','expense_categories'=>'finance-lookups','cost_dimensions'=>'finance-lookups','financials'=>'finance-statements','financial_parties'=>'finance-parties','year_closing'=>'finance-closing','global_search'=>'finance-search','notifications'=>'finance-notifications'][$p]??null;}
    private static function payment(){
        $amount=Money::fromInput(Request::str('post','payment_amount',40),Ctx::unit());if(!$amount)return [];
        $method=Request::str('post','method',10,'transfer');$r=['method'=>$method,'amount'=>$amount,'treasury_account_id'=>Request::int('post','treasury_account_id')];
        if($method==='cheque'){$r['cheque_no']=Request::str('post','cheque_no',80);$r['due_date']=Jalali::parse(Request::str('post','cheque_due',10));$r['bank_name']=Request::str('post','bank_name',100);}return [$r];
    }
    /** فایل باید آپلود واقعی PHP باشد؛ hash همان bytes وارد payload توکن می‌شود، نه محتوای تصویر. */
    public static function upload(){
        if(self::$upload!==null)return self::$upload;if(!\Agah\Erp\Security\RateLimiter::consume('file-upload|'.Ctx::uid().'|'.Ctx::cid(),30,3600))throw new ValidationException(['file'=>'سقف ۳۰ تلاش بارگذاری در ساعت پر شده است.']);$f=$_FILES['image']??null;
        if(!is_array($f)||($f['error']??1)!==UPLOAD_ERR_OK||!is_string($f['tmp_name']??null)||!is_uploaded_file($f['tmp_name'])||($f['size']??0)>2097152)throw new ValidationException(['image'=>'آپلود تصویر معتبر حداکثر دو مگابایت لازم است.']);
        self::$upload=file_get_contents($f['tmp_name'],false,null,0,2097153);return self::$upload;
    }
    public static function dispatch($act,$cid,$url){$id=Request::int('post','id');
        switch($act){
            case 'module_expense_lookup':$type=Request::str('post','entity',12);ExpenseService::saveLookup($cid,$type,$id,['kind'=>Request::str('post','kind',12),'name'=>Request::str('post','name',190),'code'=>Request::str('post','code',30),'account_id'=>Request::int('post','account_id'),'is_active'=>Request::flag('post','is_active')]);return $url;
            case 'module_expense_issue':$in=[];foreach(['category_id','party_id','project_id','branch_id','center_id'] as $key)$in[$key]=Request::int('post',$key);$in['doc_date']=Jalali::parse(Request::str('post','doc_date',10));$in['due_date']=Jalali::parse(Request::str('post','due_date',10));$in['amount']=Money::fromInput(Request::str('post','amount',40),Ctx::unit());$in['description']=Request::str('post','description',500);$id=ExpenseService::issue($cid,$in,self::payment());break;
            case 'module_expense_pay':ExpenseService::pay($cid,$id,Jalali::parse(Request::str('post','doc_date',10)),self::payment());break;
            case 'module_expense_void':ExpenseService::voidDocument($cid,$id,Request::str('post','reason',300));break;
            case 'module_expense_image':ExpenseService::attach($cid,$id,self::upload());break;
            case 'module_year_close':ClosingService::close($cid,Request::int('post','year_id'),Request::str('post','reason',300));return $url;
            case 'module_year_reopen':ClosingService::reopen($cid,$id,Request::str('post','reason',300));return $url;
            default:return OperationsController::dispatch($act,$cid,$url);
        }return agah_erp_url(['c'=>$cid,'p'=>'expense_view','id'=>$id]);
    }
    public static function image($cid){$id=Request::int('get','finance_image');if(!wp_verify_nonce(Request::str('get','_n',100),'agah_finance_image_'.$cid.'_'.$id))throw new ValidationException(['image'=>'اعتبار دریافت تصویر منقضی است.']);$r=ExpenseService::image($cid,$id);$ext=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'][$r['mime']];header('Content-Type: '.$r['mime']);header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store');header('Content-Disposition: attachment; filename="receipt-'.$id.'.'.$ext.'"');header('Content-Length: '.strlen($r['image_data']));echo $r['image_data'];}
    public static function filters(){ $f=[];foreach(['category_id','party_id','project_id','branch_id','center_id'] as $key)$f[$key]=Request::int('get',$key);$f['kind']=Request::str('get','kind',10);$f['status']=Request::str('get','status',10,'issued');foreach(['from','to'] as $key){$v=Request::str('get',$key,10);$f[$key]=$v?Jalali::parse($v):'';}return \Agah\Erp\Core\FiscalContext::range(Ctx::cid(),$f); }
    public static function dates(){ $f=self::filters();$today=Jalali::today();$fy=\Agah\Erp\Modules\Accounting\FiscalService::forDate(Ctx::cid(),$today);$end=$today;if(!$fy){$years=\Agah\Erp\Modules\Accounting\FiscalService::all(Ctx::cid());$fy=$years[0]??null;$end=$fy['end_date']??$today;}return [$f['from']?:($fy['start_date']??$today),$f['to']?:$end]; }
    public static function exportLink($cid,$type){$cap=$type==='expenses'?'expense.export':'report.export';if(Ctx::can($cap))foreach(['csv'=>'CSV','xlsx'=>'Excel XLSX'] as $format=>$label)echo '<a class="btn btn-outline erp-no-print" href="'.esc_url(add_query_arg(['finance_export'=>$type,'format'=>$format,'_n'=>wp_create_nonce('agah_finance_export_'.$cid.'_'.$type)])).'">دانلود '.$label.' ('.Money::unitLabel(Ctx::unit()).')</a>' ;}
    public static function export($cid){
        $type=Request::str('get','finance_export',20);if(!in_array($type,['expenses','statements','parties','ratios'],true))throw new ValidationException(['export'=>'نوع خروجی معتبر نیست.']);Ctx::need($type==='expenses'?'expense.export':'report.export');
        if(!wp_verify_nonce(Request::str('get','_n',100),'agah_finance_export_'.$cid.'_'.$type))throw new ValidationException(['export'=>'اعتبار خروجی منقضی است.']);
        Db::tx(function()use($cid,$type){[$from,$to]=self::dates();$rows=[];
            if($type==='ratios'){$d=\Agah\Erp\Modules\Accounting\RatioService::compute($cid,$from,$to);$u=Ctx::unit();foreach($d['ratios'] as $code=>$r)$rows[]=[$code,$r['name'],$r['value']===null?'':($r['unit']==='money'?new \Agah\Erp\Core\DecimalCell(Money::toInputValue((int)$r['value'],$u)):new \Agah\Erp\Core\DecimalCell($r['value'])),$r['unit']==='money'?Money::unitLabel($u):\Agah\Erp\Modules\Accounting\RatioService::UNITS[$r['unit']],$r['formula'],\Agah\Erp\Modules\Accounting\RatioService::DESCRIPTIONS[$code],$r['reason']];$header=['کد','نسبت','مقدار','واحد','فرمول','توضیح','دلیل غیرقابل محاسبه'];}
            elseif($type==='statements'){$data=FinancialService::statements($cid,$from,$to);foreach(['profit','balance','cashflow'] as $section)foreach($data[$section] as $key=>$value)if(is_int($value))$rows[]=[$section,$key,in_array($key,['review_count','noncash_documents'],true)?$value:new \Agah\Erp\Core\DecimalCell(Money::toInputValue($value,Ctx::unit()))];$header=['section','metric',(Ctx::unit()==='rial'?'Rial':'Toman').'_or_count'];}
            else{$reader=function($page)use($cid,$type,$from,$to){return $type==='expenses'?ExpenseService::page($cid,self::filters(),$page):FinancialService::parties($cid,$from,$to,$page);};$first=$reader(1);$keys=$type==='expenses'?['number','doc_date','kind','category_name','party_name','project_id','branch_id','center_id','amount','status']:['code','name','opening','debit','credit','closing'];$header=$keys;for($page=1;$page<=max(1,$first['pages']);$page++)foreach(($page===1?$first:$reader($page))['rows'] as $r){$line=[];foreach($keys as $key)$line[]=\Agah\Erp\Core\Tabular::cell($key,$r[$key]??'');$rows[]=$line;}}
            \Agah\Erp\Core\Tabular::download('agah-'.$type,$header,$rows);
        });
    }
}
