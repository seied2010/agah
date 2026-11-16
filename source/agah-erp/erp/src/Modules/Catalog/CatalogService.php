<?php
/** واحد، گروه و کالا/خدمت؛ FKهای ترکیبی و اعتبارسنجی حساب‌ها مانع پیوند بین‌شرکتی می‌شوند. */
namespace Agah\Erp\Modules\Catalog;
use Agah\Erp\Core\{Db,Ctx,Access,Business,Audit,Request,Settings,Money,Sequence,ValidationException,ConflictException};
if(!defined('ABSPATH')) exit;
final class CatalogService {
    private static function table($type){$map=['unit'=>'units','group'=>'item_groups','item'=>'items','tax'=>'tax_rates'];if(!isset($map[$type]))throw new ValidationException(['type'=>'نوع اطلاعات معتبر نیست.']);return $map[$type];}
    public static function page($cid,$type,$page=1,$q=''){Access::need($cid,$type==='tax'?'tax_rate.view':'item.view');return Business::page(self::table($type),$cid,$page,$q);}
    public static function get($cid,$type,$id){Access::need($cid,$type==='tax'?'tax_rate.view':'item.view');return Business::row(self::table($type),$cid,$id);}
    /** ویرایش نرخ با تاریخ مؤثر؛ همپوشانی نرخ یک کد ممنوع و تأیید فقط با مجوز مستقل است. */
    public static function save($cid,$type,$id,array $in){$table=self::table($type);return Access::write($cid,$type==='tax'?'tax_rate.edit':($id?'item.edit':'item.create'),function()use($cid,$type,$table,$id,$in){
        $old=$id?Business::row($table,$cid,$id,true):null;$name=Request::clean($in['name']??'',$type==='item'?190:100);$code=Money::normDigits(trim($in['code']??''));$errors=[];
        if(mb_strlen($name)<2)$errors['name']='نام حداقل دو نویسه باشد.';
        if(!preg_match('/^[A-Za-z0-9_-]{1,'.(in_array($type,['unit','group'],true)?20:30).'}$/D',$code))$errors['code']='کد لاتین/رقمی معتبر وارد کنید.';
        if($errors)throw new ValidationException($errors);
        $new=['name'=>$name,'code'=>$code];
        if($type==='tax'){
            $bp=$in['bp']??null;if(!is_int($bp)||$bp<0||$bp>10000)throw new ValidationException(['bp'=>'نرخ بین صفر تا صد درصد باشد.']);
            $from=Business::date($in['valid_from']??'');$to=Business::date($in['valid_to']??'');if($from>$to)throw new ValidationException(['valid_to'=>'بازه تاریخ معکوس است.']);
            if(Db::val('SELECT id FROM '.Db::t('tax_rates').' WHERE company_id=%d AND code=%s AND id<>%d AND valid_from<=%s AND valid_to>=%s',$cid,$code,$id,$to,$from))throw new ValidationException(['valid_from'=>'بازهٔ نرخ همین کد هم‌پوشانی دارد.']);
            $confirmed=empty($in['confirmed'])?0:1;if($confirmed)Ctx::need('tax_rate.confirm');
            $new+=['bp'=>$bp,'valid_from'=>$from,'valid_to'=>$to,'confirmed'=>$confirmed,'revision'=>(int)($old['revision']??0)+1];
        }else{
            if(Db::val('SELECT id FROM '.Db::t($table).' WHERE company_id=%d AND code=%s AND id<>%d',$cid,$code,$id))throw new ValidationException(['code'=>'کد تکراری است.']);
            $new['is_active']=empty($in['is_active'])?0:1;
        }
        if($type==='item'){
            $kind=$in['kind']??'goods';if(!in_array($kind,['goods','service'],true))throw new ValidationException(['kind'=>'نوع کالا/خدمت معتبر نیست.']);
            $unit=Business::row('units',$cid,Business::id($in['unit_id']??0));if(!$unit['is_active'])throw new ValidationException(['unit_id'=>'واحد غیرفعال است.']);
            $group=Business::id($in['group_id']??0);if($group&&!Business::row('item_groups',$cid,$group)['is_active'])throw new ValidationException(['group_id'=>'گروه غیرفعال است.']);
            $tax=Business::id($in['tax_rate_id']??0);if($tax)Business::row('tax_rates',$cid,$tax);
            foreach(['sale_account_id','purchase_account_id'] as $key){$a=Business::row('accounts',$cid,Business::id($in[$key]??0));if((int)$a['level']!==3||!$a['is_active'])throw new ValidationException([$key=>'حساب معین فعال انتخاب کنید.']);$new[$key]=(int)$a['id'];}
            $barcode=Request::clean($in['barcode']??'',80);if($barcode!==''&&Db::val('SELECT id FROM '.Db::t('items').' WHERE company_id=%d AND barcode=%s AND id<>%d',$cid,$barcode,$id))throw new ValidationException(['barcode'=>'بارکد تکراری است.']);
            $new+=['kind'=>$kind,'unit_id'=>$unit['id'],'group_id'=>$group?:null,'tax_rate_id'=>$tax?:null,'barcode'=>$barcode?:null,'sale_price'=>Business::amount($in['sale_price']??0),'purchase_price'=>Business::amount($in['purchase_price']??0),'trade_price'=>Business::amount(is_string($in['trade_price']??null)&&ctype_digit($in['trade_price'])?(int)$in['trade_price']:($in['trade_price']??0)),'attrs'=>self::attrs($in['attrs']??''),'rev'=>Sequence::next($cid,'item_rev',0)];
            if($old&&(Db::val('SELECT id FROM '.Db::t('production_bom_lines').' WHERE company_id=%d AND item_id=%d LIMIT 1',$cid,$id)||Db::val('SELECT id FROM '.Db::t('production_boms').' WHERE company_id=%d AND product_id=%d LIMIT 1',$cid,$id)||Db::val('SELECT id FROM '.Db::t('production_order_lines').' WHERE company_id=%d AND item_id=%d LIMIT 1',$cid,$id)||Db::val('SELECT id FROM '.Db::t('production_orders').' WHERE company_id=%d AND product_id=%d LIMIT 1',$cid,$id)||Db::val('SELECT id FROM '.Db::t('invoice_lines').' WHERE company_id=%d AND item_id=%d LIMIT 1',$cid,$id)||Db::val('SELECT id FROM '.Db::t('stock_moves').' WHERE company_id=%d AND item_id=%d LIMIT 1',$cid,$id)))foreach(['kind','unit_id','sale_account_id','purchase_account_id'] as $key)if((string)$old[$key]!== (string)$new[$key])throw new ConflictException('ساختار کالای دارای گردش تغییر نمی‌کند.');
        }
        $id=Business::save($table,$cid,$id,$new);if($type==='tax'&&!empty($new['confirmed']))Settings::set('tax_confirmed','1',$cid);Audit::log('catalog.'.$type.'.save',$type,$id,$old,$new,$cid);return $id;
    });}
    /** مشخصات فروشگاهی کالا: هر سطر «کلید=مقدار»؛ کلید لاتین کوچک، حداکثر ۳۰ سطر. فقط برای نمایش در فروشگاه است، نه محاسبهٔ مالی. */
    public static function attrs($text){
        // ردیف خوانده‌شده از پایگاه داده JSON است؛ همان دوباره اعتبارسنجی می‌شود.
        if(is_string($text)&&$text!==''&&$text[0]==='{'){$map=json_decode($text,true);if(!is_array($map))throw new ValidationException(['attrs'=>'مشخصات فروشگاهی معتبر نیست.']);$lines=[];foreach($map as $k=>$v)$lines[]=$k.'='.$v;$text=implode("\n",$lines);}
        $out=[];foreach(preg_split('/\R/u',(string)$text) as $i=>$line){$line=trim($line);if($line==='')continue;$parts=explode('=',$line,2);$k=trim($parts[0]);$v=Request::clean($parts[1]??'',200);
            if(!preg_match('/^[a-z][a-z0-9_]{0,39}$/D',$k)||$v==='')throw new ValidationException(['attrs'=>'هر سطر مشخصات باید «کلید=مقدار» با کلید لاتین کوچک باشد.']);$out[$k]=$v;}
        if(count($out)>30)throw new ValidationException(['attrs'=>'حداکثر ۳۰ مشخصه مجاز است.']);
        return $out?wp_json_encode($out,JSON_UNESCAPED_UNICODE):null;
    }
    /** حذف فقط برای رکورد بدون سابقه مجاز است؛ نرخ پیش‌فرض سامانه هرگز حذف نمی‌شود. */
    public static function delete($cid,$type,$id){$cap=$type==='tax'?'tax_rate.edit':'item.delete';Access::write($cid,$cap,function()use($cid,$type,$id){$table=self::table($type);$old=Business::row($table,$cid,$id,true);
        if($type==='tax'){
            if($old['code']==='unconfirmed')throw new ConflictException('نرخ پیش‌فرض سامانه حذف نمی‌شود.');
            $refs=['items'=>'tax_rate_id','invoice_lines'=>'tax_rate_id'];
        }else $refs=$type==='item'?['production_boms'=>'product_id','production_bom_lines'=>'item_id','production_orders'=>'product_id','production_order_lines'=>'item_id','invoice_lines'=>'item_id','stock_moves'=>'item_id','stock_minimums'=>'item_id']:['items'=>$type==='unit'?'unit_id':'group_id'];
        foreach($refs as $ref=>$col)if(Db::val('SELECT id FROM '.Db::t($ref).' WHERE company_id=%d AND '.$col.'=%d LIMIT 1',$cid,$id))throw new ConflictException('رکورد دارای ارجاع حذف نمی‌شود؛ ابتدا ارجاع‌ها را بررسی کنید.');
        Db::delete($table,['company_id'=>$cid,'id'=>$id]);Audit::log('catalog.'.$type.'.delete',$type,$id,$old,null,$cid);
    });}
    /** قرارداد خطوط فاکتور آینده: نرخ و مبلغ مالیات در خروجی snapshot ذخیره می‌شوند و هرگز زنده محاسبه نمی‌مانند. */
    public static function taxSnapshot($cid,$rateId,$date,$net){if(Ctx::cidOrZero()!==(int)$cid)throw new \Agah\Erp\Core\NotFoundException();Business::date($date);Business::amount($net);if(!$rateId)return ['tax_rate_id'=>null,'tax_name'=>'غیرمشمول','tax_bp'=>0,'tax_amount'=>0];
        $base=Business::row('tax_rates',$cid,$rateId);$r=Db::row('SELECT * FROM '.Db::t('tax_rates').' WHERE company_id=%d AND code=%s AND valid_from<=%s AND valid_to>=%s',$cid,$base['code'],$date,$date);
        if(!$r||!$r['confirmed']||$r['bp']===null||!Settings::flag('tax_confirmed',$cid))throw new ConflictException('نرخ مالیات این تاریخ هنوز به تأیید مدیر نرسیده است.');
        return ['tax_rate_id'=>(int)$r['id'],'tax_name'=>$r['name'],'tax_bp'=>(int)$r['bp'],'tax_amount'=>Money::percentOf($net,(int)$r['bp'])];
    }
}
