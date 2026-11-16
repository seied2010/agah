<?php
/** رسید چاپی تحویل/بازپس‌گیری با کد تأیید؛ و بررسی رسید کاغذی با شماره و کد. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Ctx,Jalali,Money,Request};
use Agah\Erp\Modules\Assets\AssetCustodyService as S;
if(!defined('ABSPATH'))exit;
$r=S::receipt($cid,Request::int('get','id'));$co=Ctx::company();$H=S::HOLDERS;
$verify=Request::str('get','verify_no',12);$verified=$verify!==''?S::verify($cid,$verify,Request::str('get','verify_code',24)):null;
?>
<section class="erp-card agah-receipt"><h2>رسید تحویل اموال — شمارهٔ <?php echo esc_html(Money::toFa((string)$r['receipt_no'])); ?></h2>
<p><?php echo esc_html($co['name']); ?></p>
<dl>
<dt>دارایی</dt><dd><?php echo esc_html($r['asset_code'].' — '.$r['asset_name'].($r['category_name']?' ('.$r['category_name'].')':'')); ?></dd>
<dt>متصدی</dt><dd><?php echo esc_html(($H[$r['holder_type']]??'').': '.$r['holder_name']); ?></dd>
<dt>تاریخ تحویل</dt><dd><?php echo esc_html(Jalali::format($r['effective_date'])); ?></dd>
<dt>تحویل‌دهنده</dt><dd><?php echo esc_html($r['delivered_by']); ?></dd>
<dt>تحویل‌گیرنده</dt><dd><?php echo esc_html($r['received_by']); ?></dd>
<?php if($r['note']!==''): ?><dt>یادداشت</dt><dd><?php echo esc_html($r['note']); ?></dd><?php endif; ?>
<dt>کد تأیید</dt><dd dir="ltr"><?php echo esc_html(S::code($r['receipt_hash'])); ?></dd>
</dl>
<?php if($r['return_receipt_no']): ?><h3>بازپس‌گیری — رسید شمارهٔ <?php echo esc_html(Money::toFa((string)$r['return_receipt_no'])); ?></h3><dl><dt>تاریخ</dt><dd><?php echo esc_html(Jalali::format($r['ended_date'])); ?></dd><dt>تحویل‌گیرنده</dt><dd><?php echo esc_html($r['return_received_by']); ?></dd><dt>کد تأیید</dt><dd dir="ltr"><?php echo esc_html(S::code($r['return_hash'])); ?></dd></dl><?php endif; ?>
<?php if($r['status']==='void'): ?><p class="erp-err">این تحویل باطل شده است: <?php echo esc_html($r['void_reason']); ?></p><?php endif; ?>
<p class="muted">امضای تحویل‌دهنده: ………………… &nbsp; امضای تحویل‌گیرنده: …………………</p>
<button type="button" class="btn btn-outline erp-no-print" data-print>چاپ رسید</button>
<a class="btn btn-outline erp-no-print" href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'asset_custody','tab'=>'history','asset_id'=>$r['asset_id']])); ?>">تاریخچهٔ این دارایی</a>
</section>
<section class="erp-card erp-no-print"><h3>بررسی رسید کاغذی</h3>
<form method="get" action="<?php echo esc_url(agah_erp_url()); ?>" class="erp-filter"><input type="hidden" name="page_id" value="<?php echo (int)get_option('agah_erp_page_id'); ?>"><input type="hidden" name="c" value="<?php echo (int)$cid; ?>"><input type="hidden" name="p" value="asset_receipt"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
<label>شمارهٔ رسید<input name="verify_no" inputmode="numeric" maxlength="12" value="<?php echo esc_attr($verify); ?>"></label><label>کد تأیید<input name="verify_code" dir="ltr" maxlength="24" value="<?php echo esc_attr(Request::str('get','verify_code',24)); ?>"></label><button type="submit" class="btn btn-outline">بررسی</button></form>
<?php if($verify!==''): ?><p class="<?php echo $verified?'':'erp-err'; ?>"><?php echo $verified?'رسید معتبر است (رکورد شمارهٔ '.esc_html(Money::toFa((string)$verified['id'])).'، '.($verified['kind']==='deliver'?'تحویل':'بازپس‌گیری').').':'شماره یا کد با هیچ رسید ثبت‌شده‌ای تطبیق ندارد.'; ?></p><?php endif; ?>
</section>
