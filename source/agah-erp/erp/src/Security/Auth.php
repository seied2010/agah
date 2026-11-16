<?php
/** کنترل ورود وردپرس و نشست ERP؛ نشان عامل دوم در WP_Session_Tokens و مستقل برای هر نشست ذخیره می‌شود. */
namespace Agah\Erp\Security;
use Agah\Erp\Core\{Settings,Request,ValidationException,ForbiddenException};
if (!defined('ABSPATH')) exit;
final class Auth {
    private static $blocked=false;
    const ERROR='اطلاعات ورود درست نیست یا ورود موقتاً محدود شده است.';
    /** دو فیلتر نخست و آخر جلوی دورزدن محدودیت توسط wp-login یا ایمیل به‌جای نام کاربری را می‌گیرند. */
    /** نشانهٔ کاربر سرویس فروشگاه؛ این کاربر هرگز با گذرواژه یا گذرواژهٔ برنامه وارد نمی‌شود. */
    const SERVICE_META='agah_connect_service';
    /** شناسهٔ کاربر سرویسی که درخواستش با امضای HMAC تأیید شده است؛ فقط در طول همان درخواست REST. */
    public static $machine=0;
    public static function register() {
        add_filter('authenticate',function($user){return $user instanceof \WP_User&&get_user_meta($user->ID,self::SERVICE_META,true)?new \WP_Error('agah_service','ورود این حساب مجاز نیست.'):$user;},1000);
        add_filter('wp_is_application_passwords_available_for_user',function($ok,$user){return $user instanceof \WP_User&&get_user_meta($user->ID,self::SERVICE_META,true)?false:$ok;},10,2);
        add_filter('authenticate',[__CLASS__,'before'],1,3);
        add_filter('login_form_middle',function($html){return $html.wp_nonce_field('agah_login','_agah_login_nonce',false,false);});
        add_action('login_form',function(){wp_nonce_field('agah_login','_agah_login_nonce',false);});
        add_filter('authenticate',[__CLASS__,'after'],999,3);
        add_filter('login_errors',function(){return self::ERROR;});
        add_action('set_logged_in_cookie',[__CLASS__,'created'],10,6);
        add_action('user_profile_update_errors',[__CLASS__,'profilePassword'],10,3);
        add_action('validate_password_reset',[__CLASS__,'resetPassword'],10,2);
        add_action('registration_errors',[__CLASS__,'registrationPassword'],10,3);
    }
    /** هر تلاش سهمیه می‌گیرد؛ IP کلی و IP+شناسه هر دو محدودند. محدودیت اتمیک و شامل ورود موفق است. */
    public static function before($user,$username,$password) {
        self::$blocked=false;
        if(Request::isPost() && isset($_POST['log']) && !wp_verify_nonce(Request::str('post','_agah_login_nonce',100),'agah_login')) {self::$blocked=true;return new \WP_Error('agah_login',self::ERROR);}
        if($username==='' && $password==='' && !isset($_POST['log'])) return $user;
        $name=strtolower(trim((string)$username)); self::$blocked=false;
        $u=is_email($name)?get_user_by('email',$name):get_user_by('login',$name);
        $identity=$u?'uid:'.$u->ID:'name:'.$name;
        $limit=max(1,min(100,Settings::int('login_max_attempts')));
        $seconds=max(1,min(1440,Settings::int('login_lock_min')))*60;
        $ip=Request::ip();
        $a=RateLimiter::consume('login-ip|'.$ip,$limit*4,$seconds);
        $b=RateLimiter::consume('login-pair|'.$ip.'|'.$identity,$limit,$seconds);
        self::$blocked=!($a && $b);
        return $a && $b ? $user : new \WP_Error('agah_login',self::ERROR);
    }
    /** خطاهای نام کاربری/گذرواژه/قفل یکسان‌اند؛ خطای محدودیت نباید توسط فیلترهای بعدی موفق اعلام شود. */
    public static function after($user,$username,$password) {
        if(self::$blocked || is_wp_error($user) || !$user) return new \WP_Error('agah_login',self::ERROR);
        return $user;
    }
    /** زمان فعالیت از لحظهٔ صدور نشست ثبت می‌شود تا نخستین درخواست پس از انقضا هم رد شود. */
    public static function created($cookie,$expire,$expiration,$uid,$scheme,$token) {
        $mgr=\WP_Session_Tokens::get_instance($uid);$s=$mgr->get($token);
        if($s) { $s['agah_last']=time();$s['agah_mfa_version']=-1;$mgr->update($token,$s); }
    }
    /** بررسی و تمدید زمان بی‌فعالیتی؛ فقط نشست منقضی‌شده حذف می‌شود. */
    public static function touch($uid,$token) {
        if(!$uid || !$token) return false;
        $mgr=\WP_Session_Tokens::get_instance($uid);$s=$mgr->get($token);
        if(!$s) return false;
        $last=isset($s['agah_last'])?(int)$s['agah_last']:(int)$s['login'];
        $idle=max(1,min(1440,Settings::int('idle_timeout_min')))*60;
        if(time()-$last>$idle) { $mgr->destroy($token);return false; }
        $s['agah_last']=time();$mgr->update($token,$s);return true;
    }
    /** نشان ورود دومرحله‌ای به نسخهٔ راز وابسته است؛ فعال/غیرفعال‌سازی نشست‌های قبلی را معتبر نمی‌کند. */
    public static function verified($uid,$token) {
        if(!$token) return false;
        $s=\WP_Session_Tokens::get_instance($uid)->get($token);
        $r=TwoFactor::state($uid);
        return $s && isset($s['agah_mfa_version']) && (int)$s['agah_mfa_version']===(int)$r['version'] && !empty($s['agah_mfa_at']);
    }
    public static function markVerified($uid,$token,$expectedVersion=null) {
        $mgr=\WP_Session_Tokens::get_instance($uid);$s=$mgr->get($token);
        if(!$s) throw new ForbiddenException('نشست معتبر نیست.');
        $current=(int)TwoFactor::state($uid)['version'];
        if($expectedVersion!==null && (int)$expectedVersion!==$current) throw new ForbiddenException('تنظیمات امنیت تغییر کرده است؛ دوباره تأیید کنید.');
        $s['agah_mfa_version']=$current;$s['agah_mfa_at']=time();$s['agah_last']=time();
        $mgr->update($token,$s);
    }
    public static function needsFactor($uid,$cid=0) {
        return TwoFactor::enabled($uid) || ($cid>0 && Settings::flag('require_2fa',$cid));
    }
    /** کنترل سمت سرور بعد از عضویت؛ شرکت بیگانه همچنان ۴۰۴ می‌گیرد و سیاست آن افشا نمی‌شود. */
    public static function assertCompany($uid,$cid) {
        if(PHP_SAPI==='cli') return; // مسیر سرویس‌های CLI داخلی؛ مرورگر همیشه نشست لازم دارد.
        if(self::$machine&&self::$machine===(int)$uid&&get_current_user_id()===(int)$uid&&get_user_meta($uid,self::SERVICE_META,true)) return; // درخواست امضاشدهٔ فروشگاه
        if(get_current_user_id()!== (int)$uid || !self::touch($uid,wp_get_session_token())) throw new ForbiddenException('نشست پایان یافته است؛ دوباره وارد شوید.');
        if(self::needsFactor($uid,$cid) && !self::verified($uid,wp_get_session_token())) throw new ForbiddenException('تأیید دومرحله‌ای لازم است.');
    }
    /** گذرواژه‌ها از Request::str عبور نمی‌کنند تا فاصله و نویسه‌های معتبر آن‌ها تغییر نکند. */
    public static function passwordError($password) {
        return mb_strlen($password)<10 || !preg_match('/\p{L}/u',$password) || !preg_match('/\p{N}/u',$password) ? 'گذرواژه حداقل ۱۰ نویسه و شامل حرف و عدد باشد.' : '';
    }
    public static function confirmPassword($uid,$password) {
        if(!RateLimiter::consume('confirm-password|'.$uid,5,900)) throw new ForbiddenException('تلاش‌های زیاد؛ بعداً دوباره امتحان کنید.');
        $u=get_userdata($uid);
        if(!$u || !wp_check_password($password,$u->user_pass,$uid)) throw new ValidationException(['password'=>'گذرواژه درست نیست.']);
    }
    public static function profilePassword($errors,$update,$user) {
        if(!empty($user->user_pass) && ($e=self::passwordError($user->user_pass))) $errors->add('agah_password',$e);
    }
    public static function resetPassword($errors,$user) {
        $p=isset($_POST['pass1']) && is_string($_POST['pass1'])?wp_unslash($_POST['pass1']):'';
        if($p!=='' && ($e=self::passwordError($p))) $errors->add('agah_password',$e);
    }
    public static function registrationPassword($errors,$login,$email) {
        $p=isset($_POST['user_pass']) && is_string($_POST['user_pass'])?wp_unslash($_POST['user_pass']):'';
        if($p!=='' && ($e=self::passwordError($p))) $errors->add('agah_password',$e);
        return $errors;
    }
}
