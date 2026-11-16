<?php
namespace Agah\Mobile;
use Agah\Erp\Core\ValidationException;
if (!defined('ABSPATH')) exit;
/** Deliberate JSON DTO boundary; clients cannot supply server-owned state or nested scalar fields. */
final class Payload {
    private const HEADERS=[
        'parties'=>'name kind is_customer is_supplier is_employee is_active credit_limit economic_code phone email address national_id clear_national_id postal_code registration_no',
        'items'=>'name code kind unit_id group_id tax_rate_id sale_account_id purchase_account_id barcode is_active sale_price purchase_price trade_price attrs',
        'invoices'=>'kind party_id invoice_date due_date parent_id description discount_amount extra_cost cost_basis price_reason lines',
        'treasury'=>'kind doc_date party_id description advance refund refund_of_doc_id source_account_id target_account_id amount items allocations',
        'expenses'=>'category_id party_id doc_date due_date amount description project_id branch_id center_id payment',
        'vouchers'=>'voucher_date description lines',
        'stock'=>'kind doc_date description lines'
    ];
    private const LINES=[
        'invoices'=>'item_id warehouse_id qty_scaled unit_price discount_amount source_line_id',
        'treasury'=>'method treasury_account_id amount reference due_date cheque_no bank_name',
        'expenses'=>'method treasury_account_id amount reference due_date cheque_no bank_name',
        'vouchers'=>'account_id detail_id debit credit description',
        'stock'=>'warehouse_id target_warehouse_id item_id qty_scaled cost'
    ];
    private static function scalars(array $in,$allowed,$scope) {
        $allowed=explode(' ',$allowed);
        foreach ($in as $key=>$value) {
            if (!is_string($key)||!in_array($key,$allowed,true)) throw new ValidationException([$scope=>'فیلد خارج از قرارداد ارسال شده است.']);
            if (in_array($key,['is_customer','is_supplier','is_employee','is_active','clear_national_id','advance'],true)) {
                if (!is_bool($value)) throw new ValidationException([$key=>'مقدار بله/خیر لازم است.']);
            } elseif (!is_string($value)||strlen($value)>4096) throw new ValidationException([$key=>'مقدار متنی معتبر لازم است.']);
        }
    }
    public static function validate($entity,array $in) {
        if (!isset(self::HEADERS[$entity])) throw new ValidationException(['entity'=>'نوع اطلاعات معتبر نیست.']);
        $arrays=['lines','items','payment','allocations']; $scalars=$in;
        foreach ($arrays as $key) unset($scalars[$key]);
        self::scalars($scalars,self::HEADERS[$entity],'body');
        foreach ($arrays as $key) if (array_key_exists($key,$in)) {
            if (!in_array($key,explode(' ',self::HEADERS[$entity]),true)||!is_array($in[$key])||count($in[$key])>200) throw new ValidationException([$key=>'فهرست ردیف‌ها معتبر نیست.']);
            foreach ($in[$key] as $row) {
                if (!is_array($row)) throw new ValidationException([$key=>'هر ردیف باید یک شیء معتبر باشد.']);
                self::scalars($row,$key==='allocations'?'invoice_id amount':(self::LINES[$entity]??''),$key);
            }
        }
        return $in;
    }
    public static function action(array $in) {
        self::scalars($in,'reason','body');
        return $in;
    }
}
