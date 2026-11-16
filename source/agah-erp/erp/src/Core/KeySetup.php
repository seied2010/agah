<?php
/** Pin the effective legacy key, preserving every cipher, blind index and token. Never rotate on install. */
namespace Agah\Erp\Core;
if(!defined('ABSPATH'))exit;
final class KeySetup {
    const OPTION='agah_crypto_setup';
    const GUARD="<?php\nhttp_response_code(404); exit;\n__halt_compiler();\n";
    public static function meta(){ $m=get_option(self::OPTION,[]);return is_array($m)?$m:[]; }
    public static function fingerprint($raw){return hash('sha256','agah-key-pin-v1|'.$raw);}
    /** No include/eval. Both secret files are fixed-format, independently verified copies. */
    public static function storedValue(){
        $m=self::meta();if(($m['storage']??'')!=='private')return '';
        $dir=$m['directory']??'';$files=$m['files']??[];$fingerprint=$m['fingerprint']??'';
        if(!is_string($dir)||!preg_match('/^agah-private-keys-[a-f0-9]{32}$/D',$dir)||!is_array($files)||count($files)!==2||!is_string($fingerprint))return '';
        $root=WP_CONTENT_DIR.'/'.$dir;if(is_link($root)||!is_dir($root))return '';
        foreach($files as $name){
            if(!is_string($name)||!preg_match('/^key-[a-f0-9]{32}\.php$/D',$name))continue;
            $path=$root.'/'.$name;if(is_link($path)||!is_file($path))continue;
            $bytes=@file_get_contents($path,false,null,0,4097);if(!is_string($bytes)||strlen($bytes)>4096||strpos($bytes,self::GUARD)!==0)continue;
            $encoded=trim(substr($bytes,strlen(self::GUARD)));$raw=base64_decode($encoded,true);
            if($raw!==false&&strlen($raw)>=32&&hash_equals($fingerprint,self::fingerprint($raw)))return $encoded;
        }
        return '';
    }
    /** Prove that a fallback after a missing managed key is the same original key, otherwise fail closed. */
    public static function assertFallback($raw){$m=self::meta();if(!empty($m['fingerprint'])&&(!is_string($m['fingerprint'])||!hash_equals($m['fingerprint'],self::fingerprint($raw))))throw new AppException('کلید ذخیره‌شده در دسترس نیست؛ برای حفاظت از اطلاعات، عملیات رمزنگاری متوقف شد.',503);}
    public static function configPath(){
        foreach([ABSPATH.'wp-config.php',dirname(rtrim(ABSPATH,'/\\')).'/wp-config.php'] as $p){if(is_file($p)&&!is_link($p))return $p;}return '';
    }
    /** Only inject before the standard bootstrap include. Complex/managed configs use private storage instead. */
    public static function prepareConfig($source,$encoded){
        if(!is_string($source)||strpos($source,'AGAH_ENC_KEY')!==false||strlen($source)>1048576)return null;
        if(!preg_match('/^[A-Za-z0-9+\/]+=*$/D',$encoded))throw new \InvalidArgumentException('Invalid key encoding');
        $pattern='~^[ \t]*(?:require_once|require)\b[^;\r\n]*[\'"]wp-settings\.php[\'"][^;\r\n]*;~m';
        if(!preg_match_all($pattern,$source,$matches,PREG_OFFSET_CAPTURE)||count($matches[0])!==1)return null;
        $offset=$matches[0][0][1];
        // Reject a lookalike inside a comment/string/function/conditional body.
        $cursor=0;$depth=0;$valid=false;$previous=null;
        try{$tokens=token_get_all($source,TOKEN_PARSE);}catch(\ParseError $e){return null;}
        foreach($tokens as $token){$text=is_array($token)?$token[1]:$token;$id=is_array($token)?$token[0]:null;
            if($cursor>= $offset&&$cursor<= $offset+strspn(substr($source,$offset)," \t")&&in_array($id,[T_REQUIRE,T_REQUIRE_ONCE],true))$valid=$depth===0&&in_array($previous,[';', '}',T_OPEN_TAG],true);
            if($id===null){if($text==='{')$depth++;if($text==='}')$depth--;}
            if(!in_array($id,[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true))$previous=$id??$text;
            $cursor+=strlen($text);
        }
        if(!$valid)return null;
        $block="/* AGAH managed stable encryption key. Keep this block in complete site backups. */\nif (!defined('AGAH_ENC_KEY')) { define('AGAH_ENC_KEY', '".$encoded."'); }\n\n";
        $result=substr($source,0,$offset).$block.substr($source,$offset);
        try{token_get_all($result,TOKEN_PARSE);}catch(\ParseError $e){return null;}return $result;
    }
    /** Atomic same-directory replace; the old config stays untouched on all pre-publish failures. */
    private static function writeConfig($encoded){
        $path=self::configPath();if(!$path||!is_readable($path)||!is_writable($path)||!is_writable(dirname($path)))return false;
        $handle=@fopen($path,'rb');if(!$handle)return false;$tmp='';
        try{
            if(!flock($handle,LOCK_EX))return false;$old=stream_get_contents($handle,1048577);$new=self::prepareConfig($old,$encoded);if($new===null)return false;
            $stat=fstat($handle);$tmp=dirname($path).'/.agah-config-'.bin2hex(random_bytes(16)).'.php';$f=@fopen($tmp,'xb');if(!$f)return false;
            try{if(!chmod($tmp,0600)||!self::writeAll($f,$new)||!fflush($f))return false;if(function_exists('fsync')&&!fsync($f))return false;}finally{fclose($f);}
            // Preserve existing ownership where permitted and existing read permissions.
            @chown($tmp,$stat['uid']);@chgrp($tmp,$stat['gid']);if(!chmod($tmp,$stat['mode']&0777))return false;
            clearstatcache(true,$path);if(is_link($path)||!hash_equals(hash('sha256',$old),hash_file('sha256',$path)))return false;
            if(!@rename($tmp,$path))return false;$tmp='';clearstatcache(true,$path);
            if(!hash_equals(hash('sha256',$new),hash_file('sha256',$path)))throw new AppException('بررسی ذخیره کلید کامل نشد؛ وضعیت امنیت را دوباره بررسی کنید.',503);
            if(function_exists('opcache_invalidate'))@opcache_invalidate($path,true);
            return true;
        }finally{if($tmp!==''&&is_file($tmp))@unlink($tmp);flock($handle,LOCK_UN);fclose($handle);}
    }
    private static function writeAll($stream,$bytes){$offset=0;$size=strlen($bytes);while($offset<$size){$n=fwrite($stream,substr($bytes,$offset));if(!$n)return false;$offset+=$n;}return true;}
    private static function privateFiles($encoded){
        $base=realpath(WP_CONTENT_DIR);if(!$base||!is_writable($base))throw new ValidationException(['storage'=>'ذخیره خودکار کلید در این محیط مجاز نیست؛ اطلاعات قبلی تغییر نکرده است.']);
        $name='agah-private-keys-'.bin2hex(random_bytes(16));$dir=$base.'/'.$name;$paths=[];
        if(!@mkdir($dir,0700))throw new ValidationException(['storage'=>'ساخت محل محافظت‌شده کلید انجام نشد؛ اطلاعات تغییر نکرده است.']);
        try{
            if(!chmod($dir,0700))throw new \RuntimeException('Private directory mode');
            $files=['index.php'=>"<?php http_response_code(404); exit;\n",'.htaccess'=>"Require all denied\nDeny from all\n",'web.config'=>'<?xml version="1.0"?><configuration><system.webServer><security><authorization><remove users="*" roles="" verbs=""/><add accessType="Deny" users="*"/></authorization></security></system.webServer></configuration>'];
            $secretNames=[];for($i=0;$i<2;$i++){$f='key-'.bin2hex(random_bytes(16)).'.php';$secretNames[]=$f;$files[$f]=self::GUARD.$encoded."\n";}
            foreach($files as $f=>$bytes){$p=$dir.'/'.$f;$paths[]=$p;$stream=@fopen($p,'xb');if(!$stream)throw new \RuntimeException('Create private file');try{if(!chmod($p,0600)||!self::writeAll($stream,$bytes)||!fflush($stream))throw new \RuntimeException('Write private file');if(function_exists('fsync')&&!fsync($stream))throw new \RuntimeException('Sync private file');}finally{fclose($stream);}if(file_get_contents($p)!==$bytes)throw new \RuntimeException('Private readback');}
            return ['directory'=>$name,'files'=>$secretNames];
        }catch(\Throwable $e){foreach($paths as $p)if(is_file($p))@unlink($p);@rmdir($dir);throw new ValidationException(['storage'=>'ذخیره محافظت‌شده کلید کامل نشد؛ اطلاعات قبلی تغییر نکرده است.']);}
    }
    private static function removePrivate(array $meta){
        if(empty($meta['directory'])||!preg_match('/^agah-private-keys-[a-f0-9]{32}$/D',$meta['directory']))return;
        $dir=WP_CONTENT_DIR.'/'.$meta['directory'];if(is_link($dir))return;
        foreach(array_merge($meta['files']??[],['index.php','.htaccess','web.config']) as $f)if(is_file($dir.'/'.$f)&&!is_link($dir.'/'.$f))@unlink($dir.'/'.$f);@rmdir($dir);
    }
    public static function activate(){
        if(!current_user_can('manage_options'))throw new ForbiddenException();
        $lock='agah_key_setup_'.substr(hash('sha256',Db::t('companies')),0,32);
        if((int)Db::val('SELECT GET_LOCK(%s,5)',$lock)!==1)throw new ConflictException('تنظیم کلید در حال اجراست؛ کمی بعد دوباره بررسی کنید.');
        $created=[];
        try{
            if(Crypto::hasConfiguredKey())return ['already'=>true];
            // Pin, do NOT rotate. Exact master equivalence preserves all purposes, not only national IDs.
            $raw=wp_salt('secure_auth').'|'.wp_salt('auth');self::assertFallback($raw);
            if(strlen($raw)<32||strlen($raw)>2048)throw new ValidationException(['key'=>'کلید فعلی با ذخیره خودکار سازگار نیست؛ اطلاعات تغییر نکرده است.']);
            $encoded=base64_encode($raw);$probe=Crypto::encrypt('agah-key-stability-check');$decoded=base64_decode($encoded,true);
            $nonce=base64_decode(substr($probe,3),true);$key=hash_hkdf('sha256',$decoded,32,'agah-erp-enc');
            if(!hash_equals($raw,$decoded)||sodium_crypto_secretbox_open(substr($nonce,24),substr($nonce,0,24),$key)!=='agah-key-stability-check')throw new ValidationException(['key'=>'تطبیق کلید قبلی تأیید نشد؛ اطلاعات تغییر نکرده است.']);
            $meta=['version'=>1,'fingerprint'=>self::fingerprint($raw),'at'=>gmdate('c'),'by'=>get_current_user_id()];
            if(self::writeConfig($encoded)){$meta['storage']='config';}
            else{$created=self::privateFiles($encoded);$meta+=['storage'=>'private']+$created;}
            update_option(self::OPTION,$meta,false);wp_cache_delete(self::OPTION,'options');
            $saved=self::meta();if(($saved['fingerprint']??'')!==$meta['fingerprint']){if($created)self::removePrivate($created);throw new AppException('ثبت وضعیت کلید کامل نشد؛ دوباره وضعیت را بررسی کنید.',503);}
            if($meta['storage']==='private'&&!hash_equals($encoded,self::storedValue()))throw new AppException('بازخوانی کلید تأیید نشد؛ عملیات مالی متوقف شد.',503);
            // Config applies on the next request. Key bytes are unchanged in this request too.
            Audit::log('security.key.pinned','system',0,null,['storage'=>$meta['storage'],'legacy_key_preserved'=>true],0,get_current_user_id());
            return ['already'=>false,'storage'=>$meta['storage']];
        }finally{Db::val('SELECT RELEASE_LOCK(%s)',$lock);}
    }
}
