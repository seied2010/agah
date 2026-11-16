<?php
/** نمای مراحل۱۰–۱۲؛ داده واقعی از سرویس، فرم با PRG مشترک، خروجی escape و فونت محلی است. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Business,Ctx,Db,Jalali,Money,Request,Settings};
use Agah\Erp\Modules\Expenses\ExpenseService;
use Agah\Erp\Modules\Accounting\{FinancialService,ClosingService,FiscalService};
use Agah\Erp\Modules\Dashboard\DashboardService;
if(!defined('ABSPATH'))exit;
$runs=ClosingService::page($cid);$years=FiscalService::all($cid);$options=[];foreach($years as $year)if($year['status']==='open')$options[$year['id']]=$year['title'];
?>
<section class="erp-card"><h2>بستن حساب‌ها و افتتاحیه سال بعد</h2><p>همهٔ پیش‌نویس‌ها تعیین تکلیف شوند؛ دوره آخر سال باز باشد. سال جدید باید بدون گردش باشد. تعدیل ادواری، بستن موقت، انتقال سود، اختتامیه و افتتاحیه با هم ثبت می‌شوند. وجود مغایرت انبار در دائمی مانع بستن است.</p><form method="post" class="erp-form"><?php self::form('module_year_close',$cid,'year_closing');self::select('year_id','سال مالی',$options);self::input('reason','دلیل و یادداشت بستن','','text','required minlength="5"'); ?><button type="submit" class="btn btn-primary">بستن و ساخت افتتاحیه</button></form></section>
<section class="erp-card"><h3>سابقه و بازگشایی کنترل‌شده</h3><?php foreach($runs as $r):$year=FiscalService::get($cid,(int)$r['fiscal_year_id']); ?><article><h4><?php echo esc_html($year['title'].' — '.($r['status']==='closed'?'بسته':'بازگشایی‌شده')); ?></h4><p><?php echo esc_html($r['reason']); ?></p><?php foreach(['inventory_voucher_id'=>'تعدیل ادواری','profit_voucher_id'=>'بستن موقت','result_voucher_id'=>'انتقال سود','closing_voucher_id'=>'اختتامیه','opening_voucher_id'=>'افتتاحیه'] as $key=>$label)if($r[$key]&&Ctx::can('voucher.view'))echo '<a class="btn btn-outline" href="'.esc_url(agah_erp_url(['c'=>$cid,'p'=>'voucher_view','id'=>$r[$key]])).'">'.esc_html($label).'</a>'; ?><?php if($r['status']==='closed'): ?><form method="post" class="erp-form"><?php self::form('module_year_reopen',$cid,'year_closing',$r['id']);self::input('reason','دلیل بازگشایی','','text','required minlength="5"'); ?><button type="submit" class="btn btn-outline">بازگشایی با برگشت اسناد وابسته</button></form><?php endif; ?></article><?php endforeach; ?></section>
