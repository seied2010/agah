<?php
/**
 * ناوبری درختی RTL (UX-01/02): «میانبرها و پرکاربردها»ی شخصی، سپس دسته‌های اصلی
 * (تعاریف پایه، حسابداری، خزانه، خرید و فروش، انبار و تولید، حقوق، دارایی، گزارش و تحلیل، مدیریت).
 * عنوان هر دسته صفحهٔ نمای همان دسته را باز می‌کند؛ باز/بسته‌بودن دسته‌ها در مرورگر کاربر نگه داشته می‌شود و دستهٔ فعال همیشه باز است.
 */
namespace Agah\Erp\Http;
use Agah\Erp\Core\Ctx;
use Agah\Erp\Security\Caps;
if(!defined('ABSPATH'))exit;
final class Navigation {
    const GROUPS=['base'=>'تعاریف پایه','accounting'=>'حسابداری','treasury'=>'خزانه','trade'=>'خرید و فروش','stock'=>'انبار و تولید','payroll'=>'حقوق و دستمزد','assets'=>'دارایی ثابت','report'=>'گزارش و تحلیل','admin'=>'مدیریت'];
    /** گروه قدیمی هر منبع مجوز => دستهٔ تازه. */
    const MAP=['party'=>'base','catalog'=>'base','accounting'=>'accounting','expense'=>'accounting','treasury'=>'treasury','sales'=>'trade','purchase'=>'trade','inventory'=>'stock','production'=>'stock','payroll'=>'payroll','assets'=>'assets','report'=>'report','company'=>'admin','general'=>'admin'];
    public static function mode(){return get_option('agah_erp_menu_layout','sidebar')==='top'?'top':'sidebar';}
    public static function groupOf($cap){$res=Caps::resources();$g=$res[explode('.',(string)$cap)[0]][1]??'general';return self::MAP[$g]??'admin';}
    /** صفحه‌های مجاز کاربر، دسته‌بندی‌شده. */
    public static function groups(array $nav){$out=[];foreach($nav as $key=>$data){if(!Ctx::can($data[1]))continue;$out[self::groupOf($data[1])][$key]=$data[0];}return $out;}
    public static function render(array $nav,$current,$cid){
        $groups=self::groups($nav);$cap=ProductionController::cap($current)?:TradeController::cap($current)?:($nav[$current][1]??'');$active=$current==='nav_group'?\Agah\Erp\Core\Request::str('get','g',12):($cap?self::groupOf($cap):'');
        $shortcuts=Phase2Controller::shortcuts($cid);
        echo '<details class="erp-navigation erp-no-print" open><summary data-agah="agah_erp_menu_layout"><span>بخش‌های سامانه</span><span aria-hidden="true">⌄</span></summary><nav class="erp-nav" aria-label="منوی سامانه">';
        echo '<section class="erp-nav-group" data-nav-icon="home"><details class="erp-nav-section" data-nav-group="shortcuts" open><summary><h3>میانبرها و پرکاربردها</h3></summary><div>';
        echo '<a href="'.esc_url(agah_erp_url(['c'=>$cid,'p'=>'dashboard'])).'"'.($current==='dashboard'?' class="on" aria-current="page"':'').'><span aria-hidden="true">▦</span> داشبورد</a>';
        $unread=\Agah\Erp\Modules\Notify\NotificationService::unread($cid);echo '<a href="'.esc_url(agah_erp_url(['c'=>$cid,'p'=>'notifications'])).'"'.($current==='notifications'?' class="on" aria-current="page"':'').'>اعلان‌ها'.($unread?' ('.esc_html(\Agah\Erp\Core\Money::toFa((string)$unread)).')':'').'</a>';
        foreach($shortcuts as $key=>$text)echo '<a href="'.esc_url(agah_erp_url(['c'=>$cid,'p'=>$key])).'"'.($key===$current?' class="on" aria-current="page"':'').'>★ '.esc_html($text).'</a>';
        echo '</div></details></section>';
        foreach(self::GROUPS as $g=>$label)if(isset($groups[$g])){
            echo '<section class="erp-nav-group" data-nav-icon="'.esc_attr($g).'"><details class="erp-nav-section" data-nav-group="'.esc_attr($g).'"'.($g===$active?' open data-nav-active':'').'><summary><h3>'.esc_html($label).'</h3></summary><div><a class="erp-nav-overview" href="'.esc_url(agah_erp_url(['c'=>$cid,'p'=>'nav_group','g'=>$g])).'">نمای کلی '.esc_html($label).'</a>';
            foreach($groups[$g] as $key=>$text)echo '<a href="'.esc_url(agah_erp_url(['c'=>$cid,'p'=>$key])).'"'.($key===$current?' class="on" aria-current="page"':'').'>'.esc_html($text).'</a>';
            echo '</div></details></section>';
        }
        echo '</nav></details>';
    }
    /** مسیر صفحه باید داخل ستون محتوا باشد تا فرزند سوم Grid و ردیف مستقل نسازد. */
    public static function breadcrumbs(array $nav,$current,$cid){
        $cap=ProductionController::cap($current)?:TradeController::cap($current)?:($nav[$current][1]??'');$active=$cap?self::groupOf($cap):'';$shortcuts=Phase2Controller::shortcuts($cid);
        if($current!=='dashboard'&&isset($nav[$current])){$g=$active;echo '<div class="erp-crumbs erp-no-print"><a href="'.esc_url(agah_erp_url(['c'=>$cid,'p'=>'nav_group','g'=>$g])).'">'.esc_html(self::GROUPS[$g]??'').'</a> › <span aria-current="page">'.esc_html($nav[$current][0]).'</span> ';
            echo '<form method="post" class="erp-inline-form">';ModuleController::form('module_shortcut_toggle',$cid,$current);echo '<input type="hidden" name="page_key" value="'.esc_attr($current).'"><button type="submit" class="btn btn-outline btn-sm">'.(isset($shortcuts[$current])?'★ برداشتن از میانبرها':'☆ افزودن به میانبرها').'</button></form></div>';}
    }
    /** نمای یک دسته: کارت صفحه‌های مجاز همان دسته. */
    public static function overview(array $nav,$cid,$g){
        $groups=self::groups($nav);if(!isset(self::GROUPS[$g],$groups[$g]))throw new \Agah\Erp\Core\NotFoundException();
        echo '<section class="erp-card"><h2>'.esc_html(self::GROUPS[$g]).'</h2><div class="erp-tiles">';
        foreach($groups[$g] as $key=>$text)echo '<a class="erp-tile" href="'.esc_url(agah_erp_url(['c'=>$cid,'p'=>$key])).'">'.esc_html($text).'</a>';
        echo '</div></section>';
    }
}
