<?php
/** نمای فارسی مراحل ۷–۹؛ زمینه از کنترلر و مقادیر مالی از سرویس دریافت می‌شوند؛ خروجی escape می‌شود. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Db,Ctx,Request,Money,Jalali,Settings,Business};
use Agah\Erp\Modules\Trade\InvoiceService;
use Agah\Erp\Modules\Inventory\StockService;
if(!defined('ABSPATH')) exit;
?>
<tr><td><label>کالا<select name="lines[<?php echo esc_attr($idx); ?>][item_id]"><?php foreach($items as $key=>$label): ?><option value="<?php echo (int)$key; ?>" <?php selected($row['item_id']??0,$key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label></td><td><?php foreach(['warehouse_id'=>'انبار','target_warehouse_id'=>'مقصد انتقال'] as $field=>$caption): ?><label><?php echo esc_html($caption); ?><select name="lines[<?php echo esc_attr($idx); ?>][<?php echo esc_attr($field); ?>]"><?php foreach($warehouses as $key=>$label): ?><option value="<?php echo (int)$key; ?>" <?php selected($row[$field]??0,$key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label><?php endforeach; ?></td><td><label>مقدار<input name="lines[<?php echo esc_attr($idx); ?>][qty]" value="<?php echo esc_attr($row['qty']??''); ?>" inputmode="decimal" required></label></td><td><label>بهای کل<input name="lines[<?php echo esc_attr($idx); ?>][cost]" value="<?php echo esc_attr($row['cost']??''); ?>" inputmode="decimal"></label></td><td><button type="button" class="btn btn-outline" data-business-remove>حذف ردیف</button></td></tr>
