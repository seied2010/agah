<?php
if(wp_get_environment_type()!=='local')throw new RuntimeException('Local only');
ob_start();require __DIR__.'/business-fixture.php';$f=json_decode(ob_get_clean(),true);
use Agah\Erp\Core\Db;
use Agah\Erp\Modules\Company\MemberService;
$u=get_user_by('login','uxcashier');
if(!$u){MemberService::add((int)$f['company'],['login'=>'uxcashier','email'=>'uxcashier@example.invalid','password'=>'Disposable-UX-2026-Only','role_id'=>(int)Db::val('SELECT id FROM '.Db::t('roles')." WHERE company_id=%d AND role_key='cashier'",(int)$f['company'])]);$u=get_user_by('login','uxcashier');}
MemberService::setUserInvoiceDeletion((int)$f['company'],$u->ID,-1);echo wp_json_encode($f);
