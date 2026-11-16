<?php
if (wp_get_environment_type()!=='local') throw new RuntimeException('Local only');
use Agah\Erp\Core\{Ctx,Db};
use Agah\Erp\Modules\Maintenance\PortableBackupService;
use Agah\Mobile\{Session,Policy};
$u=get_user_by('login','mobiletest');wp_set_current_user($u->ID);
$cid=(int)Db::val('SELECT id FROM '.Db::t('companies').' WHERE name=%s','تست مستقل اپ');Ctx::boot($u->ID,$cid);
Policy::save($cid,0,'write');
$snapshot=PortableBackupService::create('company',$cid,'Local-Backup-Password-2026');
$token=Session::login(['username'=>'mobiletest','password'=>'LocalIntegration-2026-Only','device_id'=>'restore-integration-device-2026','device_name'=>'Restore test']);
$key=wp_generate_uuid4();
$http=function($path,$method,$body,$access,$extra=[]) {
    // Exercise the actual REST permission/callback chain in-process; HTTP is tested separately.
    $request=new WP_REST_Request($method,'/agah-mobile/v1/'.$path);
    foreach (array_merge(['Authorization'=>'Bearer '.$access,'Content-Type'=>'application/json'],$extra) as $name=>$value) $request->set_header($name,$value);
    if ($body!==null) $request->set_body(wp_json_encode($body));
    $response=rest_do_request($request);
    return ['status'=>$response->get_status(),'body'=>$response->get_data()];
};
$input=['name'=>'حذف پس از بازیابی '.wp_generate_uuid4(),'kind'=>'real','is_customer'=>true,'is_active'=>true,'credit_limit'=>'0'];
$created=$http('companies/'.$cid.'/parties','POST',$input,$token['access_token'],['Idempotency-Key'=>$key]);
if ($created['status']!==200) throw new RuntimeException('Could not create real HTTP fixture');
$id=$created['body']['data']['id'];
Ctx::boot($u->ID,$cid);
PortableBackupService::restoreCompanyBytes($snapshot['bytes'],'Local-Backup-Password-2026',$cid,$u->ID);
$results=[];
$results['snapshot_restored']=!Db::val('SELECT id FROM '.Db::t('parties').' WHERE company_id=%d AND id=%d',$cid,$id);
$results['old_mobile_session_revoked']=$http('companies','GET',null,$token['access_token'])['status']===401;
$results['mobile_writes_disabled']=Policy::mode($cid,$u->ID)==='read';
$new=Session::login(['username'=>'mobiletest','password'=>'LocalIntegration-2026-Only','device_id'=>'restore-integration-device-2026','device_name'=>'Restore test']);
$receipt=$http('companies/'.$cid.'/operations/'.$key,'GET',null,$new['access_token']);
$results['receipt_not_false_commit']=($receipt['body']['data']['state']??'')==='restored';
Ctx::boot($u->ID,$cid);Policy::save($cid,0,'write');
$results['old_operation_cannot_replay']=$http('companies/'.$cid.'/parties','POST',$input,$new['access_token'],['Idempotency-Key'=>$key])['status']===409;
foreach ($results as $name=>$pass) if (!$pass) throw new RuntimeException('FAIL '.$name);
echo wp_json_encode($results);
