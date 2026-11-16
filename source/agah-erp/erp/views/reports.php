<?php
/** دفاتر و تراز واقعی با ماندهٔ اول، جمع بازه و ماندهٔ نهایی؛ چاپ و خروجی همان فیلتر را دارند. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Db,Ctx,Request,Money,Jalali,Crypto,Audit,Settings};
use Agah\Erp\Modules\Accounting\{AccountService,DetailService,FiscalService,VoucherService,ReportService,ChartSeed};
use Agah\Erp\Modules\Company\{CompanyService,MemberService};
if(!defined('ABSPATH')) exit;
$f=self::filters();$r=ReportService::ledger($cid,$f,Request::int('get','pg',1));
$accounts=Db::rows('SELECT id,code,name FROM '.Db::t('accounts').' WHERE company_id=%d ORDER BY code',$cid);
$details=Db::rows('SELECT id,code,name FROM '.Db::t('details').' WHERE company_id=%d ORDER BY code',$cid);
$summary=in_array($f['mode'],['general','subsidiary','trial2','trial4','trial6','trial8'],true);$trial2=$f['mode']==='trial2';
?>
<section class="erp-card erp-print-document"><h2><?php echo esc_html(Ctx::company()['name'].' — '.ReportService::MODES[$f['mode']]); ?></h2>
<p>فقط اسناد تأییدشده و قطعی؛ واحد نمایش و خروجی: <?php echo Ctx::unit()==='rial'?'ریال':'تومان'; ?>.</p>
<form method="get" action="<?php echo esc_url(agah_erp_url()); ?>" class="erp-filter erp-no-print"><input type="hidden" name="page_id" value="<?php echo (int)get_option('agah_erp_page_id'); ?>"><input type="hidden" name="c" value="<?php echo (int)$cid; ?>"><input type="hidden" name="p" value="reports">
<label>گزارش<select name="mode"><?php foreach(ReportService::MODES as $k=>$label): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($k,$f['mode']); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
<?php foreach(['from'=>'از تاریخ شمسی','to'=>'تا تاریخ شمسی'] as $k=>$label): ?><label><?php echo esc_html($label); ?><input name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr(Request::str('get',$k,10)); ?>" placeholder="۱۴۰۵/۰۱/۰۱" dir="ltr" data-jalali></label><?php endforeach; ?>
<?php foreach(['no_from'=>'از شمارهٔ سند','no_to'=>'تا شمارهٔ سند'] as $k=>$label): ?><label><?php echo esc_html($label); ?><input name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr(Request::str('get',$k,12)); ?>" inputmode="numeric" dir="ltr"></label><?php endforeach; ?>
<label>حساب و زیرمجموعه<select name="account_id"><option value="0">همه</option><?php foreach($accounts as $a): ?><option value="<?php echo (int)$a['id']; ?>" <?php selected($f['account_id'],$a['id']); ?>><?php echo esc_html($a['code'].' — '.$a['name']); ?></option><?php endforeach; ?></select></label>
<label>تفصیلی<select name="detail_id"><option value="0">همه</option><?php foreach($details as $d): ?><option value="<?php echo (int)$d['id']; ?>" <?php selected($f['detail_id'],$d['id']); ?>><?php echo esc_html($d['code'].' — '.$d['name']); ?></option><?php endforeach; ?></select></label><button class="btn btn-primary" type="submit">تهیهٔ گزارش</button></form>
<p>بازه: <?php echo esc_html(($r['from']?Jalali::format($r['from']):'از ابتدا').' تا '.($r['to']?Jalali::format($r['to']):'آخرین ثبت').($r['no_from']||$r['no_to']?' | سند '.($r['no_from']?Money::toFa($r['no_from']):'ابتدا').' تا '.($r['no_to']?Money::toFa($r['no_to']):'انتها'):'')); ?> | مانده پیش از بازه: <?php echo esc_html(Money::fmt($r['opening'],Ctx::unit())); ?></p>
<?php $columns=ReportService::columns($f['mode']); ?>
<div class="erp-scroll"><table class="erp-table"><thead><tr><?php foreach($columns as $label): ?><th><?php echo esc_html($label); ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach($r['rows'] as $row): ?><tr><?php foreach($columns as $key=>$label): ?><td><?php
$value=$row[$key]??'';
if($key==='voucher_date')echo esc_html(Jalali::format($value));
elseif($key==='number')echo '<a href="'.esc_url(agah_erp_url(['c'=>$cid,'p'=>'voucher_view','id'=>$row['voucher_id']])).'">'.esc_html(Money::toFa($value)).'</a>';
elseif(in_array($key,['code','name','detail_name','description'],true))echo esc_html($value);
else echo esc_html(Money::fmt((int)$value,Ctx::unit()));
?></td><?php endforeach; ?></tr><?php endforeach; ?>
<?php if(!$r['rows']): ?><tr><td colspan="<?php echo count($columns); ?>">چیزی برای نمایش وجود ندارد</td></tr><?php endif; ?></tbody></table></div>
<?php if(in_array($f['mode'],['trial6','trial8'],true)): ?><p>افتتاحیه از گردش بازه جداست. شش ستون: مانده پیش از بازه، گردش بازه و مانده پایان؛ هشت ستون: افتتاحیه، گردش پیش از بازه، گردش بازه و مانده پایان، هر کدام بدهکار/بستانکار.</p><?php endif; ?>
<div class="erp-summary"><p>جمع بدهکار بازه: <?php echo esc_html(Money::fmt($r['total']['debit'],Ctx::unit())); ?></p><p>جمع بستانکار بازه: <?php echo esc_html(Money::fmt($r['total']['credit'],Ctx::unit())); ?></p><p>مانده نهایی با احتساب اول دوره: <?php echo esc_html(Money::fmt($r['closing'],Ctx::unit())); ?></p></div>
<?php if(!$f['account_id'] && !$f['detail_id']): ?><p class="<?php echo $r['closing']===0?'erp-ok':'erp-err'; ?>"><?php echo $r['closing']===0?'تراز اسناد متوازن است.':'هشدار: اختلاف تراز اسناد وجود دارد.'; ?></p><?php endif; ?>
<?php self::pages($r['pages'],$r['page']); ?>
<div class="erp-no-print"><button type="button" class="btn btn-outline" data-print>چاپ همین صفحه / ذخیره PDF</button>
<?php if(Ctx::can('report.export')): foreach(['csv'=>'CSV تمام ردیف‌ها','excel'=>'Excel XLSX تمام ردیف‌ها'] as $format=>$label): ?><a class="btn btn-outline" href="<?php echo esc_url(add_query_arg(['export'=>$format,'_n'=>wp_create_nonce('agah_report_export_'.$cid),'pg'=>false])); ?>"><?php echo esc_html($label); ?></a><?php endforeach; endif; ?>
<p>چاپ مربوط به صفحهٔ جاری است؛ خروجی CSV و Excel شامل تمام صفحات است.</p></div></section>
