<?php
/** اعضا و نقش‌های مستقل هر شرکت؛ به کاربران وردپرس فقط نقش subscriber می‌دهد و نقش سراسری را ارتقا نمی‌دهد. */
namespace Agah\Erp\Modules\Company;
use Agah\Erp\Core\{Db,Ctx,Access,Audit,Request,ValidationException,ConflictException,NotFoundException};
use Agah\Erp\Security\{Caps,Auth};
if(!defined('ABSPATH')) exit;
final class MemberService {
    public static function members($cid) {
        Access::need($cid,'member.view');global $wpdb;
        return Db::rows('SELECT m.*,u.user_login,u.display_name,r.name AS role_name FROM '.Db::t('company_members').' m JOIN '.$wpdb->users.' u ON u.ID=m.user_id JOIN '.Db::t('roles').' r ON r.company_id=m.company_id AND r.id=m.role_id WHERE m.company_id=%d ORDER BY m.user_id',$cid);
    }
    /** فهرست اعضا با جست‌وجو و صفحه‌بندی در SQL؛ نقش انتخابی از همین شرکت خوانده می‌شود. */
    public static function page($cid,$page,$q='') {
        Access::need($cid,'member.view');global $wpdb;
        $page=max(1,(int)$page);$like='%'.$wpdb->esc_like(Request::clean($q,100)).'%';
        $join=' FROM '.Db::t('company_members').' m JOIN '.$wpdb->users.' u ON u.ID=m.user_id JOIN '.Db::t('roles').' r ON r.company_id=m.company_id AND r.id=m.role_id WHERE m.company_id=%d AND (u.user_login LIKE %s OR u.display_name LIKE %s)';
        $count=(int)Db::val('SELECT COUNT(*)'.$join,$cid,$like,$like);
        $rows=Db::rows('SELECT m.*,u.user_login,u.display_name,r.name AS role_name'.$join.' ORDER BY m.user_id LIMIT 50 OFFSET %d',$cid,$like,$like,($page-1)*50);
        return ['rows'=>$rows,'pages'=>(int)ceil($count/50),'page'=>$page,'count'=>$count];
    }
    public static function roles($cid) {
        if(Ctx::cidOrZero()!==(int)$cid) throw new NotFoundException();
        if(!Ctx::can('role.view') && !Ctx::can('member.view')) Ctx::need('role.view');
        $roles=Db::rows('SELECT * FROM '.Db::t('roles').' WHERE company_id=%d ORDER BY id',$cid);
        foreach($roles as &$r) $r['caps']=Db::col('SELECT cap FROM '.Db::t('role_caps').' WHERE role_id=%d',$r['id']);
        return $roles;
    }
    private static function role($cid,$id) {
        $r=Db::row('SELECT * FROM '.Db::t('roles').' WHERE company_id=%d AND id=%d',$cid,$id);
        if(!$r) throw new NotFoundException();
        foreach(Db::col('SELECT cap FROM '.Db::t('role_caps').' WHERE role_id=%d',$id) as $cap) if(!Ctx::can($cap)) throw new ConflictException('نمی‌توانید مجوزی فراتر از دسترسی خود واگذار کنید.');
        return $r;
    }
    /** افزودن کاربر موجود یا ساخت کاربر جدید با گذرواژهٔ معتبر؛ عضویت حذف‌شده دوباره فعال می‌شود. */
    public static function add($cid,array $in) {
        return Access::write($cid,'member.create',function()use($cid,$in){
            $role=self::role($cid,(int)($in['role_id']??0));
            $login=trim((string)($in['login']??''));
            $u=is_email($login)?get_user_by('email',$login):get_user_by('login',$login);
            $created=false;
            if(!$u) {
                $name=sanitize_user($login,true);$email=sanitize_email($in['email']??'');$pass=(string)($in['password']??'');
                $errors=[];
                if(!$name || $name!==$login || mb_strlen($name)>60) $errors['login']='نام کاربری معتبر وارد کنید.';
                if(!is_email($email)) $errors['email']='ایمیل معتبر وارد کنید.';
                if($e=Auth::passwordError($pass)) $errors['password']=$e;
                if($errors) throw new ValidationException($errors);
                $id=wp_insert_user(['user_login'=>$name,'user_email'=>$email,'user_pass'=>$pass,'display_name'=>Request::clean($in['display_name']??$name),'role'=>'subscriber']);
                if(is_wp_error($id)) throw new ValidationException(['login'=>'نام کاربری یا ایمیل قابل استفاده نیست.']);
                $u=get_userdata($id);$created=true;
            }
            $old=Db::row('SELECT * FROM '.Db::t('company_members').' WHERE company_id=%d AND user_id=%d',$cid,$u->ID);
            if($old && $old['status']!=='removed') throw new ConflictException('این کاربر قبلاً عضو شرکت است.');
            if($old) Db::update('company_members',['role_id'=>$role['id'],'status'=>'active'],['company_id'=>$cid,'user_id'=>$u->ID]);
            else Db::insert('company_members',['company_id'=>$cid,'user_id'=>$u->ID,'role_id'=>$role['id'],'status'=>'active','created_at'=>gmdate('Y-m-d H:i:s')]);
            Audit::log('member.add','user',$u->ID,$old,['role_id'=>$role['id'],'status'=>'active','new_user'=>$created],$cid);
            return (int)$u->ID;
        });
    }
    /** قفل شرکت باعث می‌شود دو مدیر هم‌زمان نتوانند آخرین مدیران را حذف کنند؛ سابقهٔ عضویت حفظ می‌شود. */
    public static function change($cid,$uid,$roleId,$status) {
        return Access::write($cid,$status==='removed'?'member.delete':'member.edit',function()use($cid,$uid,$roleId,$status){
            if(!in_array($status,['active','inactive','removed'],true)) throw new ValidationException(['status'=>'وضعیت معتبر نیست.']);
            $old=Db::row('SELECT m.*,r.role_key FROM '.Db::t('company_members').' m JOIN '.Db::t('roles').' r ON r.id=m.role_id AND r.company_id=m.company_id WHERE m.company_id=%d AND m.user_id=%d',$cid,$uid);
            if(!$old) throw new NotFoundException();
            $role=self::role($cid,$roleId);
            if($old['role_key']==='company_admin' && $old['status']==='active' && ($status!=='active' || $role['role_key']!=='company_admin')) {
                $count=(int)Db::val('SELECT COUNT(*) FROM '.Db::t('company_members').' m JOIN '.Db::t('roles')." r ON r.id=m.role_id AND r.company_id=m.company_id WHERE m.company_id=%d AND m.status='active' AND r.role_key='company_admin'",$cid);
                if($count<=1) throw new ConflictException('آخرین مدیر فعال شرکت قابل حذف یا تنزل نیست.');
            }
            $new=['role_id'=>$roleId,'status'=>$status];Db::update('company_members',$new,['company_id'=>$cid,'user_id'=>$uid]);
            Audit::log('member.update','user',$uid,$old,$new,$cid);
        });
    }
    /** نقش سیستمی فقط منبع کپی است؛ نقش سفارشی با فهرست معتبر و زیرمجموعهٔ مجوزهای سازنده ذخیره می‌شود. */
    public static function saveRole($cid,$id,$name,array $caps) {
        return Access::write($cid,'role.edit',function()use($cid,$id,$name,$caps){
            $name=Request::clean($name,100);
            if(mb_strlen($name)<2) throw new ValidationException(['name'=>'نام نقش حداقل دو نویسه باشد.']);
            $caps=array_values(array_unique($caps));
            foreach($caps as $cap) if(!is_string($cap) || !Caps::isValid($cap) || !Ctx::can($cap)) throw new ValidationException(['caps'=>'مجوز نامعتبر یا فراتر از دسترسی شماست.']);
            $old=null;
            if($id) {
                $old=self::role($cid,$id);
                if($old['is_system']) throw new ConflictException('نقش سیستمی قابل تغییر نیست؛ کپی بسازید.');
                $old['caps']=Db::col('SELECT cap FROM '.Db::t('role_caps').' WHERE role_id=%d',$id);
                Db::update('roles',['name'=>$name],['company_id'=>$cid,'id'=>$id]);Db::delete('role_caps',['role_id'=>$id]);
            } else $id=Db::insert('roles',['company_id'=>$cid,'name'=>$name,'role_key'=>'custom_'.\Agah\Erp\Core\Crypto::token(8),'is_system'=>0,'created_at'=>gmdate('Y-m-d H:i:s')]);
            foreach($caps as $cap) Db::insert('role_caps',['role_id'=>$id,'cap'=>$cap]);
            Audit::log('role.save','role',$id,$old,['name'=>$name,'caps'=>$caps],$cid);return (int)$id;
        });
    }
    public static function deleteRole($cid,$id) {
        Access::write($cid,'role.edit',function()use($cid,$id){
            $old=self::role($cid,$id);
            if($old['is_system'] || Db::val('SELECT COUNT(*) FROM '.Db::t('company_members').' WHERE company_id=%d AND role_id=%d',$cid,$id)) throw new ConflictException('نقش سیستمی یا دارای سابقهٔ عضویت حذف نمی‌شود.');
            Db::delete('roles',['company_id'=>$cid,'id'=>$id]);Audit::log('role.delete','role',$id,$old,null,$cid);
        });
    }
}
