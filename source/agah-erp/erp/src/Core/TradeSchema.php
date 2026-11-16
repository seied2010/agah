<?php
/** مهاجرت افزایشی فروش/خرید/انبار؛ تمام پیوندهای مالی با FK مرکب شرکت محافظت می‌شوند. */
namespace Agah\Erp\Core;
if (!defined('ABSPATH')) exit;
final class TradeSchema {
    public static function tables() { return ['warehouses','stock_docs','stock_moves','stock_layers','stock_picks','stock_minimums','invoice_advances']; }
    public static function ddl($p,$c) {
        $definitions=[
            'warehouses'=>"code varchar(30) NOT NULL,\n  name varchar(190) NOT NULL,\n  is_active tinyint(1) NOT NULL DEFAULT 1,\n  UNIQUE KEY company_code (company_id,code),",
            'stock_docs'=>"kind varchar(20) NOT NULL,\n  doc_date date NOT NULL,\n  fiscal_year_id bigint(20) unsigned NOT NULL,\n  number int(10) unsigned NOT NULL,\n  status varchar(10) NOT NULL DEFAULT 'issued',\n  description varchar(500) NOT NULL DEFAULT '',\n  voucher_id bigint(20) unsigned DEFAULT NULL,\n  created_by bigint(20) unsigned NOT NULL,\n  UNIQUE KEY company_number (company_id,fiscal_year_id,number),",
            'stock_moves'=>"warehouse_id bigint(20) unsigned NOT NULL,\n  item_id bigint(20) unsigned NOT NULL,\n  move_date date NOT NULL,\n  qty_scaled bigint(20) NOT NULL,\n  cost bigint(20) NOT NULL,\n  production_order_id bigint(20) unsigned DEFAULT NULL,\n  production_line_id bigint(20) unsigned DEFAULT NULL,\n  source_type varchar(20) NOT NULL,\n  source_id bigint(20) unsigned NOT NULL,\n  invoice_id bigint(20) unsigned DEFAULT NULL,\n  invoice_line_id bigint(20) unsigned DEFAULT NULL,\n  stock_doc_id bigint(20) unsigned DEFAULT NULL,\n  status varchar(10) NOT NULL DEFAULT 'active',\n  KEY company_stock (company_id,warehouse_id,item_id,status,move_date),",
            'stock_layers'=>"move_id bigint(20) unsigned NOT NULL,\n  warehouse_id bigint(20) unsigned NOT NULL,\n  item_id bigint(20) unsigned NOT NULL,\n  remaining_qty bigint(20) unsigned NOT NULL,\n  remaining_value bigint(20) unsigned NOT NULL,\n  UNIQUE KEY company_move (company_id,move_id),",
            'stock_picks'=>"move_id bigint(20) unsigned NOT NULL,\n  layer_id bigint(20) unsigned NOT NULL,\n  qty_scaled bigint(20) unsigned NOT NULL,\n  cost bigint(20) unsigned NOT NULL,\n  UNIQUE KEY company_pick (company_id,move_id,layer_id),",
            'stock_minimums'=>"warehouse_id bigint(20) unsigned NOT NULL,\n  item_id bigint(20) unsigned NOT NULL,\n  qty_scaled bigint(20) unsigned NOT NULL,\n  UNIQUE KEY company_minimum (company_id,warehouse_id,item_id),",
            'invoice_advances'=>"invoice_id bigint(20) unsigned NOT NULL,\n  doc_id bigint(20) unsigned NOT NULL,\n  amount bigint(20) unsigned NOT NULL,\n  status varchar(10) NOT NULL DEFAULT 'active',\n  KEY company_invoice (company_id,invoice_id,status),",
        ];
        $out=[];
        foreach($definitions as $name=>$body) $out[]="CREATE TABLE {$p}{$name} (\n  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  uuid char(36) NOT NULL,\n  company_id bigint(20) unsigned NOT NULL,\n  $body\n  created_at datetime NOT NULL,\n  updated_at datetime NOT NULL,\n  PRIMARY KEY  (id),\n  UNIQUE KEY uuid (uuid),\n  UNIQUE KEY company_id_id (company_id,id)\n) $c;";
        return $out;
    }
    public static function fks() {
        $out=[];
        foreach(self::tables() as $t) $out[]=[$t,'fk_t_'.$t.'_company',['company_id'],'companies',['id'],'RESTRICT'];
        $refs=[
            'invoices'=>['parent_id'=>'invoices'],
            'invoice_lines'=>['source_line_id'=>'invoice_lines','warehouse_id'=>'warehouses'],
            'stock_docs'=>['fiscal_year_id'=>'fiscal_years','voucher_id'=>'vouchers'],
            'stock_moves'=>['warehouse_id'=>'warehouses','item_id'=>'items','invoice_id'=>'invoices','invoice_line_id'=>'invoice_lines','stock_doc_id'=>'stock_docs'],
            'stock_layers'=>['move_id'=>'stock_moves','warehouse_id'=>'warehouses','item_id'=>'items'],
            'stock_picks'=>['move_id'=>'stock_moves','layer_id'=>'stock_layers'],
            'stock_minimums'=>['warehouse_id'=>'warehouses','item_id'=>'items'],
            'invoice_advances'=>['invoice_id'=>'invoices','doc_id'=>'treasury_docs'],
        ];
        foreach($refs as $table=>$columns) foreach($columns as $column=>$parent) $out[]=[$table,'fk_t_'.$table.'_'.$column,['company_id',$column],$parent,['company_id','id'],'RESTRICT'];
        return $out;
    }
    /** فقط مجوزهای منابع تازه به نقش‌های مناسب افزوده می‌شود؛ نقش سفارشی دست‌نخورده است. */
    public static function seed($cid=0) {
        $roles=['company_admin'=>['warehouse.view','warehouse.create','warehouse.edit','stock.view','stock.create','stock.delete','stock.export'], 'accountant'=>['warehouse.view','stock.view','stock.create','stock.export'], 'storekeeper'=>['warehouse.view','stock.view','stock.create','stock.export'], 'viewer'=>['warehouse.view','stock.view']];
        foreach($roles as $role=>$caps) foreach($caps as $cap) Db::exec('INSERT IGNORE INTO '.Db::t('role_caps').' (role_id,cap) SELECT id,%s FROM '.Db::t('roles').' WHERE role_key=%s'.($cid?' AND company_id=%d':''),...($cid?[$cap,$role,$cid]:[$cap,$role]));
    }
}
