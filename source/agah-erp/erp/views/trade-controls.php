<?php
/** سقف تخفیف و دامنهٔ تغییر قیمت هر نقش (TRD-02) و فهرست تغییرهای دیده‌نشده برای مدیر. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Ctx,Money};
use Agah\Erp\Modules\Trade\{InvoiceService,SalesControlService as S};
if(!defined('ABSPATH'))exit;
$pct=fn($bp)=>$bp===null?'':rtrim(rtrim(number_format((int)$bp/100,2,'.',''),'0'),'.');
?>
<section class="erp-card"><h2>سقف تخفیف و تغییر قیمت</h2><p class="muted">برای هر نقش، حداکثر درصد تخفیف (ردیف + سهم تخفیف کل) و دامنهٔ کاهش/افزایش قیمت نسبت به قیمت فروش کالا را تعیین کنید. نقشی که سیاست ندارد محدودیتی ندارد. تغییر داخل سقف با علت ثبت و به مدیر اعلان می‌شود؛ فراتر از سقف پذیرفته نمی‌شود. فاکتور صادرشده فقط با برگشت/اصلاحیه تغییر می‌کند.</p>
<table class="erp-table"><thead><tr><th>نقش</th><th>حداکثر تخفیف ٪</th><th>حداکثر کاهش قیمت ٪</th><th>حداکثر افزایش قیمت ٪</th><th>علت الزامی</th><th></th></tr></thead><tbody>
<?php foreach(S::policies($cid) as $p): ?><tr><td><?php echo esc_html($p['name']); ?></td><td colspan="5"><form method="post" class="erp-inline-form"><?php self::form('module_price_policy',$cid,'sales_controls',$p['role_id']); ?>
<input name="max_discount" inputmode="decimal" maxlength="6" size="5" aria-label="حداکثر تخفیف" value="<?php echo esc_attr($pct($p['max_discount_bp'])); ?>"> <input name="max_price_down" inputmode="decimal" maxlength="6" size="5" aria-label="حداکثر کاهش قیمت" value="<?php echo esc_attr($pct($p['max_price_down_bp'])); ?>"> <input name="max_price_up" inputmode="decimal" maxlength="7" size="5" aria-label="حداکثر افزایش قیمت" value="<?php echo esc_attr($pct($p['max_price_up_bp'])); ?>">
<label><input type="checkbox" name="require_reason" value="1" <?php checked($p['id']===null||(int)$p['require_reason']===1); ?>> علت الزامی</label>
<button type="submit" class="btn btn-outline btn-sm"><?php echo $p['id']?'ذخیره':'تعیین سیاست'; ?></button><?php if($p['id']): ?> <button type="submit" name="remove" value="1" class="btn btn-outline btn-sm" data-confirm="سیاست این نقش برداشته شود (بدون محدودیت)؟">برداشتن</button><?php endif; ?></form></td></tr><?php endforeach; ?>
</tbody></table></section>
<?php $un=S::unseen($cid); if($un): ?><section class="erp-card"><h3>تغییرهای قیمت و تخفیف دیده‌نشده</h3><table class="erp-table"><thead><tr><th>برگه</th><th>کالا</th><th>قیمت کالا ← قیمت برگه</th><th>تخفیف ٪</th><th>علت</th><th>کاربر</th></tr></thead><tbody><?php foreach($un as $o): ?><tr><td><a href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'invoice_view','id'=>$o['invoice_id']])); ?>"><?php echo esc_html((InvoiceService::KINDS[$o['kind']]??'').' '.($o['number']?Money::toFa((string)$o['number']):'(پیش‌نویس)')); ?></a></td><td><?php echo esc_html($o['item_name']); ?></td><td><?php echo esc_html(Money::fmt((int)$o['catalog_price'],Ctx::unit()).' ← '.Money::fmt((int)$o['unit_price'],Ctx::unit())); ?></td><td><?php echo esc_html(Money::toFa($pct($o['discount_bp']))); ?></td><td><?php echo esc_html($o['reason']); ?></td><td><?php echo esc_html((string)$o['display_name']); ?></td></tr><?php endforeach; ?></tbody></table>
<form method="post" class="erp-inline-form"><?php self::form('module_price_seen',$cid,'sales_controls'); ?><button type="submit" class="btn btn-outline">همه را دیدم</button></form></section><?php endif; ?>
