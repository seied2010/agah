<?php
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Access,Audit,Business,Ctx,Db,Jalali,Money,Request,Settings,Tabular,ValidationException};
use Agah\Erp\Modules\Manufacturing\ProductionService;
if(!defined('ABSPATH'))exit;
final class ProductionController {

    public static function nav(){
        if(Ctx::cidOrZero()&&Settings::get('activity_type',Ctx::cid())!=='manufacturing'&&!Db::val('SELECT id FROM '.Db::t('production_orders').' WHERE company_id=%d LIMIT 1',Ctx::cid()))return [];
        return ['production_boms'=>['فرمول‌های ساخت','bom.view'],'production_orders'=>['دستورهای تولید','production.view'],'production_report'=>['گزارش بهای تولید','production.view'],'production_setup'=>['تنظیمات تولید','production.view']];
    }

    public static function actions(){
        return ['module_bom_save'=>Request::int('post','id')?'bom.edit':'bom.create','module_production_plan'=>Request::int('post','id')?'production.edit':'production.create','module_production_start'=>'production.approve','module_production_complete'=>'production.approve','module_production_void'=>'production.delete','module_production_setup'=>'company_settings.edit'];
    }

    public static function cap($p){
        return $p==='production_order'?'production.view':(self::nav()[$p][1]??null);
    }

    public static function preflight($p,$cid){
        $cap=$p==='production_order'?'production.view':(self::nav()[$p][1]??'');
        if(!$cap)return false;
        Access::need($cid,$cap);
        if($p==='production_order')ProductionService::order($cid,Request::int('get','id'));
        if($p==='production_boms'&&Request::int('get','edit')){
            Access::need($cid,'bom.edit');
            ProductionService::bom($cid,Request::int('get','edit'));
        }
        return true;
    }

    public static function view($p){
        return ['production_boms'=>'production-boms','production_orders'=>'production-orders','production_order'=>'production-order','production_report'=>'production-report','production_setup'=>'production-setup'][$p]??null;
    }

    private static function rows($key){
        $raw=$_POST[$key]??[];
        if(!is_array($raw)||count($raw)>80)throw new ValidationException(['lines'=>'فهرست مواد معتبر نیست؛ حداکثر ۸۰ ردیف.']);
        $rows=wp_unslash($raw);
        foreach($rows as $r){
            if(!is_array($r))throw new ValidationException(['lines'=>'ردیف معتبر نیست.']);
            foreach($r as $v)if(!is_scalar($v))throw new ValidationException(['lines'=>'مقدار معتبر نیست.']);
        }
        return $rows;
    }

    public static function dispatch($act,$cid,$url){
        $id=Request::int('post','id');
        $rev=Request::int('post','revision');
        switch($act){
            case 'module_bom_save':$lines=[];
            foreach(self::rows('components') as $l)$lines[]=['item_id'=>$l['item_id']??0,'qty_scaled'=>Money::qtyScaled($l['qty']??''),'scrap_bp'=>Money::pctBp($l['scrap_percent']??'0')];
            $id=ProductionService::saveBom($cid,$id,['code'=>Request::str('post','code',30),'name'=>Request::str('post','name',190),'product_id'=>Request::int('post','product_id'),'base_qty'=>Money::qtyScaled(Request::str('post','base_qty',40)),'description'=>Request::str('post','description',500),'is_active'=>Request::flag('post','is_active')],$lines,$rev);
            return agah_erp_url(['c'=>$cid,'p'=>'production_boms','edit'=>$id]);
            case 'module_production_setup':$ids=[];
            foreach(['wip','labor','overhead','loss'] as $k)$ids[$k]=Request::int('post',$k.'_account_id');
            ProductionService::configure($cid,$ids);
            return $url;
            case 'module_production_plan':$id=ProductionService::plan($cid,$id,['bom_id'=>Request::int('post','bom_id'),'planned_qty'=>Money::qtyScaled(Request::str('post','planned_qty',40)),'doc_date'=>Jalali::parse(Request::str('post','doc_date',10)),'warehouse_id'=>Request::int('post','warehouse_id'),'material_warehouse_id'=>Request::int('post','material_warehouse_id'),'description'=>Request::str('post','description',500)],$rev);
            break;
            case 'module_production_start':$actual=[];
            foreach(self::rows('materials') as $k=>$l)$actual[Business::id($k)]=Money::qtyScaled($l['qty']??'');
            ProductionService::start($cid,$id,$rev,Jalali::parse(Request::str('post','start_date',10)),$actual,Request::str('post','reason',300));
            break;
            case 'module_production_complete':$in=['finish_date'=>Jalali::parse(Request::str('post','finish_date',10)),'actual_qty'=>Money::qtyScaled(Request::str('post','actual_qty',40)),'scrap_qty'=>Money::qtyScaled(Request::str('post','scrap_qty',40,'0')),'reason'=>Request::str('post','reason',300)];
            foreach(['labor_cost','overhead_cost','abnormal_cost'] as $k)$in[$k]=Money::fromInput(Request::str('post',$k,40,'0'),Ctx::unit());
            ProductionService::complete($cid,$id,$rev,$in);
            break;
            case 'module_production_void':ProductionService::voidOrder($cid,$id,$rev,Request::str('post','reason',300));
            break;
            default:throw new ValidationException(['form'=>'عمل تولید معتبر نیست.']);
        }
        return agah_erp_url(['c'=>$cid,'p'=>'production_order','id'=>$id]);
    }

    public static function export($cid){
        Access::need($cid,'production.export');
        if(!wp_verify_nonce(Request::str('get','_n',100),'agah_production_export_'.$cid))throw new ValidationException(['export'=>'اعتبار خروجی منقضی شده است.']);
        Db::tx(function()use($cid){
            $data=ProductionService::page($cid);
            if($data['count']>10000)throw new ValidationException(['export'=>'حداکثر ۱۰۰۰۰ دستور در یک خروجی مجاز است.']);
            $rows=[];
            for($page=1;
            $page<=max(1,$data['pages']);
            $page++){
                foreach(($page===1?$data:ProductionService::page($cid,$page))['rows'] as $o){
                    $s=json_decode($o['snapshot'],true);
                    $rows[]=[$o['number'],$o['doc_date'],$s['product_code']??'',$s['product_name']??'',ProductionService::STATES[$o['status']]??$o['status'],Money::qtyToDb((int)$o['planned_qty']),Money::qtyToDb((int)$o['actual_qty']),Tabular::cell('cost',(int)$o['material_cost']),Tabular::cell('cost',(int)$o['labor_cost']),Tabular::cell('cost',(int)$o['overhead_cost']),Tabular::cell('cost',(int)$o['abnormal_cost']),Tabular::cell('cost',(int)$o['total_cost'])];
                }
            }
            Audit::log('production.export','report',0,null,['rows'=>count($rows)],$cid);
            Tabular::download('agah-production',array_merge(['شماره','تاریخ','کد محصول','محصول','وضعیت','مقدار برنامه','مقدار واقعی'],array_map(function($h){return $h.' ('.Money::unitLabel(Ctx::unit()).')';},['مواد','دستمزد','سربار','ضایعات غیرعادی','بهای محصول'])),$rows);
        }
        );
    }
}
