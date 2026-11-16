<?php
/** OTP پیامکی به‌عنوان عامل دوم؛ پیش‌فرض خاموش، کد فقط HMAC و به uid و نشست وابسته است. */
namespace Agah\Erp\Security;
use Agah\Erp\Core\{Db,Crypto,ConflictException};
if (!defined('ABSPATH')) exit;
final class SmsOtp {
    private static $proof=null;
    public static function proofVersion(){return self::$proof;}
    public static function provider() {
        $p=apply_filters('agah_erp_sms_provider',null);
        return $p instanceof SmsProvider ? $p : null;
    }
    /** شماره باید توسط مسیر قابل‌اعتماد خارج از این فرم تأیید شده باشد؛ ورودی POST شماره پذیرفته نمی‌شود. */
    public static function available($uid) {
        return self::provider() && preg_match('/^\+?[0-9]{10,15}$/D',(string)get_user_meta($uid,'agah_verified_phone',true));
    }
    public static function send($uid,$session) {
        if(!$session || !self::available($uid)) throw new ConflictException('ورود پیامکی تنظیم نشده است.');
        if(!RateLimiter::consume('sms-hour|'.$uid,5,3600) || !RateLimiter::consume('sms-ip|'.\Agah\Erp\Core\Request::ip(),20,3600)) throw new ConflictException('سقف ارسال پیامک پر شده است.');
        $code=(string)random_int(100000,999999);$sh=hash('sha256',$session);
        // ابتدا کد قبلی بی‌اعتبار می‌شود؛ شکست ارائه‌دهنده هیچ کد قابل‌مصرف باقی نمی‌گذارد.
        Db::exec('INSERT INTO '.Db::t('login_otp').' (user_id,session_hash,code_hash,expires_at,attempts,consumed,created_at) VALUES (%d,%s,%s,%d,0,1,%s) ON DUPLICATE KEY UPDATE code_hash=VALUES(code_hash),expires_at=VALUES(expires_at),attempts=0,consumed=1,created_at=VALUES(created_at)', $uid,$sh,Crypto::blind('sms|'.$uid.'|'.$sh.'|'.$code),time()+120,gmdate('Y-m-d H:i:s'));
        try { self::provider()->send((string)get_user_meta($uid,'agah_verified_phone',true),$code); }
        catch(\Throwable $e) { throw new ConflictException('ارسال پیامک انجام نشد؛ بعداً دوباره تلاش کنید.'); }
        Db::exec('UPDATE '.Db::t('login_otp').' SET consumed=0 WHERE user_id=%d AND session_hash=%s AND code_hash=%s',$uid,$sh,Crypto::blind('sms|'.$uid.'|'.$sh.'|'.$code));
    }
    /** افزایش تلاش حتی برای کد غلط commit می‌شود؛ انقضا، مصرف و سقف پنج بار در قفل DB بررسی می‌شوند. */
    public static function verify($uid,$session,$code) {
        if(!self::available($uid) || !$session || !RateLimiter::consume('sms-check|'.$uid,10,300)) return false;
        return Db::tx(function()use($uid,$session,$code){
            $sh=hash('sha256',$session);
            $r=Db::row('SELECT * FROM '.Db::t('login_otp').' WHERE user_id=%d AND session_hash=%s FOR UPDATE',$uid,$sh);
            if(!$r || $r['consumed'] || (int)$r['attempts']>=5 || (int)$r['expires_at']<=time()) return false;
            $valid=preg_match('/^[0-9]{6}$/D',(string)$code) && hash_equals($r['code_hash'],Crypto::blind('sms|'.$uid.'|'.$sh.'|'.$code));
            Db::exec('UPDATE '.Db::t('login_otp').' SET attempts=attempts+1,consumed=%d WHERE user_id=%d AND session_hash=%s',$valid?1:0,$uid,$sh);
            if($valid) self::$proof=(int)TwoFactor::state($uid)['version'];
            return (bool)$valid;
        });
    }
}
