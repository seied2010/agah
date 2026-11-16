<?php
/**
 * زیرساخت سامانهٔ مؤدیان (TRD-05، NTF-04، Q40).
 *
 * - شمارهٔ داخلی برگه (invoices.number)، شناسهٔ رسمی کالا/خدمت (items.tax_item_code) و شمارهٔ منحصربه‌فرد مالیاتی
 *   (moadian_records.tax_unique_id) جدا نگه داشته می‌شوند؛ روشن/خاموش کردن ارسال هیچ کد داخلی را تغییر نمی‌دهد.
 * - انتخاب برگه، صف، اعتبارسنجی، ارسال، پیگیری، اصلاحیه و ابطال با سیاست نسخه‌دار ثبت می‌شود.
 * - اتصال واقعی فقط از راه «ارائه‌دهندهٔ رسمی» (فیلتر agah_erp_moadian_provider) است که افزونهٔ اتصالِ دارای مستند رسمی،
 *   اعتبارنامه و کلید خصوصی ثبت می‌کند. تا وقتی ثبت نشده، وضعیت «غیرفعال» گزارش می‌شود و هیچ ارسال یا پذیرشی وانمود نمی‌شود.
 *   ثبت دستی نتیجهٔ ارسال از کارپوشه فقط با شمارهٔ رسمی واردشده توسط انسان و با برچسب «دستی» ممکن است.
 *
 * قرارداد ارائه‌دهنده: name(): string؛ send(array $payload): array؛ inquire(string $reference): array
 * پاسخ: ['status'=>'sent'|'accepted'|'rejected','reference'=>'','tax_unique_id'=>'','errors'=>[]]
 */
namespace Agah\Erp\Modules\Moadian;
use Agah\Erp\Core\{Access,Audit,Business,ConflictException,Ctx,Db,Jalali,Money,Request,ValidationException};
if(!defined('ABSPATH'))exit;
final class MoadianService {
    const ACTIONS=['original'=>'اصلی','correction'=>'اصلاحی','cancel'=>'ابطالی','return'=>'برگشت از فروش'];
    const STATUS=['blocked'=>'دادهٔ ناقص','queued'=>'در صف ارسال','sent'=>'ارسال‌شده؛ در انتظار نتیجه','accepted'=>'پذیرفته‌شده','rejected'=>'ردشده'];
    const KINDS=['sale'=>'فاکتور فروش','sale_return'=>'برگشت از فروش'];
    const TYPES=[1=>'نوع اول (با مشخصات خریدار)',2=>'نوع دوم (بدون مشخصات خریدار)'];
    const PAYLOAD_VERSION='agah-moadian-payload-v1';

    private static function now(){return gmdate('Y-m-d H:i:s');}

    /** ارائه‌دهندهٔ رسمی ثبت‌شده یا null. */
    public static function provider(){
        $p=apply_filters('agah_erp_moadian_provider',null);
        return is_object($p)&&method_exists($p,'send')&&method_exists($p,'name')?$p:null;
    }
    public static function state(){
        $p=self::provider();
        return $p?['active'=>true,'label'=>'اتصال رسمی: '.Request::clean((string)$p->name(),100)]:['active'=>false,'label'=>'غیرفعال — اتصال رسمی سامانهٔ مؤدیان پیکربندی نشده است؛ صف نگه داشته می‌شود و هیچ برگه‌ای ارسال‌شده یا پذیرفته‌شده گزارش نمی‌شود.'];
    }

    /** وضعیت واقعی اتصال‌های رسمی و خروجی‌ها (Q40): هیچ اتصالی بدون ارائه‌دهنده یا داده واقعی «فعال» نشان داده نمی‌شود. */
    public static function integrations(){
        $m=self::state();$out=[];
        $out[]=['name'=>'سامانهٔ مؤدیان','state'=>$m['active']?'active':'inactive','note'=>$m['label']];
        $gw=[];if(function_exists('WC')&&WC()&&method_exists(WC(),'payment_gateways')){foreach((array)WC()->payment_gateways()->payment_gateways() as $g)if(is_object($g)&&isset($g->enabled)&&$g->enabled==='yes')$gw[]=Request::clean((string)($g->method_title?:$g->id),60);}
        $out[]=['name'=>'درگاه پرداخت اشتراک (ووکامرس)','state'=>$gw?'configured':'inactive','note'=>$gw?'روش‌های فعال در ووکامرس: '.implode('، ',$gw).'. درستی اتصال فقط با تراکنش آزمایشی واقعی تأیید می‌شود.':'ووکامرس یا روش پرداخت فعالی در این سایت نیست.'];
        $out[]=['name'=>'پیامک','state'=>has_filter('agah_erp_message_sms')?'configured':'inactive','note'=>has_filter('agah_erp_message_sms')?'ارائه‌دهندهٔ پیامک ثبت شده است.':'ارائه‌دهندهٔ پیامک ثبت نشده؛ پیام‌های پیامکی در صف با خطای «بدون ارائه‌دهنده» می‌مانند.'];
        $out[]=['name'=>'ایمیل','state'=>'available','note'=>'از سامانهٔ ایمیل وردپرس سایت؛ تحویل به تنظیمات ایمیل میزبان وابسته است.'];
        foreach(['فایل بانک حقوق','فهرست بیمهٔ حقوق','فهرست مالیات حقوق'] as $n)$out[]=['name'=>$n,'state'=>'trial','note'=>'آزمایشی — قالب رسمی پس از دریافت نمونهٔ رسمی پیاده می‌شود.'];
        return $out;
    }

    // ------------------------------------------------------------------ سیاست نسخه‌دار

    /** سیاست مؤثر در تاریخ؛ اگر هیچ نسخه‌ای ثبت نشده باشد، پیش‌فرض نسخهٔ ۰ است. */
    public static function policy($cid,$date=null){
        $date=$date?:Jalali::today();
        $r=Db::row('SELECT * FROM '.Db::t('moadian_policies').' WHERE company_id=%d AND effective_from<=%s ORDER BY effective_from DESC,version DESC LIMIT 1',$cid,$date);
        if(!$r)return ['version'=>0,'effective_from'=>'','kinds'=>['sale','sale_return'],'invoice_type'=>1,'auto_select'=>0,'note'=>'پیش‌فرض'];
        $r['kinds']=json_decode((string)$r['kinds'],true)?:[];$r['version']=(int)$r['version'];$r['invoice_type']=(int)$r['invoice_type'];return $r;
    }
    public static function policies($cid){Access::need($cid,'moadian.view');return Db::rows('SELECT * FROM '.Db::t('moadian_policies').' WHERE company_id=%d ORDER BY version DESC',$cid);}
    public static function savePolicy($cid,array $in){
        return Access::write($cid,'moadian.edit',function()use($cid,$in){
            $kinds=array_values(array_intersect(array_keys(self::KINDS),(array)($in['kinds']??[])));if(!$kinds)throw new ValidationException(['kinds'=>'دست‌کم یک نوع برگه برای ارسال انتخاب کنید.']);
            $type=(int)($in['invoice_type']??1);if(!isset(self::TYPES[$type]))throw new ValidationException(['invoice_type'=>'نوع صورتحساب معتبر نیست.']);
            $from=Business::date($in['effective_from']??'');
            $ver=(int)Db::val('SELECT COALESCE(MAX(version),0) FROM '.Db::t('moadian_policies').' WHERE company_id=%d',$cid)+1;
            $row=['version'=>$ver,'effective_from'=>$from,'kinds'=>wp_json_encode($kinds),'invoice_type'=>$type,'auto_select'=>empty($in['auto_select'])?0:1,'note'=>Request::clean($in['note']??'',300),'created_by'=>Ctx::uid()];
            $id=Business::save('moadian_policies',$cid,0,$row);Audit::log('moadian.policy.save','moadian_policy',$id,null,$row,$cid);return $id;
        });
    }
    /** نسخهٔ سیاستی که هیچ ارسالی به آن ارجاع نداده، حذف‌شدنی است. */
    public static function deletePolicy($cid,$id){
        return Access::write($cid,'moadian.edit',function()use($cid,$id){
            $p=Business::row('moadian_policies',$cid,$id,true);
            if(Db::val('SELECT id FROM '.Db::t('moadian_records').' WHERE company_id=%d AND policy_version=%d LIMIT 1',$cid,(int)$p['version']))throw new ConflictException('برگه‌هایی با این نسخهٔ سیاست ثبت شده‌اند؛ برای تغییر، نسخهٔ تازه بسازید.');
            Db::delete('moadian_policies',['company_id'=>$cid,'id'=>$id]);Audit::log('moadian.policy.delete','moadian_policy',$id,$p,null,$cid);return true;
        });
    }

    // ------------------------------------------------------------------ شناسهٔ رسمی کالا/خدمت

    public static function validItemCode($v){return $v===''||(bool)preg_match('/^\d{13}$/D',$v);}
    public static function items($cid,$page,$q,$missing=false){
        Access::need($cid,'moadian.view');$where='company_id=%d';$args=[$cid];
        if($q!==''){global $wpdb;$like='%'.$wpdb->esc_like($q).'%';$where.=' AND (code LIKE %s OR name LIKE %s OR tax_item_code LIKE %s)';array_push($args,$like,$like,$like);}
        if($missing)$where.=" AND tax_item_code='' AND is_active=1";
        $n=(int)Db::val('SELECT COUNT(*) FROM '.Db::t('items').' WHERE '.$where,...$args);$page=max(1,(int)$page);
        return ['rows'=>Db::rows('SELECT id,code,name,kind,is_active,tax_item_code FROM '.Db::t('items').' WHERE '.$where.' ORDER BY code LIMIT 50 OFFSET %d',...array_merge($args,[($page-1)*50])),'page'=>$page,'pages'=>(int)ceil($n/50),'count'=>$n];
    }
    /** شناسهٔ رسمی ۱۳ رقمی جدا از کد داخلی ذخیره می‌شود؛ نسخهٔ همگام‌سازی کاتالوگ فروشگاه تغییر نمی‌کند. */
    public static function setItemCode($cid,$itemId,$code){
        return Access::write($cid,'moadian.edit',function()use($cid,$itemId,$code){
            $code=Money::normDigits(trim((string)$code));if(!self::validItemCode($code))throw new ValidationException(['tax_item_code'=>'شناسهٔ کالا/خدمت سامانهٔ مؤدیان ۱۳ رقم است.']);
            $it=Business::row('items',$cid,$itemId,true);if($it['tax_item_code']===$code)return true;
            Db::update('items',['tax_item_code'=>$code],['company_id'=>$cid,'id'=>(int)$it['id']]);
            Audit::log('moadian.item_code','item',(int)$it['id'],['tax_item_code'=>$it['tax_item_code']],['tax_item_code'=>$code],$cid);return true;
        });
    }

    // ------------------------------------------------------------------ اعتبارسنجی و ساختار داده

    /** آخرین نتیجهٔ پذیرفته‌شدهٔ هر برگه (برای اصلاحیه، ابطال و مرجع برگشت). */
    public static function accepted($cid,$invoiceId){return Db::row('SELECT * FROM '.Db::t('moadian_records')." WHERE company_id=%d AND invoice_id=%d AND status='accepted' ORDER BY id DESC LIMIT 1",$cid,$invoiceId);}
    private static function check($cid,array $inv,array $policy,$action){
        $e=[];
        if($inv['status']!=='issued')$e[]='برگه صادرشده نیست.';
        if(!in_array($inv['kind'],$policy['kinds'],true))$e[]='نوع برگه در سیاست ارسال (نسخهٔ '.$policy['version'].') نیست.';
        $co=Db::row('SELECT economic_code FROM '.Db::t('companies').' WHERE id=%d',$cid);if(trim((string)($co['economic_code']??''))==='')$e[]='کد اقتصادی شرکت در تنظیمات شرکت ثبت نشده است.';
        $missing=Db::col('SELECT DISTINCT i.name FROM '.Db::t('invoice_lines').' l JOIN '.Db::t('items')." i ON i.company_id=l.company_id AND i.id=l.item_id WHERE l.company_id=%d AND l.invoice_id=%d AND i.tax_item_code=''",$cid,(int)$inv['id']);
        if($missing)$e[]='شناسهٔ رسمی کالا/خدمت ندارد: '.implode('، ',array_slice($missing,0,5)).(count($missing)>5?' و …':'');
        if((int)$policy['invoice_type']===1&&in_array($action,['original','return'],true)){$p=Db::row('SELECT national_id_enc,economic_code FROM '.Db::t('parties').' WHERE company_id=%d AND id=%d',$cid,(int)$inv['party_id']);if(!$p||(trim((string)$p['national_id_enc'])===''&&trim((string)$p['economic_code'])===''))$e[]='صورتحساب نوع اول شناسهٔ ملی یا کد اقتصادی خریدار را لازم دارد.';}
        if($action==='return'&&!self::accepted($cid,(int)$inv['parent_id']))$e[]='فاکتور اصلیِ این برگشت هنوز در سامانه پذیرفته نشده است.';
        if(in_array($action,['correction','cancel'],true)&&!self::accepted($cid,(int)$inv['id']))$e[]='اصلاحیه/ابطال فقط برای برگهٔ پذیرفته‌شده ممکن است.';
        return $e;
    }
    /** ساختار داخلی و نسخه‌دار داده؛ نگاشت به قالب رسمی در ارائه‌دهندهٔ رسمی انجام می‌شود. */
    public static function payload($cid,array $inv,array $policy,$action,$reason=''){
        $co=Db::row('SELECT name,economic_code,national_id_enc FROM '.Db::t('companies').' WHERE id=%d',$cid);$p=Business::row('parties',$cid,(int)$inv['party_id']);
        $lines=[];foreach(Db::rows('SELECT l.*,i.tax_item_code FROM '.Db::t('invoice_lines').' l JOIN '.Db::t('items').' i ON i.company_id=l.company_id AND i.id=l.item_id WHERE l.company_id=%d AND l.invoice_id=%d ORDER BY l.line_no',$cid,(int)$inv['id']) as $l)
            $lines[]=['tax_item_code'=>$l['tax_item_code'],'name'=>$l['item_name'],'unit'=>$l['unit_name'],'qty'=>Money::qtyToDb((int)$l['qty_scaled']),'unit_price_rial'=>Money::toInputValue((int)$l['unit_price'],'rial'),'gross_rial'=>Money::toInputValue((int)$l['gross_amount'],'rial'),'discount_rial'=>Money::toInputValue(Money::add((int)$l['discount_amount'],(int)$l['header_discount']),'rial'),'net_rial'=>Money::toInputValue((int)$l['net_amount'],'rial'),'tax_bp'=>(int)$l['tax_bp'],'tax_rial'=>Money::toInputValue((int)$l['tax_amount'],'rial')];
        $ref=$action==='return'?self::accepted($cid,(int)$inv['parent_id']):(in_array($action,['correction','cancel'],true)?self::accepted($cid,(int)$inv['id']):null);
        return ['format'=>self::PAYLOAD_VERSION,'policy_version'=>(int)$policy['version'],'invoice_type'=>(int)$policy['invoice_type'],'action'=>$action,'reason'=>$reason,
            'internal'=>['uuid'=>$inv['uuid'],'kind'=>$inv['kind'],'number'=>(string)$inv['number'],'date'=>$inv['invoice_date']],'reference_tax_id'=>$ref?$ref['tax_unique_id']:'',
            'seller'=>['name'=>$co['name'],'economic_code'=>(string)$co['economic_code']],
            'buyer'=>(int)$policy['invoice_type']===1?['name'=>$p['name'],'kind'=>$p['kind'],'economic_code'=>(string)$p['economic_code'],'has_national_id'=>trim((string)$p['national_id_enc'])!=='','postal_code'=>(string)($p['postal_code']??'')]:null,
            'total_rial'=>Money::toInputValue((int)$inv['amount'],'rial'),'lines'=>$lines];
    }

    // ------------------------------------------------------------------ صف

    /** برگه‌های صادرشدهٔ مشمول که هنوز در صف یا پذیرفته نیستند. */
    public static function candidates($cid,$from,$to){
        Access::need($cid,'moadian.view');$pol=self::policy($cid);$kinds=$pol['kinds']?:['sale'];
        $in=implode(',',array_fill(0,count($kinds),'%s'));
        return Db::rows('SELECT i.id,i.kind,i.number,i.invoice_date,i.amount,p.name AS party_name FROM '.Db::t('invoices').' i JOIN '.Db::t('parties').' p ON p.company_id=i.company_id AND p.id=i.party_id WHERE i.company_id=%d AND i.status=\'issued\' AND i.kind IN ('.$in.') AND i.invoice_date BETWEEN %s AND %s AND NOT EXISTS (SELECT 1 FROM '.Db::t('moadian_records')." r WHERE r.company_id=i.company_id AND r.invoice_id=i.id AND (r.active_slot=1 OR r.status='accepted')) ORDER BY i.invoice_date,i.id LIMIT 200",...array_merge([$cid],$kinds,[$from,$to]));
    }
    private static function enqueue($cid,array $inv,$action,$reason=''){
        $pol=self::policy($cid,$inv['invoice_date']);$errors=self::check($cid,$inv,$pol,$action);
        $payload=$errors?null:self::payload($cid,$inv,$pol,$action,$reason);$json=$payload?wp_json_encode($payload,JSON_UNESCAPED_UNICODE):null;
        return Business::save('moadian_records',$cid,0,['invoice_id'=>(int)$inv['id'],'action'=>$action,'status'=>$errors?'blocked':'queued','policy_version'=>(int)$pol['version'],'payload'=>$json,'payload_hash'=>$json?hash('sha256',$json):'','errors'=>mb_substr(implode(' ',$errors),0,1000),'reason'=>$reason,'active_slot'=>1,'created_by'=>Ctx::uid()]);
    }
    /** انتخاب خودکار هنگام صدور برگه، فقط اگر سیاست مؤثر آن را خواسته باشد (درون تراکنش صدور). */
    public static function autoSelect($cid,$invoiceId){
        $inv=Business::row('invoices',$cid,$invoiceId);if(!isset(self::KINDS[$inv['kind']]))return;
        $pol=self::policy($cid,$inv['invoice_date']);if(!(int)$pol['auto_select']||!in_array($inv['kind'],$pol['kinds'],true))return;
        if(Db::val('SELECT id FROM '.Db::t('moadian_records')." WHERE company_id=%d AND invoice_id=%d AND (active_slot=1 OR status='accepted') LIMIT 1",$cid,$invoiceId))return;
        self::enqueue($cid,$inv,$inv['kind']==='sale_return'?'return':'original');
    }
    /** پیش از ابطال برگه: موردِ ارسال‌نشده از صف برداشته می‌شود؛ برگهٔ ارسال‌شده یا پذیرفته‌شده بدون ابطالیِ پذیرفته‌شده باطل نمی‌شود. */
    public static function beforeVoid($cid,$invoiceId){
        $t=Db::t('moadian_records');
        if(Db::val("SELECT id FROM $t WHERE company_id=%d AND invoice_id=%d AND (status='sent' OR (status IN ('queued','blocked') AND attempts>0)) LIMIT 1",$cid,$invoiceId))throw new ConflictException('این برگه برای سامانهٔ مؤدیان ارسال شده و نتیجه‌اش هنوز معلوم نیست؛ پس از نتیجه دوباره تلاش کنید.');
        $acc=self::accepted($cid,$invoiceId);
        if($acc&&$acc['action']!=='cancel')throw new ConflictException('این برگه در سامانهٔ مؤدیان پذیرفته شده است؛ ابتدا صورتحساب ابطالی را ارسال و پذیرش آن را ثبت کنید.');
        foreach(Db::rows("SELECT id,action FROM $t WHERE company_id=%d AND invoice_id=%d AND status IN ('queued','blocked')",$cid,$invoiceId) as $r){Db::delete('moadian_records',['company_id'=>$cid,'id'=>(int)$r['id']]);Audit::log('moadian.remove','moadian_record',(int)$r['id'],['invoice_id'=>$invoiceId,'action'=>$r['action']],['reason'=>'ابطال برگه'],$cid);}
    }
    /** انتخاب برگه‌ها برای ارسال؛ برگهٔ دارای سابقهٔ باز یا پذیرفته‌شده دوباره وارد صف نمی‌شود. */
    public static function select($cid,array $ids){
        return Access::write($cid,'moadian.edit',function()use($cid,$ids){
            $ids=array_values(array_unique(array_filter(array_map('intval',$ids))));if(!$ids||count($ids)>200)throw new ValidationException(['ids'=>'بین یک تا ۲۰۰ برگه انتخاب کنید.']);
            $n=['queued'=>0,'blocked'=>0,'skipped'=>0];
            foreach($ids as $id){
                $inv=Business::row('invoices',$cid,$id,true);if(!isset(self::KINDS[$inv['kind']])){$n['skipped']++;continue;}
                if(Db::val('SELECT id FROM '.Db::t('moadian_records')." WHERE company_id=%d AND invoice_id=%d AND (active_slot=1 OR status='accepted') LIMIT 1",$cid,$id)){$n['skipped']++;continue;}
                $rid=self::enqueue($cid,$inv,$inv['kind']==='sale_return'?'return':'original');$n[Db::val('SELECT status FROM '.Db::t('moadian_records').' WHERE company_id=%d AND id=%d',$cid,$rid)]++;
            }
            Audit::log('moadian.select','company',$cid,null,$n+['ids'=>$ids],$cid);return $n;
        });
    }
    /** اصلاحیه یا ابطال برگهٔ پذیرفته‌شده؛ دلیل الزامی است. */
    public static function amend($cid,$invoiceId,$action,$reason){
        return Access::write($cid,'moadian.approve',function()use($cid,$invoiceId,$action,$reason){
            if(!in_array($action,['correction','cancel'],true))throw new ValidationException(['action'=>'نوع اقدام معتبر نیست.']);
            $reason=Request::clean($reason,300);if(mb_strlen($reason)<5)throw new ValidationException(['reason'=>'دلیل اصلاحیه/ابطال را بنویسید.']);
            $inv=Business::row('invoices',$cid,$invoiceId,true);if(!self::accepted($cid,$invoiceId))throw new ConflictException('این برگه هنوز در سامانه پذیرفته نشده است.');
            if(Db::val('SELECT id FROM '.Db::t('moadian_records').' WHERE company_id=%d AND invoice_id=%d AND active_slot=1',$cid,$invoiceId))throw new ConflictException('برای این برگه مورد باز در صف هست.');
            $rid=self::enqueue($cid,$inv,$action,$reason);Audit::log('moadian.amend','moadian_record',$rid,null,['action'=>$action,'reason'=>$reason],$cid);return $rid;
        });
    }
    /** بازبینی دوباره (مثلاً پس از ثبت شناسهٔ کالا)؛ دادهٔ صف از روی برگهٔ فعلی ساخته می‌شود. */
    public static function revalidate($cid,$id){
        return Access::write($cid,'moadian.edit',function()use($cid,$id){
            $r=Business::row('moadian_records',$cid,$id,true);if(!in_array($r['status'],['blocked','queued'],true))throw new ConflictException('فقط مورد ارسال‌نشده بازبینی می‌شود.');
            $inv=Business::row('invoices',$cid,(int)$r['invoice_id']);$pol=self::policy($cid,$inv['invoice_date']);$errors=self::check($cid,$inv,$pol,$r['action']);
            $json=$errors?null:wp_json_encode(self::payload($cid,$inv,$pol,$r['action'],$r['reason']),JSON_UNESCAPED_UNICODE);
            Db::update('moadian_records',['status'=>$errors?'blocked':'queued','policy_version'=>(int)$pol['version'],'payload'=>$json,'payload_hash'=>$json?hash('sha256',$json):'','errors'=>mb_substr(implode(' ',$errors),0,1000),'updated_at'=>self::now()],['company_id'=>$cid,'id'=>$id]);
            return $errors?'blocked':'queued';
        });
    }
    /** خروج از صف: فقط موردی که هرگز ارسال نشده؛ ردپا در ممیزی می‌ماند. */
    public static function remove($cid,$id){
        return Access::write($cid,'moadian.edit',function()use($cid,$id){
            $r=Business::row('moadian_records',$cid,$id,true);if(!in_array($r['status'],['blocked','queued'],true)||(int)$r['attempts']>0)throw new ConflictException('موردی که ارسال شده یا تلاش ارسال داشته از صف حذف نمی‌شود.');
            Db::delete('moadian_records',['company_id'=>$cid,'id'=>$id]);Audit::log('moadian.remove','moadian_record',$id,['invoice_id'=>$r['invoice_id'],'action'=>$r['action']],null,$cid);return true;
        });
    }
    private static function apply($cid,array $r,array $res){
        $st=in_array($res['status']??'',['sent','accepted','rejected'],true)?$res['status']:'';
        if($st==='')throw new ConflictException('پاسخ ارائه‌دهندهٔ رسمی وضعیت معتبر ندارد.');
        $tax=strtoupper(preg_replace('/[^A-Za-z0-9]/','',(string)($res['tax_unique_id']??'')));
        if($st==='accepted'&&!preg_match('/^[A-Z0-9]{22}$/D',$tax))throw new ConflictException('پاسخ «پذیرفته‌شده» شمارهٔ منحصربه‌فرد مالیاتی معتبر ندارد.');
        $final=$st!=='sent';
        Db::update('moadian_records',['status'=>$st,'tax_unique_id'=>$tax,'reference_no'=>mb_substr(Request::clean((string)($res['reference']??$r['reference_no']),64),0,64),'errors'=>mb_substr(implode(' ',array_map('strval',(array)($res['errors']??[]))),0,1000),'active_slot'=>$final?null:1,'sent_at'=>$r['sent_at']?:self::now(),'decided_at'=>$final?self::now():null,'updated_at'=>self::now()],['company_id'=>$cid,'id'=>(int)$r['id']]);
        return $st;
    }
    /** ارسال صف از راه ارائه‌دهندهٔ رسمی. بدون ارائه‌دهنده هیچ چیز ارسال یا شبیه‌سازی نمی‌شود (Q40). */
    public static function send($cid){
        $p=self::provider();if(!$p)throw new ConflictException('اتصال رسمی سامانهٔ مؤدیان پیکربندی نشده است؛ صف دست‌نخورده ماند و چیزی ارسال نشد.');
        return Access::write($cid,'moadian.approve',function()use($cid,$p){
            $n=['sent'=>0,'accepted'=>0,'rejected'=>0,'failed'=>0];
            foreach(Db::rows('SELECT * FROM '.Db::t('moadian_records')." WHERE company_id=%d AND status='queued' ORDER BY id LIMIT 50 FOR UPDATE",$cid) as $r){
                $payload=json_decode((string)$r['payload'],true);
                try{$res=$p->send(is_array($payload)?$payload:[]);$n[self::apply($cid,$r,is_array($res)?$res:[])]++;}
                catch(\Throwable $e){$n['failed']++;Db::update('moadian_records',['attempts'=>min(255,(int)$r['attempts']+1),'errors'=>mb_substr('خطای ارسال: '.$e->getMessage(),0,1000),'updated_at'=>self::now()],['company_id'=>$cid,'id'=>(int)$r['id']]);}
            }
            Audit::log('moadian.send','company',$cid,null,$n,$cid);return $n;
        });
    }
    /** پیگیری نتیجهٔ موارد «ارسال‌شده». */
    public static function poll($cid){
        $p=self::provider();if(!$p||!method_exists($p,'inquire'))throw new ConflictException('اتصال رسمی سامانهٔ مؤدیان پیکربندی نشده است.');
        return Access::write($cid,'moadian.approve',function()use($cid,$p){
            $n=['sent'=>0,'accepted'=>0,'rejected'=>0,'failed'=>0];
            foreach(Db::rows('SELECT * FROM '.Db::t('moadian_records')." WHERE company_id=%d AND status='sent' ORDER BY id LIMIT 50 FOR UPDATE",$cid) as $r){
                try{$res=$p->inquire((string)$r['reference_no']);$n[self::apply($cid,$r,is_array($res)?$res:[])]++;}catch(\Throwable $e){$n['failed']++;}
            }
            return $n;
        });
    }
    /** ثبت دستی نتیجهٔ ارسال از کارپوشه (انسان شمارهٔ رسمی را وارد می‌کند؛ منبع «دستی» ثبت و ممیزی می‌شود). */
    public static function manualResult($cid,$id,$status,$taxId,$reference,$note){
        return Access::write($cid,'moadian.approve',function()use($cid,$id,$status,$taxId,$reference,$note){
            $r=Business::row('moadian_records',$cid,$id,true);if(!in_array($r['status'],['queued','sent'],true))throw new ConflictException('نتیجه فقط برای موردِ در صف یا ارسال‌شده ثبت می‌شود.');
            if(!in_array($status,['accepted','rejected'],true))throw new ValidationException(['status'=>'نتیجه معتبر نیست.']);
            $note=Request::clean($note,300);if($status==='rejected'&&mb_strlen($note)<3)throw new ValidationException(['note'=>'علت رد را بنویسید.']);
            $tax=strtoupper(preg_replace('/[^A-Za-z0-9]/','',Money::normDigits((string)$taxId)));
            if($status==='accepted'&&!preg_match('/^[A-Z0-9]{22}$/D',$tax))throw new ValidationException(['tax_unique_id'=>'شمارهٔ منحصربه‌فرد مالیاتی ۲۲ نویسه است.']);
            if($status==='accepted'&&Db::val('SELECT id FROM '.Db::t('moadian_records')." WHERE company_id=%d AND tax_unique_id=%s AND id<>%d LIMIT 1",$cid,$tax,$id))throw new ConflictException('این شمارهٔ مالیاتی برای برگهٔ دیگری ثبت شده است.');
            Db::update('moadian_records',['status'=>$status,'tax_unique_id'=>$status==='accepted'?$tax:'','reference_no'=>mb_substr(Request::clean((string)$reference,64),0,64),'source'=>'manual','errors'=>$status==='rejected'?$note:'','reason'=>trim($r['reason'].' '.$note),'active_slot'=>null,'decided_at'=>self::now(),'updated_at'=>self::now()],['company_id'=>$cid,'id'=>$id]);
            Audit::log('moadian.manual_result','moadian_record',$id,['status'=>$r['status']],['status'=>$status,'tax_unique_id'=>$tax,'note'=>$note],$cid);return true;
        });
    }
    public static function records($cid,$status,$page){
        Access::need($cid,'moadian.view');$where='r.company_id=%d';$args=[$cid];
        if($status!==''){if(!isset(self::STATUS[$status]))throw new ValidationException(['status'=>'وضعیت معتبر نیست.']);$where.=' AND r.status=%s';$args[]=$status;}
        $n=(int)Db::val('SELECT COUNT(*) FROM '.Db::t('moadian_records').' r WHERE '.$where,...$args);$page=max(1,(int)$page);
        $rows=Db::rows('SELECT r.id,r.invoice_id,r.action,r.status,r.policy_version,r.errors,r.tax_unique_id,r.reference_no,r.source,r.reason,r.attempts,r.sent_at,r.decided_at,r.created_at,i.kind,i.number,i.invoice_date,i.amount,p.name AS party_name FROM '.Db::t('moadian_records').' r JOIN '.Db::t('invoices').' i ON i.company_id=r.company_id AND i.id=r.invoice_id JOIN '.Db::t('parties').' p ON p.company_id=i.company_id AND p.id=i.party_id WHERE '.$where.' ORDER BY r.id DESC LIMIT 50 OFFSET %d',...array_merge($args,[($page-1)*50]));
        return ['rows'=>$rows,'page'=>$page,'pages'=>(int)ceil($n/50),'count'=>$n];
    }
    public static function forInvoice($cid,$invoiceId){Access::need($cid,'moadian.view');return Db::rows('SELECT * FROM '.Db::t('moadian_records').' WHERE company_id=%d AND invoice_id=%d ORDER BY id DESC',$cid,$invoiceId);}
}
