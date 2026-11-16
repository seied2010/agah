<?php
/** شناسهٔ رسمی ۱۳ رقمی کالا/خدمت در سامانهٔ مؤدیان، جدا از کد داخلی کالا. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Ctx,Money,Request};
use Agah\Erp\Modules\Moadian\MoadianService as MD;
if(!defined('ABSPATH'))exit;
$q=Request::str('get','q',100);$missing=Request::flag('get','missing');$data=MD::items($cid,Request::int('get','pg',1),$q,$missing);
?>
<section class="erp-card erp-moadian-items"><h2>شناسهٔ رسمی کالا/خدمت</h2>
<p class="muted">این شناسه از سامانهٔ مؤدیان دریافت می‌شود و جدا از کد داخلی ذخیره می‌شود؛ تغییر آن کد داخلی یا همگام‌سازی فروشگاه را تغییر نمی‌دهد. برگه‌ای که کالای بدون شناسه دارد در صف «دادهٔ ناقص» می‌ماند.</p>
<form method="get" class="erp-filter"><input type="hidden" name="page_id" value="<?php echo (int)get_option('agah_erp_page_id'); ?>"><input type="hidden" name="c" value="<?php echo (int)$cid; ?>"><input type="hidden" name="p" value="moadian_items"><label>جست‌وجو<input name="q" value="<?php echo esc_attr($q); ?>" maxlength="100"></label><label><input type="checkbox" name="missing" value="1" <?php checked($missing); ?>> فقط کالاهای فعالِ بدون شناسه</label><button type="submit" class="btn btn-outline">نمایش</button></form>
<div class="erp-scroll"><table class="erp-table"><thead><tr><th>کد داخلی</th><th>نام</th><th>نوع</th><th>شناسهٔ رسمی (۱۳ رقم)</th></tr></thead><tbody>
<?php foreach($data['rows'] as $it): ?><tr><td dir="ltr"><?php echo esc_html($it['code']); ?></td><td><?php echo esc_html($it['name']); ?><?php if(!(int)$it['is_active']): ?> <small>(غیرفعال)</small><?php endif; ?></td><td><?php echo $it['kind']==='goods'?'کالا':'خدمت'; ?></td><td>
<?php if(Ctx::can('moadian.edit')): ?><form method="post" class="erp-inline-form"><?php self::form('module_moadian_item_code',$cid,'moadian_items',$it['id']); ?><input name="tax_item_code" value="<?php echo esc_attr($it['tax_item_code']); ?>" maxlength="13" inputmode="numeric" pattern="[0-9۰-۹]{13}" dir="ltr" aria-label="شناسهٔ رسمی <?php echo esc_attr($it['name']); ?>"><button type="submit" class="btn btn-outline btn-sm">ثبت</button></form><?php else: ?><span dir="ltr"><?php echo esc_html($it['tax_item_code']?:'—'); ?></span><?php endif; ?>
</td></tr><?php endforeach; ?>
</tbody></table></div><?php self::pages($data['pages'],$data['page']); ?>
<p class="muted">تعداد: <?php echo esc_html(Money::toFa((string)$data['count'])); ?></p></section>
