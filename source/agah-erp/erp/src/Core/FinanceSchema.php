<?php
/** Schema6 افزایشی: هزینه، ابعاد سازمانی، تصویر خصوصی و چرخهٔ بستن سال؛ روابط همگی شرکت‌محورند. */
namespace Agah\Erp\Core;
if(!defined('ABSPATH'))exit;
final class FinanceSchema {
    public static function tables(){return ['cost_dimensions','expense_categories','expense_docs','expense_images','expense_settlements','year_closings'];}
    public static function ddl($p,$c){
        $defs=[
            'cost_dimensions'=>"kind varchar(12) NOT NULL,\n  code varchar(30) NOT NULL,\n  name varchar(190) NOT NULL,\n  detail_id bigint(20) unsigned DEFAULT NULL,\n  is_active tinyint(1) NOT NULL DEFAULT 1,\n  UNIQUE KEY company_code (company_id,kind,code),",
            'expense_categories'=>"kind varchar(10) NOT NULL,\n  code varchar(30) NOT NULL,\n  name varchar(190) NOT NULL,\n  account_id bigint(20) unsigned NOT NULL,\n  is_active tinyint(1) NOT NULL DEFAULT 1,\n  UNIQUE KEY company_code (company_id,code),",
            'expense_docs'=>"kind varchar(10) NOT NULL,\n  category_id bigint(20) unsigned NOT NULL,\n  fiscal_year_id bigint(20) unsigned NOT NULL,\n  number int(10) unsigned NOT NULL,\n  doc_date date NOT NULL,\n  due_date date NOT NULL,\n  amount bigint(20) unsigned NOT NULL,\n  party_id bigint(20) unsigned DEFAULT NULL,\n  treasury_account_id bigint(20) unsigned DEFAULT NULL,\n  project_id bigint(20) unsigned DEFAULT NULL,\n  branch_id bigint(20) unsigned DEFAULT NULL,\n  center_id bigint(20) unsigned DEFAULT NULL,\n  description varchar(500) NOT NULL DEFAULT '',\n  status varchar(10) NOT NULL DEFAULT 'issued',\n  voucher_id bigint(20) unsigned DEFAULT NULL,\n  created_by bigint(20) unsigned NOT NULL,\n  UNIQUE KEY company_number (company_id,fiscal_year_id,number),\n  KEY company_date (company_id,status,kind,doc_date),",
            'expense_images'=>"expense_id bigint(20) unsigned NOT NULL,\n  mime varchar(40) NOT NULL,\n  sha256 char(64) NOT NULL,\n  byte_size int(10) unsigned NOT NULL,\n  image_data mediumblob NOT NULL,\n  created_by bigint(20) unsigned NOT NULL,\n  KEY company_expense (company_id,expense_id),",
            'expense_settlements'=>"expense_id bigint(20) unsigned NOT NULL,\n  doc_id bigint(20) unsigned NOT NULL,\n  doc_item_id bigint(20) unsigned NOT NULL,\n  amount bigint(20) unsigned NOT NULL,\n  UNIQUE KEY company_item (company_id,doc_item_id),",
            'year_closings'=>"fiscal_year_id bigint(20) unsigned NOT NULL,\n  next_year_id bigint(20) unsigned NOT NULL,\n  active_slot tinyint(1) unsigned DEFAULT 1,\n  status varchar(12) NOT NULL DEFAULT 'closed',\n  inventory_voucher_id bigint(20) unsigned DEFAULT NULL,\n  profit_voucher_id bigint(20) unsigned DEFAULT NULL,\n  result_voucher_id bigint(20) unsigned DEFAULT NULL,\n  closing_voucher_id bigint(20) unsigned DEFAULT NULL,\n  opening_voucher_id bigint(20) unsigned DEFAULT NULL,\n  periods_snapshot longtext NOT NULL,\n  balance_snapshot longtext NOT NULL,\n  reason varchar(300) NOT NULL,\n  reopen_reason varchar(300) NOT NULL DEFAULT '',\n  created_by bigint(20) unsigned NOT NULL,\n  UNIQUE KEY company_active (company_id,fiscal_year_id,active_slot),",
        ];$out=[];
        foreach($defs as $name=>$body)$out[]="CREATE TABLE {$p}{$name} (\n  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  uuid char(36) NOT NULL,\n  company_id bigint(20) unsigned NOT NULL,\n  $body\n  created_at datetime NOT NULL,\n  updated_at datetime NOT NULL,\n  PRIMARY KEY  (id),\n  UNIQUE KEY uuid (uuid),\n  UNIQUE KEY company_id_id (company_id,id)\n) $c;";
        return $out;
    }
    public static function fks(){
        $out=[];foreach(self::tables() as $table)$out[]=[$table,'fk_f_'.$table.'_company',['company_id'],'companies',['id'],'RESTRICT'];
        $refs=['cost_dimensions'=>['detail_id'=>'details'],'expense_categories'=>['account_id'=>'accounts'],'expense_docs'=>['category_id'=>'expense_categories','fiscal_year_id'=>'fiscal_years','party_id'=>'parties','treasury_account_id'=>'treasury_accounts','project_id'=>'cost_dimensions','branch_id'=>'cost_dimensions','center_id'=>'cost_dimensions','voucher_id'=>'vouchers'],'expense_images'=>['expense_id'=>'expense_docs'],'expense_settlements'=>['expense_id'=>'expense_docs','doc_id'=>'treasury_docs','doc_item_id'=>'treasury_doc_items'],'year_closings'=>['fiscal_year_id'=>'fiscal_years','next_year_id'=>'fiscal_years','inventory_voucher_id'=>'vouchers','profit_voucher_id'=>'vouchers','result_voucher_id'=>'vouchers','closing_voucher_id'=>'vouchers','opening_voucher_id'=>'vouchers']];
        foreach($refs as $table=>$columns)foreach($columns as $column=>$parent)$out[]=[$table,'fk_f_'.$table.'_'.$column,['company_id',$column],$parent,['company_id','id'],'RESTRICT'];return $out;
    }
}
