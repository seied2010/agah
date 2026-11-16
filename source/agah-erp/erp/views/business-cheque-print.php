<?php
/** برگهٔ چاپ چک؛ داده فقط از سرویس شرکت‌محور خوانده می‌شود. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Ctx,Request,Money,Jalali};
use Agah\Erp\Modules\Treasury\TreasuryService;
if(!defined('ABSPATH'))exit;
$ch=TreasuryService::cheque($cid,Request::int('get','id'));$co=Ctx::company();
?>
<section class="erp-card erp-print-document"><div class="erp-top"><div><h2><?php echo esc_html($ch['direction']==='receipt'?'رسید چک دریافتی':'برگهٔ چک پرداختی'); ?></h2><p><?php echo esc_html($co['name']); ?></p></div><button type="button" class="btn btn-primary erp-no-print" data-print>چاپ / ذخیره PDF</button></div>
<div class="erp-scroll"><table class="erp-table"><tbody>
<tr><th>شماره چک</th><td dir="ltr"><?php echo esc_html($ch['cheque_no']); ?></td><th>بانک</th><td><?php echo esc_html($ch['bank_name']?:$ch['account_name']); ?></td></tr>
<tr><th>در وجه</th><td><?php echo esc_html($ch['party_name']); ?></td><th>سررسید</th><td><?php echo esc_html(Jalali::format($ch['due_date'])); ?></td></tr>
<tr><th>مبلغ</th><td colspan="3"><strong><?php echo esc_html(Money::fmt((int)$ch['amount'],Ctx::unit()).' '.Money::unitLabel(Ctx::unit())); ?></strong></td></tr>
<tr><th>برگهٔ مبدأ</th><td><?php echo esc_html(Money::toFa($ch['doc_number']).' ـ '.Jalali::format($ch['doc_date'])); ?></td><th>وضعیت</th><td><?php echo esc_html(TreasuryService::CHEQUE_STATES[$ch['status']]); ?></td></tr>
<tr><th>شرح</th><td colspan="3"><?php echo esc_html($ch['description']?:'—'); ?></td></tr>
</tbody></table></div><p class="muted">این برگه از اطلاعات ثبت‌شده در خزانه تولید شده است.</p></section>
