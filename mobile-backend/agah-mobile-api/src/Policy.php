<?php
namespace Agah\Mobile;
use Agah\Erp\Core\{Db,Ctx,Access,Audit,ForbiddenException,ValidationException};
if (!defined('ABSPATH')) exit;
final class Policy {
    public static function mode($cid,$uid) {
        $rows=Db::rows('SELECT user_id,mode FROM '.Schema::table('policies').' WHERE company_id=%d AND user_id IN (0,%d) ORDER BY user_id',$cid,$uid);
        $global='read'; $personal=null;
        foreach ($rows as $r) { if ((int)$r['user_id']===0) $global=$r['mode']; else $personal=$r['mode']; }
        if ($global==='off'||$personal==='off') return 'off';
        // Per-user policy can narrow, never exceed the company policy.
        return $global==='write'&&$personal!=='read'?'write':'read';
    }
    public static function need($cid,$write=false) {
        $mode=self::mode($cid,get_current_user_id());
        if ($mode==='off'||($write&&$mode!=='write')) throw new ForbiddenException($write?'ثبت اطلاعات از اپ برای شما فعال نیست.':'دسترسی موبایل غیرفعال است.');
    }
    public static function save($cid,$uid,$mode) {
        return Access::write($cid,'company_settings.edit',function () use ($cid,$uid,$mode) {
            if (!in_array($mode,['off','read','write'],true)) throw new ValidationException(['mode'=>'حالت معتبر نیست.']);
            if ($uid && !Db::val('SELECT user_id FROM '.Db::t('company_members')." WHERE company_id=%d AND user_id=%d AND status='active'",$cid,$uid)) throw new ValidationException(['user_id'=>'عضو فعال همین شرکت را انتخاب کنید.']);
            Db::exec('INSERT INTO '.Schema::table('policies').' (company_id,user_id,mode) VALUES (%d,%d,%s) ON DUPLICATE KEY UPDATE mode=VALUES(mode)',$cid,$uid,$mode);
            Audit::log('mobile.policy.changed','user',$uid,null,['mode'=>$mode],$cid);
            return ['mode'=>$mode];
        });
    }
    public static function registerAdmin() {
        add_action('admin_menu',function () { add_menu_page('دسترسی اپ آگاه','اپ آگاه','read','agah-mobile',[__CLASS__,'admin'],'dashicons-smartphone',59); });
    }
    public static function admin() {
        $notice=''; $cid=isset($_REQUEST['company_id'])?absint($_REQUEST['company_id']):0;
        try {
            if ($cid) Ctx::boot(get_current_user_id(),$cid);
            if ($_SERVER['REQUEST_METHOD']==='POST') {
                check_admin_referer('agah-mobile-policy');
                self::save($cid,absint($_POST['user_id']??0),sanitize_key($_POST['mode']??''));
                $notice='تنظیمات ذخیره شد.';
            }
            $companies=Db::rows('SELECT c.id,c.name FROM '.Db::t('companies').' c JOIN '.Db::t('company_members')." m ON m.company_id=c.id WHERE m.user_id=%d AND m.status='active' AND c.status='active' ORDER BY c.name",get_current_user_id());
            echo '<div class="wrap" dir="rtl"><h1>مدیریت دسترسی اپ آگاه</h1><p>'.esc_html($notice).'</p><form method="get"><input type="hidden" name="page" value="agah-mobile"><label>شرکت <select name="company_id">';
            foreach ($companies as $c) echo '<option value="'.(int)$c['id'].'" '.selected($cid,$c['id'],false).'>'.esc_html($c['name']).'</option>';
            echo '</select></label> <button class="button">انتخاب</button></form>';
            if ($cid) {
                Ctx::need('company_settings.edit');
                echo '<p>حالت پیش‌فرض فقط مشاهده است. مجوز شخصی از سقف دسترسی شرکت فراتر نمی‌رود؛ مجوزهای نقش حسابداری نیز همواره اعمال می‌شوند.</p><form method="post">';
                wp_nonce_field('agah-mobile-policy');
                echo '<input type="hidden" name="company_id" value="'.$cid.'"><label>دامنه <select name="user_id"><option value="0">کل شرکت</option>';
                $members=Db::rows('SELECT m.user_id,u.display_name FROM '.Db::t('company_members').' m JOIN '.$GLOBALS['wpdb']->users." u ON u.ID=m.user_id WHERE m.company_id=%d AND m.status='active'",$cid);
                foreach ($members as $m) echo '<option value="'.(int)$m['user_id'].'">'.esc_html($m['display_name']).'</option>';
                echo '</select></label> <label>حالت <select name="mode"><option value="read">فقط مشاهده</option><option value="write">مشاهده و ثبت</option><option value="off">غیرفعال</option></select></label> <button class="button button-primary">ذخیره</button></form><h2>سیاست‌های ثبت‌شده</h2><ul>';
                foreach (Db::rows('SELECT user_id,mode FROM '.Schema::table('policies').' WHERE company_id=%d',$cid) as $p) echo '<li>'.esc_html(($p['user_id']?'کاربر '.$p['user_id']:'کل شرکت').' : '.$p['mode']).'</li>';
                echo '</ul>';
            }
            echo '</div>';
        } catch (\Agah\Erp\Core\AppException $e) { echo '<div class="notice notice-error"><p>'.esc_html($e->getMessage()).'</p></div>'; }
    }
}
