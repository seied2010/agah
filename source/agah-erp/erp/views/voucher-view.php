<?php
/** نمایش و چاپ سند؛ عملیات وضعیت هرکدام مجوز، nonce، نسخه و idempotency مستقل دارند. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Db,Ctx,Request,Money,Jalali,Crypto,Audit,Settings};
use Agah\Erp\Modules\Accounting\{AccountService,DetailService,FiscalService,VoucherService,ReportService,ChartSeed};
use Agah\Erp\Modules\Company\{CompanyService,MemberService};
if(!defined('ABSPATH')) exit;
$v=VoucherService::get($cid,Request::int('get','id'));
?>
<section class="erp-card erp-print-document erp-voucher-document"><h2><?php echo esc_html(Ctx::company()['name']); ?> — سند حسابداری</h2>
<dl class="erp-metadata"><dt>شماره</dt><dd><?php echo esc_html($v['number']===null?'پیش‌نویس بدون شماره':Settings::get('voucher_prefix',$cid).Money::toFa($v['number'])); ?></dd><dt>تاریخ</dt><dd><?php echo esc_html(Jalali::format($v['voucher_date'])); ?></dd><dt>وضعیت</dt><dd><?php echo esc_html(VoucherService::STATES[$v['status']]); ?></dd><dt>شرح</dt><dd><?php echo esc_html($v['description']); ?></dd><dt>شناسهٔ یکتا</dt><dd dir="ltr"><?php echo esc_html($v['uuid']); ?></dd></dl>
<?php if(strpos($v['source_type'],'treasury_')===0 && Ctx::can(substr($v['source_type'],9).'.view')): ?><p class="erp-no-print"><a href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'treasury_view','id'=>$v['source_id']])); ?>">مشاهدهٔ برگهٔ خزانهٔ مبدأ</a></p><?php endif; ?>
<?php if($v['source_type']): ?><p>منبع: <?php echo esc_html($v['source_type'].' #'.$v['source_id']); ?> — عملیات از برگهٔ مبدأ انجام می‌شود.</p><?php endif; ?>
<div class="erp-scroll" role="region" aria-label="جدول سند حسابداری" tabindex="0"><table class="erp-table erp-voucher-table"><colgroup><col style="width:5%"><col style="width:10%"><col style="width:19%"><col style="width:17%"><col style="width:21%"><col style="width:14%"><col style="width:14%"></colgroup><thead><tr><th>ردیف</th><th>کد</th><th>حساب</th><th>تفصیلی</th><th>شرح</th><th>بدهکار</th><th>بستانکار</th></tr></thead><tbody>
<?php foreach($v['lines'] as $l): ?><tr><td><?php echo esc_html(Money::toFa($l['line_no'])); ?></td><td><?php echo esc_html(Money::toFa($l['account_code'])); ?></td><td><?php echo esc_html($l['account_name']); ?></td><td><?php echo esc_html($l['detail_name']?:'—'); ?></td><td><?php echo esc_html($l['description']); ?></td><td><?php echo esc_html(Money::fmt((int)$l['debit'],Ctx::unit())); ?></td><td><?php echo esc_html(Money::fmt((int)$l['credit'],Ctx::unit())); ?></td></tr><?php endforeach; ?>
</tbody><tfoot><tr><th colspan="5">جمع (<?php echo Ctx::unit()==='rial'?'ریال':'تومان'; ?>)</th><td><?php echo esc_html(Money::fmt((int)$v['total_debit'],Ctx::unit())); ?></td><td><?php echo esc_html(Money::fmt((int)$v['total_credit'],Ctx::unit())); ?></td></tr></tfoot></table></div>
<p class="<?php echo $v['total_debit']===$v['total_credit']?'erp-ok':'erp-err'; ?>">اختلاف: <?php echo esc_html(Money::fmt(Money::add((int)$v['total_debit'],-(int)$v['total_credit']),Ctx::unit())); ?></p>
<div class="erp-voucher-signatures"><?php foreach(['voucher_sign_1','voucher_sign_2','voucher_sign_3'] as $key): ?><div><strong><?php echo esc_html(Settings::get($key,$cid)); ?></strong><span>نام و امضا</span></div><?php endforeach; ?></div>
<?php if($v['status_reason']): ?><p>دلیل تغییر وضعیت: <?php echo esc_html($v['status_reason']); ?></p><?php endif; ?>
<div class="erp-no-print"><button type="button" class="btn btn-outline" data-print>چاپ / ذخیره PDF با مرورگر</button>
<?php if(!$v['source_type']): ?>
<?php if(Ctx::can('voucher.edit') && in_array($v['status'],['draft','approved'],true)): ?><a class="btn btn-outline" href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'voucher_edit','id'=>$v['id']])); ?>">ویرایش سند</a><?php endif; ?>
<?php $actions=['draft'=>['approve'=>['تأیید و تخصیص شماره','voucher.approve']],'approved'=>['finalize'=>['قطعی‌کردن','voucher.finalize'],'void'=>['ابطال','voucher.void']],'final'=>['reopen'=>['بازگشایی','voucher.reopen'],'void'=>['ابطال','voucher.void']]];
foreach($actions[$v['status']]??[] as $action=>$meta): if(!Ctx::can($meta[1])) continue; ?>
<form method="post" class="erp-inline-form"><?php self::form('module_voucher_state',$cid,'voucher_view',$v['id']); ?><input type="hidden" name="revision" value="<?php echo (int)$v['revision']; ?>"><input type="hidden" name="transition" value="<?php echo esc_attr($action); ?>">
<?php if(in_array($action,['reopen','void'],true)): ?><label>دلیل <?php echo esc_html($meta[0]); ?><input name="reason" required minlength="5" maxlength="300"></label><?php endif; ?><button type="submit" class="btn btn-primary"><?php echo esc_html($meta[0]); ?></button></form><?php endforeach; ?>
<?php if($v['status']==='draft' && Ctx::can('voucher.delete')): ?><form method="post" class="inline"><?php self::form('module_voucher_delete',$cid,'voucher_view',$v['id']); ?><input type="hidden" name="revision" value="<?php echo (int)$v['revision']; ?>"><button type="submit" class="btn btn-outline">حذف پیش‌نویس</button></form><?php endif; endif; ?>
<?php if(Ctx::can('voucher.create')): ?><form method="post" class="erp-inline-form"><?php self::form('module_voucher_copy',$cid,'voucher_view',$v['id']); ?><button type="submit" class="btn btn-outline">کپی به پیش‌نویس جدید</button></form><?php endif; ?>
<p><a href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'vouchers'])); ?>">بازگشت به فهرست</a></p></div></section>

<?php if(\Agah\Erp\Core\Ctx::can('file.view')): ?><p class="erp-no-print"><a class="btn btn-outline" href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'files','target_type'=>'voucher','target_id'=>Request::int('get','id')])); ?>">پیوست‌های خصوصی / افزودن فایل</a></p><?php endif; ?>
