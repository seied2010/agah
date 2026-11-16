<?php
/** نمای مراحل ۴ تا ۶؛ دادهٔ شرکت از سرویس و خروجی صرفاً escapeشده است. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Db,Ctx,Request,Money,Jalali,Crypto,Audit,Settings,Business};
use Agah\Erp\Modules\Parties\PartyService;
use Agah\Erp\Modules\Catalog\CatalogService;
use Agah\Erp\Modules\Treasury\TreasuryService;
if(!defined('ABSPATH')) exit;
$d=TreasuryService::get($cid,Request::int('get','id'));$title=TreasuryService::KINDS[$d['kind']];
?>
<section class="erp-card erp-print-document"><h2><?php echo esc_html($title.' شمارهٔ '.Money::toFa($d['number'])); ?></h2><p>تاریخ: <?php echo esc_html(Jalali::format($d['doc_date'])); ?> | وضعیت: <?php echo $d['status']==='issued'?'صادرشده':'باطل'; ?></p><p><?php echo esc_html($d['description']); ?></p><p>مبلغ: <?php echo esc_html(Money::fmt((int)$d['amount'],Ctx::unit())); ?> <?php echo Ctx::unit()==='rial'?'ریال':'تومان'; ?><?php if($d['advance'])echo ' — پیش‌دریافت/پیش‌پرداخت'; ?></p>
<?php if(Ctx::can('voucher.view')): ?><a href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'voucher_view','id'=>$d['voucher_id']])); ?>">مشاهدهٔ سند حسابداری مبدأ</a><?php endif; ?>
<div class="erp-scroll"><table class="erp-table"><thead><tr><th>روش</th><th>حساب</th><th>مبلغ</th><th>ارجاع</th></tr></thead><tbody><?php foreach($d['items'] as $l): ?><tr><td><?php echo esc_html(['cash'=>'نقد','card'=>'کارت','transfer'=>'حواله','cheque'=>'چک'][$l['method']]); ?></td><td><?php echo esc_html($l['account_name']); ?></td><td><?php echo esc_html(Money::fmt((int)$l['amount'],Ctx::unit())); ?></td><td><?php echo esc_html($l['reference']); ?></td></tr><?php endforeach; ?></tbody></table></div>
<h3>چک‌های برگه</h3><?php foreach($d['cheques'] as $ch): ?><p><?php echo esc_html($ch['cheque_no'].' / '.Jalali::format($ch['due_date']).' / '.TreasuryService::CHEQUE_STATES[$ch['status']]); ?></p><?php endforeach; ?>
<h3>تخصیص‌های تسویه</h3><?php foreach($d['settlements'] as $s): ?><p><?php echo esc_html('فاکتور #'.Money::toFa($s['invoice_id']).' / '.Money::fmt((int)$s['amount'],Ctx::unit()).' / '.($s['status']==='active'?'فعال':'برگشت‌شده')); ?></p><?php endforeach; ?>
<?php if($d['status']==='issued'&&Ctx::can($d['kind'].'.delete')): ?><form method="post" class="erp-form erp-no-print"><?php self::form('module_treasury_void',$cid,'treasury_view',$d['id']); ?><input type="hidden" name="kind" value="<?php echo esc_attr($d['kind']); ?>"><?php self::input('reason','دلیل ابطال','','text','required minlength="5" maxlength="300"'); ?><button type="submit" class="btn btn-outline">ابطال برگه، اسناد و تسویه‌ها</button></form><?php endif; ?><button type="button" class="btn btn-outline erp-no-print" data-print>چاپ برگه</button></section>
