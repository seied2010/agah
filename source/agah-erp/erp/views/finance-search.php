<?php
/** نمای مراحل۱۰–۱۲؛ داده واقعی از سرویس، فرم با PRG مشترک، خروجی escape و فونت محلی است. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Business,Ctx,Db,Jalali,Money,Request,Settings};
use Agah\Erp\Modules\Expenses\ExpenseService;
use Agah\Erp\Modules\Accounting\{FinancialService,ClosingService,FiscalService};
use Agah\Erp\Modules\Dashboard\DashboardService;
if(!defined('ABSPATH'))exit;
$q=Request::str('get','q',100);$results=$q?DashboardService::search($cid,$q):[];
?>
<section class="erp-card"><h2>جست‌وجوی سراسری شرکت</h2><form method="get" class="erp-filter"><input type="hidden" name="page_id" value="<?php echo (int)get_option('agah_erp_page_id'); ?>"><input type="hidden" name="c" value="<?php echo $cid; ?>"><input type="hidden" name="p" value="global_search"><label>نام، کد، شماره یا شرح<input name="q" value="<?php echo esc_attr($q); ?>" minlength="1" maxlength="100" required></label><button class="btn btn-primary" type="submit">جست‌وجو</button></form><p>حداکثر ۱۲ نتیجه از هر نوع مجاز؛ دادهٔ شرکت دیگر جست‌وجو نمی‌شود.</p><ul class="erp-list"><?php foreach($results as $r):$args=['c'=>$cid,'p'=>$r['page']];foreach(['id','edit'] as $key)if(isset($r[$key]))$args[$key]=$r[$key]; ?><li><a href="<?php echo esc_url(agah_erp_url($args)); ?>"><?php echo esc_html($r['title']); ?></a></li><?php endforeach; ?></ul></section>
