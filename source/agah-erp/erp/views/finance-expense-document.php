<?php
/** نمای مراحل۱۰–۱۲؛ داده واقعی از سرویس، فرم با PRG مشترک، خروجی escape و فونت محلی است. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Business,Ctx,Db,Jalali,Money,Request,Settings};
use Agah\Erp\Modules\Expenses\ExpenseService;
use Agah\Erp\Modules\Accounting\{FinancialService,ClosingService,FiscalService};
use Agah\Erp\Modules\Dashboard\DashboardService;
if(!defined('ABSPATH'))exit;
$d=ExpenseService::get($cid,Request::int('get','id'));$category=Business::row('expense_categories',$cid,(int)$d['category_id']);$party=Business::row('parties',$cid,(int)$d['party_id']);
?>
<section class="erp-card"><h2><?php echo esc_html(($d['kind']==='expense'?'هزینه':'درآمد').' #'.Money::toFa($d['number'])); ?></h2><p>وضعیت: <?php echo $d['status']==='issued'?'صادرشده':'باطل'; ?></p><p><?php echo esc_html($category['name'].' / '.$party['name'].' / '.Jalali::format($d['doc_date'])); ?></p><p><?php echo esc_html($d['description']); ?></p><p>مبلغ: <?php echo esc_html(Money::fmt((int)$d['amount'],Ctx::unit())); ?> | تسویه‌شده: <?php echo esc_html(Money::fmt($d['paid'],Ctx::unit())); ?> | مانده: <strong><?php echo esc_html(Money::fmt($d['outstanding'],Ctx::unit())); ?></strong></p>
<?php foreach(['project'=>'پروژه','branch'=>'شعبه','center'=>'مرکز هزینه'] as $kind=>$label)if($d[$kind.'_id'])echo '<p>'.esc_html($label.': '.Business::row('cost_dimensions',$cid,(int)$d[$kind.'_id'])['name']).'</p>'; ?>
<?php if(Ctx::can('voucher.view')): ?><a href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'voucher_view','id'=>$d['voucher_id']])); ?>">سند حسابداری مبدأ</a><?php endif; ?><button type="button" class="btn btn-outline erp-no-print" data-print>چاپ</button>
<?php if(Ctx::can('file.view')): ?><h3>تصاویر خصوصی رسید</h3><?php foreach($d['images'] as $img): ?><p><a href="<?php echo esc_url(add_query_arg(['finance_image'=>$img['id'],'_n'=>wp_create_nonce('agah_finance_image_'.$cid.'_'.$img['id'])])); ?>">دانلود تصویر <?php echo esc_html(Money::toFa($img['id'])); ?></a></p><?php endforeach; ?><?php endif; ?></section>
<?php if($d['status']==='issued'&&$d['outstanding']&&Ctx::can('expense.edit')): ?><section class="erp-card erp-no-print"><h3>تسویه مانده</h3><form method="post" class="erp-form"><?php self::form('module_expense_pay',$cid,'expense_view',$d['id']);self::input('doc_date','تاریخ تسویه',Jalali::format(\Agah\Erp\Core\FiscalContext::today($cid),false),'text','required data-jalali');require __DIR__.'/finance-payment-fields.php'; ?><button type="submit" class="btn btn-primary">ثبت دریافت / پرداخت</button></form></section><?php endif; ?>
<?php if(Ctx::can('expense.edit')&&Ctx::can('file.create')): ?><section class="erp-card erp-no-print"><form method="post" enctype="multipart/form-data" class="erp-form"><?php self::form('module_expense_image',$cid,'expense_view',$d['id']); ?><label>تصویر رسید (JPEG/PNG/WebP تا دو مگابایت)<input type="file" name="image" accept="image/jpeg,image/png,image/webp" required></label><button type="submit" class="btn btn-outline">افزودن تصویر خصوصی</button></form></section><?php endif; ?>
<?php if($d['status']==='issued'&&Ctx::can('expense.delete')): ?><section class="erp-card erp-no-print"><form method="post" class="erp-form"><?php self::form('module_expense_void',$cid,'expense_view',$d['id']);self::input('reason','دلیل ابطال','','text','required minlength="5"'); ?><button type="submit" class="btn btn-outline">ابطال برگه و تسویه‌ها</button></form></section><?php endif; ?>

<?php if(\Agah\Erp\Core\Ctx::can('file.view')): ?><p class="erp-no-print"><a class="btn btn-outline" href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'files','target_type'=>'expense','target_id'=>\Agah\Erp\Core\Request::int('get','id')])); ?>">پیوست‌های خصوصی / افزودن فایل</a></p><?php endif; ?>
