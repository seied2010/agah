<?php
/** نمای فارسی مراحل ۷–۹؛ زمینه از کنترلر و مقادیر مالی از سرویس دریافت می‌شوند؛ خروجی escape می‌شود. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Db,Ctx,Request,Money,Jalali,Settings,Business};
use Agah\Erp\Modules\Trade\InvoiceService;
use Agah\Erp\Modules\Inventory\StockService;
if(!defined('ABSPATH')) exit;
$kind=strpos($p,'purchase_')===0?'purchase':Request::str('get','kind',24,'sale');$f=TradeController::filters();$data=InvoiceService::report($cid,$kind,Request::str('get','group',10,'item'),$f['from']?:substr(Jalali::today(),0,4).'-01-01',$f['to']?:Jalali::today(),Request::int('get','pg',1));
?>
<section class="erp-card"><h2>گزارش خالص <?php echo $kind==='sale'?'فروش':'خرید'; ?></h2><p>برگشت‌ها از مبالغ کم می‌شوند. مبلغ خالص بدون مالیات و هزینه جانبی است؛ سود ناخالص فروش از بهای ثبت‌شدهٔ همان خط محاسبه می‌شود.</p><?php require __DIR__.'/trade-filters.php';TradeController::exportLink($cid,'report',$kind); ?><div class="erp-scroll"><table class="erp-table"><thead><tr><th>گروه</th><th>مقدار</th><th>خالص</th><th>مالیات</th><th>بها</th><?php if($kind==='sale'): ?><th>سود ناخالص</th><?php endif; ?></tr></thead><tbody><?php foreach($data['rows'] as $r): ?><tr><td><?php echo esc_html($r['label']); ?></td><td><?php echo esc_html(Money::toFa(Money::qtyToDb((int)$r['qty']))); ?></td><?php foreach(['net','tax','cost'] as $key): ?><td><?php echo esc_html(Money::fmt((int)$r[$key],Ctx::unit())); ?></td><?php endforeach; ?><?php if($kind==='sale'): ?><td><?php echo esc_html(Money::fmt($r['profit'],Ctx::unit())); ?></td><?php endif; ?></tr><?php endforeach; ?></tbody></table></div><?php self::pages($data['pages'],$data['page']); ?></section>
