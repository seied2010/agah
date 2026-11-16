<?php
/** تنظیمات شرکت جاری؛ شناسهٔ ملی فقط پوشیده و تغییر آن با ورودی تازه انجام می‌شود. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Db,Ctx,Request,Money,Jalali,Crypto,Audit,Settings};
use Agah\Erp\Modules\Accounting\{AccountService,DetailService,FiscalService,VoucherService,ReportService,ChartSeed};
use Agah\Erp\Modules\Company\{CompanyService,MemberService,CompanyPolicyService,CompanyLifecycleService};
if(!defined('ABSPATH')) exit;
$co=Ctx::company();$edit=Ctx::can('company_settings.edit');
$kind=$co['kind']==='real'?'real':'legal';$req=CompanyPolicyService::effective($cid);
$mark=function($field,$label)use($req){$kinds=[];foreach(CompanyPolicyService::KINDS as $k=>$name)if(in_array($field,$req[$k],true))$kinds[]=$name;return $label.($kinds?' (الزامی برای '.implode(' و ',$kinds).')':'');};
$missing=CompanyPolicyService::missing($cid,$kind,$co+['national_id'=>$co['national_id_enc']!==''?'1':'']);
?>
<?php if($edit): ?><section class="erp-card"><h2>امضاهای چاپ سند حسابداری</h2><form method="post" class="erp-form"><?php self::form('module_voucher_print_settings',$cid,'settings');foreach(['voucher_sign_1'=>'عنوان امضای اول','voucher_sign_2'=>'عنوان امضای دوم','voucher_sign_3'=>'عنوان امضای سوم'] as $key=>$label)self::input($key,$label,Settings::get($key,$cid),'text','required maxlength="80"'); ?><button class="btn btn-primary" type="submit">ذخیرهٔ عنوان امضاها</button></form></section><?php endif; ?>
<section class="erp-card"><h2>مشخصات و تنظیمات شرکت</h2>
<?php if($missing): ?><div class="notice bad" role="status"><b>مشخصات الزامی شرکت کامل نیست:</b><ul><?php foreach($missing as $m): ?><li><?php echo esc_html($m); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<p>شناسهٔ ذخیره‌شده: <span dir="ltr"><?php echo esc_html(Crypto::hint(Crypto::decrypt($co['national_id_enc']))?:'ثبت نشده'); ?></span></p>
<?php if($edit): ?><form method="post" class="erp-form"><?php self::form('module_company_save',$cid,'settings'); ?>
<?php
self::input('name','نام شرکت',$co['name'],'text','required maxlength="190"');
self::input('legal_name',$mark('legal_name','نام ثبتی / نام کامل'),$co['legal_name'],'text','maxlength="190"');
self::select('activity_type','نوع فعالیت',['trading'=>'بازرگانی','manufacturing'=>'تولیدی (امکانات تولید در توسعه آینده)','services'=>'خدماتی'],Settings::get('activity_type',$cid));
self::select('kind','نوع شرکت',['real'=>'حقیقی','legal'=>'حقوقی'],$co['kind']);
self::select('display_unit','واحد نمایش',['rial'=>'ریال','toman'=>'تومان'],$co['display_unit']);
self::input('national_id',$mark('national_id','کد ملی (حقیقی، ۱۰ رقم) یا شناسهٔ ملی (حقوقی، ۱۱ رقم) جدید — خالی = حفظ قبلی'),'','text','inputmode="numeric" maxlength="11" autocomplete="off"');
self::input('economic_code',$mark('economic_code','شماره اقتصادی'),$co['economic_code'],'text','maxlength="40"');
echo '<div data-show-kind="legal">';self::input('registration_no',$mark('registration_no','شماره ثبت'),$co['registration_no'],'text','maxlength="40"');echo '</div>';
foreach(['phone'=>'تلفن','address'=>'نشانی','postal_code'=>'کد پستی'] as $key=>$label) self::input($key,$mark($key,$label),$co[$key],'text','maxlength="'.($key==='address'?500:40).'"');
self::input('default_due_days','مهلت پیش‌فرض سررسید (روز)',Settings::get('default_due_days',$cid),'number','min="0" max="3650" required');
self::input('voucher_prefix','پیشوند نمایشی شماره سند',Settings::get('voucher_prefix',$cid),'text','maxlength="20"');
self::input('invoice_prefix','پیشوند نمایشی شماره فاکتور',Settings::get('invoice_prefix',$cid),'text','maxlength="20"');
?>
<p class="muted">پیشوند روی شمارهٔ اصلی و ترتیب بدون فاصله اثر ندارد. فاکتورها و صورت‌های مالی از منوی سامانه در دسترس‌اند. شماره ثبت فقط برای شرکت حقوقی نمایش داده می‌شود.</p>
<label><input type="checkbox" name="clear_national_id" value="1"> حذف شناسهٔ ملی ذخیره‌شده</label>
<label><input type="checkbox" name="require_2fa" value="1" <?php checked(self::value('require_2fa',Settings::get('require_2fa',$cid)),'1'); ?>> الزام تأیید دومرحله‌ای برای ورود اعضا به این شرکت</label>
<label><input type="checkbox" name="persian_digits" value="1" <?php checked(self::value('persian_digits',Settings::get('persian_digits',$cid)),'1'); ?>> نمایش ارقام فارسی</label>
<button type="submit" class="btn btn-primary">ذخیرهٔ تنظیمات</button></form>
<?php else: ?><dl><?php foreach(['name'=>'نام','legal_name'=>'نام حقوقی','phone'=>'تلفن','address'=>'نشانی','economic_code'=>'شماره اقتصادی'] as $key=>$label): ?><dt><?php echo esc_html($label); ?></dt><dd><?php echo esc_html($co[$key]?:'—'); ?></dd><?php endforeach; ?></dl><?php endif; ?>
</section>

<?php if($edit&&CompanyPolicyService::delegated()): $own=Settings::get('company_required_override',$cid)!==''; ?>
<section class="erp-card"><h2>فیلدهای الزامی این شرکت</h2>
<p class="muted">مدیر سامانه تعیین فیلدهای الزامی را به مدیر شرکت سپرده است. بدون انتخاب «سیاست جدا»، سیاست عمومی سامانه اجرا می‌شود.</p>
<form method="post" class="erp-form"><?php self::form('module_company_policy',$cid,'settings'); ?>
<label><input type="checkbox" name="use_own" value="1" <?php checked($own); ?>> این شرکت سیاست جدا داشته باشد</label>
<?php foreach(CompanyPolicyService::KINDS as $k=>$kindLabel): ?><fieldset><legend><?php echo esc_html('شرکت '.$kindLabel); ?></legend>
<?php foreach(CompanyPolicyService::FIELDS as $field=>$def): if(!in_array($k,$def[1],true))continue; ?><label><input type="checkbox" name="required[<?php echo esc_attr($k); ?>][]" value="<?php echo esc_attr($field); ?>" <?php checked(in_array($field,$req[$k],true)); ?>> <?php echo esc_html($def[0]); ?></label><?php endforeach; ?>
</fieldset><?php endforeach; ?>
<button type="submit" class="btn btn-primary">ذخیرهٔ فیلدهای الزامی</button></form></section>
<?php endif; ?>

<?php if(Ctx::can('company_settings.delete')): $inv=CompanyLifecycleService::inventory($cid);$open=CompanyLifecycleService::openRequest($cid); ?>
<section class="erp-card erp-danger"><h2>تعلیق یا حذف شرکت</h2>
<p>شمار رکوردهای عملیاتی: <?php if($inv['data']): ?></p><ul><?php foreach($inv['data'] as $t=>$n): ?><li><?php echo esc_html((CompanyLifecycleService::LABELS[$t]??$t).': '.Money::toFa(number_format($n))); ?></li><?php endforeach; ?></ul><?php else: ?>این شرکت دادهٔ عملیاتی ندارد.</p><?php endif; ?>
<?php if($inv['blockers']): ?><div class="notice bad" role="status"><b>حذف اکنون ممکن نیست؛ فقط تعلیق:</b><ul><?php foreach($inv['blockers'] as $b): ?><li><?php echo esc_html($b); ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<h3>تعلیق</h3>
<p class="muted">با تعلیق، هیچ عضوی وارد شرکت نمی‌شود و فروشگاه‌های متصل هم فروش را نگه می‌دارند؛ هیچ داده‌ای حذف نمی‌شود. بازگشایی فقط با مدیر سامانه است.</p>
<form method="post" class="erp-form"><?php self::form('module_company_suspend',$cid,'settings'); self::input('reason','دلیل تعلیق','','text','required minlength="5" maxlength="300"'); ?><button type="submit" class="btn btn-outline">تعلیق شرکت</button></form>

<?php if(!$inv['data']&&!$inv['blockers']): ?>
<h3>حذف کامل شرکت بی‌داده</h3>
<p class="muted">ساختار پایه (نقش‌ها، کدینگ، سال مالی و تنظیمات) حذف می‌شود؛ گزارش ممیزی می‌ماند. این کار برگشت‌پذیر نیست.</p>
<form method="post" class="erp-form"><?php self::form('module_company_delete_empty',$cid,'settings'); self::input('confirm_name','برای تأیید، نام شرکت را دقیقاً بنویسید','','text','required autocomplete="off"'); ?><button type="submit" class="btn btn-danger">حذف کامل شرکت</button></form>
<?php elseif(!$inv['blockers']||$open): ?>
<h3>پاک‌سازی شرکت داده‌دار (دو مرحله)</h3>
<?php if($open): ?>
<p>درخواست ثبت‌شده در <?php echo esc_html(Jalali::format(substr(get_date_from_gmt($open['created_at']),0,10)).' '.Money::toFa(substr(get_date_from_gmt($open['created_at']),11,5))); ?> — دلیل: <?php echo esc_html($open['reason']); ?></p>
<p class="muted">پس از تأیید: شرکت قفل می‌شود، از همهٔ رکوردها بایگانی رمزشده ساخته و دوباره خوانده و شمرده می‌شود و فقط اگر کامل بود حذف آغاز می‌شود. درخواست تا ۲۴ ساعت معتبر است.</p>
<form method="post" class="erp-form"><?php self::form('module_company_purge_confirm',$cid,'settings'); ?><input type="hidden" name="id" value="<?php echo (int)$open['id']; ?>"><?php self::input('confirm_name','نام دقیق شرکت','','text','required autocomplete="off"'); ?><label>گذرواژهٔ حساب شما<input type="password" name="confirm_password" required autocomplete="current-password"><?php self::error('confirm_password'); ?></label><button type="submit" class="btn btn-danger">تأیید نهایی و شروع پاک‌سازی</button></form>
<form method="post" class="erp-form"><?php self::form('module_company_purge_cancel',$cid,'settings'); ?><input type="hidden" name="id" value="<?php echo (int)$open['id']; ?>"><button type="submit" class="btn btn-outline">لغو درخواست</button></form>
<?php else: ?>
<p class="muted">مرحلهٔ ۱ فقط درخواست و شمارش است و چیزی حذف نمی‌کند. مرحلهٔ ۲ با نام شرکت و گذرواژهٔ شما انجام می‌شود.</p>
<form method="post" class="erp-form"><?php self::form('module_company_purge_request',$cid,'settings'); self::input('confirm_name','نام دقیق شرکت','','text','required autocomplete="off"'); self::input('reason','دلیل پاک‌سازی','','text','required minlength="5" maxlength="300"'); ?><button type="submit" class="btn btn-danger">ثبت درخواست پاک‌سازی</button></form>
<?php endif; endif; ?>
<?php $history=array_filter(CompanyLifecycleService::purges($cid),function($p){return in_array($p['status'],['failed','cancelled'],true);}); if($history): ?>
<h3>سابقه</h3><table class="erp-table"><thead><tr><th>تاریخ</th><th>وضعیت</th><th>توضیح</th></tr></thead><tbody><?php foreach($history as $p): ?><tr><td><?php echo esc_html(Jalali::format(substr(get_date_from_gmt($p['updated_at']),0,10))); ?></td><td><?php echo esc_html(CompanyLifecycleService::STATUS[$p['status']]??$p['status']); ?></td><td><?php echo esc_html($p['error']?:$p['reason']); ?></td></tr><?php endforeach; ?></tbody></table>
<?php endif; ?>
</section>
<?php endif; ?>

<?php if(current_user_can('edit_theme_options')): ?><p class="erp-no-print"><a href="<?php echo esc_url(admin_url('customize.php?autofocus[section]=agah_erp_navigation')); ?>">انتخاب منوی بالا یا کناری در سفارشی‌ساز سایت</a></p><?php endif; ?>
