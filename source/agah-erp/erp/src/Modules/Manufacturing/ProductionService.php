<?php
/** Discrete, single-product batches. All inventory and accounting effects share the company transaction. */
namespace Agah\Erp\Modules\Manufacturing;
use Agah\Erp\Core\{Access,Audit,Business,Ctx,Db,FiscalContext,Money,Request,Sequence,Settings,ValidationException,ConflictException,NotFoundException};
use Agah\Erp\Modules\Accounting\{AccountService,FiscalService,VoucherService};
use Agah\Erp\Modules\Inventory\StockService;
if(!defined('ABSPATH'))exit;
final class ProductionService {
    const STATES=['draft'=>'پیش‌نویس','running'=>'در جریان تولید','completed'=>'تکمیل‌شده','void'=>'باطل'];

    private static function manufacturing($cid){
        if(Settings::get('activity_type',$cid)!=='manufacturing')throw new ValidationException(['activity_type'=>'ابتدا نوع فعالیت شرکت را در تنظیمات شرکت «تولیدی» انتخاب کنید.']);
    }

    private static function ready($cid){
        self::manufacturing($cid);
        if(Settings::get('purchase_goods_mode',$cid)!=='inventory')throw new ValidationException(['inventory'=>'تولید به روش موجودی دائمی نیاز دارد؛ تنظیمات تولید را بررسی کنید. روش شرکت دارای گردش ادواری خودکار تغییر نمی‌کند.']);
    }

    private static function qty($v,$zero=false){
        if(!is_int($v)||$v<($zero?0:1)||$v>Money::MAX)throw new ValidationException(['qty'=>'مقدار معتبر با حداکثر چهار رقم اعشار وارد کنید.']);
        return $v;
    }

    private static function add($a,$b){
        try{
            return Money::add($a,$b);
        }
        catch(\InvalidArgumentException $e){
            throw new ValidationException(['amount'=>'جمع مقدار یا مبلغ از محدوده مجاز بزرگ‌تر است.']);
        }
    }

    private static function scale($a,$b,$d){
        try{
            $v=Money::mulDiv($a,$b,$d);
        }
        catch(\InvalidArgumentException $e){
            throw new ValidationException(['qty'=>'مقدار محاسبه‌شده از محدوده مجاز بزرگ‌تر است.']);
        }
        return max(1,$v);
    }

    private static function goods($cid,$id){
        $r=Business::row('items',$cid,$id);
        if(!$r['is_active']||$r['kind']!=='goods')throw new ValidationException(['item_id'=>'کالای انباری فعال انتخاب کنید؛ خدمت ماده اولیه یا محصول انباری نیست.']);
        return $r;
    }

    private static function warehouse($cid,$id){
        $r=Business::row('warehouses',$cid,$id);
        if(!$r['is_active'])throw new ValidationException(['warehouse_id'=>'انبار فعال انتخاب کنید.']);
        return $r;
    }

    private static function account($cid,$id,$kind){
        $a=Business::row('accounts',$cid,Business::id($id));
        if(!$a['is_active']||(int)$a['level']!==3||$a['detail_rule']==='required')throw new ValidationException(['account'=>'حساب معین فعال بدون تفصیلی اجباری انتخاب کنید.']);
        $class=$a['stmt_class'];
        if($kind==='wip'&&$class!=='asset_current')throw new ValidationException(['account'=>'حساب در جریان ساخت باید دارایی جاری باشد.']);
        if($kind==='expense'&&!in_array($class,['cogs','opex','other_expense','finance_cost'],true))throw new ValidationException(['account'=>'حساب انتقال دستمزد، سربار و ضایعات باید هزینه یا بهای تمام‌شده باشد.']);
        return (int)$a['id'];
    }

    public static function accounts($cid){
        Access::need($cid,'production.view');
        $out=[];
        foreach(['wip'=>'10321','labor'=>'70130','overhead'=>'70131'] as $key=>$code)$out[$key]=(int)(Settings::get('production_'.$key.'_account',$cid)?:Db::val('SELECT id FROM '.Db::t('accounts').' WHERE company_id=%d AND code=%s',$cid,$code));
        $out['loss']=(int)(Settings::get('production_loss_account',$cid)?:AccountService::bySysKey($cid,'other_expense')['id']);
        return $out;
    }

    public static function configure($cid,array $ids){
        return Access::write($cid,'company_settings.edit',function()use($cid,$ids){
            self::manufacturing($cid);
            StockService::configure($cid,Settings::get('stock_cost_method',$cid),'inventory');
            $new=[];
            foreach(['wip','labor','overhead','loss'] as $k)$new[$k]=self::account($cid,$ids[$k]??0,$k==='wip'?'wip':'expense');
            $inv=(int)AccountService::bySysKey($cid,'inventory')['id'];
            if(in_array($inv,$new,true)||in_array($new['wip'],[$new['labor'],$new['overhead'],$new['loss']],true)||in_array($new['loss'],[$new['labor'],$new['overhead']],true))throw new ValidationException(['account'=>'موجودی، در جریان ساخت و ضایعات غیرعادی حساب‌های جدا داشته باشند.']);
            foreach($new as $k=>$v)Settings::set('production_'.$k.'_account',(string)$v,$cid);
            Audit::log('production.configure','company',$cid,null,$new,$cid);
        }
        );
    }

    private static function checkedAccounts($cid){
        $ids=self::accounts($cid);
        foreach($ids as $k=>$id)$ids[$k]=self::account($cid,$id,$k==='wip'?'wip':'expense');
        $inv=(int)AccountService::bySysKey($cid,'inventory')['id'];
        if(in_array($inv,$ids,true)||in_array($ids['wip'],[$ids['labor'],$ids['overhead'],$ids['loss']],true)||in_array($ids['loss'],[$ids['labor'],$ids['overhead']],true))throw new ValidationException(['account'=>'حساب‌های تولید را جدا و معتبر تنظیم کنید.']);
        return $ids;
    }

    public static function bom($cid,$id){
        Access::need($cid,'bom.view');
        $b=Business::row('production_boms',$cid,$id);
        $b['lines']=Db::rows('SELECT l.*,i.code AS item_code,i.name AS item_name,u.name AS unit_name FROM '.Db::t('production_bom_lines').' l JOIN '.Db::t('items').' i ON i.company_id=l.company_id AND i.id=l.item_id JOIN '.Db::t('units').' u ON u.company_id=i.company_id AND u.id=i.unit_id WHERE l.company_id=%d AND l.bom_id=%d ORDER BY l.id',$cid,$id);
        return $b;
    }

    private static function noCycle($cid,$id,$product,array $materials){
        $graph=[];
        $edges=Db::rows('SELECT b.product_id,l.item_id FROM '.Db::t('production_boms').' b JOIN '.Db::t('production_bom_lines').' l ON l.company_id=b.company_id AND l.bom_id=b.id WHERE b.company_id=%d AND b.is_active=1 AND b.id<>%d LIMIT 10001',$cid,$id);
        if(count($edges)>10000)throw new ValidationException(['lines'=>'حجم ارتباط فرمول‌ها از حد بررسی ایمن عبور کرد.']);
        foreach($edges as $e)$graph[$e['product_id']][]=(int)$e['item_id'];
        $stack=$materials;
        $seen=[];
        while($stack){
            $n=array_pop($stack);
            if($n===$product)throw new ValidationException(['lines'=>'فرمول ساخت چرخه دارد؛ محصول نمی‌تواند مستقیم یا با واسطه ماده اولیه خودش باشد.']);
            if(isset($seen[$n]))continue;
            $seen[$n]=true;
            foreach($graph[$n]??[] as $child)$stack[]=$child;
        }
    }

    public static function saveBom($cid,$id,array $in,array $lines,$revision=0){
        return Access::write($cid,$id?'bom.edit':'bom.create',function()use($cid,$id,$in,$lines,$revision){
            self::manufacturing($cid);
            $old=$id?Business::row('production_boms',$cid,$id,true):null;
            if($old&&(int)$old['revision']!==$revision)throw new ConflictException('فرمول تغییر کرده است؛ صفحه را تازه کنید.');
            $code=Money::normDigits($in['code']??'');
            $name=Request::clean($in['name']??'',190);
            if(!preg_match('/^[A-Za-z0-9_-]{1,30}$/D',$code)||mb_strlen($name)<2)throw new ValidationException(['name'=>'کد و نام معتبر فرمول وارد کنید.']);
            if(Db::val('SELECT id FROM '.Db::t('production_boms').' WHERE company_id=%d AND code=%s AND id<>%d',$cid,$code,$id))throw new ValidationException(['code'=>'کد فرمول تکراری است.']);
            $product=Business::id($in['product_id']??0);
            self::goods($cid,$product);
            $base=self::qty($in['base_qty']??0);
            if(!$lines||count($lines)>80)throw new ValidationException(['lines'=>'یک تا ۸۰ ماده اولیه وارد کنید.']);
            $clean=[];
            $seen=[];
            foreach($lines as $r){
                if(!is_array($r))throw new ValidationException(['lines'=>'ردیف معتبر نیست.']);
                $item=Business::id($r['item_id']??0);
                self::goods($cid,$item);
                if(isset($seen[$item]))throw new ValidationException(['lines'=>'ماده اولیه تکراری است.']);
                $seen[$item]=true;
                $bp=$r['scrap_bp']??0;
                if(!is_int($bp)||$bp<0||$bp>10000)throw new ValidationException(['scrap_bp'=>'ضایعات عادی بین صفر تا صد درصد باشد.']);
                $clean[]=['item_id'=>$item,'qty_scaled'=>self::qty($r['qty_scaled']??0),'scrap_bp'=>$bp];
            }
            self::noCycle($cid,$id,$product,array_keys($seen));
            $data=['code'=>$code,'name'=>$name,'product_id'=>$product,'base_qty'=>$base,'revision'=>$old?(int)$old['revision']+1:1,'is_active'=>empty($in['is_active'])?0:1,'description'=>Request::clean($in['description']??'',500)];
            $id=Business::save('production_boms',$cid,$id,$data);
            Db::delete('production_bom_lines',['company_id'=>$cid,'bom_id'=>$id]);
            foreach($clean as $l)Business::save('production_bom_lines',$cid,0,['bom_id'=>$id]+$l);
            Audit::log('production.bom.save','production_bom',$id,$old,$data,$cid);
            return $id;
        }
        );
    }

    public static function order($cid,$id){
        Access::need($cid,'production.view');
        $o=Business::row('production_orders',$cid,$id);
        $o['lines']=Db::rows('SELECT * FROM '.Db::t('production_order_lines').' WHERE company_id=%d AND order_id=%d ORDER BY id',$cid,$id);
        return $o;
    }

    public static function plan($cid,$id,array $in,$revision=0){
        return Access::write($cid,$id?'production.edit':'production.create',function()use($cid,$id,$in,$revision){
            self::ready($cid);
            $old=$id?Business::row('production_orders',$cid,$id,true):null;
            if($old&&($old['status']!=='draft'||(int)$old['revision']!==$revision))throw new ConflictException('فقط نسخه فعلی دستور پیش‌نویس قابل تغییر است.');
            $bom=Business::row('production_boms',$cid,Business::id($in['bom_id']??0));
            if(!$bom['is_active'])throw new ValidationException(['bom_id'=>'فرمول فعال انتخاب کنید.']);
            $product=self::goods($cid,$bom['product_id']);
            $qty=self::qty($in['planned_qty']??0);
            $date=Business::date($in['doc_date']??'');
            $fy=FiscalService::assertOpen($cid,$date);
            $from=Business::id($in['material_warehouse_id']??0);
            $to=Business::id($in['warehouse_id']??0);
            self::warehouse($cid,$from);
            self::warehouse($cid,$to);
            $lines=Db::rows('SELECT l.*,i.code AS item_code,i.name AS item_name,u.name AS unit_name FROM '.Db::t('production_bom_lines').' l JOIN '.Db::t('items').' i ON i.company_id=l.company_id AND i.id=l.item_id JOIN '.Db::t('units').' u ON u.company_id=i.company_id AND u.id=i.unit_id WHERE l.company_id=%d AND l.bom_id=%d ORDER BY l.id',$cid,$bom['id']);
            if(!$lines)throw new ValidationException(['bom_id'=>'فرمول ماده اولیه ندارد.']);
            $unit=Business::row('units',$cid,$product['unit_id']);
            $snapshot=['bom'=>$bom,'product_name'=>$product['name'],'product_code'=>$product['code'],'unit_name'=>$unit['name'],'lines'=>$lines,'cost_method'=>Settings::get('stock_cost_method',$cid)];
            $data=['fiscal_year_id'=>$fy['id'],'bom_id'=>$bom['id'],'bom_revision'=>$bom['revision'],'product_id'=>$bom['product_id'],'warehouse_id'=>$to,'doc_date'=>$date,'planned_qty'=>$qty,'snapshot'=>wp_json_encode($snapshot),'description'=>Request::clean($in['description']??'',500),'revision'=>$old?(int)$old['revision']+1:1];
            if(!$old||$old['fiscal_year_id']!=$fy['id'])$data['number']=Sequence::next($cid,'production',$fy['id']);
            if(!$old)$data['created_by']=Ctx::uid();
            $id=Business::save('production_orders',$cid,$id,$data);
            Db::delete('production_order_lines',['company_id'=>$cid,'order_id'=>$id]);
            foreach($lines as $l){
                self::goods($cid,$l['item_id']);
                $q=self::scale((int)$l['qty_scaled'],$qty,(int)$bom['base_qty']);
                $q=self::scale($q,10000+(int)$l['scrap_bp'],10000);
                Business::save('production_order_lines',$cid,0,['order_id'=>$id,'item_id'=>$l['item_id'],'warehouse_id'=>$from,'planned_qty'=>$q,'item_code'=>$l['item_code'],'item_name'=>$l['item_name'],'unit_name'=>$l['unit_name']]);
            }
            Audit::log('production.plan','production_order',$id,$old,['bom_id'=>$bom['id'],'bom_revision'=>$bom['revision'],'planned_qty'=>$qty],$cid);
            return $id;
        }
        );
    }

    public static function start($cid,$id,$revision,$date,array $actual=[],$reason=''){
        return Access::write($cid,'production.approve',function()use($cid,$id,$revision,$date,$actual,$reason){
            self::ready($cid);
            $o=self::order($cid,$id);
            if($o['status']!=='draft'||(int)$o['revision']!==$revision)throw new ConflictException('دستور قبلاً شروع شده یا نسخه فرم قدیمی است.');
            $date=Business::date($date);
            if($date<$o['doc_date'])throw new ValidationException(['date'=>'شروع تولید پیش از تاریخ دستور مجاز نیست.']);
            FiscalService::assertOpen($cid,$date);
            $accounts=self::checkedAccounts($cid);
            $ids=array_map('strval',array_column($o['lines'],'id'));
            foreach(array_keys($actual) as $k)if(!in_array((string)$k,$ids,true))throw new ValidationException(['lines'=>'ماده مصرفی متعلق به این دستور نیست.']);
            if($actual&&count($actual)!==count($o['lines']))throw new ValidationException(['lines'=>'مقدار همه مواد دستور را مشخص کنید.']);
            $different=false;
            $positive=false;
            foreach($o['lines'] as &$l){
                $l['actual_qty']=self::qty($actual?$actual[$l['id']]:(int)$l['planned_qty'],true);
                $different=$different||$l['actual_qty']!==(int)$l['planned_qty'];
                $positive=$positive||$l['actual_qty']>0;
            }
            unset($l);
            if(!$positive)throw new ValidationException(['lines'=>'حداقل یک ماده مصرفی با مقدار مثبت لازم است.']);
            $reason=Request::clean($reason,300);
            if($different)$reason=Business::reason($reason);
            foreach($o['lines'] as $material){
            $balance=StockService::balanceInternal($cid,(int)$material['warehouse_id'],(int)$material['item_id']);
            if($material['actual_qty']>$balance['qty'])throw new ConflictException('موجودی ماده «'.$material['item_name'].'» کافی نیست؛ موجودی: '.Money::qtyToDb($balance['qty']).' '.$material['unit_name']);
        }
        Business::save('production_orders',$cid,$id,['status'=>'posting_start','start_date'=>$date,'variance_reason'=>$reason,'wip_account_id'=>$accounts['wip'],'labor_account_id'=>$accounts['labor'],'overhead_account_id'=>$accounts['overhead'],'loss_account_id'=>$accounts['loss']]);
            $cost=0;
            foreach($o['lines'] as $l){
                Business::save('production_order_lines',$cid,(int)$l['id'],['actual_qty'=>$l['actual_qty']]);
                $value=$l['actual_qty']?StockService::move($cid,(int)$l['warehouse_id'],(int)$l['item_id'],$date,-$l['actual_qty'],0,'production',$id,(int)$l['id']):0;
                Business::save('production_order_lines',$cid,(int)$l['id'],['cost'=>$value]);
                $cost=self::add($cost,$value);
            }
            $vid=$cost?VoucherService::postSystem(['voucher_date'=>$date,'source_type'=>'production_start','source_id'=>$id,'description'=>'مصرف مواد دستور تولید '.$o['number']],[['account_id'=>$accounts['wip'],'debit'=>$cost,'credit'=>0],['account_id'=>(int)AccountService::bySysKey($cid,'inventory')['id'],'debit'=>0,'credit'=>$cost]]):null;
            Business::save('production_orders',$cid,$id,['status'=>'running','material_cost'=>$cost,'start_voucher_id'=>$vid,'revision'=>$revision+1]);
            Audit::log('production.start','production_order',$id,['status'=>'draft'],['status'=>'running','material_cost'=>$cost,'variance_reason'=>$reason],$cid);
            return $id;
        }
        );
    }

    private static function availableCosts($cid,$date,array $amounts){
        $fy=FiscalService::assertOpen($cid,$date);
        foreach($amounts as $account=>$amount){
            if(!$amount)continue;
            self::account($cid,(int)$account,'expense');
            $balance=Money::integer(Db::val('SELECT COALESCE(SUM(CAST(l.debit AS SIGNED)-CAST(l.credit AS SIGNED)),0) FROM '.Db::t('voucher_lines').' l JOIN '.Db::t('vouchers')." v ON v.company_id=l.company_id AND v.id=l.voucher_id WHERE v.company_id=%d AND l.account_id=%d AND v.fiscal_year_id=%d AND v.voucher_date<=%s AND v.status IN ('approved','final')",$cid,$account,$fy['id'],$date));
            if($balance<$amount)throw new ValidationException(['cost'=>'مانده هزینه ثبت‌شده برای انتقال دستمزد یا سربار کافی نیست؛ ابتدا هزینه واقعی را ثبت کنید.']);
        }
    }

    public static function complete($cid,$id,$revision,array $in){
        return Access::write($cid,'production.approve',function()use($cid,$id,$revision,$in){
            self::ready($cid);
            $o=self::order($cid,$id);
            if($o['status']!=='running'||(int)$o['revision']!==$revision)throw new ConflictException('دستور در جریان تولید با نسخه فعلی لازم است.');
            $date=Business::date($in['finish_date']??'');
            if($date<$o['start_date'])throw new ValidationException(['date'=>'تاریخ تکمیل پیش از شروع تولید نیست.']);
            FiscalService::assertOpen($cid,$date);
            $qty=self::qty($in['actual_qty']??0);
            $scrap=self::qty($in['scrap_qty']??0,true);
            $labor=Business::amount($in['labor_cost']??0);
            $overhead=Business::amount($in['overhead_cost']??0);
            $abnormal=Business::amount($in['abnormal_cost']??0);
            $reason=Request::clean($in['reason']??'',300);
            if($qty!==(int)$o['planned_qty']||$scrap||$abnormal)$reason=Business::reason($reason);
            $full=self::add(self::add((int)$o['material_cost'],$labor),$overhead);
            if($abnormal>$full)throw new ValidationException(['abnormal_cost'=>'هزینه ضایعات غیرعادی از کل هزینه تولید بیشتر است.']);
            $cost=$full-$abnormal;
            $amounts=[];
            foreach([[$o['labor_account_id'],$labor],[$o['overhead_account_id'],$overhead]] as [$a,$v])$amounts[$a]=self::add($amounts[$a]??0,$v);
            self::availableCosts($cid,$date,$amounts);
            self::account($cid,$o['wip_account_id'],'wip');
            self::account($cid,$o['loss_account_id'],'expense');
            Business::save('production_orders',$cid,$id,['status'=>'posting_finish','finish_date'=>$date,'actual_qty'=>$qty,'scrap_qty'=>$scrap,'labor_cost'=>$labor,'overhead_cost'=>$overhead,'abnormal_cost'=>$abnormal,'total_cost'=>$cost]);
            StockService::move($cid,(int)$o['warehouse_id'],(int)$o['product_id'],$date,$qty,$cost,'production',$id);
            $lines=[];
            if($labor+$overhead)$lines[]=['account_id'=>(int)$o['wip_account_id'],'debit'=>self::add($labor,$overhead),'credit'=>0];
            foreach($amounts as $a=>$v)if($v)$lines[]=['account_id'=>(int)$a,'debit'=>0,'credit'=>$v];
            if($full){
                if($cost)$lines[]=['account_id'=>(int)AccountService::bySysKey($cid,'inventory')['id'],'debit'=>$cost,'credit'=>0];
                if($abnormal)$lines[]=['account_id'=>(int)$o['loss_account_id'],'debit'=>$abnormal,'credit'=>0];
                $lines[]=['account_id'=>(int)$o['wip_account_id'],'debit'=>0,'credit'=>$full];
            }
            $vid=$lines?VoucherService::postSystem(['voucher_date'=>$date,'source_type'=>'production_finish','source_id'=>$id,'description'=>'تکمیل و جذب هزینه دستور تولید '.$o['number']],$lines):null;
            Business::save('production_orders',$cid,$id,['status'=>'completed','finish_voucher_id'=>$vid,'revision'=>$revision+1,'variance_reason'=>trim($o['variance_reason'].' | '.$reason,' |')]);
            Audit::log('production.complete','production_order',$id,['status'=>'running'],['status'=>'completed','actual_qty'=>$qty,'scrap_qty'=>$scrap,'material_cost'=>(int)$o['material_cost'],'labor_cost'=>$labor,'overhead_cost'=>$overhead,'abnormal_cost'=>$abnormal,'total_cost'=>$cost,'reason'=>$reason],$cid);
            return $id;
        }
        );
    }

    public static function voidOrder($cid,$id,$revision,$reason){
        return Access::write($cid,'production.delete',function()use($cid,$id,$revision,$reason){
            $o=self::order($cid,$id);
            if($o['status']==='void'||(int)$o['revision']!==$revision)throw new ConflictException('نسخه فعلی دستور باطل‌نشده لازم است.');
            $reason=Business::reason($reason);
            Business::save('production_orders',$cid,$id,['status'=>'posting_void']);
            if($o['status']!=='draft'){
                StockService::reverseSource($cid,'production',$id);
                if($o['finish_voucher_id'])VoucherService::voidSystem((int)$o['finish_voucher_id'],$reason);
                if($o['start_voucher_id'])VoucherService::voidSystem((int)$o['start_voucher_id'],$reason);
            }
            Business::save('production_orders',$cid,$id,['status'=>'void','revision'=>$revision+1,'variance_reason'=>$reason]);
            Audit::log('production.void','production_order',$id,['status'=>$o['status']],['status'=>'void','reason'=>$reason],$cid);
        }
        );
    }
    /** Only ProductionService's transient posting phases can create/reverse manufacturing movements. */

    public static function authorizeMove($cid,$id,$line,$warehouse,$item,$qty,$incoming){
        Access::need($cid,'production.approve');
        $o=Business::row('production_orders',$cid,$id,true);
        if($line){
            $l=Business::row('production_order_lines',$cid,$line);
            $ok=$o['status']==='posting_start'&&(int)$l['order_id']===$id&&(int)$l['warehouse_id']===$warehouse&&(int)$l['item_id']===$item&&$qty===-(int)$l['actual_qty']&&$incoming===0;
        }
        else{
            $ok=$o['status']==='posting_finish'&&(int)$o['warehouse_id']===$warehouse&&(int)$o['product_id']===$item&&$qty===(int)$o['actual_qty']&&$incoming===(int)$o['total_cost'];
        }
        if(!$ok||Db::val('SELECT id FROM '.Db::t('stock_moves')." WHERE company_id=%d AND production_order_id=%d AND ".($line?'production_line_id=%d':'production_line_id IS NULL')." AND status='active' LIMIT 1",...($line?[$cid,$id,$line]:[$cid,$id])))throw new ConflictException('گردش تولید با دستور یا مرحله ثبت سازگار نیست.');
    }

    public static function authorizeVoucher($cid,$id,$type){
        $o=Business::row('production_orders',$cid,$id,true);
        if($o['status']!==($type==='production_start'?'posting_start':'posting_finish'))throw new ConflictException('سند تولید خارج از مرحله مجاز است.');
    }

    public static function page($cid,$page=1){
        Access::need($cid,'production.view');
        $range=FiscalContext::range($cid,[]);
        $page=max(1,(int)$page);
        $where='company_id=%d AND doc_date<=%s AND (doc_date>=%s OR (finish_date>=%s AND finish_date<=%s) OR status=\'running\')';
        $args=[$cid,$range['to'],$range['from'],$range['from'],$range['to']];
        $count=(int)Db::val('SELECT COUNT(*) FROM '.Db::t('production_orders').' WHERE '.$where,...$args);
        return ['rows'=>Db::rows('SELECT * FROM '.Db::t('production_orders').' WHERE '.$where.' ORDER BY id DESC LIMIT 50 OFFSET %d',...array_merge($args,[($page-1)*50])),'page'=>$page,'pages'=>(int)ceil($count/50),'count'=>$count];
    }
}
