<?php
/**
 * اشتراک آگاه (SUB-01..08، NTF-01).
 * - ووکامرس فقط سفارش و پرداخت را انجام می‌دهد؛ آگاه اشتراک، ماژول‌ها و مجوز را مدیریت می‌کند. سفارش اشتراک از فاکتورهای شرکت مشتری جداست.
 * - فعال‌سازی فقط با «تأیید پرداخت سروری» (قلاب پرداخت کامل ووکامرس) یا تأیید پرداخت در محل توسط مدیر سامانه؛ بازگشت تکراری دورهٔ دوم نمی‌سازد.
 * - مبلغ و دوره همیشه در سرور از کاتالوگ نسخه‌دار تعیین می‌شود. جشنواره: ظرفیت فقط با پرداخت موفق و با قفل اتمیک مصرف می‌شود.
 * - پس از انقضا ثبت تازه بسته می‌شود اما دیدن و خروجی می‌ماند. اجبار اشتراک پیش‌فرض خاموش است (نصب اختصاصی/سازمانی)
 *   و شرکت‌هایی که پیش از روشن‌شدن آن ساخته شده‌اند تا تعیین طرح انتقال قفل نمی‌شوند.
 */
namespace Agah\Erp\Modules\Subscription;
use Agah\Erp\Core\{Access,Audit,Business,ConflictException,Ctx,Db,ForbiddenException,Jalali,Money,NotFoundException,Request,Settings,ValidationException};
use Agah\Erp\Modules\Notify\NotificationService as N;
if(!defined('ABSPATH'))exit;
final class SubscriptionService {
    const PLANS=['specialist'=>['تخصصی (یک نوع فعالیت)','specialist'],'combined'=>['ترکیبی (همهٔ ماژول‌ها)','combined'],'enterprise'=>['سازمانی (نصب اختصاصی، توافقی)','enterprise']];
    const PERIODS=['month'=>['ماهانه',1],'year'=>['سالانه',12]];
    /** قیمت پیش‌فرض (تومان) طبق توافق نهایی؛ در پنل مدیر سامانه نسخه‌دار تغییر می‌کند. */
    const DEFAULT_TOMAN=['specialist|month'=>500000,'combined|month'=>1000000,'specialist|year'=>5400000,'combined|year'=>10800000];
    const STATUS=['pending'=>'در انتظار پرداخت','cod_pending'=>'پرداخت در محل؛ منتظر تأیید مدیر','review'=>'نیازمند بررسی','active'=>'فعال','upgraded'=>'ارتقایافته','cancelled'=>'لغوشده','refunded'=>'مسترد','failed'=>'ناموفق'];
    /** مجوزهایی که پس از انقضا هم مجازند (مدیریت شرکت و دسترسی؛ تمدید). */
    const ALWAYS=['company_settings.edit','company_settings.delete','member.create','member.edit','member.delete','role.edit','dashboard.view'];
    private static function platform(){if(!current_user_can('manage_options'))throw new ForbiddenException();}
    private static function now(){return gmdate('Y-m-d H:i:s');}

    // ------------------------------------------------------------ کاتالوگ

    public static function seedCatalog(){
        foreach(self::PLANS as $code=>[$name,$kind])Db::exec('INSERT IGNORE INTO '.Db::t('sub_plans').' (code,name,kind,is_active,created_at) VALUES (%s,%s,%s,1,%s)',$code,$name,$kind,self::now());
        foreach(self::DEFAULT_TOMAN as $k=>$toman){[$plan,$period]=explode('|',$k);if(!Db::val('SELECT id FROM '.Db::t('sub_prices').' WHERE plan_code=%s AND period=%s LIMIT 1',$plan,$period))Db::insert('sub_prices',['plan_code'=>$plan,'period'=>$period,'amount'=>$toman*1000,'festival_amount'=>null,'version'=>1,'effective_from'=>'2000-01-01 00:00:00','created_at'=>self::now()]);}
    }
    public static function price($plan,$period,$at=null){return Db::row('SELECT * FROM '.Db::t('sub_prices').' WHERE plan_code=%s AND period=%s AND effective_from<=%s ORDER BY effective_from DESC,id DESC LIMIT 1',$plan,$period,$at?:self::now())?:null;}
    public static function prices(){return Db::rows('SELECT * FROM '.Db::t('sub_prices').' ORDER BY plan_code,period,effective_from DESC');}
    /** نسخهٔ تازهٔ قیمت (و قیمت جشنواره) از زمان اثر؛ دوره‌های خریداری‌شده دست نمی‌خورند. */
    public static function savePrice($plan,$period,$amount,$festival,$from){
        self::platform();if(!isset(self::PLANS[$plan])||$plan==='enterprise'||!isset(self::PERIODS[$period]))throw new ValidationException(['plan'=>'طرح یا دوره معتبر نیست.']);
        $amount=Business::amount($amount,true);$festival=$festival===null||$festival===''?null:Business::amount($festival,true);$from=$from?:self::now();
        $last=self::price($plan,$period,'9999-12-31');$id=Db::insert('sub_prices',['plan_code'=>$plan,'period'=>$period,'amount'=>$amount,'festival_amount'=>$festival,'version'=>$last?(int)$last['version']+1:1,'effective_from'=>$from,'created_by'=>get_current_user_id(),'created_at'=>self::now()]);
        Audit::log('subscription.price','platform',0,$last,['plan'=>$plan,'period'=>$period,'amount'=>$amount,'festival'=>$festival,'from'=>$from],0);return $id;
    }
    public static function festival(){$f=(array)get_option('agah_sub_festival',[]);return ['enabled'=>!empty($f['enabled']),'capacity'=>max(0,(int)($f['capacity']??10))];}
    public static function saveFestival($enabled,$capacity){self::platform();$capacity=(int)$capacity;if($capacity<0||$capacity>1000)throw new ValidationException(['capacity'=>'ظرفیت معتبر نیست.']);$old=self::festival();update_option('agah_sub_festival',['enabled'=>(bool)$enabled,'capacity'=>$capacity],false);Audit::log('subscription.festival','platform',0,$old,['enabled'=>(bool)$enabled,'capacity'=>$capacity],0);}
    public static function festivalUsed(){return (int)Db::val('SELECT COUNT(*) FROM '.Db::t('sub_festival_slots'));}

    // ------------------------------------------------------------ وضعیت و اجبار

    public static function enforced(){return get_option('agah_sub_enforce','0')==='1';}
    public static function setEnforce($on){self::platform();$was=self::enforced();update_option('agah_sub_enforce',$on?'1':'0',false);if($on&&!get_option('agah_sub_enforce_since'))update_option('agah_sub_enforce_since',self::now(),false);Audit::log('subscription.enforce','platform',0,['on'=>$was],['on'=>(bool)$on],0);}
    public static function active($cid,$date=null){$d=$date?:Jalali::today();return Db::row('SELECT * FROM '.Db::t('subscriptions')." WHERE company_id=%d AND status='active' AND start_date<=%s AND end_date>=%s ORDER BY end_date DESC LIMIT 1",$cid,$d,$d)?:null;}
    /** شرکت ساخته‌شده پیش از روشن‌شدن اجبار و بدون هیچ اشتراک: «تاریخی» و تا تعیین طرح انتقال آزاد. */
    public static function legacy($cid){$since=get_option('agah_sub_enforce_since');if(!$since)return true;$created=Db::val('SELECT created_at FROM '.Db::t('companies').' WHERE id=%d',$cid);return $created&&$created<$since&&!Db::val('SELECT id FROM '.Db::t('subscriptions')." WHERE company_id=%d AND status IN ('active','upgraded','cancelled','refunded') LIMIT 1",$cid);}
    public static function state($cid){
        $a=self::active($cid);if($a)return ['state'=>'active','sub'=>$a,'days'=>Jalali::diffDays(Jalali::today(),$a['end_date'])];
        if(!self::enforced())return ['state'=>'off','sub'=>null];if(self::legacy($cid))return ['state'=>'legacy','sub'=>null];
        return ['state'=>'expired','sub'=>Db::row('SELECT * FROM '.Db::t('subscriptions')." WHERE company_id=%d AND status IN ('active','upgraded') ORDER BY end_date DESC LIMIT 1",$cid)];
    }
    /** دروازهٔ ثبت (از Access::write): خواندن و خروجی از این مسیر نمی‌گذرند و همیشه آزادند. */
    public static function canWrite($cid,$cap){
        if(!self::enforced()||in_array($cap,self::ALWAYS,true))return true;
        $a=self::active($cid);
        if(!$a)return self::legacy($cid);
        $mods=json_decode((string)$a['modules'],true)?:[];
        if(strpos($cap,'production.')===0&&!in_array('all',$mods,true)&&!in_array('manufacturing',$mods,true))return false;
        return true;
    }

    // ------------------------------------------------------------ خرید، تمدید، ارتقا

    /** پیش‌فاکتور سروری: مبلغ قیمت جاری، جشنواره (اگر ظرفیت بماند و شرکت قبلاً جشنواره نگرفته) و اعتبار باقی‌ماندهٔ ارتقا. */
    public static function quote($cid,$plan,$period){
        if(!isset(self::PERIODS[$period])||!in_array($plan,['specialist','combined'],true))throw new ValidationException(['plan'=>'طرح یا دوره معتبر نیست؛ طرح سازمانی توافقی است.']);
        $p=self::price($plan,$period);if(!$p)throw new ConflictException('قیمت این طرح تعیین نشده است.');
        $f=self::festival();$fest=$f['enabled']&&$p['festival_amount']!==null&&self::festivalUsed()<$f['capacity']&&!Db::val('SELECT id FROM '.Db::t('subscriptions').' WHERE company_id=%d AND festival=1 LIMIT 1',$cid);
        $amount=$fest?(int)$p['festival_amount']:(int)$p['amount'];$cur=self::active($cid);$credit=0;$start=Jalali::today();$replaces=null;
        if($cur&&$cur['plan_code']===$plan){$start=Jalali::addDays($cur['end_date'],1);}
        elseif($cur&&$cur['plan_code']==='specialist'&&$plan==='combined'){$total=Jalali::diffDays($cur['start_date'],$cur['end_date'])+1;$left=Jalali::diffDays(Jalali::today(),$cur['end_date'])+1;$credit=min($amount,intdiv((int)$cur['amount']*max(0,$left),max(1,$total)));$replaces=(int)$cur['id'];}
        elseif($cur)throw new ConflictException('تغییر از طرح ترکیبی به تخصصی پیش از پایان دوره ممکن نیست.');
        $end=(new \DateTimeImmutable($start))->modify('+'.self::PERIODS[$period][1].' months')->modify('-1 day')->format('Y-m-d');
        $next=self::price($plan,$period,'9999-12-31');
        return ['plan'=>$plan,'period'=>$period,'amount'=>$amount-$credit,'list_amount'=>(int)$p['amount'],'credit'=>$credit,'festival'=>$fest,'price_version'=>(int)$p['version'],'start'=>$start,'end'=>$end,'replaces'=>$replaces,'next_price'=>(int)$next['amount']];
    }
    /** ثبت سفارش اشتراک (در انتظار پرداخت). اگر ووکامرس فعال باشد، سفارش ووکامرس با همان مبلغ سروری ساخته و نشانی پرداخت برگردانده می‌شود. */
    public static function order($cid,$plan,$period,$activity=''){
        return Access::write($cid,'company_settings.edit',function()use($cid,$plan,$period,$activity){
            $q=self::quote($cid,$plan,$period);
            if($plan==='specialist'){$activity=$activity?:(string)Settings::get('activity_type',$cid);if(!in_array($activity,['trading','manufacturing','services'],true))throw new ValidationException(['activity'=>'نوع فعالیت طرح تخصصی را انتخاب کنید.']);$mods=['core',$activity];}else $mods=['all'];
            Db::exec('UPDATE '.Db::t('subscriptions')." SET status='failed',updated_at=%s WHERE company_id=%d AND status='pending'",self::now(),$cid);
            $id=Business::save('subscriptions',$cid,0,['plan_code'=>$plan,'period'=>$period,'modules'=>wp_json_encode($mods),'start_date'=>$q['start'],'end_date'=>$q['end'],'status'=>'pending','amount'=>$q['amount'],'credit'=>$q['credit'],'price_version'=>$q['price_version'],'festival'=>$q['festival']?1:0,'replaces_id'=>$q['replaces'],'created_by'=>Ctx::uid()]);
            self::event($cid,$id,'order',$q);
            $url=null;if(function_exists('wc_create_order')&&$q['amount']>0)$url=self::wooOrder($cid,$id,$q);
            return ['id'=>$id,'quote'=>$q,'pay_url'=>$url];
        });
    }
    private static function wooOrder($cid,$sid,array $q){
        $cur=function_exists('get_woocommerce_currency')?get_woocommerce_currency():'IRT';$div=$cur==='IRR'?100:1000;
        $o=wc_create_order(['customer_id'=>get_current_user_id()]);$fee=new \WC_Order_Item_Fee();$fee->set_name('اشتراک آگاه — '.self::PLANS[$q['plan']][0].' — '.self::PERIODS[$q['period']][0]);$fee->set_amount((string)($q['amount']/$div));$fee->set_total((string)($q['amount']/$div));$fee->set_tax_status('none');$o->add_item($fee);
        $o->update_meta_data('_agah_subscription',(int)$sid);$o->update_meta_data('_agah_company',(int)$cid);$o->calculate_totals(false);$o->save();
        Db::update('subscriptions',['order_ref'=>'woo:'.$o->get_id()],['company_id'=>$cid,'id'=>$sid]);return $o->get_checkout_payment_url();
    }
    /**
     * تأیید پرداخت سروری (درگاه): فقط یک بار فعال می‌کند؛ تکرار همان پرداخت بی‌اثر است (Q23). مبلغ باید با مبلغ سروری برابر باشد.
     * جشنواره: جایگاه با قفل اتمیک و کلید یکتا؛ اگر ظرفیت در همین لحظه پر شده باشد، اشتراک «نیازمند بررسی» می‌شود (Q25).
     */
    public static function confirmPayment($sid,$orderRef,$paidAmount,$method='gateway'){
        $s=Db::row('SELECT * FROM '.Db::t('subscriptions').' WHERE id=%d',$sid);if(!$s)throw new NotFoundException();$cid=(int)$s['company_id'];
        return self::festivalLocked($s,function()use($sid,$cid,$orderRef,$paidAmount,$method){return Db::tx(function()use($sid,$cid,$orderRef,$paidAmount,$method){
            Db::row('SELECT id FROM '.Db::t('companies').' WHERE id=%d FOR UPDATE',$cid);$s=Db::row('SELECT * FROM '.Db::t('subscriptions').' WHERE id=%d FOR UPDATE',$sid);
            if(in_array($s['status'],['active','upgraded','review','cancelled','refunded'],true)){self::event($cid,$sid,'duplicate',['ref'=>$orderRef,'status'=>$s['status']],0);return $s['status'];}
            if($s['order_ref']&&$s['order_ref']!==$orderRef)throw new ConflictException('مرجع پرداخت با سفارش اشتراک یکی نیست.');
            if((int)$paidAmount!==(int)$s['amount']){Db::update('subscriptions',['status'=>'review','order_ref'=>$orderRef,'updated_at'=>self::now()],['id'=>$sid]);self::event($cid,$sid,'amount_mismatch',['paid'=>(int)$paidAmount,'expected'=>(int)$s['amount']],0);self::alertPlatform($cid,'مبلغ پرداخت اشتراک با مبلغ سفارش برابر نیست.');return 'review';}
            if($method==='cod'){Db::update('subscriptions',['status'=>'cod_pending','order_ref'=>$orderRef,'source'=>'cod','updated_at'=>self::now()],['id'=>$sid]);self::event($cid,$sid,'cod_requested',['ref'=>$orderRef],0);return 'cod_pending';}
            return self::activate($cid,$s,$orderRef,'paid');
        });});
    }
    /** قفل جشنواره پیش از تراکنش گرفته و پس از COMMIT آزاد می‌شود؛ پس شمارش هر پرداخت، جایگاه ثبت‌شدهٔ قبلی را می‌بیند. */
    private static function festivalLocked(array $s,callable $fn){
        if(!(int)$s['festival'])return $fn();
        $lock='agah_festival_'.substr(hash('sha256',Db::t('sub_festival_slots')),0,16);if(1!==(int)Db::val('SELECT GET_LOCK(%s,20)',$lock))throw new ConflictException('سامانه مشغول است؛ دوباره تلاش کنید.');
        try{return $fn();}finally{Db::val('SELECT RELEASE_LOCK(%s)',$lock);}
    }
    private static function activate($cid,array $s,$orderRef,$event,$reason=''){
        if((int)$s['festival']){
            $used=self::festivalUsed();$cap=self::festival()['capacity'];
            if($used>=$cap){Db::update('subscriptions',['status'=>'review','order_ref'=>$orderRef,'updated_at'=>self::now()],['id'=>$s['id']]);self::event($cid,(int)$s['id'],'festival_full',['used'=>$used],0);self::alertPlatform($cid,'ظرفیت جشنواره هنگام پرداخت پر بود؛ اشتراک نیازمند بررسی (استرداد مابه‌التفاوت یا فعال‌سازی با قیمت عادی) است.');return 'review';}
            Db::insert('sub_festival_slots',['slot_no'=>$used+1,'subscription_id'=>(int)$s['id'],'company_ref'=>$cid,'created_at'=>self::now()]);$slot=$used+1;
        }
        if($s['replaces_id'])Db::update('subscriptions',['status'=>'upgraded','end_date'=>Jalali::addDays($s['start_date'],-1),'updated_at'=>self::now()],['company_id'=>$cid,'id'=>(int)$s['replaces_id']]);
        Db::update('subscriptions',['status'=>'active','order_ref'=>$orderRef,'festival_slot'=>$slot??null,'updated_at'=>self::now()],['id'=>$s['id']]);
        self::event($cid,(int)$s['id'],$event,['ref'=>$orderRef,'slot'=>$slot??null,'reason'=>$reason],0);return 'active';
    }
    public static function failPayment($sid,$orderRef){$s=Db::row('SELECT * FROM '.Db::t('subscriptions').' WHERE id=%d',$sid);if(!$s||$s['status']!=='pending')return false;Db::update('subscriptions',['status'=>'failed','updated_at'=>self::now()],['id'=>$sid]);self::event((int)$s['company_id'],$sid,'payment_failed',['ref'=>$orderRef],0);return true;}
    /** پرداخت در محل فقط با تأیید مدیر سامانه و دلیل فعال می‌شود (Q24). */
    public static function approveCod($sid,$reason){
        self::platform();$reason=Business::reason($reason);$s=Db::row('SELECT * FROM '.Db::t('subscriptions').' WHERE id=%d',$sid);if(!$s)throw new NotFoundException();$cid=(int)$s['company_id'];
        return self::festivalLocked($s,function()use($sid,$cid,$reason){return Db::tx(function()use($sid,$cid,$reason){Db::row('SELECT id FROM '.Db::t('companies').' WHERE id=%d FOR UPDATE',$cid);$s=Db::row('SELECT * FROM '.Db::t('subscriptions').' WHERE id=%d FOR UPDATE',$sid);if($s['status']!=='cod_pending')throw new ConflictException('این اشتراک منتظر تأیید پرداخت در محل نیست.');$r=self::activate($cid,$s,$s['order_ref'],'cod_approved',$reason);Audit::log('subscription.cod_approve','subscription',$sid,null,['reason'=>$reason,'result'=>$r],$cid);return $r;});});
    }
    /** اعطای دستی (مثلاً طرح سازمانی یا انتقال شرکت تاریخی) با دلیل. */
    public static function grant($cid,$plan,$months,$reason,$activity=''){
        self::platform();$reason=Business::reason($reason);$months=(int)$months;if(!isset(self::PLANS[$plan])||$months<1||$months>120)throw new ValidationException(['plan'=>'طرح یا مدت معتبر نیست.']);if(!Db::val('SELECT id FROM '.Db::t('companies').' WHERE id=%d',$cid))throw new NotFoundException();
        $cur=self::active($cid);$start=$cur&&$cur['plan_code']===$plan?Jalali::addDays($cur['end_date'],1):Jalali::today();$end=(new \DateTimeImmutable($start))->modify('+'.$months.' months')->modify('-1 day')->format('Y-m-d');
        $mods=$plan==='specialist'?['core',in_array($activity,['trading','manufacturing','services'],true)?$activity:'trading']:['all'];
        if($cur&&$cur['plan_code']!==$plan)Db::update('subscriptions',['status'=>'upgraded','end_date'=>Jalali::addDays($start,-1),'updated_at'=>self::now()],['company_id'=>$cid,'id'=>$cur['id']]);
        $id=Db::insert('subscriptions',['uuid'=>\Agah\Erp\Core\Crypto::uuid(),'company_id'=>$cid,'plan_code'=>$plan,'period'=>'month','modules'=>wp_json_encode($mods),'start_date'=>$start,'end_date'=>$end,'status'=>'active','amount'=>0,'source'=>'manual','created_by'=>get_current_user_id(),'created_at'=>self::now(),'updated_at'=>self::now()]);
        self::event($cid,$id,'manual_grant',['months'=>$months,'reason'=>$reason]);Audit::log('subscription.grant','subscription',$id,null,['plan'=>$plan,'months'=>$months,'reason'=>$reason],$cid);return $id;
    }
    /** لغو یا استرداد (ثبت در آگاه؛ بازگرداندن وجه در ووکامرس/درگاه انجام می‌شود). */
    public static function cancel($sid,$reason,$refund=false){
        self::platform();$reason=Business::reason($reason);$s=Db::row('SELECT * FROM '.Db::t('subscriptions').' WHERE id=%d',$sid);if(!$s)throw new NotFoundException();
        if(!in_array($s['status'],['active','pending','cod_pending','review'],true))throw new ConflictException('این اشتراک قابل لغو نیست.');
        Db::update('subscriptions',['status'=>$refund?'refunded':'cancelled','end_date'=>min($s['end_date']??Jalali::today(),Jalali::today()),'updated_at'=>self::now()],['id'=>$sid]);
        self::event((int)$s['company_id'],$sid,$refund?'refund':'cancel',['reason'=>$reason]);Audit::log('subscription.'.($refund?'refund':'cancel'),'subscription',$sid,['status'=>$s['status']],['reason'=>$reason],(int)$s['company_id']);return true;
    }
    private static function event($cid,$sid,$event,$data,$uid=null){Business::save('sub_events',$cid,0,['subscription_id'=>$sid?:null,'event'=>$event,'data'=>wp_json_encode($data,JSON_UNESCAPED_UNICODE),'user_id'=>$uid===null?get_current_user_id():(int)$uid]);}
    private static function alertPlatform($cid,$text){foreach(get_users(['role'=>'administrator','fields'=>'ID']) as $uid)update_user_meta((int)$uid,'agah_sub_alert',1);Audit::log('subscription.alert','company',$cid,null,['text'=>$text],$cid,0);}
    public static function history($cid){Access::need($cid,'company_settings.view');return ['subs'=>Db::rows('SELECT * FROM '.Db::t('subscriptions').' WHERE company_id=%d ORDER BY id DESC LIMIT 50',$cid),'events'=>Db::rows('SELECT * FROM '.Db::t('sub_events').' WHERE company_id=%d ORDER BY id DESC LIMIT 50',$cid)];}
    public static function all($status=''){self::platform();return Db::rows('SELECT s.*,c.name AS company_name FROM '.Db::t('subscriptions').' s JOIN '.Db::t('companies').' c ON c.id=s.company_id'.($status!==''?' WHERE s.status=%s':' WHERE 1=%d').' ORDER BY s.id DESC LIMIT 200',$status!==''?$status:1);}

    // ------------------------------------------------------------ اعلان انقضا (NTF-01، Q27)

    public static function offsets(){$o=array_values(array_unique(array_map('intval',array_filter(explode(',',(string)get_option('agah_sub_expiry_offsets','7,3,1,0,-1')),'strlen'))));rsort($o);return $o;}
    public static function setOffsets($text){self::platform();$vals=array_filter(array_map('trim',explode(',',\Agah\Erp\Core\Money::normDigits((string)$text))),'strlen');foreach($vals as $v)if(!preg_match('/^-?\d{1,3}$/D',$v)||abs((int)$v)>90)throw new ValidationException(['offsets'=>'فاصله‌ها عدد صحیح روز بین ‎-۹۰ تا ۹۰، جدا با ویرگول.']);update_option('agah_sub_expiry_offsets',implode(',',$vals),false);Audit::log('subscription.offsets','platform',0,null,['offsets'=>$vals],0);}
    /** کار زمان‌بندی‌شدهٔ روزانه: برای هر اشتراک و هر فاصلهٔ تنظیم‌شده فقط یک بار به هر مدیر شرکت اعلان (و ایمیل) می‌رود. */
    public static function notifyExpiry($today=null){
        $today=$today?:Jalali::today();$sent=0;$offsets=self::offsets();if(!$offsets)return 0;
        foreach(Db::rows('SELECT s.* FROM '.Db::t('subscriptions')." s WHERE s.status='active' AND s.end_date IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ".Db::t('subscriptions')." n WHERE n.company_id=s.company_id AND n.status='active' AND n.start_date>s.end_date)") as $s){
            $days=Jalali::diffDays($today,$s['end_date']);if(!in_array($days,$offsets,true))continue;$cid=(int)$s['company_id'];
            $next=self::price($s['plan_code'],$s['period'],'9999-12-31');
            $title=$days>0?'اشتراک آگاه '.\Agah\Erp\Core\Money::toFa((string)$days).' روز دیگر تمام می‌شود':($days===0?'اشتراک آگاه امروز تمام می‌شود':'اشتراک آگاه تمام شده است؛ ثبت تازه بسته است');
            $body='پایان اعتبار: '.Jalali::format($s['end_date']).($next?' — قیمت دورهٔ بعد: '.\Agah\Erp\Core\Money::fmt((int)$next['amount'],'toman').' تومان':'');
            $sent+=N::notify($cid,N::holders($cid,'company_settings.edit'),'expiry',$title,$body,agah_erp_url(['c'=>$cid,'p'=>'subscription']),'expiry|'.$s['id'].'|'.$days,true);
        }
        return $sent;
    }
}
