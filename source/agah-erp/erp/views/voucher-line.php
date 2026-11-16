<?php
/** جزء سطر سند؛ index فقط از کنترلر/الگوی ثابت و همهٔ مقدارها escape می‌شوند. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Db,Ctx,Request,Money,Jalali,Crypto,Audit,Settings};
use Agah\Erp\Modules\Accounting\{AccountService,DetailService,FiscalService,VoucherService,ReportService,ChartSeed};
use Agah\Erp\Modules\Company\{CompanyService,MemberService};
if(!defined('ABSPATH')) exit;
?>
<tr data-line><td><select name="lines[<?php echo esc_attr($i); ?>][account_id]" required data-account aria-label="حساب معین"><option value="">انتخاب حساب</option>
<?php foreach($accounts as $a): ?><option value="<?php echo (int)$a['id']; ?>" data-rule="<?php echo esc_attr($a['detail_rule']); ?>" data-kind="<?php echo esc_attr($a['detail_kind']); ?>" <?php selected((int)($line['account_id']??0),(int)$a['id']); ?>><?php echo esc_html($a['code'].' — '.$a['name']); ?></option><?php endforeach; ?></select></td>
<td><select name="lines[<?php echo esc_attr($i); ?>][detail_id]" data-detail aria-label="تفصیلی"><option value="0">بدون تفصیلی</option><?php foreach($details as $d): ?><option value="<?php echo (int)$d['id']; ?>" data-kind="<?php echo esc_attr($d['kind']); ?>" <?php selected((int)($line['detail_id']??0),(int)$d['id']); ?>><?php echo esc_html($d['code'].' — '.$d['name']); ?></option><?php endforeach; ?></select></td>
<td><input name="lines[<?php echo esc_attr($i); ?>][description]" value="<?php echo esc_attr($line['description']??''); ?>" maxlength="500" aria-label="شرح ردیف"></td>
<td><input name="lines[<?php echo esc_attr($i); ?>][debit]" value="<?php echo esc_attr($line['debit']??''); ?>" inputmode="decimal" dir="ltr" data-debit aria-label="مبلغ بدهکار"></td>
<td><input name="lines[<?php echo esc_attr($i); ?>][credit]" value="<?php echo esc_attr($line['credit']??''); ?>" inputmode="decimal" dir="ltr" data-credit aria-label="مبلغ بستانکار"></td>
<td><button type="button" class="btn btn-outline btn-sm" data-remove-line>حذف ردیف</button><?php self::error('lines.'.$i); ?></td></tr>
