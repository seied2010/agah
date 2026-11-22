<?php
require '/bundle/app/wp-load.php';
if(wp_get_environment_type()!=='local')throw new RuntimeException('Local test only');
use Agah\Erp\Core\{Ctx,Db,Schema};
use Agah\Erp\Modules\Maintenance\PortableBackupService;
$user=get_user_by('login','agah');wp_set_current_user($user->ID);Ctx::boot($user->ID,1);
$path='/state/schema21-test.agahbackup';$password='Disposable-Schema-21-Backup-2026';
if(($argv[1]??'')==='capture'){
 if(Schema::VERSION!=='21')throw new RuntimeException('Capture must use the actual previous release');
 file_put_contents($path,PortableBackupService::create('company',1,$password)['bytes']);
 file_put_contents('/state/schema21-counts.json',wp_json_encode(['invoices'=>(int)Db::val('SELECT COUNT(*) FROM '.Db::t('invoices')),'vouchers'=>(int)Db::val('SELECT COUNT(*) FROM '.Db::t('vouchers'))]));
 echo "PASS actual schema 21 encrypted company backup captured\n";exit;
}
if(Schema::VERSION!=='22')throw new RuntimeException('Restore must use upgraded schema 22');
$result=PortableBackupService::restoreCompanyBytes(file_get_contents($path),$password,1,$user->ID);
$before=json_decode(file_get_contents('/state/schema21-counts.json'),true);
if($result['status']!=='restored'||$before['invoices']!==(int)Db::val('SELECT COUNT(*) FROM '.Db::t('invoices'))||$before['vouchers']!==(int)Db::val('SELECT COUNT(*) FROM '.Db::t('vouchers')))throw new RuntimeException('Legacy backup record counts not preserved');
if(!Ctx::can('invoice_delete.delete')||!Ctx::can('invoice_delete.edit'))throw new RuntimeException('Admin defaults missing after legacy restore');
if(!Db::val('SELECT id FROM '.Db::t('roles')." WHERE company_id=1 AND role_key='shop_manager'"))throw new RuntimeException('Store manager role missing after legacy restore');
if(Db::val('SELECT user_id FROM '.Db::t('company_members').' WHERE company_id=1 AND invoice_delete_override<>-1'))throw new RuntimeException('Legacy user override must inherit');
if(Agah\Erp\Core\Audit::verify(1)!==null)throw new RuntimeException('Audit integrity failed');
echo "PASS previous-release backup restored after real schema 21 to 22 upgrade\nPASS original accounting records and audit chain preserved\nPASS default deletion permissions initialized without granting ordinary users\n";
