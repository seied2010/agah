<?php
/** Disposable local database only; never run concurrently with Android journeys. */
if(wp_get_environment_type()!=='local')throw new RuntimeException('Local only');
use Agah\Erp\Core\{Ctx,Db};
use Agah\Erp\Modules\Maintenance\BackupService;
use Agah\Mobile\{Session,Policy};
$dir=sys_get_temp_dir().'/agah-restore-fixture-'.bin2hex(random_bytes(6));mkdir($dir,0700);
define('AGAH_BACKUP_DIR',$dir);define('AGAH_BACKUP_KEY',base64_encode(random_bytes(32)));
$u=get_user_by('login','mobiletest');wp_set_current_user($u->ID);
$c=(int)Db::val('SELECT id FROM '.Db::t('companies').' WHERE name=%s','تست مستقل اپ');Ctx::boot($u->ID,$c);Policy::save($c,0,'write');
$http=function($path,$method,$body,$token,$headers=[]) {
    $r=new WP_REST_Request($method,'/agah-mobile/v1/'.$path);
    foreach(['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json']+$headers as $k=>$v)$r->set_header($k,$v);
    if($body!==null)$r->set_body(wp_json_encode($body));
    $response=rest_do_request($r);return ['status'=>$response->get_status(),'data'=>$response->get_data()];
};
try {
    $backup=BackupService::create();
    $login=Session::login(['username'=>'mobiletest','password'=>'LocalIntegration-2026-Only','device_id'=>'full-restore-fixture-2026']);
    $token=$login['access_token'];$key=wp_generate_uuid4();$body=['name'=>'Full restore ephemeral '.wp_generate_uuid4(),'kind'=>'real','is_customer'=>true,'is_active'=>true,'credit_limit'=>'0'];
    $created=$http('companies/'.$c.'/parties','POST',$body,$token,['Idempotency-Key'=>$key]);
    if($created['status']!==200)throw new RuntimeException('Could not create full restore fixture');$id=$created['data']['data']['id'];
    BackupService::restore($backup);
    $results=['snapshot_applied'=>!Db::val('SELECT id FROM '.Db::t('parties').' WHERE company_id=%d AND id=%d',$c,$id),
        'old_access_revoked'=>$http('companies','GET',null,$token)['status']===401,
        'writes_disabled'=>Policy::mode($c,$u->ID)==='read'];
    $fresh=Session::login(['username'=>'mobiletest','password'=>'LocalIntegration-2026-Only','device_id'=>'full-restore-fixture-2026']);
    $receipt=$http('companies/'.$c.'/operations/'.$key,'GET',null,$fresh['access_token']);
    $results['receipt_is_restored']=($receipt['data']['data']['state']??'')==='restored';
    wp_set_current_user($u->ID);Ctx::boot($u->ID,$c);Policy::save($c,0,'write');
    $results['old_key_cannot_replay']=$http('companies/'.$c.'/parties','POST',$body,$fresh['access_token'],['Idempotency-Key'=>$key])['status']===409;
    foreach($results as $name=>$pass)if(!$pass)throw new RuntimeException('FAIL '.$name);
    echo wp_json_encode($results);
} finally {
    foreach(glob($dir.'/*') as $file)if(is_file($file))unlink($file);
    rmdir($dir);
}
