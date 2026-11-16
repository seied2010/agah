<?php
/** نمای فارسی مراحل ۷–۹؛ زمینه از کنترلر و مقادیر مالی از سرویس دریافت می‌شوند؛ خروجی escape می‌شود. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Db,Ctx,Request,Money,Jalali,Settings,Business};
use Agah\Erp\Modules\Trade\InvoiceService;
use Agah\Erp\Modules\Inventory\StockService;
if(!defined('ABSPATH')) exit;
$rows=StockService::warehouses($cid);$edit=Request::int('get','edit');$r=$edit?Business::row('warehouses',$cid,$edit):[];
?>
<section class="erp-card"><h2>انبارها</h2><div class="erp-scroll"><table class="erp-table"><thead><tr><th>کد</th><th>نام</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody><?php foreach($rows as $w): ?><tr><td><?php echo esc_html($w['code']); ?></td><td><?php echo esc_html($w['name']); ?></td><td><?php echo $w['is_active']?'فعال':'غیرفعال'; ?></td><td><?php if(Ctx::can('warehouse.edit')): ?><a href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'warehouses','edit'=>$w['id']])); ?>">ویرایش</a><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php if(Ctx::can($edit?'warehouse.edit':'warehouse.create')): ?><section class="erp-card"><form method="post" class="erp-form"><?php self::form('module_warehouse_save',$cid,'warehouses',$edit);self::input('code','کد',$r['code']??'','text','required');self::input('name','نام',$r['name']??'','text','required'); ?><label><input type="checkbox" name="is_active" value="1" <?php checked(self::value('is_active',$r['is_active']??1),1); ?>>فعال</label><button class="btn btn-primary" type="submit">ذخیره انبار</button></form></section><?php endif; ?>
<?php if(Ctx::can('company_settings.edit')): ?><section class="erp-card"><h3>روش حسابداری و بهای تمام‌شده</h3><p>پس از اولین گردش، تغییر این روش‌ها نیازمند مهاجرت کنترل‌شده است و این فرم آن را رد می‌کند. موجودی منفی مجاز نیست.</p><form method="post" class="erp-form"><?php self::form('module_stock_config',$cid,'warehouses');self::select('stock_cost_method','روش بهای تمام‌شده',['average'=>'میانگین موزون متحرک','fifo'=>'اولین وارده، اولین صادره (FIFO)'],Settings::get('stock_cost_method',$cid));self::select('purchase_goods_mode','حسابداری کالا',['periodic'=>'ادواری','inventory'=>'دائمی'],Settings::get('purchase_goods_mode',$cid)); ?><button class="btn btn-primary" type="submit">ذخیره روش</button></form></section><?php endif; ?>
