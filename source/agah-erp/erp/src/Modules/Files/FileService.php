<?php
/** Private BLOB store: no public path, original name never used as a storage path. */
namespace Agah\Erp\Modules\Files;
use Agah\Erp\Core\{Access,Audit,Business,Crypto,Ctx,Db,Request,Settings,ValidationException,NotFoundException};
use Agah\Erp\Modules\Trade\InvoiceService;
if(!defined('ABSPATH'))exit;
final class FileService {
    const TARGETS=['invoice'=>'invoices','voucher'=>'vouchers','party'=>'parties','expense'=>'expense_docs','employee'=>'employees'];
    public static function target($cid,$type,$id,$write=false){
        if(!isset(self::TARGETS[$type]))throw new ValidationException(['target'=>'نوع پیوست معتبر نیست.']);
        $r=Business::row(self::TARGETS[$type],$cid,$id);
        // مدارک هویتی/پرسنلی: مشاهده فقط با مجوز اطلاعات حساس حقوق؛ افزودن یا حذف علاوه بر آن ویرایش پرسنل می‌خواهد.
        if($type==='employee'){Access::need($cid,'payroll.sensitive');if($write)Access::need($cid,'payroll.edit');return $r;}
        $resource=$type==='invoice'?InvoiceService::resource($r['kind']):$type;
        Access::need($cid,$resource.'.'.($write?'edit':'view'));return $r;
    }
    public static function validate($name,$bytes){
        $max=max(1,min(10,Settings::int('max_upload_mb')))*1048576;
        if(!is_string($name)||!mb_check_encoding($name,'UTF-8')||!is_string($bytes)||strlen($bytes)<1||strlen($bytes)>$max||strlen($name)>190||preg_match('/[\\x00-\\x1f\\x7f\/\\\\]/',$name)||!preg_match('/^[^.]+\.(jpg|jpeg|png|webp|pdf)$/iD',$name,$m))throw new ValidationException(['file'=>'نام تک‌پسوندی و فایل مجاز تا سقف تنظیم‌شده لازم است.']);
        if(!class_exists('finfo'))throw new ValidationException(['file'=>'افزونه fileinfo روی سرور لازم است.']);
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);$ext=strtolower($m[1]);$types=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','pdf'=>'application/pdf'];
        if($mime!==$types[$ext]||preg_match('/<\?(?:php|=)|<script\b/i',$bytes))throw new ValidationException(['file'=>'محتوا با نوع مجاز فایل تطبیق ندارد.']);
        if($ext==='pdf'){
            // فقط دانلود می‌شود و هرگز داخل صفحه باز نمی‌شود. جریان‌های فشرده باز و همراه متن خام برای محتوای فعال بررسی می‌شوند.
            if(!self::pdfSafe($bytes))throw new ValidationException(['file'=>'PDF معتبر و بدون محتوای فعال (اسکریپت، فایل پیوست، فرم ارسالی یا رمزگذاری) لازم است.']);
        }else{
            $size=@getimagesizefromstring($bytes);if(!$size||($size['mime']??'')!==$mime||$size[0]>10000||$size[1]>10000||$size[0]*$size[1]>20000000)throw new ValidationException(['file'=>'ابعاد یا محتوای تصویر معتبر نیست.']);
            if(!function_exists('imagecreatefromstring'))throw new ValidationException(['file'=>'افزونه GD برای بررسی کامل تصویر لازم است.']);
            $limit=trim(ini_get('memory_limit'));$budget=-1;if($limit!==''&&$limit!=='-1'){$budget=(int)$limit;$suffix=strtolower(substr($limit,-1));if($suffix==='g')$budget*=1073741824;elseif($suffix==='m')$budget*=1048576;elseif($suffix==='k')$budget*=1024;}
            if($budget>0&&memory_get_usage(true)+$size[0]*$size[1]*8+strlen($bytes)*2>$budget-8388608)throw new ValidationException(['file'=>'تصویر برای حافظه این سرور بزرگ است.']);
            $image=@imagecreatefromstring($bytes);if(!$image)throw new ValidationException(['file'=>'داده کامل تصویر قابل رمزگشایی نیست.']);if(PHP_VERSION_ID<80000)imagedestroy($image);
        }return $mime;
    }
    public static function attach($cid,$type,$id,$name,$bytes,$category=''){
        return Access::write($cid,'file.create',function()use($cid,$type,$id,$name,$bytes,$category){self::target($cid,$type,$id,true);$mime=self::validate($name,$bytes);
            if((int)Db::val('SELECT COUNT(*) FROM '.Db::t('files').' WHERE company_id=%d AND '.$type.'_id=%d',$cid,$id)>=50)throw new ValidationException(['file'=>'حداکثر ۵۰ پیوست برای هر رکورد.']);
            $meta=[$type.'_id'=>(int)$id,'original_name'=>$name,'stored_name'=>bin2hex(random_bytes(32)),'category'=>Request::clean($category,80),'mime'=>$mime,'sha256'=>hash('sha256',$bytes),'byte_size'=>strlen($bytes),'created_by'=>Ctx::uid()];
            $fid=Business::save('files',$cid,0,$meta+['file_data'=>$bytes]);Audit::log('file.attach','file',$fid,null,['target'=>$type,'target_id'=>$id,'sha256'=>$meta['sha256'],'byte_size'=>strlen($bytes)],$cid);return $fid;
        });
    }
    public static function get($cid,$id,$content=true){
        Access::need($cid,'file.view');$cols=$content?'*':'id,company_id,invoice_id,voucher_id,party_id,expense_id,employee_id,original_name,category,mime,sha256,byte_size,stored_name,created_at';$r=Db::row('SELECT '.$cols.' FROM '.Db::t('files').' WHERE company_id=%d AND id=%d',$cid,Business::id($id));if(!$r)throw new NotFoundException();
        $targets=0;foreach(self::TARGETS as $type=>$table)if($r[$type.'_id']){self::target($cid,$type,$r[$type.'_id']);$targets++;}if($targets!==1)throw new NotFoundException();
        if($content&&!hash_equals($r['sha256'],hash('sha256',$r['file_data'])))throw new ValidationException(['file'=>'یکپارچگی فایل تأیید نشد.']);return $r;
    }
    /** PDF غیرفعال: سرآیند و پایان معتبر، بدون رمزگذاری و بدون کلید فعال در متن خام یا جریان‌های فشردهٔ Flate. */
    public static function pdfSafe($bytes){
        if(!preg_match('/^%PDF-(?:1\.[0-7]|2\.0)/',$bytes)||strpos(substr($bytes,-2048),'%%EOF')===false)return false;
        $texts=[$bytes];$budget=60*1048576;
        if(preg_match_all('/(?<!end)stream\r?\n/',$bytes,$m,PREG_OFFSET_CAPTURE)){
            foreach($m[0] as $hit){$start=$hit[1]+strlen($hit[0]);$end=strpos($bytes,'endstream',$start);if($end===false)return false;
                $raw=substr($bytes,$start,$end-$start);$plain=@zlib_decode($raw,$budget);
                if(is_string($plain)){$budget-=strlen($plain);if($budget<=0)return false;$texts[]=$plain;}}
        }
        foreach($texts as $text){
            $text=preg_replace_callback('/#([0-9a-fA-F]{2})/',function($h){return chr(hexdec($h[1]));},$text);
            if(preg_match('/\/(?:JavaScript|JS|Launch|EmbeddedFiles?|RichMedia|XFA|SubmitForm|ImportData|GoToR|GoToE|Rendition|Encrypt)\b/',$text))return false;
        }
        return true;
    }
    public static function remove($cid,$id){return Access::write($cid,'file.delete',function()use($cid,$id){$r=self::get($cid,$id,false);foreach(self::TARGETS as $type=>$t)if($r[$type.'_id'])self::target($cid,$type,$r[$type.'_id'],true);Db::delete('files',['company_id'=>$cid,'id'=>$id]);Audit::log('file.delete','file',$id,['sha256'=>$r['sha256'],'byte_size'=>(int)$r['byte_size']],null,$cid);});}
    public static function page($cid,$page=1,$q='',$category=''){
        Access::need($cid,'file.view');global $wpdb;$parts=[];foreach(self::TARGETS as $type=>$table){if($type==='invoice'){
            $kinds=[];foreach(InvoiceService::KINDS as $kind=>$label)if(Ctx::can(InvoiceService::resource($kind).'.view'))$kinds[]=$kind;
            foreach($kinds as $kind)$parts[]="invoice_id IN (SELECT id FROM ".Db::t('invoices')." WHERE company_id=".(int)$cid." AND kind='".$kind."')";
        }elseif(Ctx::can($type==='employee'?'payroll.sensitive':$type.'.view'))$parts[]=$type.'_id IS NOT NULL';}
        if(!$parts)return ['rows'=>[],'count'=>0,'pages'=>0,'page'=>1];$page=max(1,(int)$page);$like='%'.$wpdb->esc_like(Request::clean($q,100)).'%';$where='company_id=%d AND ('.implode(' OR ',$parts).') AND (original_name LIKE %s OR category LIKE %s)';$args=[$cid,$like,$like];if($category!==''){$where.=' AND category=%s';$args[]=Request::clean($category,80);}
        $count=(int)Db::val('SELECT COUNT(*) FROM '.Db::t('files').' WHERE '.$where,...$args);return ['count'=>$count,'page'=>$page,'pages'=>(int)ceil($count/50),'rows'=>Db::rows('SELECT id,original_name,category,mime,byte_size,sha256,created_at,invoice_id,voucher_id,party_id,expense_id,employee_id FROM '.Db::t('files').' WHERE '.$where.' ORDER BY id DESC LIMIT 50 OFFSET %d',...array_merge($args,[($page-1)*50]))];
    }
}
