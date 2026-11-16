<?php
/** کارتابل ارجاع (TRD-03): ارجاع‌های رسیده و فرستاده، اقدام، تاریخچه و گزارش مدیر. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Ctx,Jalali,Money,Request};
use Agah\Erp\Modules\Trade\{InvoiceService,SalesControlService as S};
if(!defined('ABSPATH'))exit;
$box=S::inbox($cid);$unit=Ctx::unit();
$row=function($r,$mine)use($cid,$unit){
    echo '<tr><td><a href="'.esc_url(agah_erp_url(['c'=>$cid,'p'=>'invoice_view','id'=>$r['invoice_id']])).'">'.esc_html((InvoiceService::KINDS[$r['kind']]??$r['kind']).' '.($r['number']?Money::toFa((string)$r['number']):'(پیش‌نویس)')).'</a></td><td>'.esc_html(Money::fmt((int)$r['amount'],$unit)).'</td><td>'.esc_html(($mine?$r['from_name']:$r['to_name'])?:'—').'</td><td>'.esc_html(S::REF_STATUS[$r['status']]??$r['status']).'</td><td>'.esc_html($r['note'].($r['last_note']?' / '.$r['last_note']:'')).'</td><td>'.esc_html(Jalali::format(substr(get_date_from_gmt($r['created_at']),0,10))).'</td><td>';
    $acts=$mine?['accept'=>'پذیرش','done'=>'انجام شد','return'=>'برگرداندن']:['cancel'=>'لغو'];
    foreach($acts as $a=>$l){if($a==='accept'&&$r['status']!=='open')continue;if(!in_array($r['status'],['open','accepted'],true))continue;echo '<form method="post" class="erp-inline-form">';ModuleController::form('module_referral_act',$cid,'referrals',$r['id']);echo '<input type="hidden" name="action" value="'.esc_attr($a).'">'.($a==='return'?'<input name="note" required minlength="5" maxlength="300" placeholder="علت برگرداندن" aria-label="علت برگرداندن">':'').'<button type="submit" class="btn btn-outline btn-sm">'.esc_html($l).'</button></form> ';}
    echo '</td></tr>';
};
$head='<thead><tr><th>برگه</th><th>مبلغ</th><th>%s</th><th>وضعیت</th><th>یادداشت</th><th>تاریخ</th><th>اقدام</th></tr></thead>';
?>
<section class="erp-card"><h2>کارتابل ارجاع</h2><p class="muted">ارجاع از صفحهٔ هر سفارش، پیش‌فاکتور یا فاکتور ساخته می‌شود. ارجاع باز تکراری به یک نفر ساخته نمی‌شود؛ هر اقدام در تاریخچه و ممیزی ثبت می‌شود.</p>
<h3>رسیده به من</h3><table class="erp-table"><?php printf($head,'از'); ?><tbody><?php foreach($box['to_me'] as $r)$row($r,true); ?></tbody></table>
<h3>فرستاده‌شده توسط من</h3><table class="erp-table"><?php printf($head,'به'); ?><tbody><?php foreach($box['from_me'] as $r)$row($r,false); ?></tbody></table>
</section>
<?php if(Ctx::can('sale_invoice.approve')): $st=Request::str('get','status',10); ?>
<section class="erp-card"><h3>گزارش همهٔ ارجاع‌ها</h3><p><?php foreach([''=>'همه']+S::REF_STATUS as $k=>$l): ?><a class="btn btn-sm <?php echo $st===$k?'btn-primary':'btn-outline'; ?>" href="<?php echo esc_url(add_query_arg('status',$k)); ?>"><?php echo esc_html($l); ?></a> <?php endforeach; ?></p>
<table class="erp-table"><thead><tr><th>برگه</th><th>از</th><th>به</th><th>وضعیت</th><th>یادداشت</th><th>تاریخ</th></tr></thead><tbody><?php foreach(S::report($cid,$st) as $r): ?><tr><td><a href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'invoice_view','id'=>$r['invoice_id']])); ?>"><?php echo esc_html((InvoiceService::KINDS[$r['kind']]??'').' '.Money::toFa((string)$r['number'])); ?></a></td><td><?php echo esc_html((string)$r['from_name']); ?></td><td><?php echo esc_html((string)$r['to_name']); ?></td><td><?php echo esc_html(S::REF_STATUS[$r['status']]??''); ?></td><td><?php echo esc_html($r['note'].($r['last_note']?' / '.$r['last_note']:'')); ?></td><td><?php echo esc_html(Jalali::format(substr(get_date_from_gmt($r['created_at']),0,10))); ?></td></tr><?php endforeach; ?></tbody></table></section>
<?php endif; ?>
