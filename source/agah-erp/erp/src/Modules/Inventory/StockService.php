<?php
/** انبار چندشرکتی: گردش تغییرناپذیر، لایه‌های FIFO و میانگین متحرک؛ همهٔ تغییرات زیر قفل شرکت‌اند. */
namespace Agah\Erp\Modules\Inventory;
use Agah\Erp\Core\{Access,Audit,Business,Ctx,Db,Money,Request,Sequence,Settings,ValidationException,ConflictException};
use Agah\Erp\Modules\Accounting\{AccountService,FiscalService,VoucherService};
if (!defined('ABSPATH')) exit;
final class StockService {
    public static function warehouses($cid) { Access::need($cid,'warehouse.view'); return Db::rows('SELECT * FROM '.Db::t('warehouses').' WHERE company_id=%d ORDER BY code',$cid); }
    public static function saveWarehouse($cid,$id,array $in) {
        return Access::write($cid,$id?'warehouse.edit':'warehouse.create',function()use($cid,$id,$in){
            $old=$id?Business::row('warehouses',$cid,$id,true):null;
            $code=Money::normDigits($in['code']??''); $name=Request::clean($in['name']??'',190);
            if(!preg_match('/^[A-Za-z0-9_-]{1,30}$/D',$code) || mb_strlen($name)<2) throw new ValidationException(['name'=>'نام و کد معتبر انبار وارد کنید.']);
            if(Db::val('SELECT id FROM '.Db::t('warehouses').' WHERE company_id=%d AND code=%s AND id<>%d',$cid,$code,$id)) throw new ValidationException(['code'=>'کد انبار تکراری است.']);
            $new=['code'=>$code,'name'=>$name,'is_active'=>empty($in['is_active'])?0:1];
            $id=Business::save('warehouses',$cid,$id,$new); Audit::log('warehouse.save','warehouse',$id,$old,$new,$cid); return $id;
        });
    }
    /** روش و حالت حسابداری بعد از هر گردش قفل‌اند؛ تغییر بی‌ردپای بهای تمام‌شده مجاز نیست. */
    public static function configure($cid,$method,$mode) {
        return Access::write($cid,'company_settings.edit',function()use($cid,$method,$mode){
            if(!in_array($method,['average','fifo'],true)||!in_array($mode,['periodic','inventory'],true)) throw new ValidationException(['mode'=>'روش انبار معتبر نیست.']);
            $old=['method'=>Settings::get('stock_cost_method',$cid),'mode'=>Settings::get('purchase_goods_mode',$cid)];
            if(($old['method']!==$method||$old['mode']!==$mode) && (Db::val('SELECT id FROM '.Db::t('stock_moves').' WHERE company_id=%d LIMIT 1',$cid)||Db::val('SELECT id FROM '.Db::t('invoices')." WHERE company_id=%d AND status='issued' AND kind IN ('sale','purchase','sale_return','purchase_return') LIMIT 1",$cid))) throw new ConflictException('تغییر روش پس از گردش نیازمند بستن موجودی و مهاجرت حسابداری است.');
            Settings::set('stock_cost_method',$method,$cid); Settings::set('purchase_goods_mode',$mode,$cid);
            Audit::log('stock.configure','company',$cid,$old,['method'=>$method,'mode'=>$mode],$cid);
        });
    }
    /** ماندهٔ داخلی از خود گردش‌هاست؛ برای نوشتن فقط در تراکنش مجاز است و cap سرویس مبدأ قبلاً کنترل می‌شود. */
    public static function balanceInternal($cid,$warehouse,$item) {
        if(Ctx::cidOrZero()!==(int)$cid) throw new \Agah\Erp\Core\NotFoundException();
        $r=Db::row('SELECT COALESCE(SUM(qty_scaled),0) AS qty,COALESCE(SUM(cost),0) AS value FROM '.Db::t('stock_moves')." WHERE company_id=%d AND warehouse_id=%d AND item_id=%d AND status='active'",$cid,$warehouse,$item);
        return ['qty'=>(int)$r['qty'],'value'=>(int)$r['value']];
    }
    /** رزروی که همین گردش مصرفش می‌کند (خروج سفارش اینترنتی)؛ فقط در طول همان صدور. */
    public static $consuming=0;
    /** مقدار رزروشدهٔ فروش اینترنتی که هنوز خارج نشده؛ فروش حضوری و حوالهٔ دیگر نمی‌توانند آن را مصرف کنند. */
    public static function reserved($cid,$warehouse,$item,$exclude=0) {
        return (int)Db::val('SELECT COALESCE(SUM(l.qty_scaled),0) FROM '.Db::t('connect_reservation_lines').' l JOIN '.Db::t('connect_reservations')." r ON r.company_id=l.company_id AND r.id=l.reservation_id WHERE l.company_id=%d AND l.item_id=%d AND r.warehouse_id=%d AND r.id<>%d AND (r.status='paid' OR (r.status='held' AND r.expires_at>%s))",$cid,$item,$warehouse,(int)$exclude,gmdate('Y-m-d H:i:s'));
    }
    /** ثبتِ داخلی فقط از سند stock_docs یا invoice صادرشونده و زیر تراکنش/مجوز واقعی آن پذیرفته می‌شود. */
    public static function move($cid,$warehouse,$item,$date,$qty,$incomingCost,$type,$source,$line=0) {
        if(!Db::inTx()) throw new \LogicException('Stock movement requires a transaction.');
        if($type==='invoice') {
            $doc=Business::row('invoices',$cid,$source,true);
            Access::need($cid,\Agah\Erp\Modules\Trade\InvoiceService::resource($doc['kind']).'.create');
            $l=Business::row('invoice_lines',$cid,$line);
            if($doc['status']!=='draft'||Db::val('SELECT id FROM '.Db::t('stock_moves')." WHERE company_id=%d AND invoice_line_id=%d AND status='active' LIMIT 1",$cid,$line))throw new ConflictException('گردش این خط قبلاً ثبت شده یا فاکتور پیش‌نویس نیست.');
            $expected=in_array($doc['kind'],['purchase','sale_return'],true)?(int)$l['qty_scaled']:-(int)$l['qty_scaled'];if($qty!==$expected)throw new ConflictException('مقدار گردش با خط فاکتور سازگار نیست.');
            if((int)$l['invoice_id']!==$source||(int)$l['item_id']!==$item||(int)$l['warehouse_id']!==$warehouse) throw new ConflictException('منبع گردش انبار ناسازگار است.');
        } elseif($type==='production') { \Agah\Erp\Modules\Manufacturing\ProductionService::authorizeMove($cid,$source,$line,$warehouse,$item,$qty,$incomingCost); }
        elseif($type==='stock_doc') { Access::need($cid,'stock.create'); Business::row('stock_docs',$cid,$source,true); }
        else throw new ValidationException(['source'=>'منبع انبار معتبر نیست.']);
        $wh=Business::row('warehouses',$cid,$warehouse); $it=Business::row('items',$cid,$item);
        if(!$wh['is_active']||!$it['is_active']||$it['kind']!=='goods') throw new ValidationException(['warehouse_id'=>'انبار و کالای فعال انتخاب کنید.']);
        if(!is_int($qty)||!$qty||abs($qty)>Money::MAX) throw new ValidationException(['qty_scaled'=>'مقدار معتبر نیست.']);
        FiscalService::assertOpen($cid,Business::date($date));
        $latest=Db::val('SELECT MAX(move_date) FROM '.Db::t('stock_moves')." WHERE company_id=%d AND warehouse_id=%d AND item_id=%d AND status='active'",$cid,$warehouse,$item);
        if($latest && $date<$latest) throw new ConflictException('گردش با تاریخ قبل از آخرین گردش این کالا/انبار مجاز نیست.');
        $b=self::balanceInternal($cid,$warehouse,$item); $method=Settings::get('stock_cost_method',$cid); $picks=[];
        if($qty>0) $cost=Business::amount($incomingCost);
        else {
            $take=-$qty;
            if($take>$b['qty']) throw new ConflictException('موجودی کافی نیست؛ موجودی منفی مجاز نیست.');
            if($take>$b['qty']-self::reserved($cid,$warehouse,$item,self::$consuming)) throw new ConflictException('موجودی آزاد کافی نیست؛ بخشی از موجودی برای سفارش‌های اینترنتی رزرو شده است.');
            if($method==='average') $cost=$take===$b['qty']?$b['value']:Money::mulDiv($b['value'],$take,$b['qty']);
            else {
                $cost=0;$remaining=$take;
                foreach(Db::rows('SELECT * FROM '.Db::t('stock_layers').' WHERE company_id=%d AND warehouse_id=%d AND item_id=%d AND remaining_qty>0 ORDER BY id FOR UPDATE',$cid,$warehouse,$item) as $layer) {
                    $q=min($remaining,(int)$layer['remaining_qty']);
                    $value=$q===(int)$layer['remaining_qty']?(int)$layer['remaining_value']:Money::mulDiv((int)$layer['remaining_value'],$q,(int)$layer['remaining_qty']);
                    $picks[]=['layer_id'=>(int)$layer['id'],'qty_scaled'=>$q,'cost'=>$value];
                    Db::update('stock_layers',['remaining_qty'=>(int)$layer['remaining_qty']-$q,'remaining_value'=>(int)$layer['remaining_value']-$value],['company_id'=>$cid,'id'=>$layer['id']]);
                    $cost=Money::add($cost,$value);$remaining-=$q;if(!$remaining) break;
                }
                if($remaining) throw new ConflictException('لایه‌های انبار با موجودی سازگار نیست.');
            }
        }
        Business::amount(Money::add($b['value'],$qty>0?$cost:-$cost));
        Business::amount(Money::add($b['qty'],$qty));
        $id=Business::save('stock_moves',$cid,0,['warehouse_id'=>$warehouse,'item_id'=>$item,'move_date'=>$date,'qty_scaled'=>$qty,'cost'=>$qty>0?$cost:-$cost,'source_type'=>$type,'source_id'=>$source,'invoice_id'=>$type==='invoice'?$source:null,'invoice_line_id'=>$type==='invoice'&&$line?$line:null,'production_order_id'=>$type==='production'?$source:null,'production_line_id'=>$type==='production'&&$line?$line:null,'stock_doc_id'=>$type==='stock_doc'?$source:null]);
        if($qty>0 && $method==='fifo') Business::save('stock_layers',$cid,0,['move_id'=>$id,'warehouse_id'=>$warehouse,'item_id'=>$item,'remaining_qty'=>$qty,'remaining_value'=>$cost]);
        foreach($picks as $pick) Business::save('stock_picks',$cid,0,['move_id'=>$id]+$pick);
        return $cost;
    }
    /** ابطال فقط اگر تمام گردش‌های پس از مبدأ متعلق به همان مبدأ باشند؛ جلوگیری از قیمت‌گذاری تاریخی نادرست. */
    public static function reverseSource($cid,$type,$source) {
        if(!Db::inTx()) throw new \LogicException('Stock reversal requires a transaction.');
        if($type==='invoice') { $doc=Business::row('invoices',$cid,$source);Access::need($cid,\Agah\Erp\Modules\Trade\InvoiceService::resource($doc['kind']).'.delete'); }
        elseif($type==='production') {Access::need($cid,'production.delete');$order=Business::row('production_orders',$cid,$source,true);if($order['status']!=='posting_void')throw new ConflictException('ابطال گردش فقط از دستور تولید مجاز است.');}
        elseif($type==='stock_doc') Access::need($cid,'stock.delete'); else throw new ConflictException('منبع ابطال معتبر نیست.');
        $moves=Db::rows('SELECT * FROM '.Db::t('stock_moves')." WHERE company_id=%d AND source_type=%s AND source_id=%d AND status='active' ORDER BY id DESC FOR UPDATE",$cid,$type,$source);
        foreach($moves as $m) {
            FiscalService::assertOpen($cid,$m['move_date']);
            if(Db::val('SELECT id FROM '.Db::t('stock_moves')." WHERE company_id=%d AND warehouse_id=%d AND item_id=%d AND status='active' AND id>%d LIMIT 1",$cid,$m['warehouse_id'],$m['item_id'],$m['id'])) throw new ConflictException('پس از این برگه گردش انبار ثبت شده است؛ ابتدا گردش‌های وابسته را برگردانید یا برگشت مرتبط صادر کنید.');
            foreach(Db::rows('SELECT * FROM '.Db::t('stock_picks').' WHERE company_id=%d AND move_id=%d',$cid,$m['id']) as $p) {
                $layer=Business::row('stock_layers',$cid,$p['layer_id'],true);
                Db::update('stock_layers',['remaining_qty'=>Money::add((int)$layer['remaining_qty'],(int)$p['qty_scaled']),'remaining_value'=>Money::add((int)$layer['remaining_value'],(int)$p['cost'])],['company_id'=>$cid,'id'=>$layer['id']]);
            }
            Db::exec('UPDATE '.Db::t('stock_layers').' SET remaining_qty=0,remaining_value=0 WHERE company_id=%d AND move_id=%d',$cid,$m['id']);
            Db::update('stock_moves',['status'=>'void'],['company_id'=>$cid,'id'=>$m['id']]);
        }
    }
    /** رسید/حواله/انتقال/شمارش واقعی. شمارش مقدار هدف است، نه اختلاف؛ اختلاف زیر قفل محاسبه می‌شود. */
    public static function issue($cid,array $in,array $lines) {
        return Access::write($cid,'stock.create',function()use($cid,$in,$lines){
            $kind=$in['kind']??'';
            if(!in_array($kind,['receipt','issue','transfer','count'],true)||count($lines)<1||count($lines)>80) throw new ValidationException(['lines'=>'نوع برگه و یک تا ۸۰ ردیف معتبر وارد کنید.']);
            $date=Business::date($in['doc_date']??''); $fy=FiscalService::assertOpen($cid,$date);
            $id=Business::save('stock_docs',$cid,0,['kind'=>$kind,'doc_date'=>$date,'fiscal_year_id'=>$fy['id'],'number'=>Sequence::next($cid,'stock_doc',$fy['id']),'description'=>Request::clean($in['description']??'',500),'created_by'=>Ctx::uid()]);
            $net=0;$seen=[];$moved=false;
            foreach($lines as $l) {
                $wh=Business::id($l['warehouse_id']??0);$item=Business::id($l['item_id']??0);$key=$wh.':'.$item;
                if(isset($seen[$key])) throw new ValidationException(['lines'=>'کالا/انبار تکراری در یک برگه مجاز نیست.']);$seen[$key]=true;
                $qty=$l['qty_scaled']??0;
                if(!is_int($qty)||$qty<0||$qty>Money::MAX||(!$qty&&$kind!=='count')) throw new ValidationException(['qty_scaled'=>'مقدار معتبر نیست.']);
                if($kind==='count') $qty=Money::add($qty,-self::balanceInternal($cid,$wh,$item)['qty']);
                elseif(in_array($kind,['issue','transfer'],true)) $qty=-$qty;
                if(!$qty) continue;
                $cost=self::move($cid,$wh,$item,$date,$qty,$qty>0?Business::amount($l['cost']??0):0,'stock_doc',$id);
                $moved=true;
                if($kind==='transfer') {
                    $to=Business::id($l['target_warehouse_id']??0);if($to===$wh) throw new ValidationException(['warehouse_id'=>'انبار مبدأ و مقصد متفاوت باشند.']);
                    self::move($cid,$to,$item,$date,-$qty,$cost,'stock_doc',$id);
                } else $net=Money::add($net,$qty>0?$cost:-$cost);
            }
            if(!$moved) throw new ValidationException(['lines'=>'شمارش با موجودی برابر است و تعدیلی ندارد.']);
            if($net && Settings::get('purchase_goods_mode',$cid)==='inventory') {
                $inv=(int)AccountService::bySysKey($cid,'inventory')['id'];
                $counter=(int)AccountService::bySysKey($cid,$kind==='receipt'?'opening_balance':'other_expense')['id'];
                $vid=VoucherService::postSystem(['voucher_date'=>$date,'description'=>'گردش انبار '.$id,'source_type'=>'stock_doc','source_id'=>$id],[['account_id'=>$inv,'debit'=>max(0,$net),'credit'=>max(0,-$net)],['account_id'=>$counter,'debit'=>max(0,-$net),'credit'=>max(0,$net)]]);
                Db::update('stock_docs',['voucher_id'=>$vid],['company_id'=>$cid,'id'=>$id]);
            }
            Audit::log('stock.issue','stock_doc',$id,null,['kind'=>$kind,'net_cost'=>$net],$cid);return $id;
        });
    }
    public static function voidDocument($cid,$id,$reason) {
        return Access::write($cid,'stock.delete',function()use($cid,$id,$reason){
            $reason=Business::reason($reason);$d=Business::row('stock_docs',$cid,$id,true);
            if($d['status']!=='issued') throw new ConflictException('برگه قبلاً باطل شده است.');
            FiscalService::assertOpen($cid,$d['doc_date']);self::reverseSource($cid,'stock_doc',$id);
            if($d['voucher_id']) VoucherService::voidSystem((int)$d['voucher_id'],$reason);
            Db::update('stock_docs',['status'=>'void'],['company_id'=>$cid,'id'=>$id]);Audit::log('stock.void','stock_doc',$id,$d,['reason'=>$reason],$cid);
        });
    }
    public static function setMinimum($cid,$warehouse,$item,$qty) {
        Access::write($cid,'warehouse.edit',function()use($cid,$warehouse,$item,$qty){
            Business::row('warehouses',$cid,$warehouse);Business::row('items',$cid,$item);Business::amount($qty);
            $old=Db::row('SELECT * FROM '.Db::t('stock_minimums').' WHERE company_id=%d AND warehouse_id=%d AND item_id=%d',$cid,$warehouse,$item);
            Business::save('stock_minimums',$cid,$old?(int)$old['id']:0,['warehouse_id'=>$warehouse,'item_id'=>$item,'qty_scaled'=>$qty]);
            Audit::log('stock.minimum','item',$item,$old,['warehouse'=>$warehouse,'qty'=>$qty],$cid);
        });
    }
    /** گزارش صفحه‌بندی شده با ماندهٔ افتتاح و ماندهٔ جاری، صرفاً گردش‌های فعال. */
    public static function kardex($cid,$warehouse,$item,$page=1) {
        Access::need($cid,'stock.view');Business::row('warehouses',$cid,$warehouse);Business::row('items',$cid,$item);
        $page=max(1,(int)$page);$where="company_id=%d AND warehouse_id=%d AND item_id=%d AND status='active'";
        $rows=Db::rows('SELECT * FROM '.Db::t('stock_moves').' WHERE '.$where.' ORDER BY move_date,id LIMIT 50 OFFSET %d',$cid,$warehouse,$item,($page-1)*50);
        $b=['qty'=>0,'value'=>0];
        if($rows) { $first=$rows[0];$o=Db::row('SELECT COALESCE(SUM(qty_scaled),0) AS qty,COALESCE(SUM(cost),0) AS value FROM '.Db::t('stock_moves').' WHERE '.$where.' AND (move_date<%s OR (move_date=%s AND id<%d))',$cid,$warehouse,$item,$first['move_date'],$first['move_date'],$first['id']);$b=['qty'=>(int)$o['qty'],'value'=>(int)$o['value']]; }
        $opening=$b;
        foreach($rows as &$r) { $b['qty']=Money::add($b['qty'],(int)$r['qty_scaled']);$b['value']=Money::add($b['value'],(int)$r['cost']);$r['balance_qty']=$b['qty'];$r['balance_value']=$b['value']; }unset($r);
        $count=(int)Db::val('SELECT COUNT(*) FROM '.Db::t('stock_moves').' WHERE '.$where,$cid,$warehouse,$item);
        return ['rows'=>$rows,'opening'=>$opening,'balance'=>self::balanceInternal($cid,$warehouse,$item),'pages'=>(int)ceil($count/50),'page'=>$page];
    }
    public static function inventory($cid,$page=1,$q='') {
        Access::need($cid,'stock.view');$page=max(1,(int)$page);
        $base=' FROM '.Db::t('warehouses').' w CROSS JOIN '.Db::t('items')." i LEFT JOIN ".Db::t('stock_minimums').' n ON n.company_id=w.company_id AND n.warehouse_id=w.id AND n.item_id=i.id LEFT JOIN '.Db::t('stock_moves')." m ON m.company_id=w.company_id AND m.warehouse_id=w.id AND m.item_id=i.id AND m.status='active' WHERE w.company_id=%d AND i.company_id=w.company_id AND i.kind='goods'";
        $search='';$args=[$cid];if($q!==''){global $wpdb;$like='%'.$wpdb->esc_like(Request::clean($q,100)).'%';$search=' AND (i.code LIKE %s OR i.name LIKE %s OR w.name LIKE %s)';array_push($args,$like,$like,$like);$base.=$search;}
        $rows=Db::rows('SELECT w.id AS warehouse_id,w.name AS warehouse_name,i.id AS item_id,i.name AS item_name,i.code,COALESCE(n.qty_scaled,0) AS minimum,COALESCE(SUM(m.qty_scaled),0) AS qty,COALESCE(SUM(m.cost),0) AS value'.$base.' GROUP BY w.id,w.name,i.id,i.name,i.code,n.qty_scaled ORDER BY w.code,i.code LIMIT 50 OFFSET %d',...array_merge($args,[($page-1)*50]));
        $count=(int)Db::val('SELECT COUNT(*) FROM '.Db::t('warehouses').' w JOIN '.Db::t('items')." i ON i.company_id=w.company_id AND i.kind='goods' WHERE w.company_id=%d".$search,...$args);
        return ['rows'=>$rows,'page'=>$page,'pages'=>(int)ceil($count/50)];
    }
    /** تطبیق کل ارزش انبار با حساب موجودی در دفتر؛ در ادواری تطبیق حسابی کاربرد ندارد. */
    public static function reconcile($cid) {
        Access::need($cid,'stock.view');$stock=(int)Db::val('SELECT COALESCE(SUM(cost),0) FROM '.Db::t('stock_moves')." WHERE company_id=%d AND status='active'",$cid);
        $a=AccountService::bySysKey($cid,'inventory');
        $ledger=(int)Db::val('SELECT COALESCE(SUM(CAST(l.debit AS SIGNED)-CAST(l.credit AS SIGNED)),0) FROM '.Db::t('voucher_lines').' l JOIN '.Db::t('vouchers')." v ON v.company_id=l.company_id AND v.id=l.voucher_id WHERE l.company_id=%d AND l.account_id=%d AND v.status IN ('approved','final')",$cid,$a['id']);
        return ['stock'=>$stock,'ledger'=>$ledger,'difference'=>Money::add($stock,-$ledger),'mode'=>Settings::get('purchase_goods_mode',$cid)];
    }
}
