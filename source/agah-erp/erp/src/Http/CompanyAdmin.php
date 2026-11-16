<?php
/**
 * صفحهٔ مدیر سامانه برای شرکت‌ها: فیلدهای الزامی حقیقی/حقوقی و تفویض آن، دورهٔ نگهداری قانونی،
 * بازگشایی شرکت تعلیق‌شده، ادامهٔ حذف یا پاک‌سازی نیمه‌کاره و حذف بایگانی. مدیر سامانه به دادهٔ مالی شرکت‌ها دسترسی ندارد.
 */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{AppException,Jalali,Request,ValidationException};
use Agah\Erp\Modules\Company\{CompanyLifecycleService,CompanyPolicyService,CompanyService};
if(!defined('ABSPATH'))exit;
final class CompanyAdmin {
    public static function register(){
        add_action('admin_menu',function(){add_submenu_page('agah-access','شرکت‌ها: الزام، تعلیق و حذف','شرکت‌ها','manage_options','agah-companies',[__CLASS__,'page']);add_submenu_page('agah-access','اشتراک‌ها و اعلان‌ها','اشتراک‌ها','manage_options','agah-subscriptions',[__CLASS__,'subscriptions']);},20);
        add_action('agah_company_purge',function($id){CompanyLifecycleService::run((int)$id,20);});
        add_action('agah_erp_daily',[CompanyLifecycleService::class,'resumeAll']);
    }
    private static function handle(){
        check_admin_referer('agah_companies');$task=Request::str('post','task',30);$id=Request::int('post','id');
        switch($task){
            case 'policy':CompanyPolicyService::savePlatform(Phase2Controller::policyInput(),Request::flag('post','delegate'));return 'سیاست فیلدهای الزامی ذخیره شد.';
            case 'retention':CompanyLifecycleService::setRetention(Request::str('post','years',3));return 'دورهٔ نگهداری ذخیره شد.';
            case 'reactivate':CompanyLifecycleService::reactivate($id);return 'شرکت بازگشایی شد.';
            case 'resume_delete':CompanyLifecycleService::resumeDelete($id);return 'حذف شرکت تمام شد.';
            case 'run':return CompanyLifecycleService::run($id,20)?'پاک‌سازی تمام شد.':'یک نوبت پاک‌سازی اجرا شد؛ وضعیت را ببینید.';
            case 'archive_delete':CompanyLifecycleService::deleteArchive($id,Request::str('post','confirm_name',190),is_string($_POST['confirm_password']??null)?wp_unslash($_POST['confirm_password']):'');return 'بایگانی حذف شد.';
        }
        throw new ValidationException(['task'=>'عمل معتبر نیست.']);
    }
    /** پنل مدیر سامانه برای اشتراک (SUB-08) و صف پیام (NTF-03). راز درگاه در افزونهٔ درگاه ووکامرس است و این‌جا نمایش داده نمی‌شود. */
    public static function subscriptions(){
        if(!current_user_can('manage_options'))return;
        $S='\Agah\Erp\Modules\Subscription\SubscriptionService';$N='\Agah\Erp\Modules\Notify\NotificationService';$toman=fn($v)=>\Agah\Erp\Core\Money::fmt((int)$v,'toman');$in=fn($k)=>\Agah\Erp\Core\Money::fromInput(Request::str('post',$k,40),'toman');
        echo '<div class="wrap"><h1>اشتراک‌ها و اعلان‌ها</h1>';
        if(Request::isPost()){
            try{check_admin_referer('agah_companies');$id=Request::int('post','id');$msg='ذخیره شد.';
                switch(Request::str('post','task',30)){
                    case 'sub_enforce':$S::setEnforce(Request::flag('post','enforce'));$S::setOffsets(Request::str('post','offsets',60));break;
                    case 'sub_festival':$S::saveFestival(Request::flag('post','enabled'),Request::int('post','capacity'));break;
                    case 'sub_price':$f=Request::str('post','festival',40);$S::savePrice(Request::str('post','plan',20),Request::str('post','period',6),$in('amount'),$f===''?null:$in('festival'),null);break;
                    case 'sub_cod':$S::approveCod($id,Request::str('post','reason',300));$msg='پرداخت در محل تأیید و اشتراک فعال شد.';break;
                    case 'sub_cancel':$S::cancel($id,Request::str('post','reason',300),Request::flag('post','refund'));break;
                    case 'sub_grant':$S::grant(Request::int('post','company_id'),Request::str('post','plan',20),Request::int('post','months'),Request::str('post','reason',300),Request::str('post','activity',20));break;
                    case 'msg_drain':$msg=$N::drain(50).' پیام ارسال شد.';break;
                    default:throw new ValidationException(['task'=>'عمل معتبر نیست.']);
                }
                echo '<div class="notice notice-success"><p>'.esc_html($msg).'</p></div>';
            }catch(AppException $e){echo '<div class="notice notice-error"><p>'.esc_html($e instanceof ValidationException?implode('، ',$e->errors):$e->getMessage()).'</p></div>';}
        }
        delete_user_meta(get_current_user_id(),'agah_sub_alert');$fest=$S::festival();
        echo '<h2>اجبار اشتراک و اعلان انقضا</h2><form method="post">';self::form('sub_enforce');echo '<p><label><input type="checkbox" name="enforce" value="1" '.checked($S::enforced(),true,false).'> اجبار اشتراک (ثبت تازه در شرکت بدون اشتراک فعال بسته شود). برای نصب اختصاصی/سازمانی خاموش بماند؛ شرکت‌های ساخته‌شده پیش از روشن‌کردن تا تعیین طرح انتقال آزادند.</label></p><p><label>روزهای اعلان (مثبت = پیش از انقضا، صفر = روز انقضا، منفی = پس از آن) <input name="offsets" dir="ltr" value="'.esc_attr(implode(',',$S::offsets())).'"></label></p><button class="button button-primary">ذخیره</button></form>';
        echo '<h2>جشنوارهٔ ده مشتری نخست</h2><p>ظرفیت فقط با پرداخت موفق مصرف می‌شود. استفاده‌شده: '.esc_html((string)$S::festivalUsed()).' از '.esc_html((string)$fest['capacity']).'</p><form method="post">';self::form('sub_festival');echo '<label><input type="checkbox" name="enabled" value="1" '.checked($fest['enabled'],true,false).'> فعال</label> <label>ظرفیت <input type="number" name="capacity" min="0" max="1000" value="'.(int)$fest['capacity'].'"></label> <button class="button">ذخیره</button></form>';
        echo '<h2>قیمت‌ها (نسخه‌دار، تومان)</h2><table class="widefat striped"><thead><tr><th>طرح</th><th>دوره</th><th>قیمت</th><th>قیمت جشنواره</th><th>نسخه</th><th>از</th></tr></thead><tbody>';
        foreach($S::prices() as $p)echo '<tr><td>'.esc_html($S::PLANS[$p['plan_code']][0]??$p['plan_code']).'</td><td>'.esc_html($S::PERIODS[$p['period']][0]??$p['period']).'</td><td>'.esc_html($toman($p['amount'])).'</td><td>'.esc_html($p['festival_amount']!==null?$toman($p['festival_amount']):'—').'</td><td>'.(int)$p['version'].'</td><td>'.esc_html(Jalali::format(substr($p['effective_from'],0,10))).'</td></tr>';
        echo '</tbody></table><form method="post">';self::form('sub_price');echo '<select name="plan"><option value="specialist">تخصصی</option><option value="combined">ترکیبی</option></select> <select name="period"><option value="month">ماهانه</option><option value="year">سالانه</option></select> <label>قیمت <input name="amount" inputmode="decimal" required></label> <label>قیمت جشنواره (خالی = ندارد) <input name="festival" inputmode="decimal"></label> <button class="button">ثبت نسخهٔ تازه</button></form>';
        echo '<h2>اشتراک‌ها</h2><table class="widefat striped"><thead><tr><th>شرکت</th><th>طرح</th><th>از / تا</th><th>مبلغ</th><th>وضعیت</th><th>مرجع</th><th>عمل</th></tr></thead><tbody>';
        foreach($S::all() as $s){echo '<tr><td>'.esc_html($s['company_name']).'</td><td>'.esc_html(($S::PLANS[$s['plan_code']][0]??$s['plan_code']).' / '.($S::PERIODS[$s['period']][0]??'')).'</td><td>'.esc_html(($s['start_date']?Jalali::format($s['start_date']):'').' — '.($s['end_date']?Jalali::format($s['end_date']):'')).'</td><td>'.esc_html($toman($s['amount']).($s['festival']?' (جشنواره)':'')).'</td><td>'.esc_html($S::STATUS[$s['status']]??$s['status']).'</td><td dir="ltr">'.esc_html((string)$s['order_ref']).'</td><td>';
            if($s['status']==='cod_pending'){echo '<form method="post">';self::form('sub_cod',$s['id']);echo '<input name="reason" required minlength="5" placeholder="دلیل/مستند دریافت وجه"> <button class="button button-primary">تأیید پرداخت در محل</button></form>';}
            if(in_array($s['status'],['active','pending','cod_pending','review'],true)){echo '<details><summary>لغو / استرداد</summary><form method="post">';self::form('sub_cancel',$s['id']);echo '<input name="reason" required minlength="5" placeholder="دلیل"> <label><input type="checkbox" name="refund" value="1"> استرداد</label> <button class="button button-link-delete">ثبت</button></form></details>';}
            echo '</td></tr>';}
        echo '</tbody></table><h3>اعطای دستی (طرح سازمانی یا انتقال شرکت قدیمی)</h3><form method="post">';self::form('sub_grant');echo '<select name="company_id">';foreach(CompanyService::listAllMeta() as $c)echo '<option value="'.(int)$c['id'].'">'.esc_html($c['name']).'</option>';echo '</select> <select name="plan"><option value="combined">ترکیبی</option><option value="specialist">تخصصی</option><option value="enterprise">سازمانی</option></select> <label>ماه <input type="number" name="months" min="1" max="120" value="12"></label> <input name="reason" required minlength="5" placeholder="دلیل"> <button class="button">اعطا</button></form>';
        $q=$N::queueStatus();echo '<h2>صف پیامک و ایمیل</h2><p>';foreach($q['counts'] as $c)echo esc_html($c['channel'].' / '.$c['status'].': '.$c['n']).' &nbsp; ';echo '</p><form method="post">';self::form('msg_drain');echo '<button class="button">ارسال پیام‌های سررسیده</button></form><table class="widefat striped"><thead><tr><th>کانال</th><th>گیرنده</th><th>موضوع</th><th>وضعیت</th><th>تلاش</th><th>خطا</th></tr></thead><tbody>';
        foreach($q['recent'] as $m)echo '<tr><td>'.esc_html($m['channel']).'</td><td dir="ltr">'.esc_html($m['recipient_hint']).'</td><td>'.esc_html($m['subject']).'</td><td>'.esc_html($m['status']).'</td><td>'.(int)$m['attempts'].'</td><td>'.esc_html($m['last_error']).'</td></tr>';
        echo '</tbody></table></div>';
    }
    private static function form($task,$id=0){wp_nonce_field('agah_companies');echo '<input type="hidden" name="task" value="'.esc_attr($task).'"><input type="hidden" name="id" value="'.(int)$id.'">';}
    public static function page(){
        if(!current_user_can('manage_options'))return;
        echo '<div class="wrap"><h1>شرکت‌ها: فیلدهای الزامی، تعلیق و حذف</h1>';
        if(Request::isPost()){
            try{echo '<div class="notice notice-success"><p>'.esc_html(self::handle()).'</p></div>';}
            catch(AppException $e){echo '<div class="notice notice-error"><p>'.esc_html($e instanceof ValidationException?implode('، ',$e->errors):$e->getMessage()).'</p></div>';}
        }
        $policy=CompanyPolicyService::platform();
        echo '<h2>فیلدهای الزامی مشخصات شرکت</h2><p>فیلد انتخاب‌شده هنگام ذخیرهٔ مشخصات شرکت در سرور الزامی می‌شود. فیلدی که برای آن نوع معنا ندارد (مثل شماره ثبت برای حقیقی) هرگز الزامی نیست.</p><form method="post">';self::form('policy');
        foreach(CompanyPolicyService::KINDS as $kind=>$label){echo '<fieldset style="margin:0 0 1em"><legend><b>'.esc_html('شرکت '.$label).'</b></legend>';foreach(CompanyPolicyService::FIELDS as $field=>$def){if(!in_array($kind,$def[1],true))continue;echo '<label style="display:inline-block;margin-left:1.5em"><input type="checkbox" name="required['.esc_attr($kind).'][]" value="'.esc_attr($field).'" '.checked(in_array($field,$policy[$kind],true),true,false).'> '.esc_html($def[0]).'</label>';}echo '</fieldset>';}
        echo '<p><label><input type="checkbox" name="delegate" value="1" '.checked(CompanyPolicyService::delegated(),true,false).'> مدیر هر شرکت بتواند برای شرکت خودش سیاست جدا تعیین کند</label></p><p><button class="button button-primary">ذخیرهٔ سیاست</button></p></form>';

        echo '<hr><h2>دورهٔ نگهداری قانونی</h2><p>تا وقتی سند شماره‌دار شرکتی در این دوره باشد، پاک‌سازی آن ممکن نیست و فقط تعلیق می‌شود. صفر یعنی بدون این محدودیت (مثلاً برای شرکت آزمایشی).</p><form method="post">';self::form('retention');
        echo '<label>سال <input type="number" name="years" min="0" max="30" value="'.(int)CompanyLifecycleService::retentionYears().'"></label> <button class="button">ذخیره</button></form>';

        echo '<hr><h2>شرکت‌های غیرفعال</h2>';$rows=array_filter(CompanyService::listAllMeta(),function($c){return $c['status']!=='active';});
        if(!$rows)echo '<p>شرکت غیرفعالی وجود ندارد.</p>';else{echo '<table class="widefat striped"><thead><tr><th>شرکت</th><th>وضعیت</th><th>عمل</th></tr></thead><tbody>';
            foreach($rows as $c){echo '<tr><td>'.esc_html($c['name']).'</td><td>'.esc_html(['suspended'=>'تعلیق‌شده','purging'=>'در حال پاک‌سازی','deleting'=>'حذف نیمه‌کاره'][$c['status']]??$c['status']).'</td><td>';
                if($c['status']==='suspended'){echo '<form method="post">';self::form('reactivate',$c['id']);echo '<button class="button">بازگشایی</button></form>';}
                elseif($c['status']==='deleting'){echo '<form method="post">';self::form('resume_delete',$c['id']);echo '<button class="button">تمام‌کردن حذف</button></form>';}
                echo '</td></tr>';}
            echo '</tbody></table>';}

        echo '<hr><h2>پاک‌سازی‌ها و بایگانی‌ها</h2><p>بایگانی رمزشده داخل پایگاه داده نگه داشته می‌شود و با پشتیبان سامانه همراه است. حذف بایگانی برگشت‌پذیر نیست و گذرواژهٔ شما را می‌خواهد.</p>';
        $purges=CompanyLifecycleService::allPurges();
        if(!$purges)echo '<p>پاک‌سازی‌ای ثبت نشده است.</p>';else{echo '<table class="widefat striped"><thead><tr><th>شرکت</th><th>وضعیت</th><th>ردیف بایگانی‌شده</th><th>آخرین تغییر</th><th>عمل</th></tr></thead><tbody>';
            foreach($purges as $p){$m=json_decode((string)$p['manifest'],true)?:[];$rows=0;foreach($m as $t)$rows+=(int)($t['rows']??0);
                $status=(CompanyLifecycleService::STATUS[$p['status']]??$p['status']).($p['status']==='running'?' ('.($p['stage']==='archive'?'ساخت بایگانی':'حذف داده').')':'').($p['error']?' — '.$p['error']:'');
                echo '<tr><td>'.esc_html($p['company_name']).'</td><td>'.esc_html($status).'</td><td>'.esc_html(number_format($rows)).($p['archive_deleted_at']?' (بایگانی حذف شد)':'').'</td><td>'.esc_html(Jalali::format(substr(get_date_from_gmt($p['updated_at']),0,10))).'</td><td>';
                if($p['status']==='running'){echo '<form method="post">';self::form('run',$p['id']);echo '<button class="button">اجرای یک نوبت</button></form>';}
                elseif((int)$p['chunks']>0){echo '<details><summary>حذف بایگانی</summary><form method="post">';self::form('archive_delete',$p['id']);echo '<label>نام دقیق شرکت <input name="confirm_name" autocomplete="off" required></label> <label>گذرواژهٔ شما <input type="password" name="confirm_password" autocomplete="current-password" required></label> <button class="button button-link-delete">حذف برگشت‌ناپذیر بایگانی</button></form></details>';}
                echo '</td></tr>';}
            echo '</tbody></table>';}
        echo '</div>';
    }
}
