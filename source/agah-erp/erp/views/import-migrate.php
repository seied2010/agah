<?php
/** ورود از نرم‌افزار دیگر: قالب، بارگذاری CSV/XLSX، نگاشت ستون، پیش‌نمایش (اجرای آزمایشی) و ورود جداگانه با نگاشت کد مبدأ. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Ctx,Jalali,Money,Request};
use Agah\Erp\Modules\Import\MigrationService as MS;
if(!defined('ABSPATH'))exit;
$id=Request::int('get','id');$unit=Ctx::unit();$kinds=array_filter(MS::KINDS,fn($k)=>Ctx::can(MS::cap($k)),ARRAY_FILTER_USE_KEY);
?>
<section class="erp-card"><h2>ورود از نرم‌افزار دیگر</h2>
<p class="muted">ترتیب پیشنهادی: ۱) حساب‌ها ۲) اشخاص ۳) ماندهٔ افتتاحیه. از نرم‌افزار قبلی خروجی <b>CSV یا Excel (.xlsx)</b> بگیرید؛ فایل رمزگذاری‌شده یا قالب اختصاصی خوانده نمی‌شود. کد هر رکورد در مبدأ جدا نگه داشته می‌شود؛ ورود دوبارهٔ همان فایل پذیرفته نمی‌شود و کدِ قبلاً واردشده دوباره ساخته نمی‌شود. پیش‌نمایش هیچ ثبتی انجام نمی‌دهد.</p>
<p><?php foreach($kinds as $k=>$label): ?><a class="btn btn-outline btn-sm" href="<?php echo esc_url(add_query_arg(['migrate_template'=>$k,'c'=>$cid,'_n'=>wp_create_nonce('agah_migrate_template_'.$cid.'_'.$k)],agah_erp_url())); ?>">قالب <?php echo esc_html($label); ?></a> <?php endforeach; ?></p>
<?php if($kinds): ?><form method="post" class="erp-form" enctype="multipart/form-data"><?php self::form('module_migrate_upload',$cid,'import_migrate'); self::select('kind','نوع داده',$kinds,array_key_first($kinds)); self::input('source_system','نام نرم‌افزار مبدأ','','text','required maxlength="40" placeholder="مثلاً هلو، سپیدار، محک"'); ?>
<label>فایل .csv یا .xlsx (حداکثر ۵ مگابایت، ۵۰۰۰ ردیف)<input type="file" name="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required><?php self::error('file'); ?></label>
<?php self::select('unit','واحد مبالغ فایل (برای ماندهٔ افتتاحیه)',['rial'=>'ریال','toman'=>'تومان'],'rial'); self::input('doc_date','تاریخ سند افتتاحیه (فقط ماندهٔ افتتاحیه)','','text','data-jalali maxlength="10"'); ?>
<button type="submit" class="btn btn-primary">بارگذاری و پیش‌نمایش</button></form><?php else: ?><p>برای ورود داده مجوز ساخت حساب، شخص یا سند لازم است.</p><?php endif; ?>
</section>

<?php if($id): $b=MS::batch($cid,$id);$rows=MS::rows($cid,$id);$headers=json_decode($b['headers'],true)?:[];$map=json_decode($b['mapping'],true)?:[];$open=in_array($b['status'],['preview','partial'],true);$opt=json_decode((string)$b['options'],true)?:[];
$count=[];foreach($rows as $r)$count[$r['status']]=($count[$r['status']]??0)+1;$ready=($count['ok']??0)+($count['link']??0); ?>
<section class="erp-card"><h2>دستهٔ <?php echo esc_html(Money::toFa((string)$b['id'])); ?> — <?php echo esc_html(MS::KINDS[$b['kind']]); ?> از «<?php echo esc_html($b['source_system']); ?>»</h2>
<p>فایل: <?php echo esc_html($b['file_name']); ?> — آداپتور: <span dir="ltr"><?php echo esc_html($b['adapter'].' / '.$b['encoding']); ?></span> — نسخهٔ نگاشت: <?php echo esc_html(Money::toFa((string)$b['mapping_version'])); ?> — وضعیت: <b><?php echo esc_html(MS::STATUS[$b['status']]??$b['status']); ?></b><?php if(!empty($opt['date'])): ?> — تاریخ سند: <?php echo esc_html(Jalali::format($opt['date'])); ?><?php endif; ?></p>
<p><?php foreach(MS::ROW_STATUS as $k=>$label)if(!empty($count[$k]))echo esc_html($label.': '.Money::toFa((string)$count[$k])).' &nbsp; '; ?></p>
<?php if($b['status']==='preview'): ?>
<details <?php echo (int)$b['rows_error']?'open':''; ?>><summary>نگاشت ستون‌ها</summary>
<form method="post" class="erp-form"><?php self::form('module_migrate_remap',$cid,'import_migrate',$b['id']); ?>
<?php foreach(MS::FIELDS[$b['kind']] as $field=>$label): ?><label><?php echo esc_html($label.(in_array($field,MS::REQUIRED[$b['kind']],true)?' (الزامی)':'')); ?><select name="map[<?php echo esc_attr($field); ?>]"><option value="">— ندارد —</option><?php foreach($headers as $i=>$h): ?><option value="<?php echo (int)$i; ?>" <?php selected(isset($map[$field])&&(int)$map[$field]===(int)$i); ?>><?php echo esc_html(($h!==''?$h:'ستون '.($i+1))); ?></option><?php endforeach; ?></select></label><?php endforeach; ?>
<button type="submit" class="btn btn-outline">اعمال نگاشت (نسخهٔ تازه) و پیش‌نمایش دوباره</button></form></details>
<?php endif; ?>
<div class="erp-scroll"><table class="erp-table"><thead><tr><th>ردیف فایل</th><?php foreach(MS::FIELDS[$b['kind']] as $field=>$label): ?><th><?php echo esc_html($label); ?></th><?php endforeach; ?><th>نتیجه</th></tr></thead><tbody>
<?php foreach($rows as $r): $raw=json_decode($r['raw'],true)?:[]; ?><tr><td><?php echo esc_html(Money::toFa((string)$r['row_no'])); ?></td><?php foreach(MS::FIELDS[$b['kind']] as $field=>$label): ?><td><?php echo esc_html(isset($map[$field])?(string)($raw[$map[$field]]??''):''); ?></td><?php endforeach; ?><td class="erp-wrap"><?php echo $r['status']==='error'?'<span class="erp-err">'.esc_html($r['error']).'</span>':esc_html(MS::ROW_STATUS[$r['status']]??$r['status']).($r['voucher_id']?' — <a href="'.esc_url(agah_erp_url(['c'=>$cid,'p'=>'voucher_view','id'=>$r['voucher_id']])).'">مشاهدهٔ سند</a>':''); ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php if($open): ?><div class="erp-actions">
<?php if($ready&&($b['kind']!=='opening'||!(int)$b['rows_error'])): ?><form method="post" class="erp-inline-form"><?php self::form('module_migrate_post',$cid,'import_migrate',$b['id']); ?><input type="hidden" name="snapshot" value="<?php echo esc_attr($b['snapshot']); ?>"><button type="submit" class="btn btn-primary" data-confirm="<?php echo esc_attr($b['kind']==='opening'?'سند افتتاحیهٔ پیش‌نویس ساخته می‌شود. ادامه می‌دهید؟':'ردیف‌های سالم وارد می‌شوند و ردیف‌های خطادار برای اصلاح می‌مانند. ادامه می‌دهید؟'); ?>"><?php echo esc_html($b['kind']==='opening'?'ساخت سند افتتاحیهٔ پیش‌نویس':'ورود '.Money::toFa((string)$ready).' ردیف'); ?></button></form>
<?php elseif($b['kind']==='opening'&&(int)$b['rows_error']): ?><p class="erp-err">ماندهٔ افتتاحیه فقط وقتی ساخته می‌شود که همهٔ ردیف‌ها سالم و جمع بدهکار و بستانکار برابر باشد.</p><?php endif; ?>
<form method="post" class="erp-inline-form"><?php self::form('module_migrate_refresh',$cid,'import_migrate',$b['id']); ?><button type="submit" class="btn btn-outline">بازبینی دوباره با داده‌های فعلی</button></form>
<?php if($b['status']==='preview'): ?><form method="post" class="erp-inline-form"><?php self::form('module_migrate_cancel',$cid,'import_migrate',$b['id']); ?><button type="submit" class="btn btn-outline" data-confirm="این دسته لغو می‌شود. ادامه می‌دهید؟">لغو دسته</button></form><?php endif; ?>
</div><?php elseif($b['status']==='cancelled'): ?><form method="post" class="erp-inline-form"><?php self::form('module_migrate_delete',$cid,'import_migrate',$b['id']); ?><button type="submit" class="btn btn-outline" data-confirm="دستهٔ لغوشده و ردیف‌هایش حذف می‌شوند. ادامه می‌دهید؟">حذف دسته</button></form><?php endif; ?>
</section>
<?php endif; ?>

<?php $systems=MS::systems($cid); if($systems&&Ctx::can('account.view')): $sys=Request::str('get','system',40);$sys=in_array($sys,$systems,true)?$sys:$systems[0];$ent=Request::str('get','entity',10);$ent=in_array($ent,['account','party','opening'],true)?$ent:'account'; ?>
<section class="erp-card"><h2>نگاشت کدهای مبدأ</h2>
<form method="get" class="erp-filter"><input type="hidden" name="page_id" value="<?php echo (int)get_option('agah_erp_page_id'); ?>"><input type="hidden" name="c" value="<?php echo (int)$cid; ?>"><input type="hidden" name="p" value="import_migrate"><label>مبدأ<select name="system"><?php foreach($systems as $s): ?><option <?php selected($sys,$s); ?>><?php echo esc_html($s); ?></option><?php endforeach; ?></select></label><label>نوع<select name="entity"><?php foreach(['account'=>'حساب','party'=>'شخص','opening'=>'افتتاحیه'] as $k=>$l): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($ent,$k); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?></select></label><button type="submit" class="btn btn-outline">نمایش</button></form>
<div class="erp-scroll"><table class="erp-table"><thead><tr><th>کد مبدأ</th><th>نام در مبدأ</th><th>بالادستی مبدأ</th><th>شناسهٔ پایدار آگاه</th><th>نوع پیوند</th><th>نسخهٔ نگاشت</th><th></th></tr></thead><tbody>
<?php foreach(MS::maps($cid,$sys,$ent,Request::int('get','pg',1)) as $m): ?><tr><td dir="ltr"><?php echo esc_html($m['source_code']); ?></td><td><?php echo esc_html($m['source_name']); ?></td><td dir="ltr"><?php echo esc_html($m['source_parent']); ?></td><td dir="ltr"><small><?php echo esc_html($m['target_uuid']); ?></small></td><td><?php echo $m['link']==='linked'?'نگاشت به رکورد موجود':'ساخته‌شده از ورود'; ?></td><td><?php echo esc_html(Money::toFa((string)$m['mapping_version'])); ?></td><td><?php if(Ctx::can('account.edit')): ?><form method="post" class="erp-inline-form"><?php self::form('module_migrate_forget',$cid,'import_migrate',$m['id']); ?><button type="submit" class="btn btn-outline btn-sm" data-confirm="فقط پیوند کد مبدأ برداشته می‌شود و رکورد آگاه می‌ماند. ادامه می‌دهید؟">حذف نگاشت</button></form><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>

<section class="erp-card"><h2>دسته‌های اخیر</h2><div class="erp-scroll"><table class="erp-table"><thead><tr><th>شماره</th><th>نوع</th><th>مبدأ</th><th>فایل</th><th>وضعیت</th><th>ردیف / خطا</th><th>زمان</th></tr></thead><tbody>
<?php foreach(MS::batches($cid) as $x): ?><tr><td><a href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'import_migrate','id'=>$x['id']])); ?>"><?php echo esc_html(Money::toFa((string)$x['id'])); ?></a></td><td><?php echo esc_html(MS::KINDS[$x['kind']]??$x['kind']); ?></td><td><?php echo esc_html($x['source_system']); ?></td><td><?php echo esc_html($x['file_name']); ?></td><td><?php echo esc_html(MS::STATUS[$x['status']]??$x['status']); ?></td><td><?php echo esc_html(Money::toFa($x['rows_total'].' / '.$x['rows_error'])); ?></td><td><?php echo esc_html(Jalali::format(substr(get_date_from_gmt($x['created_at']),0,10))); ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
