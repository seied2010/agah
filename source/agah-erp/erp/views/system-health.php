<?php
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Ctx,Db,Jalali,Money,Request,Settings};
use Agah\Erp\Modules\Legal\LegalService;
use Agah\Erp\Modules\Maintenance\HealthService;
if(!defined('ABSPATH'))exit;
$checks=HealthService::checks($cid);
?>
<section class="erp-card"><h2>سلامت سامانه</h2><p>این صفحه وضعیت فعلی را نشان می‌دهد؛ سبز بودن بررسی‌ها جای آزمون امنیت مستقل، بازیابی عملی یا نظارت مدیر سرور را نمی‌گیرد. نبود تنظیم پشتیبان به معنی فعال‌بودن زمان‌بندی نیست.</p><div class="erp-scroll"><table class="erp-table"><thead><tr><th>بررسی</th><th>وضعیت</th><th>جزئیات</th></tr></thead><tbody><?php foreach($checks as $k=>$r): ?><tr data-health-check="<?php echo esc_attr($k); ?>"><td><?php echo esc_html($r['label']); ?></td><td class="<?php echo $r['ok']?'erp-ok':'erp-err'; ?>"><?php echo $r['ok']?'تأیید شد':'نیازمند اقدام'; ?></td><td><?php echo esc_html($r['detail']); ?></td></tr><?php endforeach; ?></tbody></table></div><p>تنظیم مسیر خصوصی و کلید پشتیبان را مدیر سرور انجام می‌دهد. فایل پشتیبان کل نصب، از این صفحه قابل دانلود یا بازیابی نیست؛ عملیات از خط فرمان سرور انجام می‌شود.</p></section>
