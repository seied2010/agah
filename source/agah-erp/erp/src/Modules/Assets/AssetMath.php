<?php
namespace Agah\Erp\Modules\Assets;
use Agah\Erp\Core\{Business,Money,ValidationException};
if(!defined('ABSPATH'))exit;
final class AssetMath {
    /** Full Jalali month from service month; no daily proration. Final life month exhausts depreciable base. */
    public static function depreciation($cost,$residual,$accum,$method,$life,$bp,$month){
        Business::amount($cost,true);Business::amount($residual);Business::amount($accum);if($residual>$cost||$accum>$cost-$residual||!is_int($life)||$life<1||$life>1200||!is_int($month)||$month<1||!is_int($bp)||$bp<0||$bp>10000||!in_array($method,['straight','declining','none'],true))throw new ValidationException(['asset'=>'پارامتر استهلاک معتبر نیست.']);
        if($method==='none')return 0;$remaining=$cost-$residual-$accum;if(!$remaining)return 0;if($month>=$life)return $remaining;
        if($method==='straight')return min($remaining,max(0,Money::mulDiv($cost-$residual,$month,$life)-$accum));
        if($bp<1)throw new ValidationException(['annual_bp'=>'نرخ نزولی باید مثبت باشد.']);return min($remaining,Money::mulDiv($cost-$accum,$bp,120000));
    }
}
