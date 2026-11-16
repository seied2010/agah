<?php
/** تخصیص دقیق ریال و برگشت تجمعی؛ تنها Money و int استفاده می‌شوند و جمع تخصیص همیشه برابر مبلغ است. */
namespace Agah\Erp\Core;
if (!defined('ABSPATH')) exit;
final class TradeMath {
    public static function allocate($amount,array $weights) {
        Business::amount($amount); $remaining=0;
        foreach($weights as $w) $remaining=Money::add($remaining,Business::amount($w));
        if($amount && !$remaining) throw new ValidationException(['discount'=>'مبنای تخصیص باید مثبت باشد.']);
        $out=[];
        foreach($weights as $w) { $part=$remaining?min($amount,Money::mulDiv($amount,$w,$remaining)):0; $out[]=$part; $amount-=$part; $remaining-=$w; }
        return $out;
    }
    /** اختلافِ مبلغِ تجمعی، آخرین برگشت را دقیقاً تا مبلغ اصلی می‌رساند. */
    public static function portion($amount,$before,$qty,$total) {
        if($total<1 || $before<0 || $qty<1 || Money::add($before,$qty)>$total) throw new ValidationException(['qty_scaled'=>'مقدار بیش از ماندهٔ قابل برگشت است.']);
        return Money::add(Money::mulDiv($amount,$before+$qty,$total),-Money::mulDiv($amount,$before,$total));
    }
}
