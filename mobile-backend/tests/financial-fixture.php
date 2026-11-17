<?php
if (wp_get_environment_type()!=='local') throw new RuntimeException('Local only');
use Agah\Erp\Core\{Ctx,Db};
use Agah\Erp\Modules\Catalog\CatalogService;
use Agah\Erp\Modules\Accounting\AccountService;
use Agah\Erp\Modules\Treasury\TreasuryService;
use Agah\Erp\Modules\Expenses\ExpenseService;
use Agah\Erp\Modules\Inventory\StockService;
$u=get_user_by('login','mobiletest'); wp_set_current_user($u->ID);
$c=(int)Db::val('SELECT id FROM '.Db::t('companies').' WHERE name=%s','تست مستقل اپ'); Ctx::boot($u->ID,$c);
$find=function($table,$code) use ($c) { return (int)Db::val('SELECT id FROM '.Db::t($table).' WHERE company_id=%d AND code=%s',$c,$code); };
$unit=$find('units','MOB'); if (!$unit) $unit=CatalogService::save($c,'unit',0,['name'=>'واحد آزمون موبایل','code'=>'MOB','is_active'=>1]);
$wh=$find('warehouses','MOB'); if (!$wh) $wh=StockService::saveWarehouse($c,0,['name'=>'انبار آزمون موبایل','code'=>'MOB','is_active'=>1]);
$target=$find('warehouses','MOB2'); if (!$target) $target=StockService::saveWarehouse($c,0,['name'=>'انبار دوم آزمون موبایل','code'=>'MOB2','is_active'=>1]);
$cash=$find('treasury_accounts','MOB'); if (!$cash) $cash=TreasuryService::saveAccount($c,0,['name'=>'صندوق آزمون موبایل','code'=>'MOB','kind'=>'cash','is_active'=>1]);
$category=$find('expense_categories','MOB'); if (!$category) $category=ExpenseService::saveLookup($c,'category',0,['name'=>'هزینه آزمون موبایل','code'=>'MOB','kind'=>'expense','account_id'=>AccountService::bySysKey($c,'other_expense')['id'],'is_active'=>1]);
$year=Db::row('SELECT id,start_date,end_date FROM '.Db::t('fiscal_years').' WHERE company_id=%d ORDER BY id LIMIT 1',$c);
echo wp_json_encode(['company'=>(string)$c,'year'=>$year,'unit'=>(string)$unit,'warehouse'=>(string)$wh,'target_warehouse'=>(string)$target,'cash'=>(string)$cash,'category'=>(string)$category,
    'sale_account'=>(string)AccountService::bySysKey($c,'sales_services')['id'],'purchase_account'=>(string)AccountService::bySysKey($c,'purchases')['id'],
    'manual_debit'=>(string)AccountService::bySysKey($c,'other_expense')['id'],'manual_credit'=>(string)AccountService::bySysKey($c,'capital')['id']]);
