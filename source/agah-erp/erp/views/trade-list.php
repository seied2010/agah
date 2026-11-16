<?php
/** نمای فارسی مراحل ۷–۹؛ زمینه از کنترلر و مقادیر مالی از سرویس دریافت می‌شوند؛ خروجی escape می‌شود. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Db,Ctx,Request,Money,Jalali,Settings,Business};
use Agah\Erp\Modules\Trade\InvoiceService;
use Agah\Erp\Modules\Inventory\StockService;
if(!defined('ABSPATH')) exit;
$kind=substr($p,6);$data=InvoiceService::page($cid,$kind,TradeController::filters(),Request::int('get','pg',1));$res=InvoiceService::resource($kind);
?>
<section class="erp-card"><h2><?php echo esc_html(InvoiceService::KINDS[$kind]); ?></h2>
<?php if(Ctx::can($res.'.create')): ?><a class="btn btn-primary" href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'invoice_new','kind'=>$kind])); ?>">برگه جدید</a><?php endif; ?>
<?php TradeController::exportLink($cid,'list',$kind);require __DIR__.'/trade-filters.php'; ?>
<p>تعداد: <?php echo esc_html(Money::toFa($data['count'])); ?> | جمع برگه‌های فیلترشده: <?php echo esc_html(Money::fmt($data['total'],Ctx::unit())); ?></p>
<div class="erp-scroll"><table class="erp-table"><thead><tr><th>شماره</th><th>شخص</th><th>تاریخ</th><th>سررسید</th><th>مبلغ</th><th>وضعیت</th></tr></thead><tbody>
<?php foreach($data['rows'] as $r): ?><tr><td><a href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'invoice_view','id'=>$r['id']])); ?>"><?php echo esc_html($r['number']===null?'پیش‌نویس #'.Money::toFa($r['id']):Money::toFa($r['number'])); ?></a></td><td><?php echo esc_html($r['party_name']); ?></td><td><?php echo esc_html(Jalali::format($r['invoice_date'])); ?></td><td><?php echo esc_html(Jalali::format($r['due_date'])); ?></td><td><?php echo esc_html(Money::fmt((int)$r['amount'],Ctx::unit())); ?></td><td><?php echo esc_html(['draft'=>'پیش‌نویس','issued'=>'صادرشده','void'=>'باطل'][$r['status']]); ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php self::pages($data['pages'],$data['page']); ?></section>
