<?php
if (wp_get_environment_type()!=='local') throw new RuntimeException('Local only');
use Agah\Erp\Core\{Ctx,Db,Settings};
use Agah\Erp\Modules\Company\MemberService;
use Agah\Erp\Modules\Parties\PartyService;
use Agah\Erp\Modules\Files\FileService;
$admin=get_user_by('login','mobiletest');wp_set_current_user($admin->ID);
$c=(int)Db::val('SELECT id FROM '.Db::t('companies').' WHERE name=%s','تست مستقل اپ');Ctx::boot($admin->ID,$c);
// Higher login allowance applies only to the disposable local database, allowing repeatable suites.
Settings::set('login_max_attempts','100');
$viewer=get_user_by('login','mobileviewer');
if (!$viewer) {
    $role=Db::val('SELECT id FROM '.Db::t('roles').' WHERE company_id=%d AND role_key=%s',$c,'viewer');
    MemberService::add($c,['login'=>'mobileviewer','password'=>'ViewerLocal-2026-Only','email'=>'viewer@example.invalid','role_id'=>$role,'display_name'=>'مشاهده‌گر آزمون']);
    $viewer=get_user_by('login','mobileviewer');
}
$f=(int)Db::val('SELECT id FROM '.Db::t('companies').' WHERE name=%s','شرکت بیگانه');
$other=get_user_by('login','othermobile');wp_set_current_user($other->ID);Ctx::boot($other->ID,$f);
$party=Db::val('SELECT id FROM '.Db::t('parties').' WHERE company_id=%d LIMIT 1',$f);
if (!$party) $party=PartyService::save($f,0,['name'=>'شخص محرمانهٔ آزمون','kind'=>'real','is_customer'=>1,'is_active'=>1]);
$image=imagecreatetruecolor(2,2);ob_start();imagepng($image);$png=ob_get_clean();
$fid=Db::val('SELECT id FROM '.Db::t('files').' WHERE company_id=%d AND party_id=%d LIMIT 1',$f,$party);
if (!$fid) $fid=FileService::attach($f,'party',(int)$party,'foreign.png',$png);
echo wp_json_encode(['company'=>(string)$c,'foreign'=>(string)$f,'foreign_file'=>(string)$fid,'png'=>base64_encode($png),'year'=>(string)Db::val('SELECT id FROM '.Db::t('fiscal_years').' WHERE company_id=%d LIMIT 1',$c)]);
