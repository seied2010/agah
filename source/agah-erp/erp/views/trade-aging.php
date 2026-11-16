<?php
/** نمای فارسی مراحل ۷–۹؛ زمینه از کنترلر و مقادیر مالی از سرویس دریافت می‌شوند؛ خروجی escape می‌شود. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Db,Ctx,Request,Money,Jalali,Settings,Business};
use Agah\Erp\Modules\Trade\InvoiceService;
use Agah\Erp\Modules\Inventory\StockService;
if(!defined('ABSPATH')) exit;
$kind=strpos($p,'purchase_')===0?'purchase':Request::str('get','kind',24,'sale');$data=InvoiceService::aging($cid,$kind,Jalali::today(),Request::int('get','pg',1));
?>
<section class="erp-card"><h2>ماندهٔ باز و سررسیدهای <?php echo $kind==='sale'?'مطالبات':'بدهی'; ?></h2><p>ماندهٔ فعلی پس از کسر برگشت، تسویه و پیش‌پرداخت؛ سن بدهی تا امروز محاسبه می‌شود.</p><?php require __DIR__.'/trade-filters.php';TradeController::exportLink($cid,'aging',$kind); ?><div class="erp-scroll"><table class="erp-table"><thead><tr><th>فاکتور</th><th>شخص</th><th>سررسید</th><th>مانده</th><th>گروه سنی</th></tr></thead><tbody><?php foreach($data['rows'] as $r): ?><tr><td><a href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'invoice_view','id'=>$r['id']])); ?>"><?php echo esc_html(Money::toFa($r['number'])); ?></a></td><td><?php echo esc_html($r['party_name']); ?></td><td><?php echo esc_html(Jalali::format($r['due_date'])); ?></td><td><?php echo esc_html(Money::fmt($r['outstanding'],Ctx::unit())); ?></td><td><?php echo esc_html($r['bucket']); ?></td></tr><?php endforeach; ?></tbody></table></div><?php self::pages($data['pages'],$data['page']); ?></section>
