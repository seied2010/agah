<?php
/** نمای مراحل ۴ تا ۶؛ دادهٔ شرکت از سرویس و خروجی صرفاً escapeشده است. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Db,Ctx,Request,Money,Jalali,Crypto,Audit,Settings,Business};
use Agah\Erp\Modules\Parties\PartyService;
use Agah\Erp\Modules\Catalog\CatalogService;
use Agah\Erp\Modules\Treasury\TreasuryService;
if(!defined('ABSPATH')) exit;
?>
<tr><td><label>روش<select name="items[<?php echo esc_attr($i); ?>][method]"><?php foreach(['cash'=>'نقد','card'=>'کارت','transfer'=>'حواله','cheque'=>'چک'] as $key=>$label): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($row['method']??'cash',$key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label><label>حساب خزانه<select name="items[<?php echo esc_attr($i); ?>][treasury_account_id]"><?php foreach($accounts as $key=>$label): ?><option value="<?php echo (int)$key; ?>" <?php selected((string)($row['treasury_account_id']??0),(string)$key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label></td><td><input name="items[<?php echo esc_attr($i); ?>][amount]" value="<?php echo esc_attr($row['amount']??''); ?>" aria-label="مبلغ قلم" inputmode="decimal"></td><td><input name="items[<?php echo esc_attr($i); ?>][reference]" value="<?php echo esc_attr($row['reference']??''); ?>" aria-label="شماره ارجاع" maxlength="80"></td><td><label>شماره چک<input name="items[<?php echo esc_attr($i); ?>][cheque_no]" value="<?php echo esc_attr($row['cheque_no']??''); ?>" maxlength="80"></label><label>سررسید شمسی<input name="items[<?php echo esc_attr($i); ?>][due_date]" value="<?php echo esc_attr($row['due_date']??''); ?>" placeholder="۱۴۰۵/۰۸/۰۱" dir="ltr" data-jalali></label><label>بانک چک<input name="items[<?php echo esc_attr($i); ?>][bank_name]" value="<?php echo esc_attr($row['bank_name']??''); ?>" maxlength="100"></label></td><td><button type="button" class="btn btn-outline btn-sm" data-business-remove>حذف قلم</button></td></tr>
