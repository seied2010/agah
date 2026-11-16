<?php
/** بدهکاران و بستانکاران تجاری در بازهٔ از/تا. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Ctx,Jalali,Money,Request};
use Agah\Erp\Modules\Accounting\FinancialService;
if(!defined('ABSPATH'))exit;
[$from,$to]=FinanceController::dates();$data=FinancialService::parties($cid,$from,$to,Request::int('get','pg',1));
$side=function($v){return $v>0?'بدهکار':($v<0?'بستانکار':'—');};
?>
<section class="erp-card"><h2>بدهکاران و بستانکاران تجاری</h2><form method="get" class="erp-filter"><input type="hidden" name="page_id" value="<?php echo (int)get_option('agah_erp_page_id'); ?>"><input type="hidden" name="c" value="<?php echo $cid; ?>"><input type="hidden" name="p" value="financial_parties"><label>از تاریخ شمسی<input name="from" value="<?php echo esc_attr(Jalali::format($from,false)); ?>" data-jalali></label><label>تا تاریخ شمسی<input name="to" value="<?php echo esc_attr(Jalali::format($to,false)); ?>" data-jalali></label><button type="submit" class="btn btn-outline">محاسبه</button></form><?php FinanceController::exportLink($cid,'parties'); ?>
<p>بازه: <?php echo esc_html(Jalali::format($from).' تا '.Jalali::format($to)); ?>؛ واحد: <?php echo esc_html(Money::unitLabel(Ctx::unit())); ?>. حساب‌های دریافتنی و پرداختنی تجاری و اسناد آن‌ها، فقط اسناد تأییدشده و قطعی.</p>
<div class="erp-scroll"><table class="erp-table"><thead><tr><th>کد / شخص</th><th>ماندهٔ اول دوره</th><th>گردش بدهکار</th><th>گردش بستانکار</th><th>ماندهٔ پایان دوره</th><th>تشخیص</th></tr></thead><tbody><?php foreach($data['rows'] as $r): ?><tr><td><?php echo esc_html($r['code'].' — '.$r['name']); ?></td><td><?php echo esc_html(Money::fmt(abs($r['opening']),Ctx::unit()).' '.$side($r['opening'])); ?></td><td><?php echo esc_html(Money::fmt($r['debit'],Ctx::unit())); ?></td><td><?php echo esc_html(Money::fmt($r['credit'],Ctx::unit())); ?></td><td><?php echo esc_html(Money::fmt(abs($r['closing']),Ctx::unit())); ?></td><td><?php echo esc_html($side($r['closing'])); ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php self::pages($data['pages'],$data['page']); ?></section>
