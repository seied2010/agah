<?php
/** اشتراک شرکت (SUB-01..07): وضعیت، تاریخچه، خرید/تمدید/ارتقا با مبلغ سروری و اعلام قیمت دورهٔ بعد. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Ctx,Jalali,Money,Request};
use Agah\Erp\Modules\Subscription\SubscriptionService as S;
if(!defined('ABSPATH'))exit;
$st=S::state($cid);$h=S::history($cid);$t=fn($v)=>Money::fmt((int)$v,'toman').' تومان';
$labels=['active'=>'فعال','off'=>'اشتراک برای این نصب لازم نیست','legacy'=>'شرکت پیشین؛ تا تعیین طرح انتقال آزاد','expired'=>'منقضی؛ ثبت تازه بسته است (دیدن و خروجی آزاد)'];
?>
<section class="erp-card"><h2>اشتراک آگاه</h2>
<p>وضعیت: <b><?php echo esc_html($labels[$st['state']]); ?></b><?php if($st['sub']): ?> — طرح: <?php echo esc_html(S::PLANS[$st['sub']['plan_code']][0]??$st['sub']['plan_code']); ?> — اعتبار تا <?php echo esc_html(Jalali::format($st['sub']['end_date'])); ?><?php if($st['state']==='active'): ?> (<?php echo esc_html(Money::toFa((string)$st['days'])); ?> روز مانده)<?php endif; endif; ?></p>
<p class="muted">اسناد و فاکتورها سقف تعداد ندارند. هر اشتراک برای یک شرکت است. تمدید با پرداخت تازه انجام می‌شود (برداشت خودکار نداریم) و قیمت دورهٔ خریداری‌شده تا پایان همان دوره ثابت می‌ماند.</p>
<?php if(Request::int('get','ordered')): ?><p class="notice ok">سفارش ثبت شد. پرداخت آنلاین روی این نصب فعال نیست؛ برای پرداخت با مدیر سامانه هماهنگ کنید.</p><?php endif; ?>
<?php if(Ctx::can('company_settings.edit')): ?>
<h3>خرید، تمدید یا ارتقا</h3><table class="erp-table"><thead><tr><th>طرح</th><th>دوره</th><th>مبلغ این دوره</th><th>شرح</th><th></th></tr></thead><tbody>
<?php foreach(['specialist','combined'] as $plan)foreach(array_keys(S::PERIODS) as $period): try{$q=S::quote($cid,$plan,$period);}catch(\Agah\Erp\Core\AppException $e){$q=null;} if(!$q)continue; ?>
<tr><td><?php echo esc_html(S::PLANS[$plan][0]); ?></td><td><?php echo esc_html(S::PERIODS[$period][0]); ?></td><td><b><?php echo esc_html($t($q['amount'])); ?></b><?php if($q['festival']): ?> <span class="notice ok">جشنوارهٔ ده مشتری نخست</span><?php endif; ?></td>
<td><?php echo esc_html('از '.Jalali::format($q['start']).' تا '.Jalali::format($q['end']).($q['credit']?' — اعتبار باقی‌ماندهٔ طرح فعلی: '.$t($q['credit']):'').' — قیمت فهرست: '.$t($q['list_amount']).($q['next_price']!==$q['list_amount']?' — قیمت دورهٔ بعد: '.$t($q['next_price']):'')); ?></td>
<td><form method="post" class="erp-inline-form"><?php self::form('module_sub_order',$cid,'subscription'); ?><input type="hidden" name="plan" value="<?php echo esc_attr($plan); ?>"><input type="hidden" name="period" value="<?php echo esc_attr($period); ?>"><?php if($plan==='specialist'): ?><select name="activity" aria-label="نوع فعالیت"><?php foreach(['trading'=>'بازرگانی','manufacturing'=>'تولیدی','services'=>'خدماتی'] as $k=>$l): ?><option value="<?php echo esc_attr($k); ?>" <?php selected(\Agah\Erp\Core\Settings::get('activity_type',$cid),$k); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?></select><?php endif; ?><button type="submit" class="btn btn-primary btn-sm">سفارش و پرداخت</button></form></td></tr>
<?php endforeach; ?></tbody></table>
<p class="muted">طرح سازمانی (نصب روی سرور خریدار) توافقی است و از راه مدیر سامانه ثبت می‌شود.</p>
<?php endif; ?>
<h3>تاریخچه</h3><table class="erp-table"><thead><tr><th>طرح</th><th>دوره</th><th>از</th><th>تا</th><th>مبلغ</th><th>وضعیت</th><th>منبع</th></tr></thead><tbody><?php foreach($h['subs'] as $s): ?><tr><td><?php echo esc_html(S::PLANS[$s['plan_code']][0]??$s['plan_code']); ?></td><td><?php echo esc_html(S::PERIODS[$s['period']][0]??$s['period']); ?></td><td><?php echo esc_html($s['start_date']?Jalali::format($s['start_date']):''); ?></td><td><?php echo esc_html($s['end_date']?Jalali::format($s['end_date']):''); ?></td><td><?php echo esc_html($t($s['amount']).($s['festival']?' (جشنواره)':'')); ?></td><td><?php echo esc_html(S::STATUS[$s['status']]??$s['status']); ?></td><td><?php echo esc_html(['woo'=>'درگاه','cod'=>'پرداخت در محل','manual'=>'مدیر سامانه'][$s['source']]??$s['source']); ?></td></tr><?php endforeach; ?></tbody></table>
</section>
