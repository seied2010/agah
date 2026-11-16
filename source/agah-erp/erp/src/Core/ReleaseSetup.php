<?php

namespace Agah\Erp\Core;
if(!defined('ABSPATH'))exit;
final class ReleaseSetup {
    public static function seed(){
        foreach(['company_admin'=>['legal.view','legal.edit','legal.export','health.view','backup.view','backup.export'],'accountant'=>['legal.view','legal.edit','legal.export']] as $role=>$caps)foreach($caps as $cap)Db::exec('INSERT IGNORE INTO '.Db::t('role_caps').' (role_id,cap) SELECT id,%s FROM '.Db::t('roles').' WHERE role_key=%s',$cap,$role);
    }
}
