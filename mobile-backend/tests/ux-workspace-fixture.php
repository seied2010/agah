<?php
if (wp_get_environment_type()!=='local') throw new RuntimeException('Local only');
ob_start();require '/tests/financial-fixture.php';$fixture=json_decode(ob_get_clean(),true);
use Agah\Erp\Core\{Ctx,Db};
use Agah\Erp\Modules\Catalog\CatalogService;
$c=(int)$fixture['company'];Ctx::boot(get_current_user_id(),$c);
for($i=0;$i<60;$i++){
 $code='UXFIX'.str_pad((string)$i,3,'0',STR_PAD_LEFT);
 if(!Db::val('SELECT id FROM '.Db::t('units').' WHERE company_id=%d AND code=%s',$c,$code))CatalogService::save($c,'unit',0,['code'=>$code,'name'=>'واحد آزمایش رابط '.$i,'is_active'=>1]);
}
echo wp_json_encode($fixture);
