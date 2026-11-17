<?php
if (wp_get_environment_type()!=='local') throw new RuntimeException('Local only');
use Agah\Erp\Core\{Ctx,Db};
use Agah\Erp\Modules\Accounting\FiscalService;
$status=$args[0]??'';
if (!in_array($status,['open','closed'],true)) throw new RuntimeException('Specify open or closed');
$user=get_user_by('login','mobiletest');wp_set_current_user($user->ID);
$c=(int)Db::val('SELECT id FROM '.Db::t('companies').' WHERE name=%s','تست مستقل اپ');Ctx::boot($user->ID,$c);
$id=(int)Db::val('SELECT id FROM '.Db::t('fiscal_periods').' WHERE company_id=%d ORDER BY start_date LIMIT 1',$c);
FiscalService::setPeriodStatus($c,$id,$status);
echo wp_json_encode(['period'=>$id,'status'=>$status]);
