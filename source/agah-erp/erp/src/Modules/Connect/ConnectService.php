<?php
/**
 * اتصال فروشگاه‌ها به آگاه.
 *
 * - هر فروشگاه یک «اتصال» در یک شرکت است: شناسهٔ کلید، راز رمزشده، کاربر سرویسِ بدون امکان ورود و نقش محدود.
 * - قیمت و موجودی از آگاه خوانده می‌شود؛ مبلغ واقعی فروش (با تخفیف و ارسال) از فروشگاه می‌آید و همان ثبت می‌شود.
 * - پرداخت موفق = پیش‌دریافت + رزرو پرداخت‌شده. خروج واقعی کالا = فاکتور فروش + کسر انبار + اعمال پیش‌دریافت.
 * - هیچ دفتر یا انبار موازی ساخته نمی‌شود؛ همهٔ ثبت‌ها از سرویس‌های موجود خزانه، فاکتور و انبار می‌گذرند.
 */
namespace Agah\Erp\Modules\Connect;
use Agah\Erp\Core\{Access,AppException,Audit,Business,ConflictException,Crypto,Ctx,Db,Jalali,Money,NotFoundException,Request,ValidationException};
use Agah\Erp\Modules\Inventory\StockService;
use Agah\Erp\Modules\Parties\PartyService;
use Agah\Erp\Modules\Trade\InvoiceService;
use Agah\Erp\Modules\Treasury\TreasuryService;
use Agah\Erp\Security\{Auth,RateLimiter};
if(!defined('ABSPATH'))exit;

/** خطای API با کد پایدار برای افزونهٔ فروشگاه. */
final class ApiError extends AppException {
    public $code_name;public $details;
    public function __construct($status,$code,$message,array $details=[]){parent::__construct($message,$status);$this->code_name=$code;$this->details=$details;}
}

final class ConnectService {
    /** مجوزهای نقش سرویس فروشگاه؛ کمترین دسترسی لازم. */
    const SERVICE_CAPS=['item.view','warehouse.view','stock.view','party.view','party.create','sale_invoice.view','sale_invoice.create','receipt.view','receipt.create','payment.view','payment.create','treasury_account.view'];
    const STATES=['paid'=>'پرداخت‌شده؛ در انتظار خروج کالا','dispatched'=>'خروج کالا و فاکتور','cancelled'=>'لغوشده','review'=>'نیازمند بررسی','closed'=>'بسته‌شده با تصمیم حسابدار'];
    const REFUND_STATES=['refunded'=>'وجه پیش‌دریافت مسترد شد','awaiting'=>'وجه مسترد شد؛ در انتظار دریافت فیزیکی کالا','received'=>'کالا دریافت و برگشت فروش صادر شد','review'=>'نیازمند بررسی حسابدار','resolved'=>'تعیین‌تکلیف‌شده'];

    private static function now(){return gmdate('Y-m-d H:i:s');}
    /** مقدار به‌صورت رشتهٔ دهدهی بدون صفرهای اضافه (۲٫۵۰۰۰ → «2.5»). */
    private static function qtyOut($scaled){$s=Money::qtyToDb((int)$scaled);return strpos($s,'.')===false?$s:rtrim(rtrim($s,'0'),'.');}
    private static function rial($v){return Money::toInputValue((int)$v,'rial');}

    // ------------------------------------------------------------------ مدیریت اتصال (کاربر انسانی)

    public static function clients($cid){Access::need($cid,'connect.view');return Db::rows('SELECT * FROM '.Db::t('connect_clients').' WHERE company_id=%d ORDER BY id DESC',$cid);}

    /** تنظیمات اتصال را اعتبارسنجی می‌کند؛ همهٔ شناسه‌ها با همین شرکت تطبیق داده می‌شوند. */
    private static function settings($cid,array $in){
        $name=Request::clean($in['name']??'',100);if(mb_strlen($name)<2)throw new ValidationException(['name'=>'نام فروشگاه حداقل دو نویسه باشد.']);
        $wh=Business::row('warehouses',$cid,Business::id($in['warehouse_id']??0));if(!$wh['is_active'])throw new ValidationException(['warehouse_id'=>'انبار فعال انتخاب کنید.']);
        $ta=Business::row('treasury_accounts',$cid,Business::id($in['treasury_account_id']??0));if(!$ta['is_active']||$ta['kind']!=='bank')throw new ValidationException(['treasury_account_id'=>'حساب بانکی فعال برای وجوه درگاه انتخاب کنید.']);
        $ship=Business::id($in['shipping_item_id']??0);if($ship){$s=Business::row('items',$cid,$ship);if(!$s['is_active']||$s['kind']!=='service')throw new ValidationException(['shipping_item_id'=>'برای هزینهٔ ارسال یک خدمت فعال انتخاب کنید.']);}
        $ttl=(int)($in['reservation_ttl']??20);if($ttl<5||$ttl>1440)throw new ValidationException(['reservation_ttl'=>'مدت رزرو بین ۵ تا ۱۴۴۰ دقیقه باشد.']);
        $threshold=Business::amount((int)($in['party_threshold']??0));
        return ['name'=>$name,'warehouse_id'=>(int)$wh['id'],'treasury_account_id'=>(int)$ta['id'],'shipping_item_id'=>$ship?:null,'auto_dispatch'=>empty($in['auto_dispatch'])?0:1,'reservation_ttl'=>$ttl,'party_threshold'=>$threshold];
    }

    /** نقش سرویس فروشگاه در هر شرکت یک بار ساخته می‌شود. */
    private static function serviceRole($cid){
        $id=(int)Db::val('SELECT id FROM '.Db::t('roles')." WHERE company_id=%d AND role_key='connect_store'",$cid);
        if(!$id)$id=Db::insert('roles',['company_id'=>$cid,'role_key'=>'connect_store','name'=>'سرویس فروشگاه','is_system'=>1,'created_at'=>self::now()]);
        foreach(self::SERVICE_CAPS as $cap)Db::exec('INSERT IGNORE INTO '.Db::t('role_caps').' (role_id,cap) VALUES (%d,%s)',$id,$cap);
        return $id;
    }

    /** ساخت اتصال؛ راز فقط در خروجی همین فراخوانی دیده می‌شود و رمزشده ذخیره می‌شود. */
    public static function createClient($cid,array $in){
        return Access::write($cid,'connect.edit',function()use($cid,$in){
            $data=self::settings($cid,$in);$secret=Crypto::token(32);$key='ak_'.Crypto::token(12);
            $uid=wp_insert_user(['user_login'=>'agah_store_'.Crypto::token(6),'user_pass'=>Crypto::token(48),'user_email'=>'','display_name'=>'فروشگاه: '.$data['name'],'role'=>'']);
            if(is_wp_error($uid))throw new ConflictException('ساخت کاربر سرویس فروشگاه ممکن نشد.');
            update_user_meta($uid,Auth::SERVICE_META,1);
            Db::insert('company_members',['company_id'=>$cid,'user_id'=>$uid,'role_id'=>self::serviceRole($cid),'status'=>'active','created_at'=>self::now()]);
            $id=Business::save('connect_clients',$cid,0,$data+['key_id'=>$key,'secret_enc'=>Crypto::encrypt($secret),'status'=>'active','service_user_id'=>$uid,'created_by'=>Ctx::uid()]);
            Audit::log('connect.client.create','connect_client',$id,null,['name'=>$data['name'],'key_id'=>$key],$cid);
            return ['id'=>$id,'key_id'=>$key,'secret'=>$secret];
        });
    }
    public static function updateClient($cid,$id,array $in){
        return Access::write($cid,'connect.edit',function()use($cid,$id,$in){
            $old=Business::row('connect_clients',$cid,$id,true);$data=self::settings($cid,$in);
            if((int)$old['warehouse_id']!==$data['warehouse_id']&&self::openReservations($cid,$id))throw new ConflictException('تا وقتی رزرو یا سفارش باز وجود دارد، انبار اتصال تغییر نمی‌کند.');
            Business::save('connect_clients',$cid,$id,$data);Audit::log('connect.client.update','connect_client',$id,array_intersect_key($old,$data),$data,$cid);return $id;
        });
    }
    private static function openReservations($cid,$id){return (bool)Db::val('SELECT id FROM '.Db::t('connect_reservations')." WHERE company_id=%d AND client_id=%d AND status IN ('held','paid') LIMIT 1",$cid,$id);}
    /** راز تازه؛ راز قبلی فوراً از کار می‌افتد. */
    public static function rotate($cid,$id){
        return Access::write($cid,'connect.edit',function()use($cid,$id){
            $c=Business::row('connect_clients',$cid,$id,true);if($c['status']!=='active')throw new ConflictException('اتصال لغوشده راز تازه نمی‌گیرد.');
            $secret=Crypto::token(32);Business::save('connect_clients',$cid,$id,['secret_enc'=>Crypto::encrypt($secret)]);
            Audit::log('connect.client.rotate','connect_client',$id,null,['key_id'=>$c['key_id']],$cid);return ['id'=>(int)$id,'key_id'=>$c['key_id'],'secret'=>$secret];
        });
    }
    /** لغو اتصال: کلید دیگر پذیرفته نمی‌شود؛ سفارش‌ها و اسناد قبلی دست‌نخورده می‌مانند. */
    public static function revoke($cid,$id){
        return Access::write($cid,'connect.edit',function()use($cid,$id){
            $c=Business::row('connect_clients',$cid,$id,true);Business::save('connect_clients',$cid,$id,['status'=>'revoked']);
            Db::update('company_members',['status'=>'removed'],['company_id'=>$cid,'user_id'=>(int)$c['service_user_id']]);
            Audit::log('connect.client.revoke','connect_client',$id,['status'=>$c['status']],['status'=>'revoked'],$cid);return $id;
        });
    }
    /** حذف فقط برای اتصالی که هیچ رزرو یا سفارشی نداشته است؛ در غیر این صورت لغو کنید. */
    public static function deleteClient($cid,$id){
        return Access::write($cid,'connect.edit',function()use($cid,$id){
            $c=Business::row('connect_clients',$cid,$id,true);
            foreach(['connect_reservations','connect_orders','connect_customers'] as $t)if(Db::val('SELECT id FROM '.Db::t($t).' WHERE company_id=%d AND client_id=%d LIMIT 1',$cid,$id))throw new ConflictException('اتصال دارای سابقه حذف نمی‌شود؛ آن را لغو کنید.');
            Db::delete('connect_requests',['client_id'=>(int)$id]);Db::delete('connect_nonces',['client_id'=>(int)$id]);
            Db::delete('company_members',['company_id'=>$cid,'user_id'=>(int)$c['service_user_id']]);Db::delete('connect_clients',['company_id'=>$cid,'id'=>$id]);
            Audit::log('connect.client.delete','connect_client',$id,['name'=>$c['name']],null,$cid);return true;
        });
    }

    // ------------------------------------------------------------------ احراز هویت ماشین

    /** متن امضاشده؛ افزونهٔ فروشگاه دقیقاً همین را می‌سازد. */
    public static function canonical($method,$route,array $query,$ts,$nonce,$body){
        ksort($query);
        return implode("\n",[strtoupper($method),$route,http_build_query($query,'','&',PHP_QUERY_RFC3986),$ts,$nonce,hash('sha256',(string)$body)]);
    }
    /** امضا، زمان، تکرارنشدن nonce و سهمیهٔ درخواست بررسی می‌شود؛ هر شکست پاسخ یکسان دارد. */
    public static function authenticate($keyId,$ts,$nonce,$sig,$method,$route,array $query,$body){
        $deny=new ApiError(401,'unauthorized','درخواست احراز نشد.');
        if(!is_string($keyId)||!preg_match('/^ak_[a-f0-9]{24}$/D',$keyId)||!is_string($ts)||!ctype_digit($ts)||!is_string($nonce)||!preg_match('/^[A-Za-z0-9_-]{16,64}$/D',$nonce)||!is_string($sig)||!preg_match('/^[a-f0-9]{64}$/D',$sig))throw $deny;
        if(abs(time()-(int)$ts)>300)throw $deny;
        $c=Db::row('SELECT * FROM '.Db::t('connect_clients').' WHERE key_id=%s',$keyId);
        if(!$c||$c['status']!=='active')throw $deny;
        $secret=Crypto::decrypt($c['secret_enc']);
        if(!is_string($secret)||!hash_equals(hash_hmac('sha256',self::canonical($method,$route,$query,$ts,$nonce,$body),$secret),$sig))throw $deny;
        if(!RateLimiter::consume('connect|'.$c['id'],600,60))throw new ApiError(429,'rate_limited','تعداد درخواست بیش از حد مجاز است؛ کمی بعد تلاش کنید.');
        Db::exec('DELETE FROM '.Db::t('connect_nonces').' WHERE expires_at<%d LIMIT 500',time());
        if(1!==Db::exec('INSERT IGNORE INTO '.Db::t('connect_nonces').' (client_id,nonce,expires_at) VALUES (%d,%s,%d)',(int)$c['id'],$nonce,time()+600))throw $deny;
        if(!$c['last_seen_at']||strtotime($c['last_seen_at'].' UTC')<time()-60)Db::update('connect_clients',['last_seen_at'=>self::now()],['id'=>(int)$c['id']]);
        return $c;
    }
    /** زمینهٔ کاربر سرویس فقط پس از امضای معتبر ساخته می‌شود. */
    public static function boot(array $client){
        $uid=(int)$client['service_user_id'];wp_set_current_user($uid);Auth::$machine=$uid;Ctx::boot($uid,(int)$client['company_id']);
    }
    /** پاسخ موفق هر کلید تکرارناپذیر یک بار ساخته و دوباره برگردانده می‌شود. */
    public static function idempotent(array $client,$key,$route,$body,callable $fn){
        if(!is_string($key)||!preg_match('/^[A-Za-z0-9_.:-]{8,100}$/D',$key))throw new ApiError(400,'idempotency_key_required','سرآیند Idempotency-Key معتبر لازم است.');
        $hash=hash('sha256',$route."\n".$body);$t=Db::t('connect_requests');
        return Db::tx(function()use($client,$key,$hash,$fn,$t){
            if(1!==Db::exec("INSERT IGNORE INTO $t (company_id,client_id,idem_key,request_hash,state,created_at) VALUES (%d,%d,%s,%s,'pending',%s)",(int)$client['company_id'],(int)$client['id'],$key,$hash,self::now())){
                $old=Db::row("SELECT * FROM $t WHERE client_id=%d AND idem_key=%s FOR UPDATE",(int)$client['id'],$key);
                if(!$old||!hash_equals($old['request_hash'],$hash))throw new ApiError(409,'idempotency_conflict','این کلید قبلاً با محتوای دیگری استفاده شده است.');
                if($old['state']==='done')return json_decode((string)$old['response'],true);
            }
            $result=$fn();
            Db::exec("UPDATE $t SET state='done',response=%s WHERE client_id=%d AND idem_key=%s",wp_json_encode($result),(int)$client['id'],$key);
            return $result;
        });
    }

    // ------------------------------------------------------------------ کاتالوگ و موجودی

    public static function health(array $client){
        return ['company'=>(string)Ctx::company()['uuid'],'store'=>$client['name'],'display_unit'=>Ctx::unit(),'currency'=>'IRR','schema'=>\Agah\Erp\Core\Schema::VERSION,'time'=>gmdate('c'),'reservation_ttl'=>(int)$client['reservation_ttl']];
    }
    /** کالاها به ترتیب نسخهٔ تغییر؛ cursor به شکل «نسخه:شناسه» است و «0:0» یعنی از ابتدا. */
    public static function catalog(array $client,array $q){
        $cid=(int)$client['company_id'];Access::need($cid,'item.view');
        $cursor=(string)($q['cursor']??'0:0');if(!preg_match('/^(\d{1,18}):(\d{1,18})$/D',$cursor,$m))throw new ApiError(400,'bad_cursor','cursor معتبر نیست.');
        $limit=max(1,min(200,(int)($q['limit']??100)));
        $rows=Db::rows('SELECT i.*,u.name AS unit_name FROM '.Db::t('items').' i JOIN '.Db::t('units').' u ON u.company_id=i.company_id AND u.id=i.unit_id WHERE i.company_id=%d AND (i.rev>%d OR (i.rev=%d AND i.id>%d)) ORDER BY i.rev,i.id LIMIT %d',$cid,(int)$m[1],(int)$m[1],(int)$m[2],$limit+1);
        $more=count($rows)>$limit;$rows=array_slice($rows,0,$limit);$out=[];$next=$cursor;
        foreach($rows as $r){$out[]=['id'=>$r['uuid'],'code'=>$r['code'],'name'=>$r['name'],'kind'=>$r['kind'],'unit'=>$r['unit_name'],'barcode'=>(string)$r['barcode'],'active'=>(bool)$r['is_active'],'price'=>self::rial($r['sale_price']),'trade_price'=>self::rial($r['trade_price']),'attrs'=>json_decode((string)$r['attrs'],true)?:new \stdClass(),'rev'=>(int)$r['rev']];$next=$r['rev'].':'.$r['id'];}
        return ['items'=>$out,'cursor'=>$next,'has_more'=>$more,'currency'=>'IRR'];
    }
    /** موجودی قابل فروش = موجودی انبار اتصال منهای رزروهای فعال. */
    public static function stock(array $client,array $q){
        $cid=(int)$client['company_id'];Access::need($cid,'stock.view');$wh=(int)$client['warehouse_id'];
        $ids=array_values(array_filter(explode(',',(string)($q['items']??''))));if(!$ids||count($ids)>100)throw new ApiError(400,'bad_items','بین یک تا صد شناسهٔ کالا لازم است.');
        $out=[];
        foreach($ids as $uuid){$item=self::item($cid,$uuid,false);if($item['kind']!=='goods'){$out[]=['id'=>$uuid,'available'=>null];continue;}
            $b=StockService::balanceInternal($cid,$wh,(int)$item['id']);$held=StockService::reserved($cid,$wh,(int)$item['id']);
            $out[]=['id'=>$uuid,'on_hand'=>self::qtyOut($b['qty']),'reserved'=>self::qtyOut($held),'available'=>self::qtyOut(max(0,$b['qty']-$held))];}
        return ['items'=>$out,'at'=>gmdate('c')];
    }
    private static function item($cid,$uuid,$goodsOnly=true){
        if(!is_string($uuid)||!preg_match('/^[0-9a-f-]{36}$/D',$uuid))throw new ApiError(400,'bad_item','شناسهٔ کالا معتبر نیست.');
        $r=Db::row('SELECT * FROM '.Db::t('items').' WHERE company_id=%d AND uuid=%s',$cid,$uuid);
        if(!$r)throw new ApiError(404,'item_not_found','کالا پیدا نشد.',['item'=>$uuid]);
        if($goodsOnly&&($r['kind']!=='goods'||!$r['is_active']))throw new ApiError(409,'item_not_sellable','کالا فعال یا قابل انبار نیست.',['item'=>$uuid]);
        return $r;
    }

    // ------------------------------------------------------------------ رزرو

    private static function ref($v){if(!is_string($v)||!preg_match('/^[A-Za-z0-9_.:-]{1,80}$/D',$v))throw new ApiError(400,'bad_order_ref','شمارهٔ سفارش فروشگاه معتبر نیست.');return $v;}
    /** ردیف‌های رزرو: [شناسهٔ داخلی کالا => مقدار مقیاس‌شده]؛ ردیف تکراری جمع می‌شود. */
    private static function reserveLines($cid,$lines){
        if(!is_array($lines)||count($lines)<1||count($lines)>60)throw new ApiError(400,'bad_lines','بین یک تا ۶۰ ردیف لازم است.');
        $out=[];foreach($lines as $l){$item=self::item($cid,$l['item']??null);$qty=self::qty($l['qty']??null);$out[(int)$item['id']]=($out[(int)$item['id']]??0)+$qty;}
        return $out;
    }
    private static function qty($v){
        if(!is_string($v)||!preg_match('/^\d{1,12}(\.\d{1,4})?$/D',$v))throw new ApiError(400,'bad_qty','مقدار باید عدد مثبت تا چهار رقم اعشار و به‌صورت رشته باشد.');
        $q=Money::qtyScaled($v);if($q<1)throw new ApiError(400,'bad_qty','مقدار باید مثبت باشد.');return $q;
    }
    private static function amount($v,$field){
        if(!is_string($v)||!preg_match('/^\d{1,16}(\.\d{1,2})?$/D',$v))throw new ApiError(400,'bad_amount','مبلغ باید ریال و به‌صورت رشته باشد.',['field'=>$field]);
        return Money::fromInput($v,'rial');
    }
    private static function shortages($cid,$wh,array $lines,$exclude){
        $out=[];foreach($lines as $item=>$qty){$avail=StockService::balanceInternal($cid,$wh,$item)['qty']-StockService::reserved($cid,$wh,$item,$exclude);
            if($qty>$avail)$out[]=['item'=>(string)Db::val('SELECT uuid FROM '.Db::t('items').' WHERE company_id=%d AND id=%d',$cid,$item),'requested'=>self::qtyOut($qty),'available'=>self::qtyOut(max(0,$avail))];}
        return $out;
    }
    private static function hold($cid,array $client,$ref,array $lines,$status,$expires){
        $id=Business::save('connect_reservations',$cid,0,['client_id'=>(int)$client['id'],'order_ref'=>$ref,'warehouse_id'=>(int)$client['warehouse_id'],'status'=>$status,'expires_at'=>$expires]);
        foreach($lines as $item=>$qty)Business::save('connect_reservation_lines',$cid,0,['reservation_id'=>$id,'item_id'=>$item,'qty_scaled'=>$qty]);
        return $id;
    }
    private static function activeReservation($cid,$clientId,$ref){
        return Db::row('SELECT * FROM '.Db::t('connect_reservations')." WHERE company_id=%d AND client_id=%d AND order_ref=%s AND (status='paid' OR (status='held' AND expires_at>%s)) ORDER BY id DESC LIMIT 1 FOR UPDATE",$cid,$clientId,$ref,self::now());
    }
    /** رزرو اتمیک: زیر قفل شرکت، موجودی آزاد همهٔ ردیف‌ها با هم بررسی می‌شود؛ یا همه رزرو می‌شوند یا هیچ‌کدام. */
    public static function reserve(array $client,array $in){
        $cid=(int)$client['company_id'];
        return Access::write($cid,'sale_invoice.create',function()use($cid,$client,$in){
            $ref=self::ref($in['order_ref']??null);
            if(self::activeReservation($cid,(int)$client['id'],$ref))throw new ApiError(409,'reservation_exists','برای این سفارش رزرو فعال وجود دارد.');
            $lines=self::reserveLines($cid,$in['lines']??null);
            if($short=self::shortages($cid,(int)$client['warehouse_id'],$lines,0))throw new ApiError(409,'insufficient_stock','موجودی کافی نیست.',['lines'=>$short]);
            $id=self::hold($cid,$client,$ref,$lines,'held',gmdate('Y-m-d H:i:s',time()+60*(int)$client['reservation_ttl']));
            Audit::log('connect.reserve','connect_reservation',$id,null,['order_ref'=>$ref],$cid);
            return self::reservationOut($cid,$id);
        });
    }
    private static function reservationOut($cid,$id){
        $r=Business::row('connect_reservations',$cid,$id);$lines=[];
        foreach(Db::rows('SELECT l.qty_scaled,i.uuid FROM '.Db::t('connect_reservation_lines').' l JOIN '.Db::t('items').' i ON i.company_id=l.company_id AND i.id=l.item_id WHERE l.company_id=%d AND l.reservation_id=%d ORDER BY l.id',$cid,$id) as $l)$lines[]=['item'=>$l['uuid'],'qty'=>self::qtyOut((int)$l['qty_scaled'])];
        $status=$r['status']==='held'&&$r['expires_at']<=self::now()?'expired':$r['status'];
        return ['reservation'=>$r['uuid'],'order_ref'=>$r['order_ref'],'status'=>$status,'expires_at'=>$r['expires_at']?gmdate('c',strtotime($r['expires_at'].' UTC')):null,'lines'=>$lines];
    }
    private static function reservationRow($cid,array $client,$uuid,$lock=false){
        if(!is_string($uuid)||!preg_match('/^[0-9a-f-]{36}$/D',$uuid))throw new ApiError(400,'bad_reservation','شناسهٔ رزرو معتبر نیست.');
        $r=Db::row('SELECT * FROM '.Db::t('connect_reservations').' WHERE company_id=%d AND client_id=%d AND uuid=%s'.($lock?' FOR UPDATE':''),$cid,(int)$client['id'],$uuid);
        if(!$r)throw new ApiError(404,'reservation_not_found','رزرو پیدا نشد.');return $r;
    }
    public static function reservation(array $client,array $q,array $url){$cid=(int)$client['company_id'];Access::need($cid,'sale_invoice.view');return self::reservationOut($cid,(int)self::reservationRow($cid,$client,$url['id']??null)['id']);}
    /** آزادسازی رزرو پرداخت‌نشده؛ رزرو پرداخت‌شده فقط با لغو سفارش آزاد می‌شود. */
    public static function release(array $client,array $in,array $url){
        $cid=(int)$client['company_id'];
        return Access::write($cid,'sale_invoice.create',function()use($cid,$client,$url){
            $r=self::reservationRow($cid,$client,$url['id']??null,true);
            if($r['status']==='paid')throw new ApiError(409,'reservation_paid','رزرو پرداخت‌شده فقط با لغو سفارش آزاد می‌شود.');
            if($r['status']==='held')Business::save('connect_reservations',$cid,(int)$r['id'],['status'=>'released']);
            return self::reservationOut($cid,(int)$r['id']);
        });
    }

    // ------------------------------------------------------------------ پرداخت، لغو و خروج کالا

    /** مبالغ سفارش فروشگاه؛ جمع کل باید دقیقاً جمع ردیف‌ها، مالیات ردیف‌ها و هزینهٔ ارسال باشد. */
    private static function orderSnapshot($cid,array $in){
        if(($in['currency']??'')!=='IRR')throw new ApiError(400,'bad_currency','واحد پول تبادل باید IRR باشد.');
        $lines=$in['lines']??null;if(!is_array($lines)||count($lines)<1||count($lines)>60)throw new ApiError(400,'bad_lines','بین یک تا ۶۰ ردیف لازم است.');
        $out=[];$qty=[];$sum=0;
        foreach($lines as $i=>$l){$item=self::item($cid,$l['item']??null,false);if(!$item['is_active'])throw new ApiError(409,'item_not_sellable','کالا فعال نیست.',['item'=>$item['uuid']]);
            $q=self::qty($l['qty']??null);$sub=self::amount($l['subtotal']??null,'lines.'.$i.'.subtotal');$tot=self::amount($l['total']??null,'lines.'.$i.'.total');$tax=self::amount($l['tax']??'0','lines.'.$i.'.tax');
            if($tot>$sub)throw new ApiError(400,'bad_amount','مبلغ پس از تخفیف از مبلغ پیش از تخفیف بیشتر است.',['field'=>'lines.'.$i.'.total']);
            $out[]=['item_id'=>(int)$item['id'],'qty'=>$q,'subtotal'=>$sub,'total'=>$tot,'tax'=>$tax];if($item['kind']==='goods')$qty[(int)$item['id']]=($qty[(int)$item['id']]??0)+$q;
            $sum=Money::add($sum,Money::add($tot,$tax));}
        $ship=self::amount($in['shipping']??'0','shipping');$total=self::amount($in['total']??null,'total');
        if(Money::add($sum,$ship)!==$total)throw new ApiError(400,'total_mismatch','جمع کل با جمع ردیف‌ها، مالیات و هزینهٔ ارسال برابر نیست.');
        if($total<1)throw new ApiError(400,'bad_amount','مبلغ سفارش باید مثبت باشد.',['field'=>'total']);
        return ['lines'=>$out,'qty'=>$qty,'shipping'=>$ship,'total'=>$total];
    }
    /** طرف‌حساب: مشتری کلی فروشگاه، مگر فاکتور با نام خواسته شود یا مبلغ از سقف تعیین‌شده بیشتر باشد. */
    private static function party($cid,array $client,$customer,$amount){
        $customer=is_array($customer)?$customer:[];
        $separate=!empty($customer['wants_invoice'])||((int)$client['party_threshold']>0&&$amount>=(int)$client['party_threshold']);
        if(!$separate){
            $pid=(int)$client['general_party_id'];
            if(!$pid){$pid=PartyService::save($cid,0,['name'=>'مشتریان آنلاین — '.$client['name'],'kind'=>'real','is_active'=>1,'is_customer'=>1]);Db::update('connect_clients',['general_party_id'=>$pid],['company_id'=>$cid,'id'=>(int)$client['id']]);}
            return $pid;
        }
        $key=$customer['key']??null;if(!is_string($key)||!preg_match('/^[A-Za-z0-9_.:@-]{1,80}$/D',$key))throw new ApiError(400,'customer_key_required','برای فاکتور با نام خریدار، شناسهٔ پایدار مشتری لازم است.');
        $pid=(int)Db::val('SELECT party_id FROM '.Db::t('connect_customers').' WHERE company_id=%d AND client_id=%d AND customer_key=%s',$cid,(int)$client['id'],$key);
        if($pid)return $pid;
        $name=Request::clean($customer['name']??'',190);if(mb_strlen($name)<2)throw new ApiError(400,'customer_name_required','نام خریدار برای فاکتور لازم است.');
        $in=['name'=>$name,'kind'=>'real','is_active'=>1,'is_customer'=>1];if(!empty($customer['national_id']))$in['national_id']=(string)$customer['national_id'];
        $pid=PartyService::save($cid,0,$in);Business::save('connect_customers',$cid,0,['client_id'=>(int)$client['id'],'customer_key'=>$key,'party_id'=>$pid]);
        return $pid;
    }
    /** پرداخت موفق فروشگاه: پیش‌دریافت ثبت و رزرو پرداخت‌شده می‌شود. پول هرگز به‌خاطر رزرو منقضی گم نمی‌شود. */
    public static function paid(array $client,array $in){
        $cid=(int)$client['company_id'];
        return Access::write($cid,'receipt.create',function()use($cid,$client,$in){
            $ref=self::ref($in['order_ref']??null);$t=Db::t('connect_orders');
            if($o=Db::row("SELECT * FROM $t WHERE company_id=%d AND client_id=%d AND order_ref=%s FOR UPDATE",$cid,(int)$client['id'],$ref))return self::orderOut($cid,$o);
            $snap=self::orderSnapshot($cid,$in);$txn=Request::clean($in['transaction_ref']??'',80);if($txn==='')throw new ApiError(400,'transaction_ref_required','شمارهٔ پیگیری تراکنش لازم است.');
            $party=self::party($cid,$client,$in['customer']??null,$snap['total']);$date=Jalali::today();
            $doc=TreasuryService::issue($cid,['kind'=>'receipt','doc_date'=>$date,'party_id'=>$party,'advance'=>1,'description'=>'پیش‌دریافت سفارش اینترنتی '.$client['name'].' '.$ref],[['method'=>'transfer','treasury_account_id'=>(int)$client['treasury_account_id'],'amount'=>$snap['total'],'reference'=>$txn]]);
            $status='paid';$reason='';$resId=null;
            $res=self::activeReservation($cid,(int)$client['id'],$ref);
            if($res&&$res['status']==='held'&&self::sameLines($cid,(int)$res['id'],$snap['qty'])){Business::save('connect_reservations',$cid,(int)$res['id'],['status'=>'paid','expires_at'=>null]);$resId=(int)$res['id'];}
            elseif($snap['qty']){
                if($res&&$res['status']==='held')Business::save('connect_reservations',$cid,(int)$res['id'],['status'=>'released']);
                if(!self::shortages($cid,(int)$client['warehouse_id'],$snap['qty'],0))$resId=self::hold($cid,$client,$ref,$snap['qty'],'paid',null);
                else{$status='review';$reason='هنگام ثبت پرداخت، رزرو معتبر نبود و موجودی آزاد کافی نبود؛ پیش از خروج کالا بررسی شود.';}
            }
            $oid=Business::save('connect_orders',$cid,0,['client_id'=>(int)$client['id'],'order_ref'=>$ref,'reservation_id'=>$resId,'party_id'=>$party,'payment_doc_id'=>$doc,'paid_amount'=>$snap['total'],'payload'=>wp_json_encode($snap),'status'=>$status,'review_reason'=>$reason]);
            Audit::log('connect.order.paid','connect_order',$oid,null,['order_ref'=>$ref,'amount'=>$snap['total'],'status'=>$status],$cid);
            if($status==='paid'&&(int)$client['auto_dispatch'])self::tryDispatch($cid,$client,$oid);
            if(Db::val('SELECT status FROM '.Db::t('connect_orders').' WHERE company_id=%d AND id=%d',$cid,$oid)==='review')\Agah\Erp\Modules\Notify\NotificationService::notify($cid,\Agah\Erp\Modules\Notify\NotificationService::holders($cid,'connect.edit'),'api','سفارش فروشگاه نیازمند بررسی: '.$ref,(string)Db::val('SELECT review_reason FROM '.Db::t('connect_orders').' WHERE company_id=%d AND id=%d',$cid,$oid),agah_erp_url(['c'=>$cid,'p'=>'connect_orders']),'conn-review|'.$oid);
            return self::orderOut($cid,Business::row('connect_orders',$cid,$oid));
        });
    }
    private static function sameLines($cid,$resId,array $qty){
        $have=[];foreach(Db::rows('SELECT item_id,qty_scaled FROM '.Db::t('connect_reservation_lines').' WHERE company_id=%d AND reservation_id=%d',$cid,$resId) as $l)$have[(int)$l['item_id']]=($have[(int)$l['item_id']]??0)+(int)$l['qty_scaled'];
        ksort($have);ksort($qty);return $have===$qty;
    }
    /** خروج خودکار؛ اگر نشد، پرداخت و رزرو می‌مانند و سفارش با دلیل روشن «نیازمند بررسی» می‌شود. */
    private static function tryDispatch($cid,array $client,$oid){
        try{Db::tx(function()use($cid,$client,$oid){self::dispatchInternal($cid,$client,Business::row('connect_orders',$cid,$oid,true));});}
        catch(AppException $e){Business::save('connect_orders',$cid,$oid,['status'=>'review','review_reason'=>mb_substr($e->getMessage(),0,500)]);}
    }
    /**
     * خروج واقعی: فاکتور فروش با مبلغ واقعی فروشگاه، کسر انبار از همان رزرو و اعمال پیش‌دریافت.
     * فی هر ردیف = سقف(مبلغ پیش از تخفیف ÷ مقدار) و تخفیف ردیف = تفاوت تا مبلغ پس از تخفیف؛ پس جمع خالص دقیقاً برابر فروشگاه است.
     */
    private static function dispatchInternal($cid,array $client,array $order){
        if(!in_array($order['status'],['paid','review'],true))throw new ConflictException('این سفارش در وضعیت قابل خروج نیست.');
        if((int)$order['refunded_amount']>0)throw new ConflictException('بخشی از وجه این سفارش پیش از خروج کالا مسترد شده است؛ خروج خودکار ممکن نیست. فاکتور را دستی صادر کنید و سفارش را با توضیح ببندید.');
        $snap=json_decode((string)$order['payload'],true);
        $res=$order['reservation_id']?Business::row('connect_reservations',$cid,(int)$order['reservation_id'],true):null;
        if($snap['qty']&&(!$res||$res['status']!=='paid')){
            // سفارش در بررسی: اگر حالا موجودی آزاد کافی باشد، همین‌جا دوباره رزرو و خارج می‌شود.
            if(self::shortages($cid,(int)$client['warehouse_id'],$snap['qty'],0))throw new ConflictException('موجودی آزاد انبار برای این سفارش کافی نیست؛ ابتدا موجودی را تأمین کنید.');
            $rid=self::hold($cid,$client,$order['order_ref'],$snap['qty'],'paid',null);Business::save('connect_orders',$cid,(int)$order['id'],['reservation_id'=>$rid]);$res=Business::row('connect_reservations',$cid,$rid,true);
        }
        $input=[];$wh=$res?(int)$res['warehouse_id']:0;
        foreach($snap['lines'] as $l){
            $item=Business::row('items',$cid,(int)$l['item_id']);$qty=(int)$l['qty'];
            $price=(int)bcdiv(bcadd(bcmul((string)$l['subtotal'],(string)Money::QTY_SCALE),(string)($qty-1)),(string)$qty,0);
            $discount=Money::lineAmount($qty,$price)-(int)$l['total'];
            $input[]=['item_id'=>(int)$item['id'],'warehouse_id'=>$item['kind']==='goods'?$wh:0,'qty_scaled'=>$qty,'unit_price'=>$price,'discount_amount'=>$discount,'tax_rate_id'=>'item'];
        }
        if($snap['shipping']>0){if(!$client['shipping_item_id'])throw new ConflictException('خدمت «هزینهٔ ارسال» در تنظیمات اتصال تعیین نشده است.');$input[]=['item_id'=>(int)$client['shipping_item_id'],'warehouse_id'=>0,'qty_scaled'=>Money::QTY_SCALE,'unit_price'=>(int)$snap['shipping'],'discount_amount'=>0,'tax_rate_id'=>'item'];}
        $date=Jalali::today();
        $inv=InvoiceService::save($cid,0,['kind'=>'sale','party_id'=>(int)$order['party_id'],'invoice_date'=>$date,'due_date'=>$date,'description'=>'سفارش اینترنتی '.$client['name'].' '.$order['order_ref']],$input);
        $d=InvoiceService::get($cid,$inv);
        if((int)$d['amount']!==(int)$snap['total'])throw new ConflictException('مبلغ فاکتور در آگاه ('.Money::fmt((int)$d['amount'],'rial').' ریال) با مبلغ پرداخت‌شده در فروشگاه ('.Money::fmt((int)$snap['total'],'rial').' ریال) برابر نیست؛ نرخ مالیات کالا یا هزینهٔ ارسال را بررسی کنید.');
        StockService::$consuming=$res?(int)$res['id']:0;
        try{InvoiceService::issue($cid,$inv,(int)$d['revision'],[],[['doc_id'=>(int)$order['payment_doc_id'],'amount'=>(int)$d['amount']]]);}finally{StockService::$consuming=0;}
        if($res)Business::save('connect_reservations',$cid,(int)$res['id'],['status'=>'consumed']);
        Business::save('connect_orders',$cid,(int)$order['id'],['status'=>'dispatched','invoice_id'=>$inv,'review_reason'=>'','dispatched_at'=>self::now()]);
        Audit::log('connect.order.dispatch','connect_order',(int)$order['id'],['status'=>$order['status']],['status'=>'dispatched','invoice_id'=>$inv],$cid);
    }
    /** لغو سفارش پیش از خروج کالا؛ استرداد وجه پیش‌دریافت را حسابدار ثبت می‌کند و سفارش علامت «استرداد لازم» می‌گیرد. */
    public static function cancel(array $client,array $in){
        $cid=(int)$client['company_id'];
        return Access::write($cid,'sale_invoice.create',function()use($cid,$client,$in){
            $ref=self::ref($in['order_ref']??null);
            $o=Db::row('SELECT * FROM '.Db::t('connect_orders').' WHERE company_id=%d AND client_id=%d AND order_ref=%s FOR UPDATE',$cid,(int)$client['id'],$ref);
            if(!$o){if($r=self::activeReservation($cid,(int)$client['id'],$ref))Business::save('connect_reservations',$cid,(int)$r['id'],['status'=>'released']);return ['order_ref'=>$ref,'status'=>'cancelled','status_label'=>self::STATES['cancelled'],'paid'=>false];}
            if($o['status']==='dispatched')throw new ApiError(409,'already_dispatched','کالا خارج شده است؛ مرجوعی از مسیر برگشت فروش در آگاه انجام می‌شود.');
            if($o['status']!=='cancelled'){
                if($o['reservation_id'])Db::update('connect_reservations',['status'=>'released','updated_at'=>self::now()],['company_id'=>$cid,'id'=>(int)$o['reservation_id'],'status'=>'paid']);
                Business::save('connect_orders',$cid,(int)$o['id'],['status'=>'cancelled','refund_due'=>1,'review_reason'=>'سفارش پس از پرداخت لغو شد؛ استرداد وجه پیش‌دریافت باید ثبت شود.']);
                Audit::log('connect.order.cancel','connect_order',(int)$o['id'],['status'=>$o['status']],['status'=>'cancelled'],$cid);
            }
            return self::orderOut($cid,Business::row('connect_orders',$cid,(int)$o['id']));
        });
    }
    /** ردیف‌های کالای مسترد: [شناسهٔ داخلی کالا => مقدار]؛ خالی یعنی استرداد مبلغ بدون کالا. */
    private static function refundLines($cid,$lines){
        if($lines===null||$lines===[])return [];
        if(!is_array($lines)||count($lines)>60)throw new ApiError(400,'bad_lines','حداکثر ۶۰ ردیف مرجوعی پذیرفته می‌شود.');
        $out=[];foreach($lines as $l){$item=self::item($cid,is_array($l)?($l['item']??null):null,false);$out[(int)$item['id']]=($out[(int)$item['id']]??0)+self::qty(is_array($l)?($l['qty']??null):null);}
        return $out;
    }
    /**
     * استرداد وجه از فروشگاه (رویداد مالی؛ جدا از برگشت کالا).
     * - پیش از خروج کالا: برگهٔ پرداخت «استرداد پیش‌دریافت». استرداد کامل = لغو سفارش و آزادسازی رزرو؛ استرداد جزئی = «نیازمند بررسی».
     * - پس از خروج کالا: برگهٔ پرداخت «استرداد به مشتری». موجودی تغییر نمی‌کند تا کالا واقعاً دریافت شود (Q34)؛
     *   برگشت فروش با اقدام «دریافت فیزیکی کالا» در آگاه صادر می‌شود. استرداد بدون کالا برای تصمیم حسابدار می‌ماند.
     * هر refund_ref فقط یک بار ثبت می‌شود؛ تکرار همان پاسخ را برمی‌گرداند.
     */
    public static function refund(array $client,array $in){
        $cid=(int)$client['company_id'];
        return Access::write($cid,'sale_invoice.create',function()use($cid,$client,$in){
            $ref=self::ref($in['order_ref']??null);$rref=$in['refund_ref']??null;
            if(!is_string($rref)||!preg_match('/^[A-Za-z0-9_.:-]{1,80}$/D',$rref))throw new ApiError(400,'bad_refund_ref','شناسهٔ استرداد معتبر نیست.');
            $o=Db::row('SELECT * FROM '.Db::t('connect_orders').' WHERE company_id=%d AND client_id=%d AND order_ref=%s FOR UPDATE',$cid,(int)$client['id'],$ref);
            if(!$o)throw new ApiError(404,'order_not_found','سفارش پرداخت‌شده‌ای با این شماره در آگاه ثبت نشده است.');
            if($r=Db::row('SELECT * FROM '.Db::t('connect_refunds').' WHERE client_id=%d AND refund_ref=%s',(int)$client['id'],$rref))return self::refundOut($cid,$r);
            $amount=self::amount($in['amount']??null,'amount');if($amount<1)throw new ApiError(400,'bad_amount','مبلغ استرداد باید مثبت باشد.',['field'=>'amount']);
            $lines=self::refundLines($cid,$in['lines']??null);$ship=self::amount($in['shipping']??'0','shipping');
            $left=(int)$o['paid_amount']-(int)$o['refunded_amount'];
            if($amount>$left)throw new ApiError(409,'refund_exceeds','مبلغ استرداد از ماندهٔ پرداخت این سفارش بیشتر است.',['refundable'=>self::rial($left)]);
            if($o['status']==='closed')throw new ApiError(409,'order_closed','این سفارش در آگاه بسته شده است؛ استرداد را حسابدار دستی ثبت می‌کند.');
            $before=$o['status']!=='dispatched';$date=Jalali::today();
            $doc=TreasuryService::issue($cid,['kind'=>'payment','doc_date'=>$date,'party_id'=>(int)$o['party_id'],'refund'=>$before?'advance':'customer','refund_of_doc_id'=>(int)$o['payment_doc_id'],'description'=>'استرداد سفارش اینترنتی '.$client['name'].' '.$ref],[['method'=>'transfer','treasury_account_id'=>(int)$client['treasury_account_id'],'amount'=>$amount,'reference'=>$rref]]);
            $refunded=(int)$o['refunded_amount']+$amount;$upd=['refunded_amount'=>$refunded];$note='';
            if($before){
                $status='refunded';
                if($refunded===(int)$o['paid_amount']){
                    if($o['reservation_id'])Db::update('connect_reservations',['status'=>'released','updated_at'=>self::now()],['company_id'=>$cid,'id'=>(int)$o['reservation_id'],'status'=>'paid']);
                    $upd+=['status'=>'cancelled','refund_due'=>0,'review_reason'=>''];
                }else{
                    $note='بخشی از وجه ('.Money::fmt($amount,'rial').' ریال) پیش از خروج کالا مسترد شد؛ خروج خودکار متوقف است. فاکتور را دستی صادر کنید یا پس از استرداد کامل سفارش را ببندید.';
                    $upd+=$o['status']==='cancelled'?['review_reason'=>$note]:['status'=>'review','review_reason'=>$note];
                }
            }else{
                $status=$lines||$ship>0?'awaiting':'review';
                if($status==='review')$note='استرداد مبلغ بدون برگشت کالا (تعدیل پس از فروش)؛ حسابدار سند اصلاحی را ثبت و تعیین‌تکلیف کند.';
            }
            Business::save('connect_orders',$cid,(int)$o['id'],$upd);
            $rid=Business::save('connect_refunds',$cid,0,['client_id'=>(int)$client['id'],'order_id'=>(int)$o['id'],'refund_ref'=>$rref,'stage'=>$before?'before':'after','amount'=>$amount,'payload'=>wp_json_encode(['lines'=>$lines,'shipping'=>$ship,'reason'=>Request::clean($in['reason']??'',300)]),'status'=>$status,'treasury_doc_id'=>$doc,'note'=>$note]);
            Audit::log('connect.order.refund','connect_order',(int)$o['id'],['refunded'=>(int)$o['refunded_amount']],['refund_ref'=>$rref,'amount'=>$amount,'status'=>$status,'treasury_doc_id'=>$doc],$cid);
            if($status!=='refunded'||$note!=='')\Agah\Erp\Modules\Notify\NotificationService::notify($cid,\Agah\Erp\Modules\Notify\NotificationService::holders($cid,'connect.edit'),'api','استرداد سفارش فروشگاه: '.$ref,$status==='awaiting'?'وجه مسترد شد؛ پس از رسیدن کالا «دریافت فیزیکی کالا» را ثبت کنید.':$note,agah_erp_url(['c'=>$cid,'p'=>'connect_orders']),'conn-refund|'.$rid);
            return self::refundOut($cid,Business::row('connect_refunds',$cid,$rid));
        });
    }
    private static function refundOut($cid,array $r){
        $o=Business::row('connect_orders',$cid,(int)$r['order_id']);$n=$r['invoice_id']?Db::val('SELECT number FROM '.Db::t('invoices').' WHERE company_id=%d AND id=%d',$cid,(int)$r['invoice_id']):null;
        return ['order_ref'=>$o['order_ref'],'refund_ref'=>$r['refund_ref'],'stage'=>$r['stage'],'status'=>$r['status'],'status_label'=>self::REFUND_STATES[$r['status']]??$r['status'],'amount'=>self::rial($r['amount']),'return_invoice_number'=>$n!==null?(string)$n:null,'order'=>self::orderOut($cid,$o)];
    }
    /** مقدار فروخته‌شدهٔ هنوز برنگشتهٔ یک خط فاکتور (فقط برگشت‌های صادرشده). */
    private static function returnable($cid,array $line){
        return (int)$line['qty_scaled']-(int)Db::val('SELECT COALESCE(SUM(l.qty_scaled),0) FROM '.Db::t('invoice_lines').' l JOIN '.Db::t('invoices')." i ON i.company_id=l.company_id AND i.id=l.invoice_id WHERE l.company_id=%d AND l.source_line_id=%d AND i.status='issued'",$cid,(int)$line['id']);
    }
    /**
     * دریافت فیزیکی کالای مرجوعی (اقدام انسانی): برگشت فروش از روی خطوط همان فاکتور صادر و انبار افزایش می‌یابد؛
     * برگهٔ استرداد قبلی به برگشت فروش پیوند می‌خورد. اختلاف مبلغ، استرداد را «نیازمند بررسی» نگه می‌دارد.
     */
    public static function receiveReturn($cid,$refundId,$date){
        return Access::write($cid,'connect.edit',function()use($cid,$refundId,$date){
            $r=Business::row('connect_refunds',$cid,$refundId,true);if($r['status']!=='awaiting')throw new ConflictException('این استرداد در انتظار دریافت کالا نیست.');
            $o=Business::row('connect_orders',$cid,(int)$r['order_id'],true);$client=Business::row('connect_clients',$cid,(int)$o['client_id']);$inv=\Agah\Erp\Modules\Trade\InvoiceService::get($cid,(int)$o['invoice_id']);
            $p=json_decode((string)$r['payload'],true)?:[];$input=[];$gaps=[];
            foreach(($p['lines']??[]) as $itemId=>$qty){
                $need=(int)$qty;
                foreach($inv['lines'] as $l){if($need<1)break;if((int)$l['item_id']!==(int)$itemId)continue;$take=min($need,self::returnable($cid,$l));if($take<1)continue;$input[]=['source_line_id'=>(int)$l['id'],'qty_scaled'=>$take];$need-=$take;}
                if($need>0)throw new ConflictException('مقدار مرجوعی کالا بیش از مقدار فروخته‌شدهٔ برنگشته است.');
            }
            if((int)($p['shipping']??0)>0){
                $done=false;foreach($inv['lines'] as $l)if($client['shipping_item_id']&&(int)$l['item_id']===(int)$client['shipping_item_id']&&self::returnable($cid,$l)===(int)$l['qty_scaled']&&Money::add((int)$l['net_amount'],(int)$l['tax_amount'])===(int)$p['shipping']){$input[]=['source_line_id'=>(int)$l['id'],'qty_scaled'=>(int)$l['qty_scaled']];$done=true;break;}
                if(!$done)$gaps[]='استرداد هزینهٔ ارسال با خط ارسال فاکتور برابر نبود و در برگشت فروش نیامد.';
            }
            if(!$input)throw new ConflictException('خطی برای برگشت فروش پیدا نشد؛ استرداد را با توضیح تعیین‌تکلیف کنید.');
            $rid=\Agah\Erp\Modules\Trade\InvoiceService::save($cid,0,['kind'=>'sale_return','parent_id'=>(int)$inv['id'],'party_id'=>(int)$o['party_id'],'invoice_date'=>$date,'due_date'=>$date,'description'=>'مرجوعی سفارش اینترنتی '.$client['name'].' '.$o['order_ref'].' ('.$r['refund_ref'].')'],$input);
            $d=\Agah\Erp\Modules\Trade\InvoiceService::get($cid,$rid);\Agah\Erp\Modules\Trade\InvoiceService::issue($cid,$rid,(int)$d['revision']);
            $amt=(int)Db::val('SELECT amount FROM '.Db::t('invoices').' WHERE company_id=%d AND id=%d',$cid,$rid);
            TreasuryService::linkRefund($cid,(int)$r['treasury_doc_id'],$rid,min($amt,(int)$r['amount']));
            if($amt!==(int)$r['amount'])$gaps[]='مبلغ برگشت فروش ('.Money::fmt($amt,'rial').' ریال) با مبلغ استرداد ('.Money::fmt((int)$r['amount'],'rial').' ریال) برابر نیست؛ اختلاف را با سند اصلاحی تعیین‌تکلیف کنید.';
            $status=$gaps?'review':'received';
            Business::save('connect_refunds',$cid,(int)$r['id'],['status'=>$status,'invoice_id'=>$rid,'note'=>mb_substr(implode(' ',$gaps),0,500),'decided_by'=>Ctx::uid(),'decided_at'=>self::now()]);
            Audit::log('connect.refund.receive','connect_refund',(int)$r['id'],['status'=>'awaiting'],['status'=>$status,'invoice_id'=>$rid],$cid);
            return $rid;
        });
    }
    /** تعیین‌تکلیف دستی استرداد (مثلاً کالا برنمی‌گردد یا اختلاف با سند اصلاحی ثبت شد)؛ توضیح الزامی و ممیزی‌شده. */
    public static function resolveRefund($cid,$refundId,$note){
        return Access::write($cid,'connect.edit',function()use($cid,$refundId,$note){
            $r=Business::row('connect_refunds',$cid,$refundId,true);if(!in_array($r['status'],['awaiting','review'],true))throw new ConflictException('این استرداد باز نیست.');
            $note=Request::clean($note,300);if(mb_strlen($note)<5)throw new ValidationException(['note'=>'توضیح تعیین‌تکلیف را بنویسید (حداقل ۵ نویسه).']);
            Business::save('connect_refunds',$cid,(int)$r['id'],['status'=>'resolved','note'=>mb_substr(trim($r['note'].' — '.$note,' —'),0,500),'decided_by'=>Ctx::uid(),'decided_at'=>self::now()]);
            Audit::log('connect.refund.resolve','connect_refund',(int)$r['id'],['status'=>$r['status']],['status'=>'resolved','note'=>$note],$cid);return true;
        });
    }
    /** بستن دستی سفارشِ نیازمند بررسی یا لغوشدهٔ دارای استرداد ثبت‌نشده؛ رزرو آزاد می‌شود و توضیح ممیزی می‌شود. */
    public static function closeOrder($cid,$orderId,$note){
        return Access::write($cid,'connect.edit',function()use($cid,$orderId,$note){
            $o=Business::row('connect_orders',$cid,$orderId,true);if(!in_array($o['status'],['review','cancelled'],true))throw new ConflictException('فقط سفارش نیازمند بررسی یا لغوشده بسته می‌شود.');
            $note=Request::clean($note,300);if(mb_strlen($note)<5)throw new ValidationException(['note'=>'دلیل بستن را بنویسید (حداقل ۵ نویسه).']);
            if($o['reservation_id'])Db::update('connect_reservations',['status'=>'released','updated_at'=>self::now()],['company_id'=>$cid,'id'=>(int)$o['reservation_id'],'status'=>'paid']);
            Business::save('connect_orders',$cid,(int)$o['id'],['status'=>'closed','refund_due'=>0,'review_reason'=>$note]);
            Audit::log('connect.order.close','connect_order',(int)$o['id'],['status'=>$o['status'],'refund_due'=>$o['refund_due']],['status'=>'closed','note'=>$note],$cid);return true;
        });
    }
    /** استردادهای باز و اخیر برای صفحهٔ سفارش‌ها. */
    public static function refunds($cid){
        Access::need($cid,'connect.view');
        return Db::rows('SELECT r.*,o.order_ref,c.name AS store,i.number AS return_number FROM '.Db::t('connect_refunds').' r JOIN '.Db::t('connect_orders').' o ON o.company_id=r.company_id AND o.id=r.order_id JOIN '.Db::t('connect_clients').' c ON c.company_id=r.company_id AND c.id=r.client_id LEFT JOIN '.Db::t('invoices').' i ON i.company_id=r.company_id AND i.id=r.invoice_id WHERE r.company_id=%d ORDER BY FIELD(r.status,\'awaiting\',\'review\') DESC,r.id DESC LIMIT 50',$cid);
    }
    private static function orderOut($cid,array $o){
        $number=$o['invoice_id']?Db::val('SELECT number FROM '.Db::t('invoices').' WHERE company_id=%d AND id=%d',$cid,(int)$o['invoice_id']):null;
        return ['order_ref'=>$o['order_ref'],'status'=>$o['status'],'status_label'=>self::STATES[$o['status']]??$o['status'],'paid_amount'=>self::rial($o['paid_amount']),'refunded_amount'=>self::rial($o['refunded_amount']??0),'invoice_number'=>$number!==null?(string)$number:null,'dispatched_at'=>$o['dispatched_at']?gmdate('c',strtotime($o['dispatched_at'].' UTC')):null,'refund_due'=>(bool)$o['refund_due'],'updated_at'=>gmdate('c',strtotime($o['updated_at'].' UTC'))];
    }
    public static function order(array $client,array $q){
        $cid=(int)$client['company_id'];Access::need($cid,'sale_invoice.view');$ref=self::ref($q['order_ref']??null);
        $o=Db::row('SELECT * FROM '.Db::t('connect_orders').' WHERE company_id=%d AND client_id=%d AND order_ref=%s',$cid,(int)$client['id'],$ref);
        if(!$o)throw new ApiError(404,'order_not_found','سفارش پیدا نشد.');return self::orderOut($cid,$o);
    }
    /** تغییر وضعیت سفارش‌ها برای همگام‌سازی فروشگاه؛ cursor به شکل «زمان:شناسه». */
    public static function orders(array $client,array $q){
        $cid=(int)$client['company_id'];Access::need($cid,'sale_invoice.view');
        $cursor=(string)($q['cursor']??'0:0');if(!preg_match('/^(\d{1,10}):(\d{1,18})$/D',$cursor,$m))throw new ApiError(400,'bad_cursor','cursor معتبر نیست.');
        $since=gmdate('Y-m-d H:i:s',(int)$m[1]);
        $rows=Db::rows('SELECT * FROM '.Db::t('connect_orders').' WHERE company_id=%d AND client_id=%d AND (updated_at>%s OR (updated_at=%s AND id>%d)) ORDER BY updated_at,id LIMIT 101',$cid,(int)$client['id'],$since,$since,(int)$m[2]);
        $more=count($rows)>100;$rows=array_slice($rows,0,100);$next=$cursor;$out=[];
        foreach($rows as $o){$out[]=self::orderOut($cid,$o);$next=strtotime($o['updated_at'].' UTC').':'.$o['id'];}
        return ['orders'=>$out,'cursor'=>$next,'has_more'=>$more];
    }

    // ------------------------------------------------------------------ سفارش‌ها در آگاه (کاربر انسانی)

    public static function ordersPage($cid,$status,$page){
        Access::need($cid,'connect.view');$where='o.company_id=%d';$args=[$cid];
        if($status!==''){if(!isset(self::STATES[$status]))throw new ValidationException(['status'=>'وضعیت معتبر نیست.']);$where.=' AND o.status=%s';$args[]=$status;}
        $n=(int)Db::val('SELECT COUNT(*) FROM '.Db::t('connect_orders').' o WHERE '.$where,...$args);$page=max(1,(int)$page);
        $rows=Db::rows('SELECT o.*,c.name AS store,p.name AS party_name,i.number AS invoice_number FROM '.Db::t('connect_orders').' o JOIN '.Db::t('connect_clients').' c ON c.company_id=o.company_id AND c.id=o.client_id JOIN '.Db::t('parties').' p ON p.company_id=o.company_id AND p.id=o.party_id LEFT JOIN '.Db::t('invoices').' i ON i.company_id=o.company_id AND i.id=o.invoice_id WHERE '.$where.' ORDER BY o.id DESC LIMIT 50 OFFSET %d',...array_merge($args,[($page-1)*50]));
        return ['rows'=>$rows,'page'=>$page,'pages'=>(int)ceil($n/50)];
    }
    /** خروج کالا توسط کاربر مجاز آگاه (برای فروشگاهی که خروج خودکار ندارد یا سفارش در بررسی است). */
    public static function dispatch($cid,$orderId){
        return Access::write($cid,'connect.edit',function()use($cid,$orderId){
            $o=Business::row('connect_orders',$cid,$orderId,true);$client=Business::row('connect_clients',$cid,(int)$o['client_id']);
            self::dispatchInternal($cid,$client,$o);return (int)$orderId;
        });
    }
}
