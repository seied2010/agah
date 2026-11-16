<?php
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Db,Ctx,Jalali,Money,Crypto};
use Agah\Erp\Modules\Trade\SalesControlService as SC;
if(!defined('ABSPATH'))exit;
$shares=SC::shares($cid,$d['id']);$smsAllowed=SC::smsAllowed();$smsReady=$smsAllowed&&SC::smsReady();
?>
<section class="erp-card erp-no-print erp-share-section"><h3>پیوند اشتراک</h3>
<p class="muted">پیوند فعال تا زمان انقضا قابل استفاده است. پیوند لغوشده یا منقضی نمایش داده نمی‌شود؛ برای آن یک پیوند تازه بسازید. کپی و اشتراک‌گذاری برای تمام کاربران دارای مجوز خروجی این برگه در دسترس است.</p>
<?php foreach($shares as $s):$active=!$s['revoked_at']&&strtotime($s['expires_at'].' UTC')>time(); ?>
<article class="erp-share-entry">
<dl class="erp-share-meta"><dt>وضعیت</dt><dd><?php echo $active?'فعال':($s['revoked_at']?'لغوشده':'منقضی'); ?></dd><dt>انقضا</dt><dd><?php echo esc_html(Jalali::format(substr(get_date_from_gmt($s['expires_at']),0,10)).' '.Money::toFa(substr(get_date_from_gmt($s['expires_at']),11,5))); ?></dd><dt>دیده‌شده</dt><dd><?php echo esc_html(Money::toFa((string)$s['views'])); ?></dd></dl>
<?php if($active):$url=SC::shareUrl(Crypto::decrypt($s['token_enc'])); ?>
<div class="erp-share-tools"><input type="text" readonly dir="ltr" value="<?php echo esc_attr($url); ?>" aria-label="پیوند اشتراک" data-share-url><button type="button" class="btn btn-outline btn-sm" data-copy-share>کپی</button><button type="button" class="btn btn-outline btn-sm" data-web-share>اشتراک‌گذاری</button></div>
<form method="post" class="erp-inline-form"><?php self::form('module_share_revoke',$cid,'invoice_view',$s['id']); ?><button type="submit" class="btn btn-outline btn-sm" data-confirm="این پیوند لغو شود؟">لغو پیوند</button></form>
<?php if($smsAllowed): ?><details class="erp-share-sms"><summary>ارسال پیامکی پیوند فاکتور (با هزینه)</summary>
<?php if(!$smsReady): ?><p class="erp-err">سرویس پیامک روی سرور تنظیم نشده است؛ ارسال فعلاً در دسترس نیست.</p><?php else: ?>
<form method="post" class="erp-form erp-share-sms-form"><?php self::form('module_share_sms',$cid,'invoice_view',$s['id']); ?>
<label>شمارهٔ همراه گیرنده<input name="sms_phone" type="tel" dir="ltr" required maxlength="40" value="<?php echo esc_attr($party['phone']??''); ?>" autocomplete="off"></label>
<label class="erp-share-confirm"><input type="checkbox" name="sms_confirm" value="1" required> شمارهٔ گیرنده و هزینهٔ پیامک را تأیید می‌کنم؛ فقط پیوند همین فاکتور ارسال شود.</label>
<button type="submit" class="btn btn-primary">ثبت درخواست ارسال پیامک</button></form>
<p class="muted">ساخت فاکتور یا پیوند، پیامک ارسال نمی‌کند. ارسال پس از درخواست شما از صف پیام‌ها و با اجرای زمان‌بند سایت انجام می‌شود؛ یک پیوند به یک شماره دوباره درخواست نمی‌شود.</p>
<?php endif; ?></details><?php endif; ?>
<?php endif; ?>
<?php if($smsAllowed):$messages=Db::rows('SELECT recipient_hint,status,created_at FROM '.Db::t('message_queue').' WHERE company_id=%d AND channel=%s AND dedupe_key LIKE %s ORDER BY id DESC LIMIT 10',$cid,'sms','invoice-share-sms|'.(int)$s['id'].'|%');foreach($messages as $message): ?>
<p class="erp-share-status">پیامک به <bdi><?php echo esc_html($message['recipient_hint']); ?></bdi>: <?php echo esc_html(['queued'=>'در صف ارسال','sent'=>'تحویل به سرویس پیامک','dead'=>'ارسال ناموفق','blocked'=>'متوقف‌شده'][$message['status']]??$message['status']); ?></p>
<?php endforeach;endif; ?></article><?php endforeach; ?>
<form method="post" class="erp-form erp-share-create"><?php self::form('module_share_create',$cid,'invoice_view',$d['id']);self::select('hours','اعتبار',[24=>'یک روز',72=>'سه روز',168=>'یک هفته',720=>'سی روز'],72); ?><button type="submit" class="btn btn-outline">ساخت پیوند اشتراک</button></form></section>
