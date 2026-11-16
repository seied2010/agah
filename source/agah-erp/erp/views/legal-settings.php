<?php
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Ctx,Db,Jalali,Money,Request,Settings};
use Agah\Erp\Modules\Legal\LegalService;
use Agah\Erp\Modules\Maintenance\HealthService;
if(!defined('ABSPATH'))exit;
$mode=Settings::get('legal_vat_credit_mode',$cid);$note=Settings::get('legal_vat_credit_note',$cid);
?>
<section class="erp-card"><h2>تنظیمات گزارش قانونی</h2><p class="erp-review-notice"><?php echo esc_html(LegalService::NOTICE); ?></p><p>نرخ ارزش افزوده در بخش «نرخ مالیات» و پارامترهای حقوق در بخش «پارامترهای سالانه حقوق» تعیین می‌شوند. این صفحه روش بررسی اعتبار خرید را مشخص می‌کند؛ هیچ سند مالی با تغییر آن ساخته یا اصلاح نمی‌شود.</p><form method="post" class="erp-form"><?php self::form('module_legal_settings',$cid,$p);self::select('credit_mode','اعتبار مالیات خرید',['review'=>'هنوز بررسی نشده؛ خالص قابل پرداخت محاسبه نشود','all'=>'تمام مالیات خرید دوره قابل اعتبار است','none'=>'هیچ مالیات خرید دوره قابل اعتبار نیست'],$mode);self::input('credit_note','مبنای بررسی حسابدار',$note,'text','maxlength="500"'); ?><button type="submit" class="btn btn-primary">ذخیره تصمیم</button></form><p>انتخاب اعتبار کامل یا صفر یک تصمیم کلی برای این دوره‌های گزارش است. اگر بخشی از خرید قابل اعتبار است، گزارش ریز را با حسابدار بررسی کنید؛ تخصیص موردی و اظهارنامه نهایی در این صفحه فرض نمی‌شود.</p></section>
