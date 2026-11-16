<?php
namespace Agah\Erp\Core;
if(!defined('ABSPATH'))exit;
/** Additive manufacturing records; company-scoped references also protect direct database writes. */
final class ManufacturingSchema {

    public static function tables(){
        return ['production_boms','production_bom_lines','production_orders','production_order_lines'];
    }

    public static function ddl($p,$c){
        $defs=[ 'production_boms'=>"code varchar(30) NOT NULL, name varchar(190) NOT NULL, product_id bigint(20) unsigned NOT NULL, base_qty bigint(20) unsigned NOT NULL, revision int(10) unsigned NOT NULL DEFAULT 1, is_active tinyint(1) NOT NULL DEFAULT 1, description varchar(500) NOT NULL DEFAULT '', UNIQUE KEY company_code (company_id,code),", 'production_bom_lines'=>"bom_id bigint(20) unsigned NOT NULL, item_id bigint(20) unsigned NOT NULL, qty_scaled bigint(20) unsigned NOT NULL, scrap_bp int(10) unsigned NOT NULL DEFAULT 0, UNIQUE KEY company_component (company_id,bom_id,item_id),", 'production_orders'=>"number int(10) unsigned NOT NULL, fiscal_year_id bigint(20) unsigned NOT NULL, bom_id bigint(20) unsigned NOT NULL, bom_revision int(10) unsigned NOT NULL, product_id bigint(20) unsigned NOT NULL, warehouse_id bigint(20) unsigned NOT NULL, doc_date date NOT NULL, start_date date DEFAULT NULL, finish_date date DEFAULT NULL, planned_qty bigint(20) unsigned NOT NULL, actual_qty bigint(20) unsigned NOT NULL DEFAULT 0, scrap_qty bigint(20) unsigned NOT NULL DEFAULT 0, material_cost bigint(20) unsigned NOT NULL DEFAULT 0, labor_cost bigint(20) unsigned NOT NULL DEFAULT 0, overhead_cost bigint(20) unsigned NOT NULL DEFAULT 0, abnormal_cost bigint(20) unsigned NOT NULL DEFAULT 0, total_cost bigint(20) unsigned NOT NULL DEFAULT 0, wip_account_id bigint(20) unsigned DEFAULT NULL, labor_account_id bigint(20) unsigned DEFAULT NULL, overhead_account_id bigint(20) unsigned DEFAULT NULL, loss_account_id bigint(20) unsigned DEFAULT NULL, start_voucher_id bigint(20) unsigned DEFAULT NULL, finish_voucher_id bigint(20) unsigned DEFAULT NULL, revision int(10) unsigned NOT NULL DEFAULT 1, status varchar(20) NOT NULL DEFAULT 'draft', snapshot longtext NOT NULL, description varchar(500) NOT NULL DEFAULT '', variance_reason varchar(300) NOT NULL DEFAULT '', created_by bigint(20) unsigned NOT NULL, UNIQUE KEY company_number (company_id,fiscal_year_id,number), KEY company_date (company_id,doc_date,status),", 'production_order_lines'=>"order_id bigint(20) unsigned NOT NULL, item_id bigint(20) unsigned NOT NULL, warehouse_id bigint(20) unsigned NOT NULL, planned_qty bigint(20) unsigned NOT NULL, actual_qty bigint(20) unsigned NOT NULL DEFAULT 0, cost bigint(20) unsigned NOT NULL DEFAULT 0, item_code varchar(30) NOT NULL, item_name varchar(190) NOT NULL, unit_name varchar(80) NOT NULL, UNIQUE KEY company_material (company_id,order_id,item_id)," ];
        $out=[];
        foreach($defs as $t=>$body)$out[]="CREATE TABLE {$p}{$t} (\n  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  uuid char(36) NOT NULL,\n  company_id bigint(20) unsigned NOT NULL,\n  ".str_replace(', ',",\n  ",$body)."\n  created_at datetime NOT NULL,\n  updated_at datetime NOT NULL,\n  PRIMARY KEY  (id),\n  UNIQUE KEY uuid (uuid),\n  UNIQUE KEY company_id_id (company_id,id)\n) $c;";
        return $out;
    }

    public static function fks(){
        $out=[];
        foreach(self::tables() as $t)$out[]=[$t,'fk_m_'.$t.'_c',['company_id'],'companies',['id'],'RESTRICT'];
        $refs=['production_boms'=>['product_id'=>'items'],'production_bom_lines'=>['bom_id'=>'production_boms','item_id'=>'items'],'production_orders'=>['fiscal_year_id'=>'fiscal_years','bom_id'=>'production_boms','product_id'=>'items','warehouse_id'=>'warehouses','wip_account_id'=>'accounts','labor_account_id'=>'accounts','overhead_account_id'=>'accounts','loss_account_id'=>'accounts','start_voucher_id'=>'vouchers','finish_voucher_id'=>'vouchers'],'production_order_lines'=>['order_id'=>'production_orders','item_id'=>'items','warehouse_id'=>'warehouses'],'stock_moves'=>['production_order_id'=>'production_orders','production_line_id'=>'production_order_lines']];
        foreach($refs as $t=>$cols)foreach($cols as $col=>$parent)$out[]=[$t,'fk_m_'.$t.'_'.$col,['company_id',$col],$parent,['company_id','id'],'RESTRICT'];
        return $out;
    }

    public static function seed($cid=0){
        foreach(['company_admin'=>['view','create','edit','approve','delete','export'],'accountant'=>['view','create','edit','approve','export'],'viewer'=>['view']] as $role=>$actions)foreach(['production','bom'] as $r)foreach($actions as $act){
            if(!\Agah\Erp\Security\Caps::isValid($r.'.'.$act))continue;
            Db::exec('INSERT IGNORE INTO '.Db::t('role_caps').' (role_id,cap) SELECT id,%s FROM '.Db::t('roles').' WHERE role_key=%s'.($cid?' AND company_id=%d':''),...($cid?[$r.'.'.$act,$role,$cid]:[$r.'.'.$act,$role]));
        }
    }
}
