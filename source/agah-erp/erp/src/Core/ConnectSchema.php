<?php
/**
 * جدول‌های افزایشی اتصال فروشگاه‌ها (Schema 11).
 * هیچ جدول مالی یا انباری تازه‌ای ساخته نمی‌شود؛ این جدول‌ها فقط نگاشت، رزرو، صف درخواست و وضعیت سفارش را نگه می‌دارند.
 */
namespace Agah\Erp\Core;
if(!defined('ABSPATH'))exit;
final class ConnectSchema {
    public static function tables(){
        return ['connect_clients','connect_customers','connect_reservations','connect_reservation_lines','connect_orders','connect_requests','connect_nonces'];
    }
    public static function ddl($p,$c){
        $standard=[
            'connect_clients'=>"name varchar(100) NOT NULL,\n  key_id varchar(40) NOT NULL,\n  secret_enc text NOT NULL,\n  status varchar(10) NOT NULL DEFAULT 'active',\n  service_user_id bigint(20) unsigned NOT NULL,\n  warehouse_id bigint(20) unsigned NOT NULL,\n  treasury_account_id bigint(20) unsigned NOT NULL,\n  shipping_item_id bigint(20) unsigned DEFAULT NULL,\n  general_party_id bigint(20) unsigned DEFAULT NULL,\n  auto_dispatch tinyint(1) NOT NULL DEFAULT 0,\n  reservation_ttl int(10) unsigned NOT NULL DEFAULT 20,\n  party_threshold bigint(20) unsigned NOT NULL DEFAULT 0,\n  created_by bigint(20) unsigned NOT NULL,\n  last_seen_at datetime DEFAULT NULL,\n  UNIQUE KEY key_id (key_id),",
            'connect_customers'=>"client_id bigint(20) unsigned NOT NULL,\n  customer_key varchar(80) NOT NULL,\n  party_id bigint(20) unsigned NOT NULL,\n  UNIQUE KEY client_customer (client_id,customer_key),",
            'connect_reservations'=>"client_id bigint(20) unsigned NOT NULL,\n  order_ref varchar(80) NOT NULL,\n  warehouse_id bigint(20) unsigned NOT NULL,\n  status varchar(10) NOT NULL,\n  expires_at datetime DEFAULT NULL,\n  KEY client_order (client_id,order_ref),\n  KEY company_status (company_id,warehouse_id,status),",
            'connect_reservation_lines'=>"reservation_id bigint(20) unsigned NOT NULL,\n  item_id bigint(20) unsigned NOT NULL,\n  qty_scaled bigint(20) unsigned NOT NULL,\n  KEY company_item (company_id,item_id),",
            'connect_orders'=>"client_id bigint(20) unsigned NOT NULL,\n  order_ref varchar(80) NOT NULL,\n  reservation_id bigint(20) unsigned DEFAULT NULL,\n  party_id bigint(20) unsigned NOT NULL,\n  payment_doc_id bigint(20) unsigned NOT NULL,\n  invoice_id bigint(20) unsigned DEFAULT NULL,\n  paid_amount bigint(20) unsigned NOT NULL,\n  refunded_amount bigint(20) unsigned NOT NULL DEFAULT 0,\n  payload longtext NOT NULL,\n  status varchar(12) NOT NULL,\n  refund_due tinyint(1) NOT NULL DEFAULT 0,\n  review_reason varchar(500) NOT NULL DEFAULT '',\n  dispatched_at datetime DEFAULT NULL,\n  UNIQUE KEY client_order (client_id,order_ref),\n  KEY client_updated (client_id,updated_at),",
        ];
        $out=[];
        foreach($standard as $name=>$body)$out[]="CREATE TABLE {$p}{$name} (\n  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  uuid char(36) NOT NULL,\n  company_id bigint(20) unsigned NOT NULL,\n  $body\n  created_at datetime NOT NULL,\n  updated_at datetime NOT NULL,\n  PRIMARY KEY  (id),\n  UNIQUE KEY uuid (uuid),\n  UNIQUE KEY company_id_id (company_id,id)\n) $c;";
        // پاسخ ثبت‌شدهٔ هر کلید تکرارناپذیر؛ تلاش دوباره همان نتیجه را می‌گیرد و محتوای متفاوت با همان کلید رد می‌شود.
        $out[]="CREATE TABLE {$p}connect_requests (\n  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  company_id bigint(20) unsigned NOT NULL,\n  client_id bigint(20) unsigned NOT NULL,\n  idem_key varchar(100) NOT NULL,\n  request_hash char(64) NOT NULL,\n  state varchar(10) NOT NULL DEFAULT 'pending',\n  response longtext NULL,\n  created_at datetime NOT NULL,\n  PRIMARY KEY  (id),\n  UNIQUE KEY client_key (client_id,idem_key),\n  KEY created_at (created_at)\n) $c;";
        // nonce امضا تا پایان پنجرهٔ زمانی نگه داشته می‌شود تا درخواست ضبط‌شده دوباره پذیرفته نشود.
        $out[]="CREATE TABLE {$p}connect_nonces (\n  client_id bigint(20) unsigned NOT NULL,\n  nonce varchar(64) NOT NULL,\n  expires_at int(10) unsigned NOT NULL,\n  PRIMARY KEY  (client_id,nonce),\n  KEY expires_at (expires_at)\n) $c;";
        return $out;
    }
    public static function fks(){
        $out=[];
        foreach(['connect_clients','connect_customers','connect_reservations','connect_reservation_lines','connect_orders'] as $t)$out[]=[$t,'fk_x_'.$t.'_c',['company_id'],'companies',['id'],'RESTRICT'];
        $refs=[
            'connect_clients'=>['warehouse_id'=>'warehouses','treasury_account_id'=>'treasury_accounts','shipping_item_id'=>'items','general_party_id'=>'parties'],
            'connect_customers'=>['client_id'=>'connect_clients','party_id'=>'parties'],
            'connect_reservations'=>['client_id'=>'connect_clients','warehouse_id'=>'warehouses'],
            'connect_reservation_lines'=>['reservation_id'=>'connect_reservations','item_id'=>'items'],
            'connect_orders'=>['client_id'=>'connect_clients','reservation_id'=>'connect_reservations','party_id'=>'parties','payment_doc_id'=>'treasury_docs','invoice_id'=>'invoices'],
        ];
        foreach($refs as $t=>$cols)foreach($cols as $col=>$parent)$out[]=[$t,'fk_x_'.$t.'_'.$col,['company_id',$col],$parent,['company_id','id'],'RESTRICT'];
        return $out;
    }
    /** مجوز اتصال فقط به مدیر شرکت داده می‌شود؛ نقش سفارشی خودکار گسترش نمی‌یابد. */
    public static function seed(){
        foreach(['connect.view','connect.edit'] as $cap)Db::exec('INSERT IGNORE INTO '.Db::t('role_caps').' (role_id,cap) SELECT id,%s FROM '.Db::t('roles')." WHERE role_key='company_admin'",$cap);
    }
}
