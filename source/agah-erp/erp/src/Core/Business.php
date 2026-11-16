<?php
/** ابزارهای مشترک ماژول‌های تجاری؛ نوع/شناسه/تاریخ و مبلغ را پیش از DB کنترل می‌کند. */
namespace Agah\Erp\Core;
if(!defined('ABSPATH')) exit;
final class Business {
    /** شناسه باید صحیح نامنفی باشد؛ تبدیل float/رشتهٔ مخلوط به شناسه ممنوع است. */
    public static function id($v){$v=is_int($v)?(string)$v:$v;if(!is_string($v)||!preg_match('/^[0-9]{1,18}$/D',$v))throw new ValidationException(['id'=>'شناسه معتبر نیست.']);return (int)$v;}
    /** شناسهٔ شرکت هرگز از رکورد ورودی استنتاج نمی‌شود. */
    public static function row($table,$cid,$id,$lock=false){$r=Db::row('SELECT * FROM '.Db::t($table).' WHERE company_id=%d AND id=%d'.($lock?' FOR UPDATE':''),$cid,self::id($id));if(!$r)throw new NotFoundException();return $r;}
    /** مبلغ داخلی int ریال؛ مرز HTTP مسئول تبدیل تومان است. */
    public static function amount($v,$positive=false){if(!is_int($v)||$v<($positive?1:0)||$v>Money::MAX)throw new ValidationException(['amount'=>'مبلغ صحیح ریال و در محدوده مجاز وارد کنید.']);return $v;}
    public static function date($v){if(!is_string($v))throw new ValidationException(['date'=>'تاریخ معتبر نیست.']);$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$d||$d->format('Y-m-d')!==$v)throw new ValidationException(['date'=>'تاریخ معتبر نیست.']);return $v;}
    /** ثبت/ویرایش UUID و زمان مشترک؛ همهٔ callerها باید زیر Access::write باشند. */
    public static function save($table,$cid,$id,array $data){$now=gmdate('Y-m-d H:i:s');$data['updated_at']=$now;if($id){Db::update($table,$data,['company_id'=>$cid,'id'=>$id]);return (int)$id;}return Db::insert($table,$data+['company_id'=>$cid,'uuid'=>Crypto::uuid(),'created_at'=>$now]);}
    /** فهرست محدود سمت سرور؛ نام جدول/ستون صرفاً از کد سرویس می‌آید. */
    public static function page($table,$cid,$page,$q='',$extra='',$args=[]){global $wpdb;$page=max(1,(int)$page);$like='%'.$wpdb->esc_like(Request::clean($q,100)).'%';$where='company_id=%d AND (code LIKE %s OR name LIKE %s)'.$extra;$a=array_merge([$cid,$like,$like],$args);$n=(int)Db::val('SELECT COUNT(*) FROM '.Db::t($table).' WHERE '.$where,...$a);return ['rows'=>Db::rows('SELECT * FROM '.Db::t($table).' WHERE '.$where.' ORDER BY '.self::order().' LIMIT 50 OFFSET %d',...array_merge($a,[($page-1)*50])),'count'=>$n,'page'=>$page,'pages'=>(int)ceil($n/50)];}
    /** UX-05: ترتیب روی کل نتیجه (پیش از صفحه‌بندی)؛ همان پارامترهای نشانی در خروجی Excel/CSV/چاپ هم اثر دارد. فقط ستون‌های مجاز. */
    public static function order(){$s=Request::str('get','sort',10);$d=strtolower(Request::str('get','dir',4))==='desc'?'DESC':'ASC';return in_array($s,['code','name','id'],true)?'`'.$s.'` '.$d.',id '.$d:'code';}
    public static function reason($s){$s=Request::clean($s,300);if(mb_strlen($s)<5)throw new ValidationException(['reason'=>'دلیل حداقل پنج نویسه باشد.']);return $s;}
}
