<?php
/** داشبورد تصویری شرکت؛ همهٔ اعداد از DashboardService و مجوزهای فعلی خوانده می‌شوند. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Ctx,Jalali,Money};
use Agah\Erp\Modules\Dashboard\DashboardService;
if(!defined('ABSPATH'))exit;
$data=DashboardService::summary($cid,\Agah\Erp\Core\FiscalContext::today($cid));
$quick=['sale_invoice.create'=>['فاکتور فروش','invoice_new','sale','＋'],'receipt.create'=>['دریافت','treasury_new','receipt','↓'],'payment.create'=>['پرداخت','treasury_new','payment','↑'],'expense.create'=>['هزینه/درآمد','expenses','','◫'],'voucher.create'=>['سند دستی','voucher_new','','≡']];
$series=['sale'=>['فروش','#74c62b'],'purchase'=>['خرید','#ef9f24'],'expense'=>['هزینه','#e05b38'],'income'=>['درآمد','#46a6dc']];
$visible=[];$max=1;
foreach($series as $key=>$meta)if(!empty($data['visible'][$key])){$visible[$key]=$meta;foreach($data['months'] as $month)$max=max($max,abs((int)$month[$key]));}
?>
<section class="erp-dashboard-head">
  <div><span class="erp-eyebrow">نمای کلی کسب‌وکار</span><h2>داشبورد مدیریتی</h2><p><?php echo esc_html(Jalali::format($data['date'])); ?> · مبالغ به <?php echo Ctx::unit()==='rial'?'ریال':'تومان'; ?></p></div>
  <a class="erp-dashboard-search" href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'global_search'])); ?>"><span aria-hidden="true">⌕</span><span>جست‌وجو در اشخاص، کالا، سند و فاکتور</span></a>
</section>

<nav class="erp-quick-actions erp-no-print" aria-label="عملیات سریع">
<?php foreach($quick as $cap=>$meta)if(Ctx::can($cap)): ?>
  <a href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>$meta[1],'kind'=>$meta[2]])); ?>"><span aria-hidden="true"><?php echo esc_html($meta[3]); ?></span><?php echo esc_html($meta[0]); ?></a>
<?php endif; ?>
</nav>

<div class="erp-metric-grid">
<?php $metricIndex=0;foreach($data['cards'] as $label=>$value):$metricIndex++; ?>
  <section class="erp-metric-card erp-metric-<?php echo (int)(($metricIndex-1)%6+1); ?>">
    <div class="erp-metric-icon" aria-hidden="true"><?php echo esc_html(['↗','↙','◉','◎','⇄','∑'][($metricIndex-1)%6]); ?></div>
    <div><h3><?php echo esc_html($label); ?></h3><p class="big" data-dashboard-value><?php echo esc_html(Money::fmt($value,Ctx::unit())); ?></p><span><?php echo esc_html(Money::unitLabel(Ctx::unit())); ?></span></div>
  </section>
<?php endforeach; ?>
</div>

<div class="erp-dashboard-grid">
  <section class="erp-card erp-chart-card">
    <header><div><span class="erp-eyebrow">روند شش ماه اخیر</span><h3>گردش مالی</h3></div><div class="erp-chart-legend"><?php foreach($visible as $meta): ?><span><i style="--legend-color:<?php echo esc_attr($meta[1]); ?>"></i><?php echo esc_html($meta[0]); ?></span><?php endforeach; ?></div></header>
    <?php if(empty($data['has_records'])): ?>
      <p class="erp-empty">چیزی برای نمایش وجود ندارد</p>
    <?php else: ?>
      <div class="erp-chart-canvas" role="img" aria-label="نمودار گردش مالی شش ماه؛ جزئیات در جدول زیر نمودار آمده است">
        <svg viewBox="0 0 720 300" preserveAspectRatio="none" aria-hidden="true">
          <line x1="30" y1="50" x2="700" y2="50" class="erp-chart-gridline"/><line x1="30" y1="110" x2="700" y2="110" class="erp-chart-gridline"/><line x1="30" y1="170" x2="700" y2="170" class="erp-chart-axis"/>
          <?php foreach($data['months'] as $monthIndex=>$month):$barIndex=0;foreach($visible as $key=>$meta):$value=(int)$month[$key];$height=Money::mulDiv(abs($value),110,$max);$x=48+$monthIndex*108+$barIndex*18;$y=$value>=0?170-$height:170; ?>
            <rect x="<?php echo (int)$x; ?>" y="<?php echo (int)$y; ?>" width="13" height="<?php echo max(2,(int)$height); ?>" rx="3" fill="<?php echo esc_attr($meta[1]); ?>"><title><?php echo esc_html($month['label'].' · '.$meta[0].': '.Money::fmt($value,Ctx::unit())); ?></title></rect>
          <?php $barIndex++;endforeach; ?><text x="<?php echo (int)(62+$monthIndex*108); ?>" y="286" text-anchor="middle"><?php echo esc_html($month['label']); ?></text><?php endforeach; ?>
        </svg>
      </div>
      <details class="erp-chart-data"><summary>نمایش جدول اعداد نمودار</summary><div class="erp-scroll"><table class="erp-table"><thead><tr><th>ماه</th><?php foreach($visible as $meta): ?><th><?php echo esc_html($meta[0]); ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach($data['months'] as $month): ?><tr><td><?php echo esc_html($month['label']); ?></td><?php foreach($visible as $key=>$meta): ?><td><?php echo esc_html(Money::fmt($month[$key],Ctx::unit())); ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div></details>
    <?php endif; ?>
  </section>
  <aside class="erp-dashboard-side">
    <?php require __DIR__.'/finance-notifications.php'; ?>
  </aside>
</div>
