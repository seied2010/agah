<?php

/** Preparation and reconciliation only. No government submission or statutory-rate assumptions. */
namespace Agah\Erp\Modules\Legal;
use Agah\Erp\Core\{Access,Audit,Business,Crypto,Ctx,Db,Money,Request,Settings,ValidationException};
if(!defined('ABSPATH'))exit;
final class LegalService {
    const NOTICE='نیازمند بازبینی حسابدار رسمی';
    public static function range($from,$to){
        Business::date($from);
        Business::date($to);
        if($from>$to)throw new ValidationException(['date'=>'بازه گزارش معکوس است.']);
    }
    private static function trade($cid){
        Access::need($cid,'legal.view');
        foreach(['sale_invoice','sale_return','purchase_invoice','purchase_return'] as $r)Ctx::need($r.'.view');
    }
    public static function configure($cid,$mode,$note){
        return Access::write($cid,'legal.edit',function()use($cid,$mode,$note){
            if(!in_array($mode,['review','all','none'],true))throw new ValidationException(['credit'=>'روش بررسی اعتبار خرید معتبر نیست.']);$note=Request::clean($note,500);if($mode!=='review'&&mb_strlen($note)<5)throw new ValidationException(['note'=>'مبنای تصمیم حسابدار را ثبت کنید.']);$old=['mode'=>Settings::get('legal_vat_credit_mode',$cid),'note'=>Settings::get('legal_vat_credit_note',$cid)];Settings::set('legal_vat_credit_mode',$mode,$cid);Settings::set('legal_vat_credit_note',$note,$cid);Audit::log('legal.vat.configure','legal',0,$old,['mode'=>$mode,'note'=>$note],$cid);
        }
        );
    }
    public static function vat($cid,$from,$to){
        self::trade($cid);
        self::range($from,$to);
        return Db::tx(function()use($cid,$from,$to){
            $rows=Db::rows('SELECT i.kind,CASE WHEN l.tax_rate_id IS NULL THEN %s WHEN l.tax_bp=0 THEN %s ELSE %s END tax_group,SUM(CAST(l.net_amount AS SIGNED)) net,SUM(CAST(l.tax_amount AS SIGNED)) tax,SUM(CAST(l.extra_cost AS SIGNED)) extra,COUNT(*) line_count FROM '.Db::t('invoices').' i JOIN '.Db::t('invoice_lines')." l ON l.company_id=i.company_id AND l.invoice_id=i.id WHERE i.company_id=%d AND i.status='issued' AND i.kind IN ('sale','sale_return','purchase','purchase_return') AND i.invoice_date BETWEEN %s AND %s GROUP BY i.kind,tax_group ORDER BY i.kind,tax_group",'unrated','zero','taxed',$cid,$from,$to); $tot=['sale_net'=>0,'purchase_net'=>0,'output_tax'=>0,'input_tax'=>0];foreach($rows as &$r){
                $return=strpos($r['kind'],'_return')!==false;$buy=strpos($r['kind'],'purchase')===0;$sign=$return?-1:1;foreach(['net','tax','extra'] as $k)$r[$k]=$sign*self::number($r[$k]);$r['line_count']=(int)$r['line_count'];$k=$buy?'purchase_net':'sale_net';$tot[$k]=Money::add($tot[$k],$r['net']);$k=$buy?'input_tax':'output_tax';$tot[$k]=Money::add($tot[$k],$r['tax']);
            }
            unset($r); $ledger=self::vatLedger($cid,$from,$to);$mode=Settings::get('legal_vat_credit_mode',$cid);$credit=$mode==='all'?$tot['input_tax']:($mode==='none'?0:null);$net=$credit===null?null:Money::add($tot['output_tax'],-$credit); return ['notice'=>self::NOTICE,'rows'=>$rows,'totals'=>$tot,'credit_mode'=>$mode,'credit_note'=>Settings::get('legal_vat_credit_note',$cid),'credit'=>$credit,'net_candidate'=>$net,'ledger'=>$ledger,'source_output_difference'=>Money::add($tot['output_tax'],-$ledger['source_output']),'source_input_difference'=>Money::add($tot['input_tax'],-$ledger['source_input'])];
        }
        );
    }
    private static function vatLedger($cid,$from,$to){
        $rows=Db::rows('SELECT a.sys_key,v.source_type,SUM(CAST(l.debit AS SIGNED)-CAST(l.credit AS SIGNED)) amount FROM '.Db::t('vouchers').' v JOIN '.Db::t('voucher_lines').' l ON l.company_id=v.company_id AND l.voucher_id=v.id JOIN '.Db::t('accounts')." a ON a.company_id=l.company_id AND a.id=l.account_id WHERE v.company_id=%d AND v.status IN ('approved','final') AND v.voucher_date BETWEEN %s AND %s AND a.sys_key IN ('vat_input','vat_output') AND v.source_type NOT IN ('year_open','year_close','year_profit','year_result') AND v.type<>'opening' GROUP BY a.sys_key,v.source_type",$cid,$from,$to);
        $out=['input'=>0,'output'=>0,'source_input'=>0,'source_output'=>0];
        foreach($rows as $r){
            $k=$r['sys_key']==='vat_input'?'input':'output';
            $amount=self::number($r['amount'])*($k==='input'?1:-1);
            $out[$k]=Money::add($out[$k],$amount);
            if(in_array($r['source_type'],['invoice_sale','invoice_sale_return','invoice_purchase','invoice_purchase_return'],true))$out['source_'.$k]=Money::add($out['source_'.$k],$amount);
        }
        return $out;
    }
    private static function number($value){
        return Money::integer($value);
    }
    public static function transactions($cid,$from,$to,$page=1,$full=false){
        self::trade($cid);
        Ctx::need('party.view');
        self::range($from,$to);
        if($full){
            Ctx::need('legal.export');
            Ctx::need('party.sensitive');
        }
        $page=max(1,(int)$page);
        $where="i.company_id=%d AND i.status='issued' AND i.kind IN ('sale','sale_return','purchase','purchase_return') AND i.invoice_date BETWEEN %s AND %s";
        $count=(int)Db::val('SELECT COUNT(*) FROM '.Db::t('invoices').' i WHERE '.$where,$cid,$from,$to);
        $rows=Db::rows('SELECT i.id,i.kind,i.number,i.fiscal_year_id,i.invoice_date,i.amount,i.party_id,i.print_snapshot,p.name party_name,p.kind party_kind,p.national_id_hint,p.national_id_enc,p.economic_code FROM '.Db::t('invoices').' i JOIN '.Db::t('parties').' p ON p.company_id=i.company_id AND p.id=i.party_id WHERE '.$where.' ORDER BY i.invoice_date,i.id LIMIT 50 OFFSET %d',$cid,$from,$to,($page-1)*50);
        foreach($rows as &$r){
            $snapshot=json_decode($r['print_snapshot']??'',true);
            $party=$snapshot['party']??[];
            $amounts=Db::row('SELECT COALESCE(SUM(net_amount),0) net,COALESCE(SUM(tax_amount),0) tax,COALESCE(SUM(extra_cost),0) extra FROM '.Db::t('invoice_lines').' WHERE company_id=%d AND invoice_id=%d',$cid,$r['id']);
            $sign=strpos($r['kind'],'_return')!==false?-1:1;
            $r['net']=$sign*self::number($amounts['net']);
            $r['tax']=$sign*self::number($amounts['tax']);
            $r['extra']=$sign*self::number($amounts['extra']);
            $r['amount']=$sign*(int)$r['amount'];
            $r['name']=$party['name']??$r['party_name'];
            $r['economic_code']=$party['economic_code']??$r['economic_code'];
            $r['national_id']=$full?Crypto::decrypt($r['national_id_enc']):($party['national_id_hint']??$r['national_id_hint']);
            $r['identity_basis']=$full?'current_identity':(isset($snapshot['party'])&&array_key_exists('national_id_hint',$party)&&array_key_exists('economic_code',$party)?'issued_hint':'current_fallback');
            $r['missing_identity']=$r['national_id']===''&&$r['economic_code']==='';
            $r['notice']=self::NOTICE;
            unset($r['national_id_enc'],$r['print_snapshot'],$r['party_name']);
        }
        unset($r);
        return ['rows'=>$rows,'count'=>$count,'page'=>$page,'pages'=>(int)ceil($count/50),'notice'=>self::NOTICE];
    }
    public static function payroll($cid,$from,$to,$page=1){
        Access::need($cid,'legal.view');
        Ctx::need('payroll.view');
        self::range($from,$to);
        $page=max(1,(int)$page);
        return Db::tx(function()use($cid,$from,$to,$page){
            $where="company_id=%d AND status='issued' AND doc_date BETWEEN %s AND %s";$tot=Db::row('SELECT COUNT(*) count,COALESCE(SUM(gross),0) gross,COALESCE(SUM(net),0) net,COALESCE(SUM(tax),0) tax,COALESCE(SUM(insurance_employee),0) insurance_employee,COALESCE(SUM(insurance_employer),0) insurance_employer FROM '.Db::t('payslips').' WHERE '.$where,$cid,$from,$to);foreach($tot as $k=>$v)$tot[$k]=self::number($v); $rows=Db::rows('SELECT id,number,period_key,doc_date,snapshot,gross,net,tax,insurance_employee,insurance_employer,voucher_id FROM '.Db::t('payslips').' WHERE '.$where.' ORDER BY doc_date,id LIMIT 50 OFFSET %d',$cid,$from,$to,($page-1)*50);foreach($rows as &$r){
                $s=json_decode($r['snapshot'],true);$r['employee_name']=$s['employee']['name'];$r['employee_code']=$s['employee']['code'];$r['taxable']=$s['calculation']['taxable'];$r['notice']=self::NOTICE;unset($r['snapshot']);
            }
            unset($r); $ledger=self::number(Db::val('SELECT COALESCE(SUM(CAST(l.credit AS SIGNED)-CAST(l.debit AS SIGNED)),0) FROM '.Db::t('payslips').' p JOIN '.Db::t('vouchers')." v ON v.company_id=p.company_id AND v.id=p.voucher_id JOIN ".Db::t('voucher_lines')." l ON l.company_id=v.company_id AND l.voucher_id=v.id WHERE p.company_id=%d AND p.status='issued' AND p.doc_date BETWEEN %s AND %s AND v.status IN ('approved','final') AND l.account_id=CAST(JSON_UNQUOTE(JSON_EXTRACT(p.snapshot,'$.params.tax_account_id')) AS UNSIGNED)",$cid,$from,$to));
            // If the configured tax account is shared with insurance/deductions, show the combined ledger and mark ambiguity.
            $shared=(int)Db::val('SELECT COUNT(*) FROM '.Db::t('payslips').' WHERE '.$where." AND (JSON_EXTRACT(snapshot,'$.params.tax_account_id')=JSON_EXTRACT(snapshot,'$.params.insurance_account_id') OR JSON_EXTRACT(snapshot,'$.params.tax_account_id')=JSON_EXTRACT(snapshot,'$.params.deduction_account_id') OR JSON_EXTRACT(snapshot,'$.params.tax_account_id')=JSON_EXTRACT(snapshot,'$.params.salary_account_id'))",$cid,$from,$to); return ['rows'=>$rows,'totals'=>$tot,'ledger_tax_account'=>$ledger,'difference'=>Money::add($tot['tax'],-$ledger),'shared_account_count'=>$shared,'page'=>$page,'pages'=>(int)ceil($tot['count']/50),'count'=>$tot['count'],'notice'=>self::NOTICE];
        }
        );
    }
}
