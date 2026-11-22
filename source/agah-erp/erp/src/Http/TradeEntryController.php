<?php
/** Read-only, company-scoped lookup for fast invoice entry. Never posts financial data. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Access,AppException,Business,Ctx,Db,Money,Request,ValidationException};
use Agah\Erp\Modules\Trade\InvoiceService;
use Agah\Erp\Modules\Inventory\StockService;
if(!defined('ABSPATH')) exit;
final class TradeEntryController {
    public static function lookup() {
        nocache_headers();
        try {
            if(!check_ajax_referer('agah_trade_entry','nonce',false)) throw new ValidationException(['nonce'=>'نشست منقضی شده است؛ صفحه را تازه کنید.']);
            if(SecurityController::blocked()) throw new \Agah\Erp\Core\ForbiddenException();
            $cid=Request::int('post','company');Ctx::boot(get_current_user_id(),$cid);
            $kind=Request::str('post','kind',24,'sale');
            if(!in_array($kind,['sale','purchase','proforma','sale_order','purchase_order','purchase_request'],true)) throw new ValidationException(['kind'=>'نوع برگه معتبر نیست.']);
            $resource=InvoiceService::resource($kind);
            if(!Ctx::can($resource.'.create')) Access::need($cid,$resource.'.edit');
            $q=Money::normDigits(Request::str('post','q',100));
            $mode=Request::str('post','mode',12,'barcode');$warehouse=Request::int('post','warehouse');
            if(!in_array($mode,['barcode','search','id'],true)||$q==='') throw new ValidationException(['q'=>'نام، کد یا بارکد را وارد کنید.']);
            if($warehouse) { $wh=Business::row('warehouses',$cid,$warehouse);if(!$wh['is_active'])throw new ValidationException(['warehouse'=>'انبار غیرفعال است.']); }
            global $wpdb;
            $price=strpos($kind,'purchase')===0?'purchase_price':'sale_price';
            if($mode==='id'&&!preg_match('/^[1-9][0-9]{0,15}$/D',$q))throw new ValidationException(['q'=>'کالا معتبر نیست.']);
            $where=$mode==='id'?'id=%d':($mode==='barcode'?'barcode=%s':'(name LIKE %s OR code LIKE %s)');
            $values=$mode!=='search'?[$cid,$q]:[$cid,'%'.$wpdb->esc_like($q).'%','%'.$wpdb->esc_like($q).'%'];
            $rows=Db::rows('SELECT id,code,name,kind,unit_id,tax_rate_id,'.$price.' AS price FROM '.Db::t('items').' WHERE company_id=%d AND is_active=1 AND '.$where.' ORDER BY code,id LIMIT 21',...$values);
            if($mode==='barcode'&&count($rows)>1)throw new ValidationException(['barcode'=>'بارکد تکراری است؛ کالا را با نام یا کد انتخاب کنید.']);
            $more=count($rows)>20;$rows=array_slice($rows,0,20);$out=[];
            foreach($rows as $r) {
                $item=['id'=>(string)$r['id'],'code'=>$r['code'],'name'=>$r['name'],'kind'=>$r['kind'],'tax_rate_id'=>(string)($r['tax_rate_id']??0),'unit_id'=>(string)$r['unit_id'],'price'=>Money::toInputValue((int)$r['price'],Ctx::unit()),'stock'=>null];
                if($warehouse&&$r['kind']==='goods'&&Ctx::can('stock.view')) {
                    $b=StockService::balanceInternal($cid,$warehouse,(int)$r['id']);$reserved=StockService::reserved($cid,$warehouse,(int)$r['id']);
                    $item['stock']=['on_hand'=>Money::qtyToDb($b['qty']),'available'=>Money::qtyToDb(max(0,$b['qty']-$reserved))];
                }
                $out[]=$item;
            }
            wp_send_json_success(['items'=>$out,'more'=>$more]);
        } catch(AppException $e) { wp_send_json_error(['message'=>$e instanceof ValidationException?implode(' ',$e->errors):$e->getMessage()],$e->status); }
        catch(\Throwable $e) { error_log('[agah-trade-entry] '.get_class($e));wp_send_json_error(['message'=>'دریافت کالا کامل نشد؛ دوباره جست‌وجو کنید.'],500); }
    }
}
