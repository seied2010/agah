<?php
/** ورود سند از Excel: قالب نسخه‌دار، بارگذاری، نگاشت ستون، پیش‌نمایش کامل با تراز هر سند، صدور جداگانه به پیش‌نویس. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Ctx,Db,Jalali,Money,Request};
use Agah\Erp\Modules\Import\ImportService;
if(!defined('ABSPATH'))exit;
$id=Request::int('get','id');$unit=Ctx::unit();
?>
<section class="erp-card"><h2>ورود سند از Excel</h2>
<p class="muted">۱) قالب را دریافت و پر کنید؛ ردیف‌های هم‌شماره یک سند می‌شوند. ۲) فایل را بارگذاری کنید. ۳) پیش‌نمایش را بررسی کنید. ۴) «صدور» فقط سند <b>پیش‌نویس</b> می‌سازد؛ تأیید و قطعی‌کردن در فهرست اسناد انجام می‌شود. فایل تکراری پذیرفته نمی‌شود.</p>
<p><a class="btn btn-outline" href="<?php echo esc_url(add_query_arg(['import_template'=>1,'c'=>$cid,'_n'=>wp_create_nonce('agah_import_template_'.$cid)],agah_erp_url())); ?>">دریافت قالب خام (<?php echo esc_html(ImportService::TEMPLATE); ?>)</a></p>
<form method="post" class="erp-form" enctype="multipart/form-data"><?php self::form('module_import_voucher_upload',$cid,'import_vouchers'); ?>
<label>فایل .xlsx (حداکثر ۵ مگابایت، ۵۰۰۰ ردیف)<input type="file" name="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required><?php self::error('file'); ?></label>
<?php self::select('unit','واحد مبالغ فایل',['toman'=>'تومان','rial'=>'ریال'],$unit); ?>
<button type="submit" class="btn btn-primary">بارگذاری و پیش‌نمایش</button></form>
</section>

<?php if($id): $pv=ImportService::preview($cid,$id);$b=$pv['batch'];$headers=json_decode($b['headers'],true)?:[];$map=json_decode($b['mapping'],true)?:[];$open=$b['status']==='preview'; ?>
<section class="erp-card"><h2>دستهٔ <?php echo esc_html(Money::toFa((string)$b['id'])); ?> — <?php echo esc_html($b['file_name']); ?></h2>
<p>وضعیت: <b><?php echo esc_html(ImportService::STATUS[$b['status']]??$b['status']); ?></b> — ردیف: <?php echo esc_html(Money::toFa((string)$b['rows_total'])); ?> — خطا: <?php echo esc_html(Money::toFa((string)$b['rows_error'])); ?> — واحد فایل: <?php echo esc_html(Money::unitLabel($b['unit'])); ?></p>
<?php if($open): ?>
<details <?php echo (int)$b['rows_error']?'open':''; ?>><summary>نگاشت ستون‌ها</summary>
<form method="post" class="erp-form"><?php self::form('module_import_voucher_remap',$cid,'import_vouchers',$b['id']); ?>
<?php foreach(ImportService::FIELDS['voucher'] as $field=>$label): ?><label><?php echo esc_html($label.(in_array($field,ImportService::REQUIRED['voucher'],true)?' (الزامی)':'')); ?><select name="map[<?php echo esc_attr($field); ?>]"><option value="">— ندارد —</option><?php foreach($headers as $i=>$h): ?><option value="<?php echo (int)$i; ?>" <?php selected(isset($map[$field])&&(int)$map[$field]===(int)$i); ?>><?php echo esc_html(($h!==''?$h:'ستون '.($i+1))); ?></option><?php endforeach; ?></select></label><?php endforeach; ?>
<button type="submit" class="btn btn-outline">اعمال نگاشت و پیش‌نمایش دوباره</button></form></details>
<?php endif; ?>
<?php foreach($pv['groups'] as $g=>$grp): $bal=$grp['debit']===$grp['credit']&&$grp['debit']>0; ?>
<h3>سند «<?php echo esc_html($g); ?>» <?php echo $bal?'':'<span class="erp-err">(تراز نیست)</span>'; ?></h3>
<table class="erp-table"><thead><tr><th>ردیف Excel</th><th>تاریخ</th><th>حساب / تفصیلی</th><th>شرح</th><th>بدهکار</th><th>بستانکار</th><th>وضعیت</th></tr></thead><tbody>
<?php foreach($grp['rows'] as $r): $d=$r['parsed'];$raw=json_decode($r['raw'],true)?:[]; ?><tr><td><?php echo esc_html(Money::toFa((string)$r['row_no'])); ?></td><td><?php echo esc_html(!empty($d['date'])?Jalali::format($d['date']):($raw[$map['date']??-1]??'')); ?></td><td><?php echo esc_html(trim(($raw[$map['account']??-1]??'').' '.(isset($map['detail'])&&($raw[$map['detail']]??'')!==''?'/ '.$raw[$map['detail']]:''))); ?></td><td><?php echo esc_html($d['description']??''); ?></td><td><?php echo esc_html(!empty($d['debit'])?Money::fmt($d['debit'],$unit):''); ?></td><td><?php echo esc_html(!empty($d['credit'])?Money::fmt($d['credit'],$unit):''); ?></td><td><?php echo $r['status']==='error'?'<span class="erp-err">'.esc_html($r['error']).'</span>':esc_html(ImportService::ROW_STATUS[$r['status']]??$r['status']).($r['voucher_id']?' — <a href="'.esc_url(agah_erp_url(['c'=>$cid,'p'=>'voucher_view','id'=>$r['voucher_id']])).'">مشاهدهٔ سند</a>':''); ?></td></tr><?php endforeach; ?>
</tbody><tfoot><tr><th colspan="4">جمع (<?php echo esc_html(Money::unitLabel($unit)); ?>)</th><th><?php echo esc_html(Money::fmt($grp['debit'],$unit)); ?></th><th><?php echo esc_html(Money::fmt($grp['credit'],$unit)); ?></th><th></th></tr></tfoot></table>
<?php endforeach; ?>
<?php if($open): ?><div class="erp-actions">
<?php if(!(int)$b['rows_error']): ?><form method="post" class="erp-inline-form"><?php self::form('module_import_voucher_post',$cid,'import_vouchers',$b['id']); ?><input type="hidden" name="snapshot" value="<?php echo esc_attr($b['snapshot']); ?>"><button type="submit" class="btn btn-primary" data-confirm="از این پیش‌نمایش، سند پیش‌نویس ساخته می‌شود. ادامه می‌دهید؟">صدور <?php echo esc_html(Money::toFa((string)count($pv['groups']))); ?> سند پیش‌نویس</button></form>
<?php else: ?><p class="erp-err">تا وقتی ردیف خطادار هست، هیچ سندی صادر نمی‌شود. فایل را اصلاح و دوباره بارگذاری کنید (ابتدا این دسته را لغو کنید) یا نگاشت/کدینگ را اصلاح کنید.</p><?php endif; ?>
<form method="post" class="erp-inline-form"><?php self::form('module_import_voucher_refresh',$cid,'import_vouchers',$b['id']); ?><button type="submit" class="btn btn-outline">بازبینی دوباره با کدینگ فعلی</button></form>
<form method="post" class="erp-inline-form"><?php self::form('module_import_voucher_cancel',$cid,'import_vouchers',$b['id']); ?><button type="submit" class="btn btn-outline" data-confirm="این دسته لغو می‌شود. ادامه می‌دهید؟">لغو دسته</button></form>
</div><?php elseif($b['status']==='cancelled'): ?><form method="post" class="erp-inline-form"><?php self::form('module_import_voucher_delete',$cid,'import_vouchers',$b['id']); ?><button type="submit" class="btn btn-outline" data-confirm="دستهٔ لغوشده و ردیف‌هایش حذف می‌شوند. ادامه می‌دهید؟">حذف دسته</button></form><?php endif; ?>
</section>
<?php endif; ?>

<section class="erp-card"><h2>دسته‌های اخیر</h2><table class="erp-table"><thead><tr><th>شماره</th><th>فایل</th><th>وضعیت</th><th>ردیف / خطا</th><th>زمان</th></tr></thead><tbody>
<?php foreach(ImportService::batches($cid,'voucher') as $x): ?><tr><td><a href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'import_vouchers','id'=>$x['id']])); ?>"><?php echo esc_html(Money::toFa((string)$x['id'])); ?></a></td><td><?php echo esc_html($x['file_name']); ?></td><td><?php echo esc_html(ImportService::STATUS[$x['status']]??$x['status']); ?></td><td><?php echo esc_html(Money::toFa($x['rows_total'].' / '.$x['rows_error'])); ?></td><td><?php echo esc_html(Jalali::format(substr(get_date_from_gmt($x['created_at']),0,10))); ?></td></tr><?php endforeach; ?>
</tbody></table></section>
