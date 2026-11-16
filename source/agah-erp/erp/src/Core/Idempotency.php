<?php
/** توکن فرم و ثبت اتمیک نتیجه در idempotency؛ ادعا، تغییر دامنه و complete باید در یک Db::tx باشند. */
namespace Agah\Erp\Core;
if (!defined('ABSPATH')) exit;
final class Idempotency {
    /** امضای وابسته به کاربر، شرکت و عمل؛ توکن گم‌شده یا ساختگی پذیرفته نمی‌شود. */
    public static function issue($uid, $cid, $action) {
        $body = Crypto::token(24) . '.' . time();
        return $body . '.' . Crypto::blind('form|' . $uid . '|' . $cid . '|' . $action . '|' . $body);
    }
    /** null یعنی درخواست جدید؛ رشته یعنی نتیجهٔ درخواست قبلی. قفل یکتا درخواست هم‌زمان را منتظر commit نگه می‌دارد. */
    public static function claim($token, $uid, $cid, $action, $fingerprint) {
        if (!Db::inTx()) throw new \LogicException('Idempotency requires transaction');
        $parts = explode('.', (string)$token);
        if (count($parts)!==3 || !preg_match('/^[a-f0-9]{48}$/D',$parts[0]) || !ctype_digit($parts[1]) ||
            (int)$parts[1] > time()+60 || (int)$parts[1] < time()-86400 ||
            !hash_equals(Crypto::blind('form|'.$uid.'|'.$cid.'|'.$action.'|'.$parts[0].'.'.$parts[1]),$parts[2])) {
            throw new ValidationException(['form'=>'اعتبار فرم پایان یافته؛ صفحه را تازه کنید.']);
        }
        $key=hash('sha256',$token);
        $r=Db::exec('INSERT IGNORE INTO '.Db::t('idempotency').' (token,company_id,user_id,action,request_hash,expires_at,created_at) VALUES (%s,%d,%d,%s,%s,%d,%s)',
            $key,$cid,$uid,$action,$fingerprint,(int)$parts[1]+86400,gmdate('Y-m-d H:i:s'));
        if ($r===1) return null;
        $old=Db::row('SELECT * FROM '.Db::t('idempotency').' WHERE token=%s LOCK IN SHARE MODE',$key);
        if (!$old || (int)$old['user_id']!== (int)$uid || (int)$old['company_id']!==(int)$cid || $old['action']!==$action || !hash_equals($old['request_hash'],$fingerprint)) {
            throw new ConflictException('این فرم قبلاً با اطلاعات دیگری ارسال شده است؛ صفحه را تازه کنید.');
        }
        if ($old['state']!=='done') throw new ConflictException('درخواست در حال پردازش است.');
        return $old['result_url'];
    }
    /** نتیجه فقط پس از موفقیت دامنه، در همان تراکنش و با نشانی محلی ثبت می‌شود. */
    public static function complete($token,$url) {
        if (!Db::inTx()) throw new \LogicException('Idempotency requires transaction');
        $safe=wp_validate_redirect($url,'');
        if (!$safe) throw new \InvalidArgumentException('Invalid result URL');
        Db::exec('UPDATE '.Db::t('idempotency')." SET state='done',result_url=%s WHERE token=%s AND state='pending'",$safe,hash('sha256',$token));
    }
    /** خطای اعتبارسنجی در تراکنش موجب rollback ادعا هم می‌شود؛ این متد برای استفادهٔ صریح سرویس‌هاست. */
    public static function release($token) {
        if (!Db::inTx()) throw new \LogicException('Idempotency requires transaction');
        Db::exec('DELETE FROM '.Db::t('idempotency')." WHERE token=%s AND state='pending'",hash('sha256',$token));
    }
    /** پاک‌سازی روزانه؛ توکن منقضی حتی پس از حذف رکورد قابل استفاده نیست. */
    public static function cleanup() {
        Db::exec('DELETE FROM '.Db::t('idempotency').' WHERE expires_at < %d',time());
        Db::exec('DELETE FROM '.Db::t('rate_limits').' WHERE expires_at < %d',time());
        Db::exec('DELETE FROM '.Db::t('login_otp').' WHERE expires_at < %d',time());
    }
}
