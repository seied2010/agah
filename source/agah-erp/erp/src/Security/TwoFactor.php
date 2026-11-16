<?php
/** مدیریت رازهای رمزنگاری‌شده و کدهای بازیابی auth_states؛ همهٔ مصرف‌ها با قفل ردیف اتمیک‌اند. */
namespace Agah\Erp\Security;
use Agah\Erp\Core\{Db,Crypto,Audit,ValidationException,ConflictException};
if (!defined('ABSPATH')) exit;
final class TwoFactor {
    private static $proof=[];
    public static function proofVersion($uid) { return self::$proof[$uid]??null; }
    /** تضمین ردیف امنیتی برای کاربر موجود وردپرس؛ uid از نشست سرور می‌آید. */
    private static function ensure($uid) {
        if(!get_userdata($uid)) throw new ValidationException(['user'=>'کاربر معتبر نیست.']);
        Db::exec('INSERT IGNORE INTO '.Db::t('auth_states').' (user_id,updated_at) VALUES (%d,%s)',$uid,gmdate('Y-m-d H:i:s'));
    }
    public static function state($uid) {
        return Db::row('SELECT * FROM '.Db::t('auth_states').' WHERE user_id=%d',$uid) ?: ['secret_enc'=>'','version'=>0];
    }
    public static function enabled($uid) { return !empty(self::state($uid)['secret_enc']); }
    /** مرحلهٔ شروع فقط بعد از تأیید گذرواژه؛ راز معلق به همان نشست و ده دقیقه محدود است. */
    public static function begin($uid,$password,$session) {
        Auth::confirmPassword($uid,$password);
        if(!$session) throw new ConflictException('نشست معتبر نیست.');
        self::ensure($uid);
        return Db::tx(function() use($uid,$session) {
            $r=Db::row('SELECT * FROM '.Db::t('auth_states').' WHERE user_id=%d FOR UPDATE',$uid);
            if($r['secret_enc']) throw new ConflictException('ورود دومرحله‌ای از قبل فعال است.');
            $secret=Totp::secret();
            Db::update('auth_states',['pending_enc'=>Crypto::encrypt($secret),'pending_session'=>hash('sha256',$session),'pending_until'=>time()+600,'updated_at'=>gmdate('Y-m-d H:i:s')],['user_id'=>$uid]);
            return $secret;
        });
    }
    /** خواندن کلید معلق فقط در نشست مالک؛ خروجی هرگز در لاگ یا ممیزی درج نمی‌شود. */
    public static function pending($uid,$session) {
        $r=self::state($uid);
        return !empty($r['pending_enc']) && (int)$r['pending_until']>time() && hash_equals($r['pending_session'],hash('sha256',$session)) ? Crypto::decrypt($r['pending_enc']) : '';
    }
    /** تأیید کد اول، ساخت هشت کد یکبارمصرف و افزایش نسخهٔ امنیتی برای بی‌اعتبارکردن نشان نشست‌های قبلی. */
    public static function enable($uid,$code,$session) {
        self::limit($uid);
        return Db::tx(function() use($uid,$code,$session) {
            $r=Db::row('SELECT * FROM '.Db::t('auth_states').' WHERE user_id=%d FOR UPDATE',$uid);
            if(!$r || $r['secret_enc'] || (int)$r['pending_until']<=time() || !hash_equals($r['pending_session'],hash('sha256',$session))) throw new ValidationException(['code'=>'درخواست فعال‌سازی منقضی شده است.']);
            $step=Totp::matchStep(Crypto::decrypt($r['pending_enc']),$code);
            if($step===null) throw new ValidationException(['code'=>'کد درست نیست یا منقضی شده است.']);
            $plain=[];$hashes=[];
            for($i=0;$i<8;$i++) { $c=Crypto::token(6);$plain[]=$c;$hashes[]=self::backupHash($uid,$c); }
            Db::update('auth_states',['secret_enc'=>$r['pending_enc'],'pending_enc'=>'','pending_session'=>'','pending_until'=>0,'last_step'=>$step,'backup_hashes'=>wp_json_encode($hashes),'version'=>(int)$r['version']+1,'updated_at'=>gmdate('Y-m-d H:i:s')],['user_id'=>$uid]);
            self::$proof[$uid]=(int)$r['version']+1;
            Audit::log('auth.2fa.enable','user',$uid,null,['enabled'=>true],0,$uid);
            return $plain;
        });
    }
    private static function backupHash($uid,$code) { return Crypto::blind('backup|'.$uid.'|'.strtolower($code)); }
    /** سقف تلاش مستقل از اعتبار کد؛ در تراکنش شکست‌خورده rollback نمی‌شود. */
    private static function limit($uid) {
        if(!RateLimiter::consume('mfa|'.$uid,8,300)) throw new ConflictException('تلاش‌های زیاد؛ چند دقیقه بعد دوباره امتحان کنید.');
    }
    /** مصرف TOTP یا کد بازیابی؛ آخرین گام و حذف هش زیر یک قفل قرار دارند. */
    public static function verify($uid,$code) {
        self::limit($uid);
        return Db::tx(function() use($uid,$code) {
            $r=Db::row('SELECT * FROM '.Db::t('auth_states').' WHERE user_id=%d FOR UPDATE',$uid);
            if(!$r || !$r['secret_enc']) return false;
            $step=Totp::matchStep(Crypto::decrypt($r['secret_enc']),$code);
            $values=['updated_at'=>gmdate('Y-m-d H:i:s')];
            if($step!==null && $step>(int)$r['last_step']) $values['last_step']=$step;
            else {
                $hashes=json_decode($r['backup_hashes'],true) ?: []; $found=false;
                foreach($hashes as $i=>$h) if(hash_equals($h,self::backupHash($uid,$code))) {unset($hashes[$i]);$found=true;break;}
                if(!$found) return false;
                $values['backup_hashes']=wp_json_encode(array_values($hashes));
            }
            Db::update('auth_states',$values,['user_id'=>$uid]);
            self::$proof[$uid]=(int)$r['version'];
            Audit::log('auth.2fa.verify','user',$uid,null,['verified'=>true],0,$uid);
            return true;
        });
    }
    /** غیرفعال‌کردن با گذرواژه و نشست تأییدشده؛ الزام شرکتی در Auth دوباره اعمال می‌شود. */
    public static function disable($uid,$password) {
        Auth::confirmPassword($uid,$password);
        Db::tx(function() use($uid) {
            $r=Db::row('SELECT * FROM '.Db::t('auth_states').' WHERE user_id=%d FOR UPDATE',$uid);
            if(!$r) return;
            Db::update('auth_states',['secret_enc'=>'','pending_enc'=>'','pending_session'=>'','pending_until'=>0,'last_step'=>-1,'backup_hashes'=>'','version'=>(int)$r['version']+1,'updated_at'=>gmdate('Y-m-d H:i:s')],['user_id'=>$uid]);
            Audit::log('auth.2fa.disable','user',$uid,null,['enabled'=>false],0,$uid);
        });
    }
}
