<?php
/** الگوریتم RFC 6238 بدون وابستگی خارجی؛ SHA-1، شش رقم، گام ۳۰ ثانیه برای برنامه‌های رمزساز. */
namespace Agah\Erp\Security;
if (!defined('ABSPATH')) exit;
final class Totp {
    /** Base32 بدون padding برای کلید ۲۰ بایتی تصادفی تولید می‌شود. */
    public static function secret() {
        $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$out='';$bits='';
        foreach(str_split(random_bytes(20)) as $b) $bits.=str_pad(decbin(ord($b)),8,'0',STR_PAD_LEFT);
        foreach(str_split($bits,5) as $b) $out.=$alphabet[bindec($b)];
        return $out;
    }
    /** محاسبهٔ HOTP روی شمارندهٔ ۶۴بیتی؛ پارامترها برای بردارهای رسمی RFC قابل انتخاب‌اند. */
    public static function code($secret,$step,$digits=6,$algo='sha1') {
        $bits='';$alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        foreach(str_split(strtoupper($secret)) as $c) {
            $i=strpos($alphabet,$c); if($i===false) throw new \InvalidArgumentException('Invalid Base32');
            $bits.=str_pad(decbin($i),5,'0',STR_PAD_LEFT);
        }
        $key=''; foreach(str_split($bits,8) as $b) if(strlen($b)===8) $key.=chr(bindec($b));
        $counter=pack('N2',intdiv($step,4294967296),$step%4294967296);
        $h=hash_hmac($algo,$counter,$key,true);$o=ord(substr($h,-1))&15;
        $v=unpack('N',substr($h,$o,4))[1]&0x7fffffff;
        return str_pad((string)($v%(10**$digits)),$digits,'0',STR_PAD_LEFT);
    }
    /** گام پذیرفته‌شده بازگردانده می‌شود؛ مصرف و replay در TwoFactor با قفل DB کنترل می‌شود. */
    public static function matchStep($secret,$code,$time=null) {
        if (!preg_match('/^[0-9]{6}$/D',(string)$code)) return null;
        $step=intdiv($time===null?time():$time,30);
        for($i=0;$i<=1;$i++) foreach($i===0?[0]:[-1,1] as $d) {
            if(hash_equals(self::code($secret,$step+$d),$code)) return $step+$d;
        }
        return null;
    }
}
