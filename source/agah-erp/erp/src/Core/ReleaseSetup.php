<?php

namespace Agah\Erp\Core;
if(!defined('ABSPATH'))exit;
final class ReleaseSetup {
    public static function seed(){
        foreach(['company_admin'=>['legal.view','legal.edit','legal.export','health.view','backup.view','backup.export'],'accountant'=>['legal.view','legal.edit','legal.export']] as $role=>$caps)foreach($caps as $cap)Db::exec('INSERT IGNORE INTO '.Db::t('role_caps').' (role_id,cap) SELECT id,%s FROM '.Db::t('roles').' WHERE role_key=%s',$cap,$role);
        self::seedInvoiceDeletion();
    }
    public static function seedInvoiceDeletion($company=0){
        foreach(['invoice_delete.delete','invoice_delete.edit'] as $cap)Db::exec('INSERT IGNORE INTO '.Db::t('role_caps')." (role_id,cap) SELECT id,%s FROM ".Db::t('roles')." WHERE role_key='company_admin'".($company?' AND company_id=%d':''),...($company?[$cap,(int)$company]:[$cap]));
        $definition=\Agah\Erp\Security\Caps::builtinRoles()['shop_manager'];
        foreach(Db::col('SELECT id FROM '.Db::t('companies').($company?' WHERE id=%d':''),...($company?[(int)$company]:[])) as $cid){
            if(Db::val('SELECT id FROM '.Db::t('roles')." WHERE company_id=%d AND role_key='shop_manager'",$cid))continue;
            $rid=Db::insert('roles',['company_id'=>$cid,'role_key'=>'shop_manager','name'=>$definition[0],'is_system'=>1,'created_at'=>gmdate('Y-m-d H:i:s')]);
            foreach($definition[1] as $cap)Db::insert('role_caps',['role_id'=>$rid,'cap'=>$cap]);
        }
    }
}
