<?php
/** صفحهٔ عمومی پیوند اشتراک: فقط همان برگه، بدون منو، اسکریپت یا دادهٔ دیگری از شرکت. $d از Phase2Controller::publicShare. */
use Agah\Erp\Core\{Jalali,Money};
use Agah\Erp\Modules\Trade\InvoiceService;
if(!defined('ABSPATH'))exit;
$kinds=['proforma'=>'پیش‌فاکتور','sale_order'=>'سفارش فروش','sale'=>'فاکتور فروش'];
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?php echo $d?esc_html(($kinds[$d['invoice']['kind']]??'برگه').' '.Money::toFa((string)$d['invoice']['number'])):'پیوند نامعتبر'; ?></title>
<style>body{font-family:Tahoma,Arial,sans-serif;background:#f4f6f8;color:#1b2a33;margin:0;padding:16px}main{max-width:900px;margin:auto;background:#fff;border:1px solid #d5dde3;border-radius:10px;padding:20px}h1{font-size:1.3rem;margin:0 0 .5rem}table{width:100%;border-collapse:collapse;margin:1rem 0}th,td{border:1px solid #d5dde3;padding:.45rem;text-align:right;font-size:.95rem}th{background:#eef3f6}.muted{color:#5c6b75;font-size:.85rem}.wrap{overflow-x:auto}@media print{body{background:#fff;padding:0}main{border:0}}</style></head><body><main>
<?php if(!$d): ?><h1>این پیوند معتبر نیست</h1><p>پیوند منقضی، لغو یا نادرست است، یا برگه دیگر معتبر نیست. از فرستندهٔ برگه پیوند تازه بخواهید.</p>
<?php else: $i=$d['invoice'];$unit=$d['company']['display_unit']==='rial'?'rial':'toman';$f=fn($v)=>Money::fmt((int)$v,$unit); ?>
<h1><?php echo esc_html(($kinds[$i['kind']]??'برگه').' شمارهٔ '.Money::toFa((string)$i['number'])); ?></h1>
<p><b><?php echo esc_html($d['company']['legal_name']?:$d['company']['name']); ?></b><?php if($d['company']['phone']): ?> — تلفن: <?php echo esc_html($d['company']['phone']); ?><?php endif; ?></p>
<p>خریدار: <?php echo esc_html($d['party']); ?> — تاریخ: <?php echo esc_html(Jalali::format($i['invoice_date'])); ?> — سررسید: <?php echo esc_html(Jalali::format($i['due_date'])); ?></p>
<div class="wrap"><table><thead><tr><th>#</th><th>شرح کالا/خدمت</th><th>مقدار</th><th>فی</th><th>تخفیف</th><th>خالص</th><th>مالیات</th></tr></thead><tbody>
<?php foreach($d['lines'] as $n=>$l): ?><tr><td><?php echo esc_html(Money::toFa((string)($n+1))); ?></td><td><?php echo esc_html($l['item_name']); ?></td><td><?php echo esc_html(Money::toFa(rtrim(rtrim(number_format((int)$l['qty_scaled']/10000,4,'.',''),'0'),'.')).' '.$l['unit_name']); ?></td><td><?php echo esc_html($f($l['unit_price'])); ?></td><td><?php echo esc_html($f((int)$l['discount_amount']+(int)$l['header_discount'])); ?></td><td><?php echo esc_html($f($l['net_amount'])); ?></td><td><?php echo esc_html($f($l['tax_amount'])); ?></td></tr><?php endforeach; ?>
</tbody><tfoot><tr><th colspan="5">مبلغ نهایی (<?php echo esc_html(Money::unitLabel($unit)); ?>)</th><th colspan="2"><?php echo esc_html($f($i['amount'])); ?></th></tr></tfoot></table></div>
<?php if($i['description']!==''): ?><p><?php echo esc_html($i['description']); ?></p><?php endif; ?>
<p class="muted">این پیوند تا <?php echo esc_html(Jalali::format(substr(get_date_from_gmt($d['expires_at']),0,10)).' '.Money::toFa(substr(get_date_from_gmt($d['expires_at']),11,5))); ?> معتبر است و فقط همین برگه را نشان می‌دهد. برای ذخیره، از «چاپ ← ذخیره به PDF» مرورگر استفاده کنید.</p>
<?php endif; ?></main></body></html>
