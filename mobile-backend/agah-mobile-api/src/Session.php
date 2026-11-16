<?php
namespace Agah\Mobile;
use Agah\Erp\Core\{Db,AppException,ForbiddenException,Request,Audit};
use Agah\Erp\Security\{Auth,TwoFactor,RateLimiter};
if (!defined('ABSPATH')) exit;

/** Access tokens are real WP sessions; REST installs an in-process cookie only after independent validation. */
final class Session {
    private static $current=null;
    private static function hash($raw) { return hash('sha256',$raw); }
    private static function passwordVersion($user) { return hash_hmac('sha256',$user->user_pass,wp_salt('auth')); }
    private static function locked($uid,callable $fn) {
        global $wpdb;
        try {
            return Db::tx(function () use ($uid,$fn,$wpdb) {
                if (!Db::row('SELECT ID FROM '.$wpdb->users.' WHERE ID=%d FOR UPDATE',$uid)) throw new AppException('نشست معتبر نیست.',401);
                clean_user_cache($uid); wp_cache_delete($uid,'user_meta');
                return $fn();
            });
        } finally { clean_user_cache($uid); wp_cache_delete($uid,'user_meta'); }
    }
    public static function login(array $in) {
        $name=$in['username']??''; $password=$in['password']??'';
        if (!is_string($name)||!is_string($password)||strlen($name)>190||strlen($password)>4096) throw new AppException(Auth::ERROR,401);
        $user=wp_authenticate($name,$password);
        if (is_wp_error($user)||!$user||get_user_meta($user->ID,Auth::SERVICE_META,true)) throw new AppException(Auth::ERROR,401);
        $verified=false; $version=(int)TwoFactor::state($user->ID)['version'];
        if (TwoFactor::enabled($user->ID)) {
            $code=$in['factor']??'';
            if (!is_string($code)||!TwoFactor::verify($user->ID,$code)) throw new AppException('کد تأیید دومرحله‌ای معتبر لازم است.',401);
            $version=TwoFactor::proofVersion($user->ID); $verified=true;
        }
        $device=$in['device_id']??'';
        if (!is_string($device)||!preg_match('/^[a-zA-Z0-9-]{16,80}$/D',$device)) throw new AppException('شناسهٔ نصب معتبر نیست.',422);
        $device=self::hash($device);
        $label=sanitize_text_field(is_string($in['device_name']??null)?$in['device_name']:'Android');
        return self::locked($user->ID,function () use ($user,$device,$label,$version,$verified) {
            if ((int)TwoFactor::state($user->ID)['version']!==(int)$version) throw new AppException('تنظیمات امنیت تغییر کرد؛ دوباره وارد شوید.',401);
            Db::exec('UPDATE '.Schema::table('sessions').' SET revoked=1 WHERE user_id=%d AND device_id=%s',$user->ID,$device);
            return self::issue(get_userdata($user->ID),$device,mb_substr($label,0,100),$version,$verified);
        });
    }
    private static function issue($user,$device,$label,$version,$verified) {
        global $wpdb;
        $now=time(); $expiry=$now+900;
        $mgr=\WP_Session_Tokens::get_instance($user->ID);
        $access=$mgr->create($expiry);
        if ($verified) Auth::markVerified($user->ID,$access,$version);
        $refresh=bin2hex(random_bytes(32));
        $ok=$wpdb->insert(Schema::table('sessions'),[
            'user_id'=>$user->ID,'device_id'=>$device,'device_name'=>$label,
            'access_hash'=>self::hash($access),'refresh_hash'=>self::hash($refresh),
            'password_version'=>self::passwordVersion($user),'mfa_version'=>$version,'mfa_verified'=>$verified?1:0,
            'expires_at'=>$now+604800,'created_at'=>$now,'last_seen'=>$now,'revoked'=>0
        ]);
        if (!$ok) throw new \RuntimeException('Session insert failed');
        Audit::log('mobile.session.created','mobile_session',(int)$wpdb->insert_id,null,null,0,$user->ID);
        return ['access_token'=>$user->ID.'.'.$access,'refresh_token'=>$user->ID.'.'.$refresh,'expires_in'=>900,'user'=>['id'=>(string)$user->ID,'name'=>$user->display_name]];
    }
    private static function parts($raw) {
        if (!is_string($raw)||!preg_match('/^([1-9][0-9]{0,18})\.([a-zA-Z0-9]{40,128})$/D',$raw,$m)) throw new AppException('نشست معتبر نیست.',401);
        return [(int)$m[1],$m[2]];
    }
    private static function valid($row,$user) {
        return $row && $user && !$row['revoked'] && (int)$row['expires_at']>time()
            && time()-(int)$row['last_seen']<=max(1,min(1440,\Agah\Erp\Core\Settings::int('idle_timeout_min')))*60
            && hash_equals($row['password_version'],self::passwordVersion($user))
            && (int)$row['mfa_version']===(int)TwoFactor::state($user->ID)['version']
            && !get_user_meta($user->ID,Auth::SERVICE_META,true);
    }
    public static function authenticate($request) {
        $header=$request->get_header('authorization');
        if (!is_string($header)||!preg_match('/^Bearer (\S+)$/D',$header,$m)) throw new AppException('ورود لازم است.',401);
        [$uid,$token]=self::parts($m[1]);
        if (!RateLimiter::consume('mobile-api-ip|'.Request::ip(),480,60)) throw new AppException('تعداد درخواست زیاد است؛ کمی صبر کنید.',429);
        if (!RateLimiter::consume('mobile-api|'.$uid,240,60)) throw new AppException('تعداد درخواست زیاد است؛ کمی صبر کنید.',429);
        $row=Db::row('SELECT * FROM '.Schema::table('sessions').' WHERE user_id=%d AND access_hash=%s',$uid,self::hash($token));
        $user=get_userdata($uid);
        if (!self::valid($row,$user)||!\WP_Session_Tokens::get_instance($uid)->verify($token)||!Auth::touch($uid,$token)) throw new AppException('نشست پایان یافته است؛ دوباره وارد شوید.',401);
        // No Set-Cookie header is emitted. Existing Auth::assertCompany still enforces idle expiry and MFA.
        wp_set_current_user($uid);
        $_COOKIE[LOGGED_IN_COOKIE]=wp_generate_auth_cookie($uid,time()+900,'logged_in',$token);
        self::$current=$row;
        Db::exec('UPDATE '.Schema::table('sessions').' SET last_seen=%d WHERE id=%d AND revoked=0',time(),$row['id']);
        return true;
    }
    public static function recheck() {
        if (!self::$current) throw new AppException('ورود لازم است.',401);
        $row=Db::row('SELECT * FROM '.Schema::table('sessions').' WHERE id=%d LOCK IN SHARE MODE',self::$current['id']);
        if (!$row||!self::valid($row,get_userdata((int)$row['user_id']))) throw new AppException('نشست لغو شده است.',401);
    }
    public static function refresh(array $in) {
        [$uid,$token]=self::parts($in['refresh_token']??'');
        if (!RateLimiter::consume('mobile-refresh|'.Request::ip(),60,60)) throw new AppException('تلاش‌های زیاد؛ بعداً امتحان کنید.',429);
        return self::locked($uid,function () use ($uid,$token) {
            $r=Db::row('SELECT * FROM '.Schema::table('sessions').' WHERE user_id=%d AND refresh_hash=%s FOR UPDATE',$uid,self::hash($token));
            $user=get_userdata($uid);
            if (!self::valid($r,$user)) throw new AppException('نشست پایان یافته است؛ دوباره وارد شوید.',401);
            Db::exec('UPDATE '.Schema::table('sessions').' SET revoked=1 WHERE id=%d',$r['id']);
            return self::issue($user,$r['device_id'],$r['device_name'],(int)$r['mfa_version'],(bool)$r['mfa_verified']);
        });
    }
    public static function devices() {
        $rows=Db::rows('SELECT id,device_name,created_at,last_seen FROM '.Schema::table('sessions').' WHERE user_id=%d AND revoked=0 AND expires_at>%d ORDER BY last_seen DESC LIMIT 100',get_current_user_id(),time());
        foreach ($rows as &$row) $row['current']=(int)$row['id']===(int)self::$current['id'];
        return $rows;
    }
    public static function revoke($id=null) {
        $uid=get_current_user_id(); $id=$id===null?(int)self::$current['id']:(int)$id;
        return self::locked($uid,function () use ($uid,$id) {
            $row=Db::row('SELECT id FROM '.Schema::table('sessions').' WHERE user_id=%d AND id=%d',$uid,$id);
            if (!$row) throw new \Agah\Erp\Core\NotFoundException();
            Db::exec('UPDATE '.Schema::table('sessions').' SET revoked=1 WHERE user_id=%d AND id=%d',$uid,$id);
            Audit::log('mobile.session.revoked','mobile_session',$id,null,null,0,$uid);
            return ['revoked'=>true];
        });
    }
}
