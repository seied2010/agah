<?php
namespace Agah\Erp\Core;
if(!defined('ABSPATH'))exit;
/** The only supported historical change is the additive schema 21 -> 22 transition. */
final class BackupUpgrade {
    public static function normalize(array $data,array $metadata) {
        if((string)($data['schema']??'')!=='21' || Schema::VERSION!=='22')return $data;
        foreach(['invoices'=>['deleted_at'=>null],'company_members'=>['invoice_delete_override'=>'-1']] as $table=>$defaults){
            $current=$metadata[$table]['columns']??[];$old=array_values(array_diff($current,array_keys($defaults)));
            $snapshot=$data['tables'][$table]??null;
            if(!$current || !is_array($snapshot) || ($snapshot['columns']??null)!==$old || !is_array($snapshot['rows']??null))throw new ValidationException(['backup'=>'ساختار پشتیبان نسخهٔ قبلی با مسیر ارتقا تطبیق ندارد.']);
            $rows=[];
            foreach($snapshot['rows'] as $row){
                if(!is_array($row)||count($row)!==count($old)||array_keys($row)!==range(0,count($old)-1))throw new ValidationException(['backup'=>'ردیف پشتیبان نسخهٔ قبلی ناقص است.']);
                $values=array_combine($old,$row);$next=[];
                foreach($current as $column)$next[]=array_key_exists($column,$values)?$values[$column]:($defaults[$column]===null?null:base64_encode($defaults[$column]));
                $rows[]=$next;
            }
            $data['tables'][$table]=['columns'=>$current,'rows'=>$rows];
        }
        $data['schema']=Schema::VERSION;return $data;
    }
}
