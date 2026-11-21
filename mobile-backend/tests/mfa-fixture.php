<?php
if(wp_get_environment_type()!=='local') throw new RuntimeException('Local only');
use Agah\Erp\Security\{TwoFactor,Totp};
$name=$args[1]??'';
if(!preg_match('/^mfafixture-[a-f0-9]{12}$/D',$name)) throw new RuntimeException('Dedicated fixture name required');
$password='MfaLocal-2026-Only';
if(($args[0]??'')==='disable') {
    $user=get_user_by('login',$name);if(!$user)throw new RuntimeException('Fixture missing');
    wp_set_current_user($user->ID);TwoFactor::disable($user->ID,$password);echo 'disabled';return;
}
$uid=wp_create_user($name,$password,$name.'@example.invalid');if(is_wp_error($uid))throw new RuntimeException('Fixture creation failed');
wp_set_current_user($uid);$session=WP_Session_Tokens::get_instance($uid)->create(time()+900);
$secret=TwoFactor::begin($uid,$password,$session);
$codes=TwoFactor::enable($uid,Totp::code($secret,intdiv(time(),30)),$session);
// Returned only to the local test process; never log the secret, token or recovery value.
echo wp_json_encode(['username'=>$name,'factor'=>Totp::code($secret,intdiv(time(),30)+1),'recovery'=>$codes[0]]);
