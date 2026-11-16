<?php
/** مرکز دریافت پشتیبان؛ تولید فایل در کنترلر و خارج از خروجی HTML انجام می‌شود. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Crypto,Ctx,Money};
use Agah\Erp\Modules\Maintenance\PortableBackupService;
if(!defined('ABSPATH'))exit;
$stable=Crypto::hasConfiguredKey();
?>
<section class="erp-dashboard-head">
  <div><span class="erp-eyebrow">نسخهٔ خارج از سایت</span><h2>پشتیبان‌گیری</h2><p>فایل کامل و رمزگذاری‌شدهٔ اطلاعات شرکت، شامل پیوست‌ها و سوابق.</p></div>
</section>

<?php if(!$stable): ?>
<section class="erp-card"><h3>کلید پایدار هنوز فعال نیست</h3><p class="erp-err">پیش از ساخت پشتیبان، مدیر سامانه باید در «مدیریت حسابداری» کلید پایدار رمزنگاری را فعال کند. این کنترل مانع ساخت فایلی می‌شود که بعداً اطلاعات رمزگذاری‌شدهٔ آن قابل خواندن نباشد.</p></section>
<?php endif; ?>

<div class="erp-grid">
  <section class="erp-card">
    <h3>پشتیبان همین شرکت</h3>
    <p>فقط رکوردهای شرکت جاری، کاربران عضو، اسناد، کالا، انبار، خزانه، حقوق، فایل‌ها و ردپای ممیزی را در یک فایل می‌گذارد. اطلاعات شرکت دیگر وارد فایل نمی‌شود.</p>
    <form method="post" class="erp-form" autocomplete="off">
      <?php self::form('module_backup_download',$cid,'backups'); ?>
      <input type="hidden" name="backup_scope" value="company">
      <label>گذرواژهٔ فایل (حداقل ۱۲ نویسه)<input type="password" name="backup_password" minlength="12" maxlength="200" required autocomplete="new-password"></label>
      <label>تکرار گذرواژهٔ فایل<input type="password" name="backup_password_confirm" minlength="12" maxlength="200" required autocomplete="new-password"></label>
      <label>گذرواژهٔ حساب کاربری شما<input type="password" name="confirm_password" required autocomplete="current-password"></label>
      <p class="muted">گذرواژهٔ فایل در سامانه ذخیره نمی‌شود. آن را در محل امن و جدا از فایل نگه دارید.</p>
      <button class="btn btn-primary" type="submit" <?php disabled(!$stable); ?>>دریافت پشتیبان شرکت</button>
    </form>
  </section>

  <?php if(current_user_can('manage_options')): ?>
  <section class="erp-card">
    <h3>پشتیبان همهٔ شرکت‌ها</h3>
    <p>تمام شرکت‌های این نصب و تنظیمات مشترک آگاه را در یک فایل می‌گذارد. نشست‌های ورود، کدهای یک‌بارمصرف و محدودیت‌های امنیتی عمداً وارد فایل نمی‌شوند.</p>
    <form method="post" class="erp-form" autocomplete="off">
      <?php self::form('module_backup_download',$cid,'backups'); ?>
      <input type="hidden" name="backup_scope" value="all">
      <label>گذرواژهٔ فایل (حداقل ۱۲ نویسه)<input type="password" name="backup_password" minlength="12" maxlength="200" required autocomplete="new-password"></label>
      <label>تکرار گذرواژهٔ فایل<input type="password" name="backup_password_confirm" minlength="12" maxlength="200" required autocomplete="new-password"></label>
      <label>گذرواژهٔ حساب کاربری شما<input type="password" name="confirm_password" required autocomplete="current-password"></label>
      <p class="muted">این فایل حاوی دادهٔ همهٔ شرکت‌هاست و فقط باید در اختیار مدیر سامانه باشد.</p>
      <button class="btn btn-primary" type="submit" <?php disabled(!$stable); ?>>دریافت پشتیبان همهٔ شرکت‌ها</button>
    </form>
  </section>
  <?php endif; ?>
</div>

<section class="erp-card">
  <h3>خروجی قابل‌خواندن Excel یا CSV</h3>
  <p>این خروجی برای مشاهده، کنترل و بایگانی اطلاعات شرکت است؛ داده‌های رمزگذاری‌شده، گذرواژه‌ها، توکن‌ها و محتوای باینری در آن قرار نمی‌گیرند. برای بازیابی سامانه باید از فایل رمزگذاری‌شدهٔ بالا استفاده شود.</p>
  <?php foreach(['xlsx'=>'دریافت Excel','csv'=>'دریافت CSV'] as $format=>$label): ?><a class="btn btn-outline" href="<?php echo esc_url(add_query_arg(['company_data_export'=>1,'format'=>$format,'_n'=>wp_create_nonce('agah_company_data_export_'.$cid),'pg'=>false])); ?>"><?php echo esc_html($label); ?></a> <?php endforeach; ?>
</section>

<?php if(Ctx::roleKey()==='company_admin'): ?>
<section class="erp-card">
  <h3>بازیابی همین شرکت</h3>
  <p class="erp-err"><strong>هشدار:</strong> بازیابی، اطلاعات فعلی همین شرکت را با محتوای فایل جایگزین می‌کند. ابتدا از وضعیت فعلی یک پشتیبان تازه بگیرید. فایل باید متعلق به همین شرکت، همین نصب، همین کلید رمزنگاری و همین نسخهٔ ساختار باشد.</p>
  <form method="post" enctype="multipart/form-data" class="erp-form" autocomplete="off">
    <?php self::form('module_backup_restore',$cid,'backups'); ?>
    <label>فایل پشتیبان <code>.<?php echo esc_html(PortableBackupService::EXT); ?></code><input type="file" name="backup_file" accept=".<?php echo esc_attr(PortableBackupService::EXT); ?>" required></label>
    <label>گذرواژهٔ فایل پشتیبان<input type="password" name="backup_password" minlength="12" maxlength="200" required autocomplete="new-password"></label>
    <label>برای تأیید، نام شرکت را دقیق وارد کنید<input name="confirm_name" required maxlength="190" autocomplete="off" placeholder="<?php echo esc_attr(Ctx::company()['name']); ?>"></label>
    <label>گذرواژهٔ حساب کاربری شما<input type="password" name="confirm_password" required autocomplete="current-password"></label>
    <label><input type="checkbox" name="restore_ack" value="1" required> می‌دانم اطلاعات فعلی این شرکت جایگزین می‌شود و از وضعیت فعلی پشتیبان گرفته‌ام.</label>
    <button class="btn btn-primary" type="submit">بررسی فایل و بازیابی همین شرکت</button>
  </form>
  <p class="muted">بازیابی فایل «همهٔ شرکت‌ها» از وب انجام نمی‌شود و برای جلوگیری از بازنویسی کل سامانه فقط در پنجرهٔ نگهداری فنی مجاز است.</p>
</section>
<?php endif; ?>

<section class="erp-card">
  <h3>نکات بازیابی و نگهداری</h3>
  <ul class="erp-list">
    <li>پسوند فایل <code>.<?php echo esc_html(PortableBackupService::EXT); ?></code> است و بدون گذرواژه باز نمی‌شود.</li>
    <li>ساخت فایل هیچ داده‌ای را حذف یا تغییر نمی‌دهد؛ فقط رویداد دریافت پشتیبان در ممیزی ثبت می‌شود.</li>
    <li>بازیابی همین شرکت برای مدیر شرکت فعال است و پیش از هر تغییری، اصالت، نسخه، کلید نصب، کاربران و تعداد ردیف‌ها کنترل می‌شود.</li>
    <li>بازیابی همهٔ شرکت‌ها فقط از مسیر نگهداری فنی سرور انجام می‌شود.</li>
    <li>سقف پیش‌فرض فایل قابل‌دریافت <?php echo esc_html(Money::toFa((string)round(PortableBackupService::maxBytes()/1048576))); ?> مگابایت است؛ دادهٔ بزرگ‌تر باید در سطح سرور پشتیبان‌گیری شود.</li>
  </ul>
</section>
