<?php
/** شمارندهٔ اتمیک محدودیت تلاش؛ کلیدها HMAC هستند و IP/نام کاربری آشکار در rate_limits نگهداری نمی‌شود. */
namespace Agah\Erp\Security;
use Agah\Erp\Core\{Db,Crypto};
if (!defined('ABSPATH')) exit;
final class RateLimiter {
    /** یک سهمیه مصرف می‌کند؛ قفل سطر از دورزدن سقف در درخواست‌های موازی جلوگیری می‌کند. */
    public static function consume($key,$limit,$seconds) {
        $key=Crypto::blind('rate|'.$key); $now=time();
        return Db::tx(function() use($key,$limit,$seconds,$now) {
            Db::exec('INSERT INTO '.Db::t('rate_limits').' (bucket,hits,expires_at) VALUES (%s,0,%d) ON DUPLICATE KEY UPDATE bucket=VALUES(bucket)',$key,$now+max(1,$seconds));
            $r=Db::row('SELECT * FROM '.Db::t('rate_limits').' WHERE bucket=%s FOR UPDATE',$key);
            $hits=(int)$r['expires_at']<=$now ? 0 : (int)$r['hits'];
            $expiry=(int)$r['expires_at']<=$now ? $now+max(1,$seconds) : (int)$r['expires_at'];
            if ($hits>=max(1,$limit)) return false;
            Db::exec('UPDATE '.Db::t('rate_limits').' SET hits=%d,expires_at=%d WHERE bucket=%s',$hits+1,$expiry,$key);
            return true;
        });
    }
}
