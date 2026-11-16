<?php

namespace Agah\Erp\Modules\Maintenance;
use Agah\Erp\Core\{Access,Audit,Crypto,Ctx,Db,Schema};
if(!defined('ABSPATH'))exit;
final class HealthService {
    public static function checks($cid){
        Access::need($cid,'health.view');
        $bad=[];
        foreach(Schema::tables() as $table){
            $engine=Db::val('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',Db::t($table));
            if($engine!=='InnoDB')$bad[]=$table;
        }
        $audit=Ctx::can('audit.view')?Audit::verify($cid):false;
        $missing=Schema::missingForeignKeys();
        $last=get_option('agah_backup_last',[]);
        $next=wp_next_scheduled('agah_erp_backup');
        return ['schema'=>['ok'=>get_option(Schema::OPTION_VER)===Schema::VERSION,'label'=>'نسخه ساختار پایگاه داده','detail'=>get_option(Schema::OPTION_VER).' / '.Schema::VERSION], 'engine'=>['ok'=>!$bad,'label'=>'موتور تراکنشی InnoDB','detail'=>$bad?implode(', ',$bad):'تمام جدول‌ها'], 'foreign_keys'=>['ok'=>!$missing,'label'=>'کلیدهای خارجی','detail'=>$missing?implode(', ',$missing):'کامل'], 'key'=>['ok'=>Crypto::hasConfiguredKey(),'label'=>'کلید اختصاصی رمزنگاری داده','detail'=>Crypto::hasConfiguredKey()?'تنظیم شده':'از مدیریت حسابداری، فعال‌سازی خودکار کلید پایدار را انجام دهید'], 'https'=>['ok'=>is_ssl(),'label'=>'ارتباط HTTPS این درخواست','detail'=>is_ssl()?'فعال':'درخواست فعلی HTTPS نیست'], 'audit'=>['ok'=>$audit===null,'label'=>'زنجیره ممیزی همین شرکت','detail'=>$audit===false?'نیازمند مجوز audit.view':($audit===null?'سالم':'ناسازگاری تشخیص داده شد')], 'backup'=>['ok'=>BackupService::ready(),'label'=>'محل خصوصی و کلید مستقل پشتیبان','detail'=>BackupService::ready()?'آماده':'نیازمند تنظیم مدیر سرور؛ هنوز فعال نیست'], 'schedule'=>['ok'=>$next!==false,'label'=>'زمان‌بندی پشتیبان','detail'=>$next?gmdate('Y-m-d H:i:s',$next).' UTC':'ثبت نشده'], 'last_backup'=>['ok'=>($last['status']??'')==='ok','label'=>'آخرین اجرای پشتیبان','detail'=>empty($last)?'هنوز اجرا نشده':(($last['status']==='ok'?'موفق':'ناموفق').' — '.$last['at'])]];
    }
}
