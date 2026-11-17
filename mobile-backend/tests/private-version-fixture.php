<?php
if(wp_get_environment_type()!=='local') throw new RuntimeException('Local only');
use Agah\Erp\Core\{Db,Crypto};
$id=(int)($args[0]??0);
$row=Db::row('SELECT id,national_id_enc FROM '.Db::t('parties').' WHERE id=%d AND name=%s',$id,'Private version fixture');
if(!$row||!$row['national_id_enc']) throw new RuntimeException('Fixture missing');
// Simulate ciphertext rotation without changing the public DTO or its second-resolution timestamp.
Db::exec('UPDATE '.Db::t('parties').' SET national_id_enc=%s WHERE id=%d',Crypto::encrypt(Crypto::decrypt($row['national_id_enc'])),$id);
echo 'rotated';
