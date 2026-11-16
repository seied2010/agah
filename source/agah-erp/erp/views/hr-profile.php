<?php
/** پروندهٔ کارمند (PAY-01): مشخصات، کد ملی پوشیده، حساب و بیمه، مدارک خصوصی، سابقهٔ پست و فیش‌ها. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Ctx,Db,Jalali,Money,Request};
use Agah\Erp\Modules\Payroll\HrService;
use Agah\Erp\Modules\Files\FileService;
if(!defined('ABSPATH'))exit;
$id=Request::int('get','id');$e=HrService::profile($cid,$id);$edit=Ctx::can('payroll.edit');$sensitive=Ctx::can('payroll.sensitive');
?>
<section class="erp-card"><h2>پروندهٔ <?php echo esc_html($e['name'].' ('.$e['code'].')'); ?></h2>
<p>کد ملی: <span dir="ltr"><?php echo esc_html($e['national_hint']?:'ثبت نشده'); ?></span>
<?php if($sensitive&&$e['national_hint']): ?><form method="post" class="erp-inline-form" target="_blank"><?php self::form('module_hr_reveal',$cid,'hr_profile',$id); ?><button type="submit" class="btn btn-outline btn-sm">نمایش کامل (ثبت در ممیزی)</button></form><?php endif; ?></p>
<?php if($edit): ?><form method="post" class="erp-form"><?php self::form('module_hr_profile',$cid,'hr_profile',$id);
foreach(['personnel_no'=>'شمارهٔ پرسنلی','phone'=>'تلفن','email'=>'ایمیل','education'=>'تحصیلات','address'=>'نشانی','insurance_no'=>'شمارهٔ بیمه','iban'=>'شبای حقوق (IR…)'] as $k=>$l)self::input($k,$l,$e[$k],'text','maxlength="'.($k==='address'?500:190).'"'.(in_array($k,['iban','email','insurance_no'],true)?' dir="ltr"':''));
self::input('dependents','افراد تحت تکفل (فرزند)',$e['dependents'],'number','min="0" max="20"');self::select('employment_status','وضعیت',HrService::STATUS,$e['employment_status']);
foreach(['hire_date'=>'تاریخ استخدام','leave_date'=>'تاریخ پایان همکاری'] as $k=>$l)self::input($k,$l,$e[$k]?Jalali::format($e[$k],false):'','text','data-jalali maxlength="10"');
self::input('national_id','کد ملی تازه (خالی = حفظ قبلی)','','text','inputmode="numeric" maxlength="10" autocomplete="off"'); ?>
<label><input type="checkbox" name="clear_national_id" value="1"> حذف کد ملی ذخیره‌شده</label>
<button type="submit" class="btn btn-primary">ذخیرهٔ پرونده</button></form><?php else: ?><dl><?php foreach(['personnel_no'=>'شمارهٔ پرسنلی','phone'=>'تلفن','education'=>'تحصیلات','dependents'=>'افراد تحت تکفل','employment_status'=>'وضعیت'] as $k=>$l): ?><dt><?php echo esc_html($l); ?></dt><dd><?php echo esc_html($k==='employment_status'?(HrService::STATUS[$e[$k]]??''):(string)$e[$k]); ?></dd><?php endforeach; ?></dl><?php endif; ?>
</section>
<?php if($sensitive): $files=Db::rows('SELECT id,original_name,category,byte_size,created_at FROM '.Db::t('files').' WHERE company_id=%d AND employee_id=%d ORDER BY id DESC',$cid,$id); ?>
<section class="erp-card"><h3>مدارک خصوصی (فقط با مجوز اطلاعات حساس)</h3>
<table class="erp-table"><thead><tr><th>فایل</th><th>دسته</th><th>تاریخ</th></tr></thead><tbody><?php foreach($files as $f): ?><tr><td><a href="<?php echo esc_url(OperationsController::fileUrl($cid,$f['id'])); ?>"><?php echo esc_html($f['original_name']); ?></a></td><td><?php echo esc_html($f['category']); ?></td><td><?php echo esc_html(Jalali::format(substr(get_date_from_gmt($f['created_at']),0,10))); ?></td></tr><?php endforeach; ?></tbody></table>
<?php if($edit&&Ctx::can('file.create')): ?><form method="post" class="erp-form" enctype="multipart/form-data"><?php self::form('module_file_attach',$cid,'hr_profile'); ?><input type="hidden" name="target_type" value="employee"><input type="hidden" name="target_id" value="<?php echo (int)$id; ?>"><label>فایل (PDF یا تصویر)<input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.webp" required></label><?php self::input('category','دسته (مثلاً کارت ملی، قرارداد امضاشده)','','text','maxlength="80"'); ?><button type="submit" class="btn btn-outline">بارگذاری مدرک</button></form><?php endif; ?></section>
<?php endif; ?>
<section class="erp-card"><h3>سابقهٔ پست</h3><table class="erp-table"><thead><tr><th>پست</th><th>واحد</th><th>از</th><th>تا</th></tr></thead><tbody><?php foreach(HrService::assignments($cid,$id) as $a): ?><tr><td><?php echo esc_html($a['post_title']); ?></td><td><?php echo esc_html($a['unit_name']); ?></td><td><?php echo esc_html(Jalali::format($a['start_date'])); ?></td><td><?php echo esc_html($a['end_date']?Jalali::format($a['end_date']):'جاری'); ?></td></tr><?php endforeach; ?></tbody></table>
<h3>فیش‌ها</h3><table class="erp-table"><thead><tr><th>شماره</th><th>ماه</th><th>خالص</th><th>وضعیت</th></tr></thead><tbody><?php foreach(Db::rows('SELECT id,number,period_key,net,status FROM '.Db::t('payslips').' WHERE company_id=%d AND employee_id=%d ORDER BY period_key DESC,id DESC LIMIT 36',$cid,$id) as $s): ?><tr><td><a href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'payslip_view','id'=>$s['id']])); ?>"><?php echo esc_html(Money::toFa((string)$s['number'])); ?></a></td><td><?php echo esc_html($s['period_key']); ?></td><td><?php echo esc_html(Money::fmt((int)$s['net'],Ctx::unit())); ?></td><td><?php echo $s['status']==='issued'?'صادرشده':'باطل'; ?></td></tr><?php endforeach; ?></tbody></table></section>
