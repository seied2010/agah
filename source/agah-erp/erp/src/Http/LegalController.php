<?php

namespace Agah\Erp\Http;
use Agah\Erp\Core\{Audit,Business,Ctx,Db,Jalali,Request,ValidationException};
use Agah\Erp\Modules\Legal\LegalService;
use Agah\Erp\Modules\Maintenance\HealthService;
if(!defined('ABSPATH'))exit;
final class LegalController {
    public static function nav(){
        return ['legal_vat'=>['ارزش افزوده و تطبیق','legal.view'],'legal_transactions'=>['داده معاملات ماده ۱۶۹','legal.view'],'legal_payroll'=>['مالیات و بیمه حقوق','legal.view'],'legal_settings'=>['تنظیمات گزارش قانونی','legal.edit'],'system_health'=>['سلامت سامانه','health.view']]+ConnectController::nav();
    }
    public static function cap($p){
        return self::nav()[$p][1]??ConnectController::cap($p);
    }
    public static function actions(){
        return ['module_legal_settings'=>'legal.edit','module_legal_sensitive'=>'legal.export']+ConnectController::actions();
    }
    public static function preflight($p,$cid){
        if(!self::cap($p))return false;
        Ctx::need(self::cap($p));
        return true;
    }
    public static function view($p){
        return ['legal_vat'=>'legal-vat','legal_transactions'=>'legal-transactions','legal_payroll'=>'legal-payroll','legal_settings'=>'legal-settings','system_health'=>'system-health'][$p]??ConnectController::view($p);
    }
    public static function dispatch($act,$cid,$url){
        if(isset(ConnectController::actions()[$act]))return ConnectController::dispatch($act,$cid,$url);
        if($act!=='module_legal_settings')throw new ValidationException(['form'=>'عمل معتبر نیست.']);
        LegalService::configure($cid,Request::str('post','credit_mode',10),Request::str('post','credit_note',500));
        return $url;
    }
    public static function dates($source='get'){
        if($source==='get')return FinanceController::dates();
        $from=Jalali::parse(Request::str('post','from',10));
        $to=Jalali::parse(Request::str('post','to',10));
        LegalService::range($from,$to);
        return [$from,$to];
    }
    public static function filters($cid,$p,$from,$to){
        echo '<form method="get" class="erp-filter" action="'.esc_url(agah_erp_url()).'"><input type="hidden" name="page_id" value="'.(int)get_option('agah_erp_page_id').'"><input type="hidden" name="c" value="'.(int)$cid.'"><input type="hidden" name="p" value="'.esc_attr($p).'">';
        ModuleController::input('from','از تاریخ شمسی',Jalali::format($from,false),'text','required data-jalali');
        ModuleController::input('to','تا تاریخ شمسی',Jalali::format($to,false),'text','required data-jalali');
        echo '<button type="submit" class="btn btn-outline">نمایش دوره</button></form>';
    }
    public static function exportLink($cid,$type){
        if(Ctx::can('legal.export'))foreach(['csv'=>'CSV','xlsx'=>'Excel XLSX'] as $format=>$label)echo '<a class="btn btn-outline erp-no-print" href="'.esc_url(add_query_arg(['legal_export'=>$type,'format'=>$format,'_n'=>wp_create_nonce('agah_legal_export_'.$cid.'_'.$type)])).'">دانلود '.$label.' ریال — '.esc_html(LegalService::NOTICE).'</a>' ;
    }
    public static function output($cid,$full=false){
        Ctx::need('legal.export');
        $type=$full?'transactions':Request::str('get','legal_export',20);
        if(!in_array($type,['vat','transactions','payroll'],true))throw new ValidationException(['export'=>'گزارش معتبر نیست.']);
        if(!$full&&!wp_verify_nonce(Request::str('get','_n',100),'agah_legal_export_'.$cid.'_'.$type))throw new ValidationException(['export'=>'اعتبار خروجی منقضی شده است.']);
        [$from,$to]=self::dates($full?'post':'get');
        $rows=Db::tx(function()use($cid,$type,$full,$from,$to){
            if($type==='vat'){
                $d=LegalService::vat($cid,$from,$to);$rows=[];foreach($d['rows'] as $r)$rows[]=['نوع'=>$r['kind'],'گروه'=>$r['tax_group'],'خالص'=>$r['net'],'مالیات'=>$r['tax'],'هزینه_جانبی'=>$r['extra'],'توضیح'=>LegalService::NOTICE];foreach($d['totals']+$d['ledger']+['credit_mode'=>$d['credit_mode'],'credit_note'=>$d['credit_note'],'source_output_difference'=>$d['source_output_difference'],'source_input_difference'=>$d['source_input_difference'],'credit'=>$d['credit'],'net_candidate'=>$d['net_candidate']] as $k=>$v)$rows[]=['نوع'=>'summary','گروه'=>$k,'خالص'=>$v===null?'نیازمند تعیین اعتبار خرید':$v,'مالیات'=>'','هزینه_جانبی'=>'','توضیح'=>LegalService::NOTICE];
            }
            else{
                $first=$type==='payroll'?LegalService::payroll($cid,$from,$to):LegalService::transactions($cid,$from,$to,1,$full);if($first['count']>10000)throw new ValidationException(['export'=>'خروجی تا ۱۰هزار ردیف است؛ بازه را کوتاه‌تر کنید.']);$keys=$type==='payroll'?['number','employee_code','employee_name','period_key','doc_date','gross','taxable','tax','insurance_employee','insurance_employer','notice']:['kind','fiscal_year_id','number','invoice_date','party_id','name','party_kind','economic_code','national_id','identity_basis','missing_identity','net','tax','extra','amount','notice'];$rows=[];for($page=1;$page<=max(1,$first['pages']);$page++){
                    $data=$page===1?$first:($type==='payroll'?LegalService::payroll($cid,$from,$to,$page):LegalService::transactions($cid,$from,$to,$page,$full));foreach($data['rows'] as $r){
                        $row=[];foreach($keys as $key)$row[$key]=$r[$key];$rows[]=$row;
                    }
                }
            }
            if($full)Audit::log('legal.identity.export','legal',0,null,['from'=>$from,'to'=>$to,'rows'=>count($rows)],$cid); return $rows;
        }
        );
        $keys=array_keys($rows[0]??['notice'=>'']);
        if(!$rows)$rows=[['notice'=>LegalService::NOTICE]];
        \Agah\Erp\Core\Tabular::$unit='rial';$out=[];foreach($rows as $row){$line=[];foreach($row as $k=>$v)$line[]=\Agah\Erp\Core\Tabular::cell($k,$v);$out[]=$line;}
        \Agah\Erp\Core\Tabular::download('agah-legal-'.$type.'-rial',$keys,$out,$full?'csv':null);
    }
}
