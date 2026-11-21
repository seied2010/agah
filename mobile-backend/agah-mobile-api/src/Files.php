<?php
namespace Agah\Mobile;
use Agah\Erp\Core\{Db,Ctx,Business,AppException,ValidationException};
use Agah\Erp\Modules\Files\FileService;
if (!defined('ABSPATH')) exit;
final class Files {
    public static function listing($r) {
        [$c]=Api::scope($r); Ctx::need('file.view');
        $type=Api::pathValue($r,'target'); $id=Business::id(Api::pathValue($r,'id'));
        FileService::target($c,$type,$id);
        // type was validated against FileService::TARGETS before use as a column name.
        $rows=Db::rows('SELECT id,original_name,mime,byte_size,sha256,created_at FROM '.Db::t('files').' WHERE company_id=%d AND '.$type.'_id=%d ORDER BY id DESC LIMIT 50',$c,$id);
        foreach($rows as &$row)$row['source']='file';unset($row);
        // Explicit opt-in keeps old clients from treating legacy image IDs as generic file IDs.
        if($type==='expense'&&Api::text($r,'include_expense_images','0',1)==='1') {
            $expense=\Agah\Erp\Modules\Expenses\ExpenseService::get($c,$id);
            foreach($expense['images'] as $image)$rows[]=$image+['original_name'=>'تصویر رسید '.$image['id'],'source'=>'expense_image'];
        }
        return ['rows'=>$rows];
    }
    public static function upload($r) {
        [$c]=Api::scope($r); $type=Api::pathValue($r,'target'); $id=Business::id(Api::pathValue($r,'id'));
        if (strlen($r->get_body())>3000000) throw new AppException('فایل باید حداکثر دو مگابایت باشد.',413);
        $body=$r->get_json_params();
        if (!is_array($body)||!is_string($body['name']??null)||!is_string($body['content_base64']??null)) throw new ValidationException(['file'=>'فایل معتبر انتخاب کنید.']);
        $bytes=base64_decode($body['content_base64'],true);
        if ($bytes===false||strlen($bytes)>2097152) throw new ValidationException(['file'=>'فایل باید حداکثر دو مگابایت باشد.']);
        $hash=hash('sha256',$bytes);
        return Operations::execute($c,'file.create',$r->get_header('idempotency-key'),'file.attach',[$type,$id,$body['name'],$hash],function () use ($c,$type,$id,$body,$bytes) {
            return ['id'=>(string)FileService::attach($c,$type,$id,$body['name'],$bytes),'entity'=>'file','state'=>'committed'];
        });
    }
    public static function download($r) {
        [$c]=Api::scope($r); $file=FileService::get($c,Business::id(Api::pathValue($r,'id')));
        if ((int)$file['byte_size']>2097152) throw new AppException('این فایل بزرگ‌تر از سقف دریافت موبایل است؛ از وب دریافت کنید.',413);
        return ['name'=>$file['original_name'],'mime'=>$file['mime'],'sha256'=>$file['sha256'],'content_base64'=>base64_encode($file['file_data'])];
    }
    public static function expenseImage($r) {
        [$c]=Api::scope($r);
        $image=\Agah\Erp\Modules\Expenses\ExpenseService::image($c,Business::id(Api::pathValue($r,'id')));
        if((int)$image['byte_size']>2097152)throw new AppException('تصویر بزرگ‌تر از سقف دریافت موبایل است.',413);
        $extension=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'][$image['mime']]??'image';
        return ['name'=>'receipt-'.$image['id'].'.'.$extension,'mime'=>$image['mime'],'sha256'=>$image['sha256'],'content_base64'=>base64_encode($image['image_data'])];
    }
}
