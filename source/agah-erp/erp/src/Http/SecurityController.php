<?php
/** کنترلر امنیت /accounting/: هدرها، بی‌فعالیتی و فرم‌های مالک نشست؛ هیچ uid از POST پذیرفته نمی‌شود. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Db,Settings,Request,Money,Crypto,AppException,ValidationException};
use Agah\Erp\Security\{Auth,TwoFactor,SmsOtp};
if (!defined('ABSPATH')) exit;
final class SecurityController {
    public static function register() { add_action('template_redirect',[__CLASS__,'route'],0); add_filter('show_admin_bar',function($show){return self::isPage()?false:$show;}); add_filter('wp_inline_script_attributes',function($a){if(self::isPage()) $a['nonce']=self::cspNonce();return $a;}); }
    private static $nonce='';
    private static function cspNonce(){if(!self::$nonce)self::$nonce=base64_encode(random_bytes(18));return self::$nonce;}
    public static function isPage() { return is_page((int)get_option('agah_erp_page_id')); }
    /** عضویت پیش از سیاست شرکت بررسی می‌شود تا شرکت بیگانه از روی الزام عامل دوم شناسایی نشود. */
    public static function required($uid,$cid) {
        $member=$cid>0 && Db::val('SELECT COUNT(*) FROM '.Db::t('company_members')." WHERE company_id=%d AND user_id=%d AND status='active'",$cid,$uid);
        return Auth::needsFactor($uid,$member?$cid:0);
    }
    private static function flashKey() { return 'agah_security_'.get_current_user_id().'_'.substr(hash('sha256',wp_get_session_token()),0,20); }
    private static function password() { return isset($_POST['password']) && is_string($_POST['password']) ? wp_unslash($_POST['password']):''; }
    /** خروجی‌های مالی هرگز cache نمی‌شوند؛ نشست منقضی قبل از dispatch هر POST پاک می‌شود. */
    public static function route() {
        if(!self::isPage()) return;
        if(!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE',true);
        nocache_headers();
        $preview=is_customize_preview()&&current_user_can('edit_theme_options');$ancestors=$preview?"'self'":"'none'";header('X-Frame-Options: '.($preview?'SAMEORIGIN':'DENY'));header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');header('X-Robots-Tag: noindex, nofollow');
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-".self::cspNonce()."'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors $ancestors");
        if(is_user_logged_in() && !Auth::touch(get_current_user_id(),wp_get_session_token())) { wp_clear_auth_cookie();wp_set_current_user(0); }
        if(!Request::isPost()) return;
        $act=Request::str('post','act',40);
        if(strpos($act,'security_')!==0) {
            if(is_user_logged_in() && self::required(get_current_user_id(),Request::int('post','c')) && !Auth::verified(get_current_user_id(),wp_get_session_token())) {
                wp_safe_redirect(agah_erp_url(['c'=>Request::int('post','c'),'p'=>'security']));exit;
            }
            return;
        }
        if(!is_user_logged_in()) {wp_safe_redirect(agah_erp_url());exit;}
        $uid=get_current_user_id();$token=wp_get_session_token();$msg='';$codes=[];$errors=[];
        try {
            if(!wp_verify_nonce(Request::str('post','_n',100),'agah_erp_'.$act)) throw new ValidationException(['form'=>'نشست فرم منقضی شده است.']);
            $code=Money::normDigits(Request::str('post','code',50));
            switch($act) {
                case 'security_begin': TwoFactor::begin($uid,self::password(),$token);$msg='کلید را در برنامهٔ رمزساز وارد و کد آن را تأیید کنید.';break;
                case 'security_enable': $codes=TwoFactor::enable($uid,$code,$token);Auth::markVerified($uid,$token,TwoFactor::proofVersion($uid));$msg='ورود دومرحله‌ای فعال شد. کدهای پشتیبان را همین حالا در جای امن نگهداری کنید.';break;
                case 'security_verify':
                    if(!TwoFactor::verify($uid,$code)) throw new ValidationException(['code'=>'کد درست نیست یا قبلاً مصرف شده است.']);
                    Auth::markVerified($uid,$token,TwoFactor::proofVersion($uid));$msg='هویت شما تأیید شد.';break;
                case 'security_sms_send': SmsOtp::send($uid,$token);$msg='کد ارسال شد و دو دقیقه اعتبار دارد.';break;
                case 'security_sms_verify':
                    if(!SmsOtp::verify($uid,$token,$code)) throw new ValidationException(['code'=>'کد درست نیست یا منقضی شده است.']);
                    Auth::markVerified($uid,$token,SmsOtp::proofVersion());$msg='هویت شما تأیید شد.';break;
                case 'security_disable':
                    if(!Auth::verified($uid,$token)) throw new ValidationException(['code'=>'ابتدا کد دومرحله‌ای را تأیید کنید.']);
                    TwoFactor::disable($uid,self::password());$msg='ورود دومرحله‌ای غیرفعال شد؛ شرکت‌های دارای الزام، فعال‌سازی مجدد می‌خواهند.';break;
                default: throw new ValidationException(['form'=>'عمل نامعتبر است.']);
            }
        } catch(ValidationException $e) {$errors=$e->errors;}
        catch(AppException $e) {$errors=['form'=>$e->getMessage()];}
        catch(\Throwable $e) { $ref=Crypto::token(4);error_log('[agah-security] '.$ref.' '.get_class($e));$errors=['form'=>'خطای داخلی؛ شناسه پیگیری: '.$ref]; }
        set_transient(self::flashKey(),Crypto::encrypt(wp_json_encode(['message'=>$msg,'errors'=>$errors,'codes'=>$codes])),120);
        wp_safe_redirect(agah_erp_url(['p'=>'security','c'=>Request::int('post','c')]));exit;
    }
    public static function blocked() {
        return is_user_logged_in() && self::required(get_current_user_id(),Request::int('get','c')) && !Auth::verified(get_current_user_id(),wp_get_session_token());
    }
    /** دادهٔ view فقط از نشست جاری؛ کدهای پشتیبان یک‌بار نمایش و سپس transient حذف می‌شوند. */
    public static function render() {
        $uid=get_current_user_id();$enabled=TwoFactor::enabled($uid);$verified=Auth::verified($uid,wp_get_session_token());
        $secret=TwoFactor::pending($uid,wp_get_session_token());$cid=Request::int('get','c');
        $blob=get_transient(self::flashKey());delete_transient(self::flashKey());
        $notice=$blob?json_decode(Crypto::decrypt($blob),true):[];$notice=is_array($notice)?$notice:[];
        $sms=SmsOtp::available($uid);
        require AGAH_ERP_DIR.'/views/security/account.php';
    }
    /** اجزای nonce از action مجزا استفاده می‌کنند؛ همهٔ فرم‌ها به همین صفحهٔ محلی ارسال می‌شوند. */
    public static function fields($act,$cid) {
        echo '<input type="hidden" name="act" value="'.esc_attr($act).'"><input type="hidden" name="_n" value="'.esc_attr(wp_create_nonce('agah_erp_'.$act)).'"><input type="hidden" name="c" value="'.(int)$cid.'">';
    }
}
