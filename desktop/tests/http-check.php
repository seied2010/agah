<?php
$s=json_decode(file_get_contents('/state/settings.json'),true);$base=$s['url'];$jar='/tmp/agah-local-test-cookie.txt';
$count=0;function ok($v,$name){global $count;if(!$v)throw new RuntimeException('FAIL '.$name);echo 'PASS '.(++$count).' '.$name."\n";}
function request($path,$post=null,$host=null){global $base,$jar;$c=curl_init($base.$path);$opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEJAR=>$jar,CURLOPT_COOKIEFILE=>$jar,CURLOPT_TIMEOUT=>30];if($post!==null)$opts[CURLOPT_POSTFIELDS]=http_build_query($post);if($host)$opts[CURLOPT_HTTPHEADER]=['Host: '.$host];curl_setopt_array($c,$opts);$r=curl_exec($c);if($r===false)throw new RuntimeException(curl_error($c));$size=curl_getinfo($c,CURLINFO_HEADER_SIZE);$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);return [$code,substr($r,$size),substr($r,0,$size)];}
[$code,$body,$head]=request('/wp-login.php');ok($code===200&&strpos($body,'user_login')!==false,'offline login page');ok(strpos($head,"connect-src 'self'")!==false,'browser connections restricted to local origin');
[$code]=request('/',null, 'outside.example:18817');ok($code===403,'foreign Host header rejected');
[$code,$body]=request('/wp-login.php');$dom=new DOMDocument();@$dom->loadHTML($body);$inputs=[];foreach($dom->getElementsByTagName('input') as $input)if($input->getAttribute('type')==='hidden')$inputs[$input->getAttribute('name')]=$input->getAttribute('value');
$inputs['log']='agah';$inputs['pwd']=$s['admin_password'];$inputs['wp-submit']='Log In';request('/wp-login.php',$inputs);
ok(strpos(file_get_contents($jar),'wordpress_logged_in_')!==false,'local administrator authentication');
$page=trim(file_get_contents('/state/page-id.txt'));$route='/?page_id='.$page.'&c=1&p=';
[$code,$body]=request($route.'dashboard');ok($code===200&&strpos($body,'erp-workspace-content')!==false,'accounting dashboard rendered');
preg_match_all('~(?:src|href)=["\x27](https?://[^"\x27]+)~',$body,$urls);$external=array_filter($urls[1],fn($url)=>!in_array(parse_url(html_entity_decode($url),PHP_URL_HOST),['127.0.0.1','localhost'],true));ok(!$external,'dashboard resources need no external host');
[$code,$body]=request($route.'backups');@$dom->loadHTML($body);$xpath=new DOMXPath($dom);$form=$xpath->query('//form[.//input[@name="backup_scope" and @value="company"]]')->item(0);ok($form!==null,'company backup form available locally');
$post=[];foreach($form->getElementsByTagName('input') as $i)$post[$i->getAttribute('name')]=$i->getAttribute('value');$post['backup_password']='Offline-Backup-Test-2026!';$post['backup_password_confirm']=$post['backup_password'];$post['confirm_password']=$s['admin_password'];
[$code,$body]=request($route.'backups',$post);ok($code===200&&strpos($body,"AGAH-PORTABLE-1\n")===0,'encrypted company backup downloaded offline');
require '/bundle/app/wp-load.php';$external=wp_remote_get('https://example.com');ok(is_wp_error($external)&&$external->get_error_code()==='agah_offline','server-side external HTTP blocked');
$companies=(int)\Agah\Erp\Core\Db::val('SELECT COUNT(*) FROM '.\Agah\Erp\Core\Db::t('companies'));ok($companies===1,'repeat setup preserved a single company');
ok(\Agah\Erp\Core\Schema::missingForeignKeys()===[],'local schema foreign keys installed');
$desktopRoute='/?page_id='.trim(file_get_contents('/state/page-id.txt')).'&c=1&p=';
[$code,$entry]=request($desktopRoute.'invoice_new&kind=sale');ok($code===200&&strpos($entry,'data-trade-entry')!==false,'new fast entry available offline');
@$dom->loadHTML($entry);$xpath=new DOMXPath($dom);$node=$xpath->query('//*[@data-trade-entry]')->item(0);
[$code,$json]=request('/wp-admin/admin-ajax.php',['action'=>'agah_trade_entry','nonce'=>$node->getAttribute('data-nonce'),'company'=>1,'kind'=>'sale','mode'=>'search','q'=>'NONEXISTENT-LOCAL']);$result=json_decode($json,true);ok($code===200&&!empty($result['success'])&&$result['data']['items']===[],'authenticated fast lookup works without internet');
echo "OFFLINE HTTP RESULT: $count passed\n";
