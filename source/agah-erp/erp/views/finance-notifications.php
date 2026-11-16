<?php
/** نمای مراحل۱۰–۱۲؛ داده واقعی از سرویس، فرم با PRG مشترک، خروجی escape و فونت محلی است. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Business,Ctx,Db,Jalali,Money,Request,Settings};
use Agah\Erp\Modules\Expenses\ExpenseService;
use Agah\Erp\Modules\Accounting\{FinancialService,ClosingService,FiscalService};
use Agah\Erp\Modules\Dashboard\DashboardService;
if(!defined('ABSPATH'))exit;
$alerts=isset($alerts)?$alerts:DashboardService::notifications($cid);
?>
<section class="erp-card"><h2>مرکز اعلان‌ها</h2><p>تا ۲۵ مورد از هر گروه، بر اساس اطلاعات ثبت‌شده و دسترسی شما؛ ارسال پیامک/ایمیل انجام نمی‌شود.</p><?php if(!$alerts): ?><p>در محدودهٔ دسترسی شما مورد فعالی ثبت نشده است.</p><?php else: ?><ul class="erp-list"><?php foreach($alerts as $a):$args=['c'=>$cid,'p'=>$a['page']];foreach(['id','warehouse_id','item_id'] as $key)if(isset($a[$key]))$args[$key]=$a[$key]; ?><li><a href="<?php echo esc_url(agah_erp_url($args)); ?>"><?php echo esc_html($a['title']); ?></a><?php if($a['date'])echo ' — '.esc_html(Jalali::format($a['date']));if(isset($a['amount']))echo ' / '.esc_html(Money::fmt($a['amount'],Ctx::unit())); ?></li><?php endforeach; ?></ul><?php endif; ?></section>
