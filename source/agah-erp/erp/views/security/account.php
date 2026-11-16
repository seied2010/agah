<?php
/** نمای امنیت شخصی؛ داده از SecurityController و فقط برای مالک نشست آماده می‌شود. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Db,Ctx,Request,Money,Jalali,Crypto,Audit,Settings};
use Agah\Erp\Modules\Accounting\{AccountService,DetailService,FiscalService,VoucherService,ReportService,ChartSeed};
use Agah\Erp\Modules\Company\{CompanyService,MemberService};
if(!defined('ABSPATH')) exit;
?>
<section class="erp-card erp-security" aria-labelledby="security-title">
<h2 id="security-title">امنیت حساب و تأیید دومرحله‌ای</h2>
<p>تأیید دومرحله‌ای برای هر نشست مرورگر جداست. کدهای برنامهٔ رمزساز و پشتیبان فقط یک بار قابل استفاده‌اند.</p>
<?php if(!empty($notice['message'])): ?><p class="erp-ok" role="status"><?php echo esc_html($notice['message']); ?></p><?php endif; ?>
<?php foreach(($notice['errors']??[]) as $field=>$message): ?><p class="erp-err" role="alert" data-field="<?php echo esc_attr($field); ?>"><?php echo esc_html($message); ?></p><?php endforeach; ?>
<?php if(!empty($notice['codes'])): ?>
<div class="erp-recovery"><h3>هشت کد پشتیبان — فقط همین بار نمایش داده می‌شوند</h3><p>آن‌ها را در محل امن و جدا از این دستگاه ذخیره کنید. هر کد یک بار مصرف می‌شود.</p><ul dir="ltr"><?php foreach($notice['codes'] as $backup): ?><li><code><?php echo esc_html($backup); ?></code></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if($enabled && !$verified): ?>
<form method="post" class="erp-form">
<?php self::fields('security_verify',$cid); ?>
<label>کد برنامهٔ رمزساز یا کد پشتیبان<input name="code" required maxlength="12" dir="ltr" autocomplete="one-time-code" aria-describedby="mfa-help" autofocus></label>
<p id="mfa-help">کد شش‌رقمی تازه یا یکی از کدهای پشتیبان ذخیره‌شده را وارد کنید.</p><button class="btn btn-primary" type="submit">تأیید هویت</button></form>
<?php elseif(!$enabled): ?>
<p>برای شرکت‌هایی که ورود دومرحله‌ای را الزامی کرده‌اند، ابتدا برنامهٔ رمزساز را فعال کنید.</p>
<?php if($secret): ?>
<p>افزودن دستی حساب در برنامهٔ رمزساز: نوع زمان‌محور (TOTP)، SHA-1، شش رقم و دورهٔ ۳۰ ثانیه.</p>
<p>نام حساب: <b><?php echo esc_html(get_bloginfo('name').' / '.wp_get_current_user()->user_login); ?></b></p>
<label>کلید فعال‌سازی<input readonly dir="ltr" class="erp-secret" value="<?php echo esc_attr($secret); ?>" aria-label="کلید محرمانه فعال‌سازی"></label>
<p>این کلید محرمانه است؛ آن را در گفتگو، گزارش یا تصویر صفحه به اشتراک نگذارید. ده دقیقه اعتبار دارد.</p>
<form method="post" class="erp-form"><?php self::fields('security_enable',$cid); ?><label>کد برنامهٔ رمزساز<input name="code" required pattern="[0-9۰-۹٠-٩]{6}" maxlength="6" dir="ltr" inputmode="numeric" autocomplete="one-time-code"></label><button class="btn btn-primary" type="submit">تأیید و فعال‌سازی</button></form>
<?php else: ?>
<form method="post" class="erp-form"><?php self::fields('security_begin',$cid); ?><label>گذرواژهٔ فعلی<input name="password" type="password" required autocomplete="current-password"></label><button class="btn btn-primary" type="submit">شروع فعال‌سازی رمزساز</button></form>
<?php endif; ?>
<?php else: ?>
<p class="erp-ok">رمزساز فعال و این نشست تأیید شده است.</p>
<form method="post" class="erp-form"><?php self::fields('security_disable',$cid); ?><label>گذرواژهٔ فعلی برای غیرفعال‌سازی<input name="password" type="password" required autocomplete="current-password"></label><button class="btn btn-outline" type="submit">غیرفعال‌کردن رمزساز</button></form>
<?php endif; ?>
<?php if($sms && !$verified): ?>
<h3>تأیید با پیامک به شمارهٔ از پیش تأییدشده</h3>
<form method="post"><?php self::fields('security_sms_send',$cid); ?><button class="btn btn-outline" type="submit">ارسال کد پیامکی</button></form>
<form method="post" class="erp-form"><?php self::fields('security_sms_verify',$cid); ?><label>کد پیامکی<input name="code" required maxlength="6" inputmode="numeric" dir="ltr" autocomplete="one-time-code"></label><button class="btn btn-primary" type="submit">تأیید کد پیامکی</button></form>
<?php endif; ?>
<p><a href="<?php echo esc_url(agah_erp_url($cid?['c'=>$cid]:[])); ?>">بازگشت به سامانه</a> | <a href="<?php echo esc_url(wp_logout_url(agah_erp_url())); ?>">خروج</a></p>
</section>
