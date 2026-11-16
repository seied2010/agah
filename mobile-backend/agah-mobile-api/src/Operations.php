<?php
namespace Agah\Mobile;
use Agah\Erp\Core\{Db,Ctx,Access,ConflictException,ValidationException};
if (!defined('ABSPATH')) exit;
final class Operations {
    public static function canonical($value) {
        if (is_float($value)) throw new ValidationException(['amount'=>'اعداد اعشاری باید به صورت رشته ارسال شوند.']);
        if (is_array($value)) {
            if (array_keys($value)!==range(0,count($value)-1)) ksort($value);
            foreach ($value as &$entry) $entry=self::canonical($entry);
        }
        return $value;
    }
    public static function fingerprint($value) { return hash('sha256',wp_json_encode(self::canonical($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); }
    public static function key($key) {
        if (!is_string($key)||!preg_match('/^[A-Za-z0-9-]{20,100}$/D',$key)) throw new ValidationException(['operation'=>'شناسهٔ یکتای عملیات لازم است.']);
        return hash('sha256',$key);
    }
    public static function execute($cid,$cap,$key,$action,array $payload,callable $fn) {
        $hash=self::key($key); $fingerprint=self::fingerprint([$action,$payload]); $uid=get_current_user_id();
        return Access::write($cid,$cap,function () use ($cid,$uid,$hash,$fingerprint,$action,$fn) {
            Session::recheck(); Policy::need($cid,true);
            $old=Db::row('SELECT * FROM '.Schema::table('operations').' WHERE company_id=%d AND user_id=%d AND operation_hash=%s FOR UPDATE',$cid,$uid,$hash);
            if ($old) {
                if ($old['action']==='restored') throw new ConflictException('داده‌ها از پشتیبان بازیابی شده‌اند؛ این درخواست قدیمی دوباره اجرا نمی‌شود.');
                if ($old['action']==='cancel') throw new ConflictException('این درخواست لغو شده است؛ برای ثبت تازه شناسهٔ تازه بسازید.');
                if (!hash_equals($old['fingerprint'],$fingerprint)) throw new ConflictException('این شناسه برای درخواست دیگری استفاده شده است.');
                return json_decode($old['result_json'],true);
            }
            $result=$fn();
            Db::exec('INSERT INTO '.Schema::table('operations').' (company_id,user_id,operation_hash,fingerprint,action,result_json,created_at) VALUES (%d,%d,%s,%s,%s,%s,%d)',$cid,$uid,$hash,$fingerprint,$action,wp_json_encode($result),time());
            return $result;
        });
    }
    public static function status($cid,$key) {
        Policy::need($cid);
        $row=Db::row('SELECT result_json,action,created_at FROM '.Schema::table('operations').' WHERE company_id=%d AND user_id=%d AND operation_hash=%s',$cid,get_current_user_id(),self::key($key));
        return $row?['state'=>$row['action']==='restored'?'restored':($row['action']==='cancel'?'cancelled':'committed'),'result'=>json_decode($row['result_json'],true),'action'=>$row['action'],'created_at'=>$row['created_at']]:['state'=>'not_found','message'=>'ثبت قطعی با این شناسه پیدا نشد؛ درخواستِ در حال اجرا ممکن است هنوز تمام نشده باشد.'];
    }
    /** A cancellation tombstone under the financial company lock defeats even delayed HTTP requests. */
    public static function cancel($cid,$key) {
        $hash=self::key($key); $uid=get_current_user_id();
        return Db::tx(function () use ($cid,$hash,$uid) {
            if (!Db::row('SELECT id FROM '.Db::t('companies').' WHERE id=%d FOR UPDATE',$cid)) throw new \Agah\Erp\Core\NotFoundException();
            Ctx::boot($uid,$cid,true); Session::recheck(); Policy::need($cid);
            $old=Db::row('SELECT * FROM '.Schema::table('operations').' WHERE company_id=%d AND user_id=%d AND operation_hash=%s FOR UPDATE',$cid,$uid,$hash);
            if ($old) return ['state'=>$old['action']==='restored'?'restored':($old['action']==='cancel'?'cancelled':'committed'),'result'=>json_decode($old['result_json'],true)];
            Db::exec('INSERT INTO '.Schema::table('operations').' (company_id,user_id,operation_hash,fingerprint,action,result_json,created_at) VALUES (%d,%d,%s,%s,%s,%s,%d)',$cid,$uid,$hash,str_repeat('0',64),'cancel','{"state":"cancelled"}',time());
            return ['state'=>'cancelled'];
        });
    }
    public static function etag($data) { return '"'.hash_hmac('sha256',wp_json_encode(self::canonical($data)),wp_salt('auth')).'"'; }
    public static function match($header,$current) {
        if (!is_string($header)||!hash_equals(self::etag($current),$header)) throw new ConflictException('اطلاعات تغییر کرده یا نسخهٔ سند ارسال نشده است؛ دوباره دریافت کنید.');
    }
}
