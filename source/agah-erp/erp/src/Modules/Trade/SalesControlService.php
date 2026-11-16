<?php
/**
 * کنترل‌های فروش فاز دوم:
 * - TRD-02 سقف تخفیف و دامنهٔ تغییر قیمت هر نقش تا پیش از قطعی‌شدن؛ قبل/بعد و علت ثبت و در ورود بعدی به مدیر اعلان می‌شود.
 * - TRD-03 کارتابل ارجاع سفارش/فاکتور با وضعیت، گیرنده و تاریخچه؛ ارجاع باز تکراری ساخته نمی‌شود.
 * - TRD-04 پیوند اشتراک زمان‌دار فقط برای همان برگه، قابل لغو و با ردپای دیدن.
 */
namespace Agah\Erp\Modules\Trade;
use Agah\Erp\Core\{Access,Audit,Business,ConflictException,Crypto,Ctx,Db,Money,NotFoundException,Request,ValidationException};
if(!defined('ABSPATH'))exit;
final class SalesControlService {
    const SELLING=['proforma','sale_order','sale'];
    const SHAREABLE=['proforma','sale_order','sale'];
    const REF_STATUS=['open'=>'باز','accepted'=>'پذیرفته','done'=>'انجام‌شده','returned'=>'برگشت به ارجاع‌دهنده','cancelled'=>'لغوشده'];

    // ------------------------------------------------------------ TRD-02

    public static function policies($cid){Access::need($cid,'role.view');return Db::rows('SELECT r.id AS role_id,r.name,r.role_key,p.max_discount_bp,p.max_price_down_bp,p.max_price_up_bp,p.require_reason,p.id FROM '.Db::t('roles').' r LEFT JOIN '.Db::t('price_policies').' p ON p.company_id=r.company_id AND p.role_id=r.id WHERE r.company_id=%d ORDER BY r.id',$cid);}
    public static function savePolicy($cid,$role,array $in){
        return Access::write($cid,'role.edit',function()use($cid,$role,$in){
            $r=Business::row('roles',$cid,$role);$old=Db::row('SELECT * FROM '.Db::t('price_policies').' WHERE company_id=%d AND role_id=%d FOR UPDATE',$cid,$role);
            if(!empty($in['remove'])){if($old){Db::delete('price_policies',['company_id'=>$cid,'id'=>$old['id']]);Audit::log('sales.policy.delete','role',$role,$old,null,$cid);}return true;}
            $data=['role_id'=>(int)$r['id'],'require_reason'=>empty($in['require_reason'])?0:1];
            foreach(['max_discount_bp','max_price_down_bp','max_price_up_bp'] as $k){$v=(int)($in[$k]??0);if($v<0||$v>10000*($k==='max_price_up_bp'?10:1))throw new ValidationException([$k=>'درصد معتبر نیست.']);$data[$k]=$v;}
            $id=Business::save('price_policies',$cid,(int)($old['id']??0),$data);Audit::log('sales.policy.save','role',$role,$old,$data,$cid);return $id;
        });
    }
    private static function policyFor($cid){
        $role=(int)Db::val('SELECT role_id FROM '.Db::t('company_members').' WHERE company_id=%d AND user_id=%d',$cid,Ctx::uid());
        return $role?Db::row('SELECT * FROM '.Db::t('price_policies').' WHERE company_id=%d AND role_id=%d',$cid,$role):null;
    }
    /** انحراف هر ردیف از قیمت کالا و تخفیف آن؛ فراتر از سقف نقش ردّ می‌شود، داخل سقف (و غیرصفر) علت می‌خواهد و ثبت می‌شود. */
    public static function check($cid,$kind,array $rows,$reason){
        if(!in_array($kind,self::SELLING,true))return [];$p=self::policyFor($cid);if(!$p)return [];$out=[];$errors=[];
        foreach($rows as $i=>$r){
            $catalog=(int)Db::val('SELECT sale_price FROM '.Db::t('items').' WHERE company_id=%d AND id=%d',$cid,$r['item_id']);
            $change=$catalog>0?intdiv(((int)$r['unit_price']-$catalog)*10000,$catalog):0;$disc=(int)$r['gross_amount']>0?intdiv(((int)$r['discount_amount']+(int)$r['header_discount'])*10000,(int)$r['gross_amount']):0;
            if($change<0&&-$change>(int)$p['max_price_down_bp'])$errors['lines.'.$i]='کاهش قیمت «'.$r['item_name'].'» بیش از اختیار شما ('.Money::toFa((string)($p['max_price_down_bp']/100)).'٪) است.';
            elseif($change>0&&$change>(int)$p['max_price_up_bp'])$errors['lines.'.$i]='افزایش قیمت «'.$r['item_name'].'» بیش از اختیار شما است.';
            elseif($disc>(int)$p['max_discount_bp'])$errors['lines.'.$i]='تخفیف «'.$r['item_name'].'» بیش از اختیار شما ('.Money::toFa((string)($p['max_discount_bp']/100)).'٪) است.';
            if($change!==0||$disc>0)$out[]=['item_id'=>(int)$r['item_id'],'item_name'=>$r['item_name'],'catalog_price'=>$catalog,'unit_price'=>(int)$r['unit_price'],'change_bp'=>$change,'discount_bp'=>$disc];
        }
        if($errors)throw new ValidationException($errors);
        if($out&&(int)$p['require_reason']&&mb_strlen(Request::clean((string)$reason,300))<5)throw new ValidationException(['price_reason'=>'برای تغییر قیمت یا تخفیف، علت (حداقل پنج نویسه) لازم است.']);
        return $out;
    }
    public static function record($cid,$invoice,array $deviations,$reason){
        if(!$deviations)return;$reason=Request::clean((string)$reason,300);
        Db::exec('DELETE FROM '.Db::t('price_overrides').' WHERE company_id=%d AND invoice_id=%d AND seen_at IS NULL',$cid,$invoice);
        foreach($deviations as $d)Business::save('price_overrides',$cid,0,$d+['invoice_id'=>(int)$invoice,'reason'=>$reason,'user_id'=>Ctx::uid()]);
        Audit::log('invoice.price_override','invoice',$invoice,null,['lines'=>$deviations,'reason'=>$reason],$cid);
        \Agah\Erp\Modules\Notify\NotificationService::notify($cid,\Agah\Erp\Modules\Notify\NotificationService::holders($cid,'sale_invoice.approve'),'discount','تغییر قیمت یا تخفیف در برگهٔ فروش',$reason,agah_erp_url(['c'=>$cid,'p'=>'sales_controls']),'po|'.$invoice.'|'.md5(wp_json_encode($deviations)));
    }
    /** اعلان مدیر: تغییرهای دیده‌نشده (برای دارندگان مجوز تأیید فروش). */
    public static function unseen($cid){if(!Ctx::can('sale_invoice.approve'))return [];return Db::rows('SELECT o.*,i.number,i.kind,i.status AS invoice_status,u.display_name FROM '.Db::t('price_overrides').' o JOIN '.Db::t('invoices').' i ON i.company_id=o.company_id AND i.id=o.invoice_id LEFT JOIN '.$GLOBALS['wpdb']->users.' u ON u.ID=o.user_id WHERE o.company_id=%d AND o.seen_at IS NULL ORDER BY o.id DESC LIMIT 50',$cid);}
    public static function markSeen($cid){return Access::write($cid,'sale_invoice.approve',function()use($cid){$n=Db::exec('UPDATE '.Db::t('price_overrides').' SET seen_at=%s,seen_by=%d,updated_at=%s WHERE company_id=%d AND seen_at IS NULL',gmdate('Y-m-d H:i:s'),Ctx::uid(),gmdate('Y-m-d H:i:s'),$cid);Audit::log('sales.override.seen','company',$cid,null,['count'=>(int)$n],$cid);return (int)$n;});}

    // ------------------------------------------------------------ TRD-03

    private static function invoiceCap($cid,$invoice,$action='view'){$i=Business::row('invoices',$cid,$invoice);Access::need($cid,InvoiceService::resource($i['kind']).'.'.$action);return $i;}
    public static function refer($cid,$invoice,$to,$note){
        return Access::write($cid,'dashboard.view',function()use($cid,$invoice,$to,$note){
            $i=self::invoiceCap($cid,$invoice);$to=(int)$to;if($to===Ctx::uid())throw new ValidationException(['to_user'=>'ارجاع به خودتان معنا ندارد.']);
            $m=Db::row('SELECT m.role_id FROM '.Db::t('company_members')." m WHERE m.company_id=%d AND m.user_id=%d AND m.status='active'",$cid,$to);if(!$m)throw new ValidationException(['to_user'=>'گیرنده عضو فعال شرکت نیست.']);
            if(!Db::val('SELECT 1 FROM '.Db::t('role_caps').' WHERE role_id=%d AND cap=%s',$m['role_id'],InvoiceService::resource($i['kind']).'.view'))throw new ValidationException(['to_user'=>'گیرنده مجوز دیدن این برگه را ندارد.']);
            if($dup=Db::val('SELECT id FROM '.Db::t('referrals')." WHERE company_id=%d AND invoice_id=%d AND to_user=%d AND status IN ('open','accepted') LIMIT 1",$cid,$invoice,$to))throw new ConflictException('همین برگه به همین فرد ارجاع باز دارد (شمارهٔ '.(int)$dup.')؛ ارجاع تکراری ساخته نمی‌شود.');
            $note=Request::clean((string)$note,300);$id=Business::save('referrals',$cid,0,['invoice_id'=>(int)$invoice,'from_user'=>Ctx::uid(),'to_user'=>$to,'status'=>'open','note'=>$note]);
            Business::save('referral_events',$cid,0,['referral_id'=>$id,'action'=>'refer','user_id'=>Ctx::uid(),'note'=>$note]);Audit::log('referral.create','invoice',$invoice,null,['referral'=>$id,'to'=>$to],$cid);
            \Agah\Erp\Modules\Notify\NotificationService::notify($cid,[$to],'referral','ارجاع تازه: '.(InvoiceService::KINDS[$i['kind']]??'برگه').($i['number']?' '.$i['number']:''),$note,agah_erp_url(['c'=>$cid,'p'=>'referrals']),'ref|'.$id);return $id;
        });
    }
    /** اقدام روی ارجاع: گیرنده می‌پذیرد، انجام می‌دهد یا برمی‌گرداند؛ ارجاع‌دهنده لغو می‌کند. */
    public static function act($cid,$id,$action,$note){
        return Access::write($cid,'dashboard.view',function()use($cid,$id,$action,$note){
            $r=Business::row('referrals',$cid,$id,true);self::invoiceCap($cid,$r['invoice_id']);$uid=Ctx::uid();$note=Request::clean((string)$note,300);
            $rules=['accept'=>[['open'],'to_user','accepted'],'done'=>[['open','accepted'],'to_user','done'],'return'=>[['open','accepted'],'to_user','returned'],'cancel'=>[['open','accepted'],'from_user','cancelled']];
            if(!isset($rules[$action]))throw new ValidationException(['action'=>'اقدام معتبر نیست.']);[$from,$who,$to]=$rules[$action];
            if((int)$r[$who]!==$uid)throw new \Agah\Erp\Core\ForbiddenException('این اقدام با '.($who==='to_user'?'گیرندهٔ':'ارجاع‌دهندهٔ').' ارجاع است.');
            if(!in_array($r['status'],$from,true))throw new ConflictException('وضعیت ارجاع اجازهٔ این اقدام را نمی‌دهد.');
            if($action==='return'&&mb_strlen($note)<5)throw new ValidationException(['note'=>'برای برگرداندن، توضیح بنویسید.']);
            Business::save('referrals',$cid,$id,['status'=>$to,'last_note'=>$note]);Business::save('referral_events',$cid,0,['referral_id'=>$id,'action'=>$action,'user_id'=>$uid,'note'=>$note]);
            Audit::log('referral.'.$action,'referral',$id,['status'=>$r['status']],['status'=>$to,'note'=>$note],$cid);return true;
        });
    }
    private static function refBase(){return 'SELECT r.*,i.kind,i.number,i.status AS invoice_status,i.amount,fu.display_name AS from_name,tu.display_name AS to_name FROM '.Db::t('referrals').' r JOIN '.Db::t('invoices').' i ON i.company_id=r.company_id AND i.id=r.invoice_id LEFT JOIN '.$GLOBALS['wpdb']->users.' fu ON fu.ID=r.from_user LEFT JOIN '.$GLOBALS['wpdb']->users.' tu ON tu.ID=r.to_user';}
    public static function inbox($cid){Access::need($cid,'dashboard.view');$u=Ctx::uid();return ['to_me'=>Db::rows(self::refBase()." WHERE r.company_id=%d AND r.to_user=%d AND r.status IN ('open','accepted') ORDER BY r.id DESC LIMIT 100",$cid,$u),'from_me'=>Db::rows(self::refBase().' WHERE r.company_id=%d AND r.from_user=%d ORDER BY r.id DESC LIMIT 50',$cid,$u)];}
    public static function openCount($cid){return (int)Db::val('SELECT COUNT(*) FROM '.Db::t('referrals')." WHERE company_id=%d AND to_user=%d AND status='open'",$cid,Ctx::uid());}
    /** گزارش همهٔ ارجاع‌ها برای مدیر فروش. */
    public static function report($cid,$status=''){Access::need($cid,'sale_invoice.approve');$w=' WHERE r.company_id=%d';$a=[$cid];if($status!==''){if(!isset(self::REF_STATUS[$status]))throw new ValidationException(['status'=>'وضعیت معتبر نیست.']);$w.=' AND r.status=%s';$a[]=$status;}return Db::rows(self::refBase().$w.' ORDER BY r.id DESC LIMIT 300',...$a);}
    public static function events($cid,$id){Access::need($cid,'dashboard.view');$r=Business::row('referrals',$cid,$id);self::invoiceCap($cid,$r['invoice_id']);return Db::rows('SELECT e.*,u.display_name FROM '.Db::t('referral_events').' e LEFT JOIN '.$GLOBALS['wpdb']->users.' u ON u.ID=e.user_id WHERE e.company_id=%d AND e.referral_id=%d ORDER BY e.id',$cid,$id);}
    public static function forInvoice($cid,$invoice){self::invoiceCap($cid,$invoice);return Db::rows(self::refBase().' WHERE r.company_id=%d AND r.invoice_id=%d ORDER BY r.id DESC',$cid,$invoice);}

    // ------------------------------------------------------------ TRD-04

    public static function shares($cid,$invoice){self::invoiceCap($cid,$invoice);return Db::rows('SELECT id,expires_at,revoked_at,views,last_view_at,created_at,token_enc FROM '.Db::t('share_links').' WHERE company_id=%d AND invoice_id=%d ORDER BY id DESC',$cid,$invoice);}
    public static function shareUrl($token){if(is_string($token)&&preg_match('/^[a-f0-9]{64}$/D',$token))$token=rtrim(strtr(base64_encode(hex2bin($token)),'+/','-_'),'=');return add_query_arg(['ag'=>$token],home_url('/'));}
    public static function createShare($cid,$invoice,$hours){
        return Access::write($cid,'dashboard.view',function()use($cid,$invoice,$hours){
            $i=self::invoiceCap($cid,$invoice,'export');if(!in_array($i['kind'],self::SHAREABLE,true))throw new ValidationException(['invoice'=>'فقط فاکتور فروش، پیش‌فاکتور و سفارش فروش به اشتراک گذاشته می‌شوند.']);
            if($i['status']!=='issued')throw new ConflictException('فقط برگهٔ صادرشده به اشتراک گذاشته می‌شود.');
            $hours=(int)$hours;if($hours<1||$hours>720)throw new ValidationException(['hours'=>'اعتبار پیوند بین ۱ ساعت تا ۳۰ روز.']);
            $token=rtrim(strtr(base64_encode(random_bytes(24)),'+/','-_'),'=');$id=Business::save('share_links',$cid,0,['invoice_id'=>(int)$invoice,'token_hash'=>hash('sha256',$token),'token_enc'=>Crypto::encrypt($token),'expires_at'=>gmdate('Y-m-d H:i:s',time()+$hours*3600),'created_by'=>Ctx::uid(),'views'=>0]);
            Audit::log('invoice.share.create','invoice',$invoice,null,['share'=>$id,'hours'=>$hours],$cid);return ['id'=>$id,'token'=>$token,'url'=>self::shareUrl($token)];
        });
    }
    public static function revokeShare($cid,$id){
        return Access::write($cid,'dashboard.view',function()use($cid,$id){$s=Business::row('share_links',$cid,$id,true);self::invoiceCap($cid,$s['invoice_id'],'export');if($s['revoked_at'])throw new ConflictException('این پیوند قبلاً لغو شده است.');
            Db::update('share_links',['revoked_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')],['company_id'=>$cid,'id'=>$id]);Audit::log('invoice.share.revoke','invoice',$s['invoice_id'],null,['share'=>(int)$id],$cid);return true;});
    }
    public static function smsAllowed(){return in_array(Ctx::roleKey(),['company_admin','cashier'],true);}
    public static function smsReady(){ $p=apply_filters('agah_erp_message_sms',null);return is_object($p)&&method_exists($p,'send'); }
    /** Manual, confirmed transaction message; one request per active link/recipient. */
    public static function shareSms($cid,$id,$phone,$confirmed){
        return Access::write($cid,'dashboard.view',function()use($cid,$id,$phone,$confirmed){
            if(!self::smsAllowed())throw new \Agah\Erp\Core\ForbiddenException('درخواست پیامک فقط برای مدیر شرکت یا صندوق‌دار مجاز است.');
            $s=Business::row('share_links',$cid,$id,true);$i=self::invoiceCap($cid,$s['invoice_id'],'export');
            if(!$confirmed)throw new ValidationException(['sms_confirm'=>'هزینهٔ ارسال و شمارهٔ گیرنده را تأیید کنید.']);
            if(!in_array($i['kind'],self::SHAREABLE,true)||$i['status']!=='issued'||$s['revoked_at']||strtotime($s['expires_at'].' UTC')<=time())throw new ConflictException('پیوند فعال نیست؛ ابتدا یک پیوند اشتراک تازه بسازید.');
            $phone=preg_replace('/[\s()-]/u','',Money::normDigits((string)$phone));
            if(strpos($phone,'0098')===0)$phone='0'.substr($phone,4);elseif(strpos($phone,'+98')===0)$phone='0'.substr($phone,3);
            if(!preg_match('/^09[0-9]{9}$/D',$phone))throw new ValidationException(['sms_phone'=>'شمارهٔ همراه معتبر ایران وارد کنید.']);
            if(!self::smsReady())throw new ValidationException(['sms'=>'سرویس ارسال پیامک روی سرور تنظیم نشده است؛ پیامکی درخواست نشد.']);
            $dedupe='invoice-share-sms|'.$id.'|'.hash('sha256',$phone);
            if(Db::val('SELECT id FROM '.Db::t('message_queue').' WHERE company_id=%d AND dedupe_key=%s',$cid,$dedupe))throw new ConflictException('برای این پیوند و شماره قبلاً درخواست پیامک ثبت شده است؛ وضعیت قبلی را بررسی کنید.');
            if(!\Agah\Erp\Security\RateLimiter::consume('share-sms|'.$cid.'|'.Ctx::uid(),10,3600))throw new ConflictException('سقف ده درخواست پیامک در ساعت پر شده است.');
            $url=self::shareUrl(Crypto::decrypt($s['token_enc']));
            $body=Ctx::company()['name']."\n".(InvoiceService::KINDS[$i['kind']]??'فاکتور').' شماره '.($i['number']??'')."\n".$url;
            \Agah\Erp\Modules\Notify\NotificationService::queue($cid,'sms',$phone,'پیوند فاکتور',$body,$dedupe);
            if(!wp_next_scheduled('agah_erp_share_sms_queue'))wp_schedule_single_event(time()+10,'agah_erp_share_sms_queue');
            Audit::log('invoice.share.sms.request','invoice',(int)$i['id'],null,['share'=>(int)$id,'recipient_hint'=>'*******'.substr($phone,-4)],$cid);
            return true;
        });
    }
    /** دسترسی عمومی با پیوند: فقط همان برگه، فقط پیش از انقضا و لغو، و فقط برگهٔ صادرشدهٔ باطل‌نشده. هر دیدن ثبت می‌شود. */
    public static function resolve($token){
        if(is_string($token)&&preg_match('/^[A-Za-z0-9_-]{43}$/D',$token)){$raw=base64_decode(strtr($token,'-_','+/').'=',true);if($raw===false||strlen($raw)!==32)return null;$token=bin2hex($raw);}
        if(!is_string($token)||!preg_match('/^(?:[A-Za-z0-9_-]{32}|[a-f0-9]{64})$/D',$token))return null;
        $s=Db::row('SELECT * FROM '.Db::t('share_links').' WHERE token_hash=%s',hash('sha256',$token));
        if(!$s||$s['revoked_at']||strtotime($s['expires_at'].' UTC')<time())return null;
        $i=Db::row('SELECT * FROM '.Db::t('invoices').' WHERE company_id=%d AND id=%d',$s['company_id'],$s['invoice_id']);if(!$i||$i['status']!=='issued')return null;
        $lines=Db::rows('SELECT item_name,item_code,unit_name,qty_scaled,unit_price,discount_amount,header_discount,net_amount,tax_amount FROM '.Db::t('invoice_lines').' WHERE company_id=%d AND invoice_id=%d ORDER BY line_no',$s['company_id'],$s['invoice_id']);
        $company=Db::row('SELECT name,legal_name,display_unit,phone,address FROM '.Db::t('companies')." WHERE id=%d AND status='active'",$s['company_id']);if(!$company)return null;
        $party=(string)Db::val('SELECT name FROM '.Db::t('parties').' WHERE company_id=%d AND id=%d',$s['company_id'],$i['party_id']);
        Db::update('share_links',['views'=>(int)$s['views']+1,'last_view_at'=>gmdate('Y-m-d H:i:s')],['id'=>(int)$s['id']]);
        Audit::log('invoice.share.view','invoice',(int)$i['id'],null,['share'=>(int)$s['id']],(int)$s['company_id'],0);
        return ['invoice'=>$i,'lines'=>$lines,'company'=>$company,'party'=>$party,'expires_at'=>$s['expires_at']];
    }
}
