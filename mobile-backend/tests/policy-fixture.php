<?php
if(wp_get_environment_type()!=='local')throw new RuntimeException('Local only');
use Agah\Erp\Core\{Ctx,Db};
use Agah\Mobile\Policy;
$user=get_user_by('login','othermobile');$c=(int)Db::val('SELECT id FROM '.Db::t('companies').' WHERE name=%s','شرکت بیگانه');
wp_set_current_user($user->ID);Ctx::boot($user->ID,$c);
Policy::save($c,($args[0]??'')==='user'?$user->ID:0,$args[1]??'read');
echo wp_json_encode(['company'=>(string)$c]);
