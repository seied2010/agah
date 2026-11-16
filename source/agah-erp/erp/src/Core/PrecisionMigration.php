<?php
/** Schema9: retain integer arithmetic, convert Rial to hundredth Rial atomically. */
namespace Agah\Erp\Core;
if(!defined('ABSPATH'))exit;
final class PrecisionMigration {
    const MAP=[
        'parties'=>['credit_limit'],'items'=>['sale_price','purchase_price'],
        'vouchers'=>['total_debit','total_credit'],'voucher_lines'=>['debit','credit'],
        'treasury_docs'=>['amount'],'treasury_doc_items'=>['amount'],'cheques'=>['amount'],
        'invoices'=>['amount','discount_amount','extra_cost'],
        'invoice_lines'=>['unit_price','gross_amount','discount_amount','header_discount','net_amount','tax_amount','extra_cost','total_cost'],
        'settlements'=>['amount'],'invoice_advances'=>['amount'],'bank_reconciliations'=>['statement_balance'],
        'stock_moves'=>['cost'],'stock_layers'=>['remaining_value'],'stock_picks'=>['cost'],
        'expense_docs'=>['amount'],'expense_settlements'=>['amount'],
        'contracts'=>['base_salary','benefits','benefits_insurance_exempt','benefits_tax_exempt','overtime_base_extra'],'attendance'=>['extra_benefits','deductions','extra_insurance_exempt','extra_tax_exempt'],
        'payslips'=>['gross','insurance_employee','insurance_employer','tax','deductions','net'],'payslip_items'=>['amount'],
        'assets'=>['cost','residual'],'asset_events'=>['amount','proceeds']
    ];
    public static function scaleValue($value){
        $s=ltrim((string)$value,'-');$limit=intdiv(Money::MAX,100);
        if(!ctype_digit($s)||strlen($s)>strlen((string)$limit)||(int)$s>$limit)throw new \RuntimeException('تبدیل دقت مبلغ از حد مجاز عبور می‌کند؛ ارتقا متوقف شد و داده‌ها تغییر نکردند.');
        return (int)$value*100;
    }
    public static function jsonScale($value){
        if(!is_array($value))return $value;
        $keys=['benefits_insurance_exempt','benefits_tax_exempt','overtime_base_extra','extra_insurance_exempt','extra_tax_exempt','amount','unit_price','gross_amount','discount_amount','header_discount','net_amount','tax_amount','extra_cost','total_cost','base_salary','benefits','extra_benefits','deductions','insurance_ceiling','tax_exemption','upper','base','overtime','gross','insurance_employee','insurance_employer','tax','net','taxable','insured','balance','debit','credit','book_before','proceeds','gain'];
        foreach($value as $k=>&$v){if(is_array($v))$v=self::jsonScale($v);elseif($v!==null&&in_array($k,$keys,true)&&preg_match('/^-?\d+$/D',(string)$v))$v=self::scaleValue($v);}unset($v);return $value;
    }
    public static function run(){
        // The marker and every converted cell commit together; failed/repeated upgrades cannot double values.
        Db::tx(function(){
            Db::exec('INSERT IGNORE INTO '.Db::t('settings')." (company_id,skey,svalue) VALUES (0,'money_storage_scale','1')");
            $scale=Db::val('SELECT svalue FROM '.Db::t('settings')." WHERE company_id=0 AND skey='money_storage_scale' FOR UPDATE");
            if($scale==='100')return;
            if($scale!=='1')throw new \RuntimeException('Unknown money storage scale');
            $companies=Db::col('SELECT id FROM '.Db::t('companies').' ORDER BY id FOR UPDATE');foreach($companies as $id){} // same order as financial writers
            foreach(self::MAP as $table=>$columns){
                foreach($columns as $col){
                    if(Db::val('SELECT id FROM '.Db::t($table).' WHERE ABS(`'.$col.'`)>%d LIMIT 1',intdiv(Money::MAX,100)))throw new \RuntimeException('مبلغ قدیمی برای دقت جدید بزرگ است؛ ارتقا بدون تغییر مبالغ متوقف شد.');
                }
                $set=[];foreach($columns as $col)$set[]='`'.$col.'`=`'.$col.'`*100';Db::exec('UPDATE '.Db::t($table).' SET '.implode(',',$set));
            }
            foreach(['payroll_params'=>'params_json','payslips'=>'snapshot','asset_events'=>'snapshot','year_closings'=>'balance_snapshot'] as $table=>$column){
                $last=0;do{$rows=Db::rows('SELECT id,`'.$column.'` AS payload FROM '.Db::t($table).' WHERE id>%d ORDER BY id LIMIT 100',$last);
                    foreach($rows as $r){$last=(int)$r['id'];$data=json_decode($r['payload'],true);if(!is_array($data))throw new \RuntimeException('Invalid historical financial snapshot');Db::update($table,[$column=>wp_json_encode(self::jsonScale($data))],['id'=>$last]);}
                }while(count($rows)===100);
            }
            foreach($companies as $cid)Audit::log('money.precision','company',(int)$cid,null,['old_scale'=>1,'new_scale'=>100,'schema'=>9],(int)$cid);
            // Audit bytes and hashes are deliberately immutable; pre-Schema9 audit amounts are legacy Rial.
            Db::exec('UPDATE '.Db::t('settings')." SET svalue='100' WHERE company_id=0 AND skey='money_storage_scale'");
        });
    }
}
