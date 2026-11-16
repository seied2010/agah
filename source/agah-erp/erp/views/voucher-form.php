<?php
/** فرم ثبت/ویرایش سند؛ محاسبهٔ مرورگر فقط راهنماست، تمام قیود در VoucherService بررسی می‌شوند. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Db,Ctx,Request,Money,Jalali,Crypto,Audit,Settings};
use Agah\Erp\Modules\Accounting\{AccountService,DetailService,FiscalService,VoucherService,ReportService,ChartSeed};
use Agah\Erp\Modules\Company\{CompanyService,MemberService};
if(!defined('ABSPATH')) exit;
$id=$p==='voucher_edit'?Request::int('get','id'):0;$v=$id?VoucherService::get($cid,$id):[];
if($id && (!in_array($v['status'],['draft','approved'],true) || $v['source_type'])) throw new \Agah\Erp\Core\ConflictException('این سند قابل ویرایش دستی نیست.');
$accounts=Db::rows('SELECT id,code,name,detail_rule,detail_kind FROM '.Db::t('accounts').' WHERE company_id=%d AND level=3 AND is_active=1 ORDER BY code',$cid);
$details=Db::rows('SELECT id,code,name,kind FROM '.Db::t('details').' WHERE company_id=%d AND is_active=1 ORDER BY code',$cid);
$lines=self::value('lines',null);
if(!is_array($lines)) {
    $lines=$v['lines']??[['account_id'=>0,'detail_id'=>0,'debit'=>'','credit'=>'','description'=>''],['account_id'=>0,'detail_id'=>0,'debit'=>'','credit'=>'','description'=>'']];
    if($id) foreach($lines as &$line) { $line['debit']=Money::fmt((int)$line['debit'],Ctx::unit(),false);$line['credit']=Money::fmt((int)$line['credit'],Ctx::unit(),false); }unset($line);
}
?>
<section class="erp-card"><h2><?php echo $id?'ویرایش سند':'سند حسابداری جدید'; ?></h2><p>واحد ورودی: <strong><?php echo Ctx::unit()==='rial'?'ریال':'تومان'; ?></strong>. فقط حساب معین فعال پذیرفته می‌شود. پیش‌نویس می‌تواند نامتوازن باشد و وارد دفاتر نمی‌شود.</p>
<form method="post" class="erp-form erp-voucher-form" data-unit="<?php echo esc_attr(Ctx::unit()); ?>">
<?php self::form('module_voucher_save',$cid,$id?'voucher_edit':'voucher_new',$id); ?><input type="hidden" name="revision" value="<?php echo (int)($v['revision']??0); ?>">
<?php self::input('voucher_date','تاریخ شمسی',isset($v['voucher_date'])?Jalali::format($v['voucher_date'],false):Jalali::format(\Agah\Erp\Core\FiscalContext::today($cid),false),'text','required placeholder="۱۴۰۵/۰۷/۲۶" dir="ltr" data-jalali');self::select('type','نوع سند',['normal'=>'عادی','opening'=>'افتتاحیه دستی','closing'=>'اختتامیه دستی','adjustment'=>'تعدیل'],$v['type']??'normal');self::input('description','شرح سند',$v['description']??'','text','maxlength="500"'); ?>
<div class="erp-scroll erp-entry-scroll" role="region" aria-label="ردیف‌های سند حسابداری" tabindex="0"><table class="erp-table erp-lines erp-voucher-lines"><colgroup><col style="width:24%"><col style="width:18%"><col style="width:22%"><col style="width:14%"><col style="width:14%"><col style="width:8%"></colgroup><thead><tr><th>حساب معین</th><th>تفصیلی</th><th>شرح ردیف</th><th>بدهکار</th><th>بستانکار</th><th>عملیات</th></tr></thead><tbody data-lines>
<?php foreach(array_values($lines) as $i=>$line): require AGAH_ERP_DIR.'/views/voucher-line.php';endforeach; ?>
</tbody></table></div><p class="erp-totals" aria-live="polite" data-totals></p>
<p><?php self::error('lines'); ?></p>
<button type="button" class="btn btn-outline" data-add-line>افزودن ردیف (Alt+N)</button><button type="submit" class="btn btn-primary">ذخیرهٔ سند (Alt+S)</button>
</form>
<template data-line-template><?php $i='__INDEX__';$line=['account_id'=>0,'detail_id'=>0,'description'=>'','debit'=>'','credit'=>''];require AGAH_ERP_DIR.'/views/voucher-line.php'; ?></template>
<p><a href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'vouchers'])); ?>">بازگشت به اسناد</a></p></section>
