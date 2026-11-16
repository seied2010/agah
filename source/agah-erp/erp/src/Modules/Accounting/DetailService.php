<?php
/** تفصیلی شناور شرکت؛ حذف فقط بدون گردش، دادهٔ مرجع اشخاص و بانک‌ها در ماژول مبدأ مدیریت خواهد شد. */
namespace Agah\Erp\Modules\Accounting;
use Agah\Erp\Core\{Db,Access,Audit,Request,ValidationException,ConflictException,NotFoundException};
if(!defined('ABSPATH')) exit;
final class DetailService {
    const KINDS=['party'=>'شخص','bank'=>'بانک','cash'=>'صندوق','cost_center'=>'مرکز هزینه','project'=>'پروژه','other'=>'سایر / شعبه'];
    public static function all($cid) {
        Access::need($cid,'detail.view');return Db::rows('SELECT * FROM '.Db::t('details').' WHERE company_id=%d ORDER BY code',$cid);
    }
    /** فهرست جست‌وجوشونده در SQL؛ خروجی به پنجاه ردیف هر صفحه محدود است. */
    public static function page($cid,$page,$q='') {
        Access::need($cid,'detail.view');global $wpdb;
        $page=max(1,(int)$page);$like='%'.$wpdb->esc_like(Request::clean($q,100)).'%';$where='company_id=%d AND (code LIKE %s OR name LIKE %s)';
        $count=(int)Db::val('SELECT COUNT(*) FROM '.Db::t('details').' WHERE '.$where,$cid,$like,$like);
        return ['rows'=>Db::rows('SELECT * FROM '.Db::t('details').' WHERE '.$where.' ORDER BY code LIMIT 50 OFFSET %d',$cid,$like,$like,($page-1)*50),'pages'=>(int)ceil($count/50),'page'=>$page,'count'=>$count];
    }
    public static function get($cid,$id) {
        $r=Db::row('SELECT * FROM '.Db::t('details').' WHERE company_id=%d AND id=%d',$cid,$id);
        if(!$r) throw new NotFoundException();return $r;
    }
    public static function save($cid,$id,array $in) {
        return Access::write($cid,$id?'detail.edit':'detail.create',function()use($cid,$id,$in){
            $old=$id?self::get($cid,$id):null;
            $name=Request::clean($in['name']??'',190);$code=\Agah\Erp\Core\Money::normDigits(trim($in['code']??''));$kind=$in['kind']??'other';
            $errors=[];
            if(mb_strlen($name)<2) $errors['name']='نام تفصیلی را وارد کنید.';
            if(!preg_match('/^[0-9A-Za-z_-]{1,20}$/D',$code)) $errors['code']='کد ۱ تا ۲۰ حرف یا رقم لاتین باشد.';
            if(!isset(self::KINDS[$kind])) $errors['kind']='نوع تفصیلی معتبر نیست.';
            if(Db::val('SELECT id FROM '.Db::t('details').' WHERE company_id=%d AND code=%s AND id<>%d',$cid,$code,$id)) $errors['code']='کد تفصیلی تکراری است.';
            if($errors) throw new ValidationException($errors);
            $used=$id && Db::val('SELECT COUNT(*) FROM '.Db::t('voucher_lines').' WHERE company_id=%d AND detail_id=%d',$cid,$id);
            if($old && ($old['ref_type'] || ($used && ($old['kind']!==$kind || $old['code']!==$code)))) throw new ConflictException('تفصیلی وابسته یا دارای گردش قابل تغییر ساختاری نیست.');
            $new=['name'=>$name,'code'=>$code,'kind'=>$kind,'is_active'=>!empty($in['is_active'])?1:0];
            if($id) Db::update('details',$new,['company_id'=>$cid,'id'=>$id]);
            else $id=Db::insert('details',$new+['company_id'=>$cid,'created_at'=>gmdate('Y-m-d H:i:s')]);
            Audit::log('detail.save','detail',$id,$old,$new,$cid);return (int)$id;
        });
    }
    public static function delete($cid,$id) {
        Access::write($cid,'detail.delete',function()use($cid,$id){
            $old=self::get($cid,$id);
            if($old['ref_type'] || Db::val('SELECT COUNT(*) FROM '.Db::t('voucher_lines').' WHERE company_id=%d AND detail_id=%d',$cid,$id)) throw new ConflictException('تفصیلی وابسته یا دارای گردش حذف نمی‌شود؛ آن را غیرفعال کنید.');
            Db::delete('details',['company_id'=>$cid,'id'=>$id]);Audit::log('detail.delete','detail',$id,$old,null,$cid);
        });
    }
}
