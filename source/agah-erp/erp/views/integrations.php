<?php
/** وضعیت واقعی اتصال‌های رسمی و خروجی‌ها (Q40): بدون ارائه‌دهنده، مجوز یا نمونهٔ رسمی هیچ موردی «فعال» نشان داده نمی‌شود. */
namespace Agah\Erp\Http;
use Agah\Erp\Modules\Moadian\MoadianService as MD;
if(!defined('ABSPATH'))exit;
$labels=['active'=>'فعال','configured'=>'پیکربندی‌شده (تأییدنشده با تراکنش واقعی)','available'=>'در دسترس','trial'=>'آزمایشی','inactive'=>'غیرفعال'];
?>
<section class="erp-card"><h2>وضعیت اتصال‌های رسمی</h2>
<p class="muted">این فهرست فقط آنچه واقعاً پیکربندی شده را نشان می‌دهد. رازها و کلیدها هرگز در آگاه نمایش داده نمی‌شوند؛ نگهداری آن‌ها با افزونهٔ اتصال مربوط است.</p>
<div class="erp-scroll"><table class="erp-table"><thead><tr><th>اتصال / خروجی</th><th>وضعیت</th><th>توضیح</th></tr></thead><tbody>
<?php foreach(MD::integrations() as $i): ?><tr><td><?php echo esc_html($i['name']); ?></td><td><?php echo $i['state']==='inactive'?'<span class="erp-err">'.esc_html($labels[$i['state']]).'</span>':esc_html($labels[$i['state']]??$i['state']); ?></td><td class="erp-wrap"><?php echo esc_html($i['note']); ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
