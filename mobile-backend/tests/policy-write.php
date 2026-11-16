<?php
if (wp_get_environment_type()!=='local') throw new RuntimeException('Local only');
use Agah\Erp\Core\{Ctx,Db};
$user=get_user_by('login','mobiletest'); wp_set_current_user($user->ID);
$cid=(int)Db::val('SELECT id FROM '.Db::t('companies').' WHERE name=%s','تست مستقل اپ');
Ctx::boot($user->ID,$cid);
\Agah\Mobile\Policy::save($cid,0,'write');
