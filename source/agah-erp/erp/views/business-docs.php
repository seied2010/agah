<?php
/** نمای مراحل ۴ تا ۶؛ دادهٔ شرکت از سرویس و خروجی صرفاً escapeشده است. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Db,Ctx,Request,Money,Jalali,Crypto,Audit,Settings,Business};
use Agah\Erp\Modules\Parties\PartyService;
use Agah\Erp\Modules\Catalog\CatalogService;
use Agah\Erp\Modules\Treasury\TreasuryService;
if(!defined('ABSPATH')) exit;
$kind=['receipts'=>'receipt','payments'=>'payment','transfers'=>'transfer'][$p];$data=TreasuryService::documents($cid,$kind,Request::int('get','pg',1),(int)(\Agah\Erp\Core\FiscalContext::selected($cid)['id']??0));
?>
<?php CommerceController::exportLink($cid,$p); ?>
<section class="erp-card"><div class="erp-top"><h2>برگه‌های <?php echo esc_html(TreasuryService::KINDS[$kind]); ?></h2><?php if(Ctx::can($kind.'.create')): ?><a class="btn btn-primary" href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'treasury_new','kind'=>$kind])); ?>">ثبت <?php echo esc_html(TreasuryService::KINDS[$kind]); ?></a><?php endif; ?></div><div class="erp-scroll"><table class="erp-table"><thead><tr><th>شماره</th><th>تاریخ</th><th>شرح</th><th>مبلغ</th><th>وضعیت</th></tr></thead><tbody><?php foreach($data['rows'] as $d): ?><tr><td><a href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'treasury_view','id'=>$d['id']])); ?>"><?php echo esc_html(Money::toFa($d['number'])); ?></a></td><td><?php echo esc_html(Jalali::format($d['doc_date'])); ?></td><td><?php echo esc_html($d['description']); ?></td><td><?php echo esc_html(Money::fmt((int)$d['amount'],Ctx::unit())); ?></td><td><?php echo $d['status']==='issued'?'صادرشده':'باطل'; ?></td></tr><?php endforeach; ?></tbody></table></div><?php self::pages($data['pages'],$data['page']); ?></section>
