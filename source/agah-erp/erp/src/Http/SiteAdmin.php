<?php
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Audit,Db,FiscalContext,Request,KeySetup,Crypto,ValidationException,ConflictException,ForbiddenException};
if(!defined('ABSPATH'))exit;
final class SiteAdmin {
    public static function register(){
        add_action('wp_ajax_agah_key_setup',[__CLASS__,'setupKey']);
        add_action('admin_menu',function(){add_menu_page('مدیریت حسابداری','مدیریت حسابداری','manage_options','agah-access',[__CLASS__,'page'],'dashicons-groups');});
        add_action('show_user_profile',[__CLASS__,'profile']);add_action('edit_user_profile',[__CLASS__,'profile']);
        add_action('user_new_form',function(){self::profile((object)['ID'=>0]);});add_action('user_register',[__CLASS__,'saveProfile']);
        add_action('user_profile_update_errors',[__CLASS__,'profileErrors'],10,3);
        add_action('admin_enqueue_scripts',function($hook){if(in_array($hook,['profile.php','user-edit.php','user-new.php','toplevel_page_agah-access'],true)){wp_enqueue_script('agah-admin-search',AGAH_ERP_URL.'/assets/select-search.js',[],AGAH_ERP_VERSION,true);wp_enqueue_script('agah-admin-access',AGAH_ERP_URL.'/assets/admin-access.js',['agah-admin-search'],AGAH_ERP_VERSION,true);}});
    }
    public static function setupKey(){
        if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'اجازه انجام این کار را ندارید.'],403);
        if(!check_ajax_referer('agah_key_setup','nonce',false))wp_send_json_error(['message'=>'نشست منقضی شده است؛ صفحه را تازه کنید.'],403);
        try{KeySetup::activate();wp_send_json_success(['message'=>'کلید پایدار فعال است؛ اطلاعات قبلی بدون تغییر حفظ شد.']);}
        catch(\Agah\Erp\Core\AppException $e){wp_send_json_error(['message'=>$e instanceof ValidationException?implode('، ',$e->errors):$e->getMessage()],$e->status);}
        catch(\Throwable $e){wp_send_json_error(['message'=>'تکمیل تنظیم امنیتی انجام نشد؛ وضعیت را دوباره بررسی کنید.'],500);}
    }
    private static function keyPanel(){
        $ready=Crypto::hasConfiguredKey();
        echo '<section id="agah-crypto-security"><h2>امنیت رمزنگاری</h2><p>'.($ready?'کلید پایدار رمزنگاری فعال است.':'کلید پایدار هنوز فعال نشده است.').'</p><p>فعال‌سازی، کلید فعلی را پایدار ذخیره می‌کند و اطلاعات شرکت‌ها، اشخاص و اسناد را تغییر نمی‌دهد. نیازی به ورود به هاست یا ویرایش فایل نیست.</p>';
        if(!$ready){echo '<form id="agah-key-setup" action="'.esc_url(admin_url('admin-ajax.php')).'" method="post"><input type="hidden" name="action" value="agah_key_setup"><input type="hidden" name="nonce" value="'.esc_attr(wp_create_nonce('agah_key_setup')).'"><button class="button button-primary">فعال‌سازی خودکار کلید پایدار</button><p role="status" aria-live="polite"></p></form>';wp_enqueue_script('agah-key-setup',AGAH_ERP_URL.'/assets/key-setup.js',[],AGAH_ERP_VERSION,true);}
        echo '</section><hr>';
    }
    public static function grant($cid,$uid,$role,$status,$risk=false,$reason=''){
        if(!current_user_can('manage_options'))throw new ForbiddenException();
        if(\Agah\Erp\Modules\Maintenance\BackupService::blocked())throw new ConflictException('بازیابی در حال اجراست.');
        if(!in_array($status,['active','inactive'],true)||!get_userdata($uid))throw new ValidationException(['user'=>'کاربر یا وضعیت معتبر نیست.']);
        $self=$uid===get_current_user_id();
        return Db::tx(function()use($cid,$uid,$role,$status,$risk,$reason,$self){
            if(!Db::row('SELECT id FROM '.Db::t('companies').' WHERE id=%d FOR UPDATE',$cid))throw new ValidationException(['company'=>'شرکت معتبر نیست.']);
            $r=Db::row('SELECT * FROM '.Db::t('roles').' WHERE company_id=%d AND id=%d',$cid,$role);if(!$r)throw new ValidationException(['role'=>'نقش متعلق به این شرکت نیست.']);
            $old=Db::row('SELECT m.*,r.role_key FROM '.Db::t('company_members').' m JOIN '.Db::t('roles').' r ON r.company_id=m.company_id AND r.id=m.role_id WHERE m.company_id=%d AND m.user_id=%d',$cid,$uid);
            if($old&&(int)$old['role_id']===$role&&$old['status']===$status)return;
            if($self&&$status==='active'&&(!$risk||mb_strlen(Request::clean($reason,300))<5))throw new ValidationException(['risk'=>'برای اعطای دسترسی به خودتان، پذیرش خطر و دلیل حداقل پنج نویسه لازم است.']);
            if($old&&$old['role_key']==='company_admin'&&$old['status']==='active'&&($status!=='active'||$r['role_key']!=='company_admin')){
                $n=Db::val('SELECT COUNT(*) FROM '.Db::t('company_members').' m JOIN '.Db::t('roles')." r ON r.company_id=m.company_id AND r.id=m.role_id WHERE m.company_id=%d AND m.status='active' AND r.role_key='company_admin'",$cid);if((int)$n<=1)throw new ConflictException('آخرین مدیر فعال شرکت قابل حذف یا تنزل نیست.');
            }
            $data=['role_id'=>$role,'status'=>$status];
            if($old)Db::update('company_members',$data,['company_id'=>$cid,'user_id'=>$uid]);
            else Db::insert('company_members',$data+['company_id'=>$cid,'user_id'=>$uid,'created_at'=>gmdate('Y-m-d H:i:s')]);
            Audit::log($self?'platform.self_access':'platform.member_access','user',$uid,$old,$data+['risk_accepted'=>$self&&$risk,'reason'=>Request::clean($reason,300)],$cid);
        });
    }
    public static function profile($user){
        if(!current_user_can('manage_options'))return;
        echo '<div class="agah-admin-selects"><h2>دسترسی حسابداری، مستقل در هر شرکت</h2><p>این تنظیم نقش وردپرس را تغییر نمی‌دهد. برای نقش سفارشی، در شرکت نقش بسازید و اینجا انتخاب کنید.</p>';wp_nonce_field('agah_platform_members','agah_members_nonce');
        self::matrix((int)$user->ID);
        if((int)$user->ID===get_current_user_id())echo '<p><label><input type="checkbox" name="agah_self_risk" value="1"> خطر دسترسی به اطلاعات شرکت دیگر را می‌پذیرم و آگاهانه به خودم مجوز می‌دهم.</label></p><label>دلیل دسترسی<input name="agah_access_reason" maxlength="300" value="'.esc_attr(Request::str('post','agah_access_reason',300)).'"></label>';
        echo '</div>';
    }
    private static function matrix($uid){
        echo '<table class="widefat"><thead><tr><th>شرکت</th><th>نقش این شرکت</th><th>وضعیت</th></tr></thead><tbody>';
        foreach(self::matrixCompanies($uid) as $c){$cid=(int)$c['id'];$m=Db::row('SELECT * FROM '.Db::t('company_members').' WHERE company_id=%d AND user_id=%d',$cid,$uid);$posted=$_POST['agah_access'][$cid]??null;if(Request::isPost()&&is_array($posted)&&is_scalar($posted['role']??null)&&is_scalar($posted['status']??null))$m=['role_id'=>(int)$posted['role'],'status'=>(string)$posted['status']];
            echo '<tr><td>'.esc_html($c['name']).'</td><td><select name="agah_access['.$cid.'][role]"><option value="0">بدون عضویت</option>';
            foreach(Db::rows('SELECT id,name FROM '.Db::t('roles').' WHERE company_id=%d ORDER BY id',$cid) as $r)echo '<option value="'.(int)$r['id'].'" '.selected((int)($m['role_id']??0),(int)$r['id'],false).'>'.esc_html($r['name']).'</option>';
            echo '</select></td><td><select name="agah_access['.$cid.'][status]"><option value="active">فعال</option><option value="inactive" '.selected($m['status']??'active','inactive',false).'>غیرفعال</option></select></td></tr>';
        }echo '</tbody></table>';self::companyAdder($uid);
    }
    private static function matrixCompanies($uid){
        $page=max(1,Request::int('get','agah_member_page',1));$rows=Db::rows('SELECT c.id,c.name FROM '.Db::t('companies').' c JOIN '.Db::t('company_members')." m ON m.company_id=c.id AND m.user_id=%d WHERE m.status<>'removed' ORDER BY c.id LIMIT 50 OFFSET %d",$uid,($page-1)*50);
        // Re-display only submitted company rows after a validation error; never hundreds of blank inputs.
        $ids=array_column($rows,'id');foreach(array_slice(array_keys(is_array($_POST['agah_access']??null)?$_POST['agah_access']:[]),0,100) as $id){if(ctype_digit((string)$id)&&!in_array((string)$id,array_map('strval',$ids),true)){$r=Db::row('SELECT id,name FROM '.Db::t('companies').' WHERE id=%d',(int)$id);if($r)$rows[]=$r;}}
        return $rows;
    }
    private static function companyAdder($uid){
        $companies=Db::rows('SELECT id,name FROM '.Db::t('companies').' ORDER BY name,id');$map=[];
        foreach(Db::rows('SELECT company_id,id,name FROM '.Db::t('roles').' ORDER BY id') as $role)$map[$role['company_id']][]=['id'=>(int)$role['id'],'name'=>$role['name']];
        echo '<div class="agah-company-adder"><label>شرکت برای افزودن<select data-add-company><option value="0">شرکت را انتخاب کنید</option>';
        foreach($companies as $c)echo '<option value="'.(int)$c['id'].'">'.esc_html($c['name'].' (#'.$c['id'].')').'</option>';
        echo '</select></label><button type="button" class="button" data-add-company-button>افزودن شرکت به فهرست دسترسی</button><p>در هر ذخیره تا ۱۰۰ شرکت؛ عضویت‌های موجود در صفحه‌های ۵۰تایی‌اند. شرکت‌های نمایش‌داده‌نشده تغییر نمی‌کنند.</p><script type="application/json" data-company-roles>'.wp_json_encode($map,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).'</script></div>';
        $page=max(1,Request::int('get','agah_member_page',1));$count=(int)Db::val('SELECT COUNT(*) FROM '.Db::t('company_members')." WHERE user_id=%d AND status<>'removed'",$uid);
        if($page>1)echo '<a href="'.esc_url(add_query_arg('agah_member_page',$page-1)).'">عضویت‌های قبلی</a> ';
        if($page*50<$count)echo '<a href="'.esc_url(add_query_arg('agah_member_page',$page+1)).'">عضویت‌های بعدی</a>';
    }
    public static function profileErrors($errors,$update,$user){
        if(!$update||!current_user_can('manage_options')||!isset($_POST['agah_members_nonce'])||$errors->has_errors())return;
        if(!wp_verify_nonce(Request::str('post','agah_members_nonce',100),'agah_platform_members')){$errors->add('agah_access','نشست مدیریت دسترسی منقضی شده است.');return;}
        try{self::saveMatrix((int)$user->ID);}catch(\Agah\Erp\Core\AppException $e){$errors->add('agah_access',$e instanceof ValidationException?implode('، ',$e->errors):$e->getMessage());}
    }
    public static function saveProfile($uid){
        if(!current_user_can('manage_options')||!isset($_POST['agah_members_nonce']))return;
        check_admin_referer('agah_platform_members','agah_members_nonce');
        try{self::saveMatrix((int)$uid);}catch(\Agah\Erp\Core\AppException $e){wp_die(esc_html($e instanceof ValidationException?implode('، ',$e->errors):$e->getMessage()),'دسترسی شرکت',['back_link'=>true]);}
    }
    private static function saveMatrix($uid){
        $rows=$_POST['agah_access']??[];if(!is_array($rows)||count($rows)>100)throw new ValidationException(['access'=>'تعداد شرکت‌ها معتبر نیست.']);
        ksort($rows,SORT_NUMERIC);Db::tx(function()use($rows,$uid){foreach($rows as $cid=>$row){if(!ctype_digit((string)$cid)||!is_array($row)||!is_scalar($row['role']??null)||!is_scalar($row['status']??null))throw new ValidationException(['access'=>'ورودی معتبر نیست.']);$role=(int)$row['role'];$status=(string)$row['status'];if(!$role){$old=Db::row('SELECT role_id FROM '.Db::t('company_members').' WHERE company_id=%d AND user_id=%d',(int)$cid,$uid);if(!$old)continue;$role=(int)$old['role_id'];$status='inactive';}self::grant((int)$cid,$uid,$role,$status,!empty($_POST['agah_self_risk']),Request::str('post','agah_access_reason',300));}});
    }
    public static function page(){
        if(!current_user_can('manage_options'))return;
        echo '<div class="wrap agah-admin-selects"><h1>مدیریت دسترسی و سال‌های حسابداری</h1>';
        if(Request::isPost()){
            check_admin_referer('agah_site_admin');
            try{
                if(Request::str('post','admin_task')==='catalog'){
                    $text=\Agah\Erp\Core\Money::normDigits(Request::text('post','catalog',3000));$years=array_values(array_unique(array_map('intval',preg_split('/[;\r\n]+/',$text))));sort($years);if(!$years||count($years)>301||min($years)<1300||max($years)>1600)throw new ValidationException(['years'=>'سال‌ها از ۱۳۰۰ تا ۱۶۰۰ و هر کدام در یک خط باشند.']);update_option('agah_fiscal_catalog',$years,false);
                    update_option('agah_allow_automated_comments',Request::flag('post','allow_automated_comments')?'1':'0',false);
                }else self::saveMatrix(Request::int('post','user_id'));
                echo '<div class="notice notice-success"><p>ذخیره شد.</p></div>';
            }catch(\Agah\Erp\Core\AppException $e){echo '<div class="notice notice-error"><p>'.esc_html($e instanceof ValidationException?implode('، ',$e->errors):$e->getMessage()).'</p></div>';}
        }
        self::keyPanel();
        echo '<p>مدیر سایت عضویت خودکار در شرکت‌ها ندارد. اعطای دسترسی به خود نیازمند پذیرش صریح خطر و دلیل ثبت‌شونده در ممیزی است.</p><p>برای تخصیص یک یا چند شرکت، <a href="'.esc_url(admin_url('users.php')).'">کاربر را ویرایش کنید</a>.</p>';
        echo '<h2>دسترسی خودم</h2><form method="post">';wp_nonce_field('agah_site_admin');echo '<input type="hidden" name="admin_task" value="self"><input type="hidden" name="user_id" value="'.get_current_user_id().'">';self::matrix(get_current_user_id());echo '<p><label><input name="agah_self_risk" type="checkbox" value="1"> خطر دسترسی به داده شرکت‌ها را می‌پذیرم.</label></p><label>دلیل<input name="agah_access_reason" maxlength="300" value="'.esc_attr(Request::str('post','agah_access_reason',300)).'"></label><p><button class="button button-primary">ذخیره دسترسی خودم</button></p></form>';
        echo '<hr><h2>فهرست سال‌های قابل انتخاب</h2><p>پیش‌فرض ۱۴۰۰ تا ۱۴۲۰ است. هر سال در یک خط؛ حذف از این فهرست داده یا سال مالی ساخته‌شده شرکت را حذف نمی‌کند.</p><form method="post">';wp_nonce_field('agah_site_admin');echo '<input type="hidden" name="admin_task" value="catalog"><textarea name="catalog" rows="12" dir="ltr">'.esc_textarea(implode("\n",FiscalContext::catalog())).'</textarea><p><label><input type="checkbox" name="allow_automated_comments" value="1" '.checked(get_option('agah_allow_automated_comments','0'),'1',false).'> اجازه دیدگاه خودکار و مهمان (کاهش محدودیت پیش‌فرض ضدربات)</label></p><button class="button button-primary">ذخیره تنظیمات</button></form></div>';
    }
}
