<?php
/**
 * مرکز اعلان داخلی (NTF-02) و صف پیامک/ایمیل با ارائه‌دهندهٔ قابل تعویض (NTF-03).
 * - هر اعلان برای هر گیرنده با کلید یکتا (dedupe) فقط یک بار ثبت می‌شود؛ پس رویداد تکراری اعلان دوم نمی‌سازد.
 * - صف پیام: گیرنده رمزشده، تلاش محدود (۳ بار با فاصلهٔ رو به افزایش)، وضعیت تحویل و خطا؛ پیام تبلیغاتی بدون رضایت ارسال نمی‌شود.
 * - ارائه‌دهندهٔ ایمیل پیش‌فرض wp_mail است؛ پیامک فقط از فیلتر سرور (agah_erp_message_sms) می‌آید و بدون آن ارسال صوری نمی‌شود.
 */
namespace Agah\Erp\Modules\Notify;
use Agah\Erp\Core\{Access,Audit,Crypto,Ctx,Db,Request,ValidationException};
if(!defined('ABSPATH'))exit;
final class NotificationService {
    const TYPES=['referral'=>'ارجاع','discount'=>'تخفیف و تغییر قیمت','reconcile'=>'مغایرت و تطبیق','expiry'=>'انقضای اشتراک','api'=>'نقص اتصال فروشگاه','sensitive'=>'رخداد حساس'];
    const MAX_ATTEMPTS=3;

    /** اعضای فعال دارای یک مجوز (بدون کاربر سرویس فروشگاه). */
    public static function holders($cid,$cap){
        return array_map('intval',Db::col('SELECT DISTINCT m.user_id FROM '.Db::t('company_members').' m JOIN '.Db::t('role_caps')." rc ON rc.role_id=m.role_id AND rc.cap=%s WHERE m.company_id=%d AND m.status='active' AND NOT EXISTS (SELECT 1 FROM ".Db::t('connect_clients').' cc WHERE cc.company_id=m.company_id AND cc.service_user_id=m.user_id)',$cap,$cid));
    }
    /** ثبت اعلان برای گیرندگان؛ خروجی: شمار اعلان تازه. $email=true یعنی برای هر گیرندهٔ تازه ایمیل هم در صف می‌رود. */
    public static function notify($cid,array $users,$type,$title,$body,$link,$dedupe,$email=false){
        if(!isset(self::TYPES[$type]))throw new ValidationException(['type'=>'نوع اعلان معتبر نیست.']);$n=0;$now=gmdate('Y-m-d H:i:s');
        foreach(array_unique(array_map('intval',$users)) as $uid){if($uid<1)continue;
            $ok=Db::exec('INSERT IGNORE INTO '.Db::t('notifications').' (uuid,company_id,user_id,type,title,body,link,dedupe_key,created_at,updated_at) VALUES (%s,%d,%d,%s,%s,%s,%s,%s,%s,%s)',Crypto::uuid(),$cid,$uid,$type,mb_substr($title,0,190),mb_substr((string)$body,0,500),mb_substr((string)$link,0,300),mb_substr($dedupe,0,120),$now,$now);
            if($ok){$n++;if($email){$u=get_userdata($uid);if($u&&is_email($u->user_email))self::queue($cid,'email',$u->user_email,$title,$body."\n".$link,'n|'.$cid.'|'.$uid.'|'.$dedupe);}}
        }
        return $n;
    }
    public static function mine($cid,$unreadOnly=false,$limit=100){Access::need($cid,'dashboard.view');return Db::rows('SELECT * FROM '.Db::t('notifications').' WHERE company_id=%d AND user_id=%d'.($unreadOnly?' AND read_at IS NULL':'').' ORDER BY id DESC LIMIT %d',$cid,Ctx::uid(),(int)$limit);}
    public static function unread($cid){return (int)Db::val('SELECT COUNT(*) FROM '.Db::t('notifications').' WHERE company_id=%d AND user_id=%d AND read_at IS NULL',$cid,Ctx::uid());}
    public static function markRead($cid,$id=0){
        Access::need($cid,'dashboard.view');$now=gmdate('Y-m-d H:i:s');
        return (int)($id?Db::exec('UPDATE '.Db::t('notifications').' SET read_at=%s,updated_at=%s WHERE company_id=%d AND user_id=%d AND id=%d AND read_at IS NULL',$now,$now,$cid,Ctx::uid(),(int)$id):Db::exec('UPDATE '.Db::t('notifications').' SET read_at=%s,updated_at=%s WHERE company_id=%d AND user_id=%d AND read_at IS NULL',$now,$now,$cid,Ctx::uid()));
    }

    // ------------------------------------------------------------ صف پیامک/ایمیل (NTF-03)

    private static function hint($channel,$to){if($channel==='email'){[$a,$d]=array_pad(explode('@',$to,2),2,'');return mb_substr($a,0,2).'***@'.$d;}return str_repeat('*',max(0,strlen($to)-4)).substr($to,-4);}
    /** پیام در صف؛ همان کلید یکتا دوباره صف نمی‌شود. */
    public static function queue($cid,$channel,$to,$subject,$body,$dedupe,$consent='transactional'){
        if(!in_array($channel,['email','sms'],true)||!in_array($consent,['transactional','marketing'],true))throw new ValidationException(['channel'=>'کانال یا نوع پیام معتبر نیست.']);
        $to=trim((string)$to);if($channel==='email'&&!is_email($to))throw new ValidationException(['to'=>'ایمیل معتبر نیست.']);if($channel==='sms'&&!preg_match('/^\+?[0-9]{10,15}$/D',$to))throw new ValidationException(['to'=>'شمارهٔ همراه معتبر نیست.']);
        $now=gmdate('Y-m-d H:i:s');
        return (bool)Db::exec('INSERT IGNORE INTO '.Db::t('message_queue').' (company_id,channel,recipient_enc,recipient_hint,subject,body,consent,status,dedupe_key,next_try_at,created_at,updated_at) VALUES (%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)',(int)$cid,$channel,Crypto::encrypt($to),self::hint($channel,$to),mb_substr((string)$subject,0,190),mb_substr((string)$body,0,2000),$consent,'queued',mb_substr($dedupe,0,140),$now,$now,$now);
    }
    /** ارسال پیام‌های سررسیده؛ شکست موقت با فاصلهٔ ۵، ۱۰، ۲۰ دقیقه تکرار و پس از ۳ بار «ناموفق نهایی» می‌شود. */
    public static function drain($limit=20){
        $sent=0;$lock='agah_msg_'.substr(hash('sha256',Db::t('message_queue')),0,16);if(1!==(int)Db::val('SELECT GET_LOCK(%s,0)',$lock))return 0;
        try{foreach(Db::rows('SELECT * FROM '.Db::t('message_queue')." WHERE status='queued' AND next_try_at<=%s ORDER BY id LIMIT %d",gmdate('Y-m-d H:i:s'),(int)$limit) as $m){
            $now=gmdate('Y-m-d H:i:s');
            if($m['channel']==='sms'&&preg_match('/^invoice-share-sms\|([0-9]+)\|/D',$m['dedupe_key'],$share)){
                $active=Db::val('SELECT s.id FROM '.Db::t('share_links').' s JOIN '.Db::t('invoices')." i ON i.company_id=s.company_id AND i.id=s.invoice_id JOIN ".Db::t('companies')." c ON c.id=s.company_id WHERE s.company_id=%d AND s.id=%d AND s.revoked_at IS NULL AND s.expires_at>%s AND i.status='issued' AND c.status='active'",$m['company_id'],(int)$share[1],$now);
                if(!$active){Db::update('message_queue',['status'=>'blocked','last_error'=>'پیوند یا فاکتور دیگر فعال نیست؛ برای جلوگیری از هزینه ارسال نشد.','updated_at'=>$now],['id'=>$m['id']]);continue;}
            }
            if($m['consent']==='marketing'&&!self::consented($m)){Db::update('message_queue',['status'=>'blocked','last_error'=>'رضایت گیرنده برای پیام تبلیغاتی ثبت نشده است.','updated_at'=>$now],['id'=>$m['id']]);continue;}
            $manualShare=$m['channel']==='sms'&&strpos($m['dedupe_key'],'invoice-share-sms|')===0;
            try{$ref=self::send($m['channel'],(string)Crypto::decrypt($m['recipient_enc']),$m['subject'],$m['body']);if($manualShare&&($ref===false||$ref===null||$ref===''))throw new \RuntimeException('تأیید معتبر از سرویس پیامک دریافت نشد؛ پیش از درخواست تازه وضعیت سرویس را بررسی کنید.');Db::update('message_queue',['status'=>'sent','sent_at'=>$now,'provider_ref'=>mb_substr((string)$ref,0,100),'attempts'=>(int)$m['attempts']+1,'last_error'=>'','updated_at'=>$now],['id'=>$m['id']]);$sent++;}
            catch(\Throwable $e){$a=(int)$m['attempts']+1;$final=$manualShare||$a>=self::MAX_ATTEMPTS||$e instanceof NoProviderException;Db::update('message_queue',['status'=>$final?'dead':'queued','attempts'=>$a,'last_error'=>mb_substr($e->getMessage(),0,300),'next_try_at'=>gmdate('Y-m-d H:i:s',time()+300*(2**($a-1))),'updated_at'=>$now],['id'=>$m['id']]);}
        }}finally{Db::val('SELECT RELEASE_LOCK(%s)',$lock);}
        return $sent;
    }
    private static function consented(array $m){$to=(string)Crypto::decrypt($m['recipient_enc']);$u=$m['channel']==='email'?get_user_by('email',$to):null;return $u&&get_user_meta($u->ID,'agah_marketing_consent',true)==='1';}
    /** ارائه‌دهنده: ایمیل = wp_mail (قابل جایگزینی با فیلتر)، پیامک = فقط فیلتر سرور. */
    private static function send($channel,$to,$subject,$body){
        $p=apply_filters('agah_erp_message_'.$channel,null);
        if(is_object($p)&&method_exists($p,'send'))return $p->send($to,$subject,$body);
        if($channel==='email'){if(!wp_mail($to,$subject,$body))throw new \RuntimeException('ارسال ایمیل ناموفق بود.');return 'wp_mail';}
        throw new NoProviderException('ارائه‌دهندهٔ پیامک روی سرور تنظیم نشده است؛ پیامک ارسال نشد.');
    }
    public static function queueStatus(){if(!current_user_can('manage_options'))throw new \Agah\Erp\Core\ForbiddenException();return ['counts'=>Db::rows('SELECT status,channel,COUNT(*) n FROM '.Db::t('message_queue').' GROUP BY status,channel'),'recent'=>Db::rows('SELECT id,company_id,channel,recipient_hint,subject,status,attempts,last_error,created_at,sent_at FROM '.Db::t('message_queue').' ORDER BY id DESC LIMIT 30')];}
}
final class NoProviderException extends \RuntimeException {}
