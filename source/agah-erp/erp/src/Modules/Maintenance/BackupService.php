<?php

/** Host-operated encrypted logical backups. No HTTP archive download or restore endpoint. */
namespace Agah\Erp\Modules\Maintenance;
use Agah\Erp\Core\{Audit,Crypto,Ctx,Db,Schema,Settings,ConflictException,ValidationException};
if(!defined('ABSPATH'))exit;
final class BackupService {
    const MAGIC="AGAH-BACKUP-1\n";
    private static $restoring=false;
    private static function host(){
        if(!(defined('WP_CLI')&&WP_CLI)&&!doing_action('agah_erp_backup'))throw new ConflictException('عملیات پشتیبان فقط از وظیفه سرور مجاز است.');
    }
    private static function key(){
        $value=defined('AGAH_BACKUP_KEY')?(string)AGAH_BACKUP_KEY:(string)getenv('AGAH_BACKUP_KEY');
        $key=base64_decode($value,true);
        if($key===false||strlen($key)!==32)throw new ValidationException(['backup'=>'کلید مستقل پشتیبان ۳۲بایتی base64 تنظیم نشده است.']);
        return $key;
    }
    public static function directory(){
        $value=defined('AGAH_BACKUP_DIR')?(string)AGAH_BACKUP_DIR:(string)getenv('AGAH_BACKUP_DIR');
        $dir=realpath($value);
        if(!$dir||!is_dir($dir)||is_link($value)||!is_writable($dir)||((fileperms($dir)&0077)!==0))throw new ValidationException(['backup'=>'پوشه خصوصی موجود، قابل نوشتن و با مجوز0700 تنظیم کنید.']);
        $roots=[realpath(ABSPATH),realpath(WP_CONTENT_DIR)];
        $web=defined('AGAH_WEB_ROOT')?AGAH_WEB_ROOT:($_SERVER['DOCUMENT_ROOT']??'');
        if(!$web)$web=dirname(untrailingslashit(ABSPATH));
        $realWeb=realpath($web);
        if(!$realWeb)throw new ValidationException(['backup'=>'ریشه عمومی وب معتبر تنظیم نشده است.']);
        $roots[]=$realWeb;
        foreach(array_filter($roots) as $root)if($dir===$root||strpos($dir,$root.DIRECTORY_SEPARATOR)===0)throw new ValidationException(['backup'=>'محل پشتیبان نباید زیر مسیر عمومی وب باشد.']);
        return $dir;
    }
    public static function ready(){
        try{
            self::key();
            self::directory();
            return true;
        }
        catch(\Throwable $e){
            return false;
        }
    }
    public static function blocked(){
        if(self::$restoring)return false;
        global $wpdb;
        return (int)Db::val('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s','agah_erp_restore_until')>time();
    }
    private static function identity(){
        $id=get_option('agah_backup_instance');
        if(!$id){
            $id=Crypto::uuid();
            add_option('agah_backup_instance',$id,'','no');
            $id=get_option('agah_backup_instance');
        }
        return $id;
    }
    private static function metadata(){
        global $wpdb;
        $actual=Db::col('SHOW TABLES LIKE %s',$wpdb->esc_like(Db::t('')).'%');
        $expected=array_map([Db::class,'t'],Schema::tables());
        sort($actual);
        sort($expected);
        if($actual!==$expected)throw new ValidationException(['backup'=>'فهرست جدول‌های سامانه با نسخه کد تطبیق ندارد.']);
        $out=[];
        foreach(Schema::tables() as $table){
            $columns=Db::rows('SHOW COLUMNS FROM '.Db::t($table));
            $out[$table]=['columns'=>array_column($columns,'Field'),'nullable'=>array_column($columns,'Null','Field'),'primary'=>[]];
            foreach(Db::rows('SHOW INDEX FROM '.Db::t($table)." WHERE Key_name='PRIMARY'") as $r)$out[$table]['primary'][]=$r['Column_name'];
        }
        return $out;
    }
    private static function memoryBudget(){
        $v=trim(ini_get('memory_limit'));
        if($v===''||$v==='-1')return 0;
        $n=(int)$v;
        $suffix=strtolower(substr($v,-1));
        if($suffix==='g')$n*=1073741824;
        elseif($suffix==='m')$n*=1048576;
        elseif($suffix==='k')$n*=1024;
        return $n;
    }
    private static function limit(){
        return defined('AGAH_BACKUP_MAX_BYTES')?max(1048576,min(268435456,(int)AGAH_BACKUP_MAX_BYTES)):33554432;
    }
    private static function capture(){
        return Db::tx(function(){
            Db::rows('SELECT id FROM '.Db::t('companies').' ORDER BY id FOR UPDATE');$meta=self::metadata();$tables=[];$size=0; foreach($meta as $table=>$m){
                $rows=[];$order=implode(',',array_map(function($c){
                    return '`'.$c.'`';
                }
                ,$m['primary']));$step=in_array($table,['files','expense_images'],true)?1:100;$offset=0;do{
                    $batch=Db::rows('SELECT * FROM '.Db::t($table).' ORDER BY '.$order.' LIMIT '.$step.' OFFSET %d',$offset);foreach($batch as $r){
                        $encoded=[];foreach($m['columns'] as $c){
                            $v=$r[$c];$encoded[]=$v===null?null:base64_encode((string)$v);$size+=($v===null?4:strlen((string)$v)*2+8);
                        }
                        if((self::memoryBudget()>0&&memory_get_usage(true)+$size*5>self::memoryBudget()-16777216)||$size>self::limit())throw new ValidationException(['backup'=>'حجم داده از سقف پشتیبان داخلی بیشتر است؛ پشتیبان پایگاه داده در سطح سرور لازم است.']);$rows[]=$encoded;
                    }
                    $offset+=$step;
                }
                while(count($batch)===$step);$tables[$table]=['columns'=>$m['columns'],'rows'=>$rows];
            }
            $users=[];global $wpdb;foreach(Db::rows('SELECT ID,user_login FROM '.$wpdb->users.' ORDER BY ID') as $u)$users[(string)$u['ID']]=hash('sha256',$u['user_login']);return ['format'=>1,'schema'=>Schema::VERSION,'site'=>self::identity(),'created_at'=>gmdate('c'),'encryption_probe'=>Crypto::encrypt('agah-backup-key-check'),'users'=>$users,'tables'=>$tables];
        }
        );
    }
    private static function write(array $data,$purpose){
        $dir=self::directory();
        $raw=wp_json_encode($data);
        if(!is_string($raw)||strlen($raw)>self::limit()*2)throw new ValidationException(['backup'=>'اندازه پشتیبان مجاز نیست.']);
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $bytes=self::MAGIC.$nonce.sodium_crypto_secretbox($raw,$nonce,self::key());
        $name=gmdate('Ymd-His').'-'.$purpose.'-'.bin2hex(random_bytes(12)).'.agah';
        $temp=tempnam($dir,'.agah-');
        if(!$temp)throw new \RuntimeException('backup storage unavailable');
        chmod($temp,0600);
        try{
            $n=file_put_contents($temp,$bytes,LOCK_EX);
            if($n!==strlen($bytes))throw new \RuntimeException('backup write incomplete');
            $path=$dir.DIRECTORY_SEPARATOR.$name;
            if(!rename($temp,$path))throw new \RuntimeException('backup publish failed');
            chmod($path,0600);
            return $name;
        }
        finally{
            if(is_file($temp))unlink($temp);
        }
    }
    public static function create($purpose='manual'){
        self::host();
        if(!in_array($purpose,['manual','scheduled','pre-restore'],true))throw new ValidationException(['backup'=>'نوع پشتیبان معتبر نیست.']);
        if($purpose==='scheduled'&&!(defined('AGAH_BACKUP_ENABLED')&&AGAH_BACKUP_ENABLED===true))throw new ConflictException('پشتیبان زمان‌بندی‌شده فعال نشده است.');
        if(self::blocked())throw new ConflictException('بازیابی در حال اجراست.');
        self::key();
        self::directory();
        $metadata=self::metadata();
        self::plan($metadata);
        $name=self::write(self::capture(),$purpose);
        self::prune($name);
        update_option('agah_backup_last',['status'=>'ok','at'=>gmdate('c')],false);
        return $name;
    }
    private static function prune($keep){
        $count=defined('AGAH_BACKUP_KEEP')?max(2,min(100,(int)AGAH_BACKUP_KEEP)):7;
        $files=array_values(array_diff(self::listing(),[$keep]));
        foreach(array_slice($files,$count-1) as $f)if($f!==$keep)unlink(self::directory().DIRECTORY_SEPARATOR.$f);
    }
    public static function listing(){
        self::host();
        $files=[];
        foreach(scandir(self::directory()) as $name)if(preg_match('/^[0-9]{8}-[0-9]{6}-(manual|scheduled|pre-restore)-[a-f0-9]{24}\.agah$/D',$name)&&!is_link(self::directory().DIRECTORY_SEPARATOR.$name))$files[]=$name;
        rsort($files,SORT_STRING);
        return $files;
    }
    private static function read($name){
        if(!is_string($name)||!preg_match('/^[0-9]{8}-[0-9]{6}-(manual|scheduled|pre-restore)-[a-f0-9]{24}\.agah$/D',$name))throw new ValidationException(['backup'=>'نام پشتیبان معتبر نیست.']);
        $path=self::directory().DIRECTORY_SEPARATOR.$name;
        if(is_link($path)||!is_file($path)||((fileperms($path)&0077)!==0)||filesize($path)>self::limit()*2+1024)throw new ValidationException(['backup'=>'فایل پشتیبان معتبر نیست.']);
        $bytes=file_get_contents($path);
        $head=strlen(self::MAGIC);
        if(substr($bytes,0,$head)!==self::MAGIC||strlen($bytes)<$head+40)throw new ValidationException(['backup'=>'قالب پشتیبان معتبر نیست.']);
        $nonce=substr($bytes,$head,24);
        $raw=sodium_crypto_secretbox_open(substr($bytes,$head+24),$nonce,self::key());
        if($raw===false)throw new ValidationException(['backup'=>'اصالت پشتیبان یا کلید آن تأیید نشد.']);
        $budget=self::memoryBudget();
        if($budget>0&&memory_get_usage(true)+strlen($raw)*12>$budget-16777216)throw new ValidationException(['backup'=>'حافظه کافی برای اعتبارسنجی این پشتیبان نیست؛ بازیابی در سطح سرور لازم است.']);
        $data=json_decode($raw,true,512,JSON_BIGINT_AS_STRING);
        if(!is_array($data))throw new ValidationException(['backup'=>'ساختار پشتیبان معتبر نیست.']);
        return $data;
    }
    private static function validate(array $data){
        if(($data['format']??null)!==1||($data['schema']??'')!==Schema::VERSION||($data['site']??'')!==self::identity()||Crypto::decrypt($data['encryption_probe']??'')!=='agah-backup-key-check')throw new ValidationException(['backup'=>'نسخه، هویت نصب یا کلید رمزنگاری داده با پشتیبان یکسان نیست.']);
        $meta=self::metadata();
        $actual=array_keys($data['tables']??[]);
        $expected=array_keys($meta);
        sort($actual);
        sort($expected);
        if($actual!==$expected)throw new ValidationException(['backup'=>'فهرست جدول‌ها تطبیق ندارد.']);
        foreach($meta as $table=>$m){
            $d=$data['tables'][$table];
            if(($d['columns']??null)!==$m['columns']||!is_array($d['rows']??null))throw new ValidationException(['backup'=>'ستون‌های پشتیبان تطبیق ندارند.']);
            foreach($d['rows'] as $row){
                if(!is_array($row)||count($row)!==count($m['columns'])||array_keys($row)!==range(0,count($m['columns'])-1))throw new ValidationException(['backup'=>'ردیف پشتیبان ناقص است.']);
                foreach($row as $v)if($v!==null&&(!is_string($v)||base64_decode($v,true)===false))throw new ValidationException(['backup'=>'محتوای باینری پشتیبان معتبر نیست.']);
            }
        }
        if(!is_array($data['users']??null)||!$data['users'])throw new ValidationException(['backup'=>'فهرست کاربران مبدأ ناقص است.']);
        global $wpdb;
        foreach($data['users'] as $id=>$hash){
            if(!ctype_digit((string)$id)||(int)$id<1||!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash))throw new ValidationException(['backup'=>'فهرست کاربران مبدأ معتبر نیست.']);
            $login=Db::val('SELECT user_login FROM '.$wpdb->users.' WHERE ID=%d',$id);
            if($login===null||!hash_equals($hash,hash('sha256',$login)))throw new ValidationException(['backup'=>'کاربران WordPress با نصب مبدأ تطبیق ندارند؛ نگاشت شناسه‌ها خودکار انجام نمی‌شود.']);
        }
        return $meta;
    }
    private static function plan(array $meta){
        $tables=array_fill_keys(array_keys($meta),true);
        $prefix=Db::t('');
        $database=Db::val('SELECT DATABASE()');
        $edges=[];
        $nullable=[];
        $refs=Db::rows('SELECT CONSTRAINT_SCHEMA,REFERENCED_TABLE_SCHEMA,TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_NAME IS NOT NULL AND (CONSTRAINT_SCHEMA=DATABASE() OR REFERENCED_TABLE_SCHEMA=DATABASE())');
        $groups=[];
        foreach($refs as $r)$groups[$r['CONSTRAINT_SCHEMA'].'|'.$r['TABLE_NAME'].'|'.$r['CONSTRAINT_NAME']][]=$r;
        foreach($groups as $group){
            $r=$group[0];
            $child=substr($r['TABLE_NAME'],strlen($prefix));
            $parent=substr($r['REFERENCED_TABLE_NAME'],strlen($prefix));
            $inChild=$r['CONSTRAINT_SCHEMA']===$database&&strpos($r['TABLE_NAME'],$prefix)===0&&isset($tables[$child]);
            $inParent=$r['REFERENCED_TABLE_SCHEMA']===$database&&strpos($r['REFERENCED_TABLE_NAME'],$prefix)===0&&isset($tables[$parent]);
            if($inChild!==$inParent)throw new ValidationException(['backup'=>'رابطه با جدول خارج سامانه مانع بازیابی داخلی است.']);
            if(!$inChild)continue;
            $break=[];
            foreach($group as $part)if($meta[$child]['nullable'][$part['COLUMN_NAME']]==='YES')$break[$part['COLUMN_NAME']]=true;
            if($break){
                foreach($break as $col=>$unused)$nullable[$child][$col]=true;
            }
            elseif($child!==$parent)$edges[$child][$parent]=true;
            else throw new ValidationException(['backup'=>'وابستگی خودارجاع اجباری مانع بازیابی داخلی است.']);
        }
        // A composite FK containing a nullable target is temporarily broken as a whole, including its company_id edge.
        $order=[];
        $pending=$tables;
        while($pending){
            $progress=false;
            foreach(array_keys($pending) as $t){
                $deps=$edges[$t]??[];
                if(!array_intersect_key($deps,$pending)){
                    unset($pending[$t]);
                    $order[]=$t;
                    $progress=true;
                }
            }
            if(!$progress)throw new ValidationException(['backup'=>'چرخه اجباری جدول‌ها نیاز به بازیابی تخصصی دارد.']);
        }
        return [$order,$nullable];
    }
    public static function inspect($name){
        self::host();
        $data=self::read($name);
        $data=\Agah\Erp\Core\BackupUpgrade::normalize($data,self::metadata());
        $meta=self::validate($data);
        self::plan($meta);
        $counts=[];
        foreach($data['tables'] as $t=>$d)$counts[$t]=count($d['rows']);
        return ['schema'=>$data['schema'],'created_at'=>$data['created_at'],'counts'=>$counts];
    }
    public static function restore($name){
        self::host();
        if(!(defined('WP_CLI')&&WP_CLI))throw new ConflictException('بازیابی فقط از خط فرمان سرور مجاز است.');
        $data=self::read($name);
        $legacy=(string)($data['schema']??'')==='21' && Schema::VERSION==='22';
        $data=\Agah\Erp\Core\BackupUpgrade::normalize($data,self::metadata());
        $meta=self::validate($data);
        [$order,$nullable]=self::plan($meta);
        if(Db::inTx())throw new ConflictException('بازیابی باید تراکنش مستقل باشد.');
        $lock='agah_restore_'.substr(hash('sha256',Db::t('companies')),0,24);
        if((int)Db::val('SELECT GET_LOCK(%s,0)',$lock)!==1)throw new ConflictException('بازیابی دیگری فعال است.');
        $pre='';
        try{
            update_option('agah_erp_restore_until',time()+3600,false);
            self::$restoring=true;
            $pre=self::create('pre-restore');
            self::inspect($pre);
            Db::tx(function()use($data,$meta,$order,$nullable,$legacy){
                Db::rows('SELECT id FROM '.Db::t('companies').' ORDER BY id FOR UPDATE'); $keep=['auth_states'=>true,'rate_limits'=>true];$clear=['idempotency'=>true,'login_otp'=>true]; foreach($nullable as $table=>$cols)if(!isset($keep[$table]))foreach($cols as $col=>$unused)Db::raw('UPDATE '.Db::t($table).' SET `'.$col.'`=NULL'); foreach(array_reverse($order) as $table)if(!isset($keep[$table]))Db::raw('DELETE FROM '.Db::t($table)); foreach($order as $table){
                    if(isset($keep[$table])||isset($clear[$table]))continue;foreach($data['tables'][$table]['rows'] as $row){
                        $r=[];foreach($meta[$table]['columns'] as $i=>$col)$r[$col]=isset($nullable[$table][$col])?null:($row[$i]===null?null:base64_decode($row[$i],true));Db::insert($table,$r);
                    }
                }
                foreach($nullable as $table=>$cols){
                    if(isset($keep[$table])||isset($clear[$table]))continue;foreach($data['tables'][$table]['rows'] as $row){
                        $all=[];foreach($meta[$table]['columns'] as $i=>$c)$all[$c]=$row[$i]===null?null:base64_decode($row[$i],true);$where=[];foreach($meta[$table]['primary'] as $c)$where[$c]=$all[$c];$values=array_intersect_key($all,$cols);if($values)Db::update($table,$values,$where);
                    }
                }
                // A backup must never revive consumed OTPs, form tokens or recovery codes. Keep live MFA and throttles.
                Db::raw('UPDATE '.Db::t('auth_states')." SET pending_enc=NULL,pending_session='',pending_until=0,version=version+1"); foreach(Db::col('SELECT id FROM '.Db::t('companies')) as $cid)if(Audit::verify((int)$cid)!==null)throw new ValidationException(['backup'=>'زنجیره ممیزی پشتیبان ناسالم است.']);if(Audit::verify(0)!==null)throw new ValidationException(['backup'=>'ممیزی پلتفرم پشتیبان ناسالم است.']); $bad=Db::val('SELECT v.id FROM '.Db::t('vouchers').' v LEFT JOIN '.Db::t('voucher_lines')." l ON l.company_id=v.company_id AND l.voucher_id=v.id WHERE v.status IN ('approved','final') GROUP BY v.id,v.total_debit,v.total_credit HAVING COALESCE(SUM(l.debit),0)<>v.total_debit OR COALESCE(SUM(l.credit),0)<>v.total_credit OR v.total_debit<>v.total_credit OR v.total_debit=0 LIMIT 1");if($bad)throw new ValidationException(['backup'=>'سند نامتوازن در پشتیبان است.']); foreach(['files'=>['sha256','file_data'],'expense_images'=>['sha256','image_data']] as $table=>$cols){
                    $offset=0;do{
                        $batch=Db::rows('SELECT '.$cols[0].','.$cols[1].' FROM '.Db::t($table).' ORDER BY id LIMIT 50 OFFSET %d',$offset);foreach($batch as $r)if(!hash_equals($r[$cols[0]],hash('sha256',$r[$cols[1]])))throw new ValidationException(['backup'=>'یکپارچگی فایل پشتیبان ناسالم است.']);$offset+=50;
                    }
                    while(count($batch)===50);
                }
                if($legacy)\Agah\Erp\Core\ReleaseSetup::seedInvoiceDeletion();
                do_action('agah_erp_all_restored');
            }
            );
            global $wpdb;
            foreach(Db::col('SELECT ID FROM '.$wpdb->users) as $uid)\WP_Session_Tokens::get_instance((int)$uid)->destroy_all();
            Settings::flush();
            Ctx::reset();
            wp_cache_flush();
            update_option('agah_backup_restore_last',['status'=>'ok','at'=>gmdate('c')],false);
            return ['pre_restore'=>$pre,'status'=>'restored'];
        }
        finally{
            self::$restoring=false;
            delete_option('agah_erp_restore_until');
            Db::val('SELECT RELEASE_LOCK(%s)',$lock);
        }
    }
    public static function reconcileSchedule(){
        if(defined('AGAH_BACKUP_ENABLED')&&AGAH_BACKUP_ENABLED===true&&self::ready()){
            if(!wp_next_scheduled('agah_erp_backup'))wp_schedule_event(time()+3600,'daily','agah_erp_backup');
        }elseif(wp_next_scheduled('agah_erp_backup')){
            wp_clear_scheduled_hook('agah_erp_backup');
        }
    }
    public static function register(){
        add_action('agah_erp_backup',function(){
            try{
                self::create('scheduled');
            }
            catch(\Throwable $e){
                update_option('agah_backup_last',['status'=>'failed','at'=>gmdate('c')],false);error_log('[agah-backup] scheduled backup failed: '.get_class($e));
            }
        }
        );
        add_action('init',[__CLASS__,'reconcileSchedule']);
        if(defined('WP_CLI')&&WP_CLI){
            \WP_CLI::add_command('agah backup',function($args,$assoc){
                try{
                    $action=$args[0]??'list';if($action==='create')\WP_CLI::success(self::create());elseif($action==='list')foreach(self::listing() as $name)\WP_CLI::line($name);elseif($action==='verify')\WP_CLI::line(wp_json_encode(self::inspect($args[1]??'')));elseif($action==='restore'){
                        if(empty($assoc['yes']))\WP_CLI::error('Restore requires --yes after reviewing agah backup verify and a maintenance window.');\WP_CLI::line(wp_json_encode(self::restore($args[1]??'')));
                    }
                    else \WP_CLI::error('Use create, list, verify or restore.');
                }
                catch(ValidationException $e){
                    \WP_CLI::error(implode(' ',$e->errors));
                }
                catch(ConflictException $e){
                    \WP_CLI::error($e->getMessage());
                }
                catch(\Throwable $e){
                    error_log('[agah-backup-cli] '.get_class($e));\WP_CLI::error('عملیات پشتیبان کامل نشد؛ گزارش سرور را بررسی کنید.');
                }
            }
            );
        }
    }
}
