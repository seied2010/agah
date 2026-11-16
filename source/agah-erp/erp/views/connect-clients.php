<?php
/** اتصال فروشگاه‌ها: ساخت، تنظیم، چرخش راز، لغو و حذف. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Ctx,Db,Jalali,Money,Request};
use Agah\Erp\Modules\Connect\ConnectService;
if(!defined('ABSPATH'))exit;
$clients=ConnectService::clients($cid);$secret=ConnectController::takeSecret();$canEdit=Ctx::can('connect.edit');
$warehouses=[];foreach(Db::rows('SELECT id,code,name FROM '.Db::t('warehouses').' WHERE company_id=%d AND is_active=1 ORDER BY code',$cid) as $w)$warehouses[$w['id']]=$w['code'].' — '.$w['name'];
$banks=[];foreach(Db::rows('SELECT id,code,name FROM '.Db::t('treasury_accounts')." WHERE company_id=%d AND is_active=1 AND kind='bank' ORDER BY code",$cid) as $b)$banks[$b['id']]=$b['code'].' — '.$b['name'];
$services=[0=>'بدون هزینهٔ ارسال'];foreach(Db::rows('SELECT id,code,name FROM '.Db::t('items')." WHERE company_id=%d AND is_active=1 AND kind='service' ORDER BY code",$cid) as $s)$services[$s['id']]=$s['code'].' — '.$s['name'];
$editId=Request::int('get','edit');$edit=null;foreach($clients as $c)if((int)$c['id']===$editId)$edit=$c;
$api=rest_url('agah-connect/v1');
?>
<section class="erp-card"><h2>اتصال فروشگاه‌ها</h2>
<p>هر فروشگاه ووکامرسی با یک «اتصال» به این شرکت وصل می‌شود. قیمت و موجودی از آگاه خوانده می‌شود و فروش واقعی فروشگاه این‌جا ثبت می‌شود. نشانی سرویس: <code dir="ltr"><?php echo esc_html($api); ?></code></p>
<?php if($secret): ?><div class="notice ok" role="alert"><p>این اطلاعات را همین حالا در تنظیمات افزونهٔ فروشگاه وارد کنید. راز فقط همین یک بار نمایش داده می‌شود.</p><p>شناسهٔ کلید: <code dir="ltr"><?php echo esc_html($secret['key_id']); ?></code></p><p>راز: <code dir="ltr"><?php echo esc_html($secret['secret']); ?></code></p></div><?php endif; ?>
<div class="erp-scroll"><table class="erp-table"><thead><tr><th>فروشگاه</th><th>شناسهٔ کلید</th><th>انبار</th><th>وضعیت</th><th>آخرین ارتباط</th><th>خروج خودکار</th><th>عملیات</th></tr></thead><tbody>
<?php foreach($clients as $c): ?><tr><td><?php echo esc_html($c['name']); ?></td><td dir="ltr"><?php echo esc_html($c['key_id']); ?></td><td><?php echo esc_html($warehouses[$c['warehouse_id']]??'—'); ?></td><td><?php echo esc_html($c['status']==='active'?'فعال':'لغوشده'); ?></td><td><?php echo esc_html($c['last_seen_at']?Jalali::format(get_date_from_gmt($c['last_seen_at'],'Y-m-d')).' '.get_date_from_gmt($c['last_seen_at'],'H:i'):'—'); ?></td><td><?php echo $c['auto_dispatch']?'بله':'خیر'; ?></td><td>
<?php if($canEdit): ?><a class="btn btn-outline btn-sm" href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'connect_clients','edit'=>$c['id']])); ?>">ویرایش</a>
<?php if($c['status']==='active'): ?><form method="post" class="erp-inline-form"><?php self::form('module_connect_rotate',$cid,'connect_clients',$c['id']); ?><button type="submit" class="btn btn-outline btn-sm" data-confirm="راز قبلی فوراً از کار می‌افتد. ادامه می‌دهید؟">راز تازه</button></form>
<form method="post" class="erp-inline-form"><?php self::form('module_connect_revoke',$cid,'connect_clients',$c['id']); ?><button type="submit" class="btn btn-outline btn-sm" data-confirm="فروشگاه دیگر به آگاه دسترسی نخواهد داشت. ادامه می‌دهید؟">لغو اتصال</button></form><?php endif; ?>
<form method="post" class="erp-inline-form"><?php self::form('module_connect_delete',$cid,'connect_clients',$c['id']); ?><button type="submit" class="btn btn-outline btn-sm" data-confirm="فقط اتصال بدون سابقه حذف می‌شود. ادامه می‌دهید؟">حذف</button></form><?php endif; ?>
</td></tr><?php endforeach; ?></tbody></table></div></section>
<?php if($canEdit): ?><section class="erp-card"><h2><?php echo $edit?'ویرایش اتصال':'اتصال تازه'; ?></h2>
<?php if(!$warehouses||!$banks): ?><p class="notice bad">برای ساخت اتصال ابتدا یک انبار فعال و یک حساب بانکی فعال (برای وجوه درگاه) تعریف کنید.</p><?php else: ?>
<form method="post" class="erp-form"><?php self::form($edit?'module_connect_update':'module_connect_create',$cid,'connect_clients',$edit['id']??0);
self::input('name','نام فروشگاه',$edit['name']??'','text','required minlength="2" maxlength="100"');
self::select('warehouse_id','انبار فروش اینترنتی',$warehouses,$edit['warehouse_id']??0);
self::select('treasury_account_id','حساب بانکی وجوه درگاه',$banks,$edit['treasury_account_id']??0);
self::select('shipping_item_id','خدمت هزینهٔ ارسال',$services,$edit['shipping_item_id']??0);
self::input('reservation_ttl','مدت رزرو تا پرداخت (دقیقه)',(string)($edit['reservation_ttl']??20),'number','min="5" max="1440" required');
self::input('party_threshold','ثبت جدای خریدار از این مبلغ به بالا؛ صفر یعنی غیرفعال ('.Money::unitLabel(Ctx::unit()).')',Money::toInputValue((int)($edit['party_threshold']??0),Ctx::unit()),'text','inputmode="decimal"'); ?>
<label><input type="checkbox" name="auto_dispatch" value="1" <?php checked(!empty($edit['auto_dispatch'])); ?>> خروج خودکار کالا و صدور فاکتور پس از پرداخت</label>
<p class="muted">اگر خاموش باشد، پس از پرداخت موجودی رزرو می‌ماند و انباردار از «سفارش‌های فروشگاه» خروج کالا را ثبت می‌کند.</p>
<button type="submit" class="btn btn-primary"><?php echo $edit?'ذخیره':'ساخت اتصال و نمایش کلید'; ?></button></form><?php endif; ?></section><?php endif; ?>
