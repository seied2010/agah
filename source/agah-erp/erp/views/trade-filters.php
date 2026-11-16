<?php
/** نمای فارسی مراحل ۷–۹؛ زمینه از کنترلر و مقادیر مالی از سرویس دریافت می‌شوند؛ خروجی escape می‌شود. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Db,Ctx,Request,Money,Jalali,Settings,Business};
use Agah\Erp\Modules\Trade\InvoiceService;
use Agah\Erp\Modules\Inventory\StockService;
if(!defined('ABSPATH')) exit;
?>
<form method="get" class="erp-filter erp-no-print"><input type="hidden" name="page_id" value="<?php echo (int)get_option('agah_erp_page_id'); ?>"><input type="hidden" name="c" value="<?php echo (int)$cid; ?>"><input type="hidden" name="p" value="<?php echo esc_attr($p); ?>">
<?php if(in_array($p,['trade_report','trade_aging'],true)): ?><label>نوع گزارش<select name="kind"><option value="sale" <?php selected($kind,'sale'); ?>>فروش و مطالبات</option><option value="purchase" <?php selected($kind,'purchase'); ?>>خرید و بدهی</option></select></label><?php endif; ?>
<?php if(!in_array($p,['trade_aging','purchase_aging'],true)): ?><label>از تاریخ شمسی<input name="from" data-jalali value="<?php echo esc_attr(Request::str('get','from',10)); ?>"></label><label>تا تاریخ شمسی<input name="to" data-jalali value="<?php echo esc_attr(Request::str('get','to',10)); ?>"></label><?php endif; ?>
<?php if(in_array($p,['trade_report','purchase_report'],true)): ?><label>گروه‌بندی<select name="group"><?php foreach(['party'=>'شخص','item'=>'کالا/خدمت','seller'=>'ثبت‌کننده','period'=>'ماه میلادی'] as $key=>$label): ?><option value="<?php echo esc_attr($key); ?>" <?php selected(Request::str('get','group',10,'item'),$key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label><?php elseif(!in_array($p,['trade_aging','purchase_aging'],true)): ?><label>وضعیت<select name="status"><option value="">همه</option><?php foreach(['draft'=>'پیش‌نویس','issued'=>'صادرشده','void'=>'باطل'] as $key=>$label): ?><option value="<?php echo esc_attr($key); ?>" <?php selected(Request::str('get','status',10),$key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label><label>شخص<select name="party_id"><option value="0">همه</option><?php foreach(Db::rows('SELECT id,name FROM '.Db::t('parties').' WHERE company_id=%d ORDER BY name',$cid) as $party): ?><option value="<?php echo (int)$party['id']; ?>" <?php selected(Request::int('get','party_id'),$party['id']); ?>><?php echo esc_html($party['name']); ?></option><?php endforeach; ?></select></label><?php endif; ?>
<button class="btn btn-outline" type="submit">اعمال فیلتر</button></form>
