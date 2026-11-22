<?php
if(wp_get_environment_type()!=='local')throw new RuntimeException('Local only');
ob_start();require __DIR__.'/financial-fixture.php';$fixture=json_decode(ob_get_clean(),true);
$bank=(int)\Agah\Erp\Core\Db::val('SELECT id FROM '.\Agah\Erp\Core\Db::t('treasury_accounts').' WHERE company_id=%d AND code=%s',(int)$fixture['company'],'BUSFAST');
if(!$bank)$bank=\Agah\Erp\Modules\Treasury\TreasuryService::saveAccount((int)$fixture['company'],0,['code'=>'BUSFAST','name'=>'بانک آزمون صندوق سریع','kind'=>'bank','is_active'=>1]);
$fixture['bank']=(string)$bank;echo wp_json_encode($fixture);
