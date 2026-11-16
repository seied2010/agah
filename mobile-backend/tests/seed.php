<?php
// Isolated disposable database only. Run with wp eval-file /tests/seed.php.
if (wp_get_environment_type()!=='local') throw new RuntimeException('Local test only');
use Agah\Erp\Core\{Ctx,Db};
use Agah\Erp\Modules\Company\CompanyService;
$admin=get_user_by('login','mobiletest'); wp_set_current_user($admin->ID);
$name='تست مستقل اپ';
$company=Db::row('SELECT id FROM '.Db::t('companies').' WHERE name=%s',$name);
$cid=$company?(int)$company['id']:CompanyService::create($admin->ID,['name'=>$name,'kind'=>'legal','display_unit'=>'rial','jy'=>1405]);
$other=get_user_by('login','othermobile');
if (!$other) { $id=wp_create_user('othermobile','OtherLocal-2026-Only','other@example.invalid'); $other=get_userdata($id); }
$foreign=Db::row('SELECT id FROM '.Db::t('companies').' WHERE name=%s','شرکت بیگانه');
$foreignId=$foreign?(int)$foreign['id']:CompanyService::create($other->ID,['name'=>'شرکت بیگانه','kind'=>'legal','display_unit'=>'rial','jy'=>1405]);
echo wp_json_encode(['company'=>(string)$cid,'foreign'=>(string)$foreignId,'year'=>Db::val('SELECT id FROM '.Db::t('fiscal_years').' WHERE company_id=%d LIMIT 1',$cid)]);
