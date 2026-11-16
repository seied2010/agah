<?php
/** تنظیمات ظاهر سامانه در سفارشی‌ساز. به‌صورت option ذخیره می‌شوند تا با تعویض قالب از بین نروند؛ هیچ اختیار مالی‌ای نمی‌دهند. */
if(!defined('ABSPATH'))exit;

/** کلیدهای ظاهر سامانه. */
function agah_erp_ui_keys(){return ['agah_erp_title','agah_erp_brand','agah_erp_primary','agah_erp_background','agah_erp_radius','agah_erp_font_size','agah_erp_width','agah_erp_logo','agah_erp_font','agah_erp_menu_layout','agah_erp_theme'];}

/** انتقال یک‌بارهٔ تنظیمات از نسخه‌ای که ERP داخل قالب بود. */
function agah_erp_migrate_ui(){
    if(get_option('agah_erp_ui_migrated'))return;
    $mods=(array)get_option('theme_mods_agah-accounting',[]);
    foreach(agah_erp_ui_keys() as $key)if(array_key_exists($key,$mods)&&false===get_option($key,false))update_option($key,$mods[$key],false);
    update_option('agah_erp_ui_migrated',1,false);
}
add_action('init','agah_erp_migrate_ui',4);

function agah_erp_customize($manager){
    $manager->add_panel('agah_erp_panel',['title'=>'ظاهر سامانه حسابداری','priority'=>35]);
    foreach(['identity'=>'نام و نشان','appearance'=>'رنگ و خوانایی','layout'=>'چیدمان','navigation'=>'منوی سامانه'] as $k=>$label)$manager->add_section('agah_erp_'.$k,['title'=>$label,'panel'=>'agah_erp_panel']);
    $defs=[ 'agah_erp_title'=>['نام سامانه','سامانه حسابداری','identity','text'], 'agah_erp_brand'=>['رنگ دکمه‌ها و تأکید','#1F6F5C','appearance','color'], 'agah_erp_primary'=>['رنگ عنوان‌ها','#12304A','appearance','color'], 'agah_erp_background'=>['رنگ زمینه','#F5F8F7','appearance','color'], 'agah_erp_radius'=>['گردی گوشه‌ها',14,'appearance','range',0,28], 'agah_erp_font_size'=>['اندازه متن (پیکسل)',16,'appearance','range',14,20], 'agah_erp_width'=>['عرض فضای کاری (پیکسل)',1440,'layout','range',980,1680], ];
    foreach($defs as $id=>$d){
        $sanitize='sanitize_text_field';
        if($d[3]==='color')$sanitize='sanitize_hex_color';
        if($d[3]==='range')$sanitize=function($v)use($d){return max($d[4],min($d[5],absint($v)));};
        $manager->add_setting($id,['type'=>'option','default'=>$d[1],'sanitize_callback'=>$sanitize,'transport'=>'refresh']);
        $control=['label'=>$d[0],'section'=>'agah_erp_'.$d[2],'type'=>$d[3]];
        if($d[3]==='range')$control['input_attrs']=['min'=>$d[4],'max'=>$d[5],'step'=>1];
        if($d[3]==='color')$manager->add_control(new WP_Customize_Color_Control($manager,$id,$control));
        else$manager->add_control($id,$control);
    }
    $manager->add_setting('agah_erp_logo',['type'=>'option','default'=>0,'sanitize_callback'=>'absint','transport'=>'refresh']);
    $manager->add_control(new WP_Customize_Media_Control($manager,'agah_erp_logo',['label'=>'نشان سامانه','section'=>'agah_erp_identity','mime_type'=>'image']));
    $manager->add_setting('agah_erp_font',['type'=>'option','default'=>'vazirmatn','sanitize_callback'=>function($v){return in_array($v,['vazirmatn','tahoma','arial'],true)?$v:'vazirmatn';},'transport'=>'refresh']);
    $manager->add_control('agah_erp_font',['label'=>'فونت محلی','section'=>'agah_erp_appearance','type'=>'select','choices'=>['vazirmatn'=>'وزیرمتن (فایل محلی)','tahoma'=>'Tahoma سیستم','arial'=>'Arial سیستم']]);
    $manager->add_setting('agah_erp_menu_layout',['type'=>'option','default'=>'sidebar','sanitize_callback'=>function($v){return $v==='top'?'top':'sidebar';},'transport'=>'refresh']);
    $manager->add_control('agah_erp_menu_layout',['section'=>'agah_erp_navigation','label'=>'چیدمان منوی سامانه','type'=>'select','choices'=>['top'=>'بالای صفحه','sidebar'=>'کناری / کشو']]);
    $manager->add_setting('agah_erp_theme',['type'=>'option','default'=>'system','sanitize_callback'=>function($v){return in_array($v,['system','light','dark'],true)?$v:'system';},'transport'=>'refresh']);
    $manager->add_control('agah_erp_theme',['section'=>'agah_erp_appearance','label'=>'حالت رنگ سامانه','description'=>'کاربر می‌تواند از نوار بالای سامانه حالت روشن و تیره را موقتاً تغییر دهد.','type'=>'select','choices'=>['system'=>'هماهنگ با دستگاه','light'=>'روشن','dark'=>'تیره']]);
}
add_action('customize_register','agah_erp_customize',20);
function agah_erp_presentation(){
    $colors=[];
    foreach(['brand'=>'#1F6F5C','primary'=>'#12304A','background'=>'#F5F8F7'] as $k=>$default)$colors[$k]=sanitize_hex_color(get_option('agah_erp_'.$k,$default))?:$default;
    $size=max(14,min(20,absint(get_option('agah_erp_font_size',16))));
    $radius=max(0,min(28,absint(get_option('agah_erp_radius',14))));
    $width=max(980,min(1680,absint(get_option('agah_erp_width',1440))));
    $fonts=['vazirmatn'=>'Vazirmatn,Tahoma,sans-serif','tahoma'=>'Tahoma,sans-serif','arial'=>'Arial,sans-serif'];
    $font=$fonts[get_option('agah_erp_font','vazirmatn')]??$fonts['vazirmatn'];
    $css='.erp{--c-brand:'.$colors['brand'].';--c-secondary:'.$colors['brand'].';--c-primary:'.$colors['primary'].';--c-bg:'.$colors['background'].';--radius:'.$radius.'px;--erp-width:'.$width.'px;font-size:'.$size.'px;font-family:'.$font.'}';
    // Text on configurable brand backgrounds always gets a measured WCAG AA contrast choice.
    $lum=function($hex){
        $hex=strlen($hex)===4?'#'.$hex[1].$hex[1].$hex[2].$hex[2].$hex[3].$hex[3]:$hex;$rgb=sscanf($hex,'#%02x%02x%02x');
        $l=[];
        foreach($rgb as $v){
            $x=$v/255;
            $l[]=$x<=0.04045?$x/12.92:pow(($x+0.055)/1.055,2.4);
        }
        return .2126*$l[0]+.7152*$l[1]+.0722*$l[2];
    }
    ;
    if(1.05/($lum($colors['primary'])+.05)<4.5)$css.='.erp{--c-primary:#12304A}';
    $l=$lum($colors['brand']);
    $on=(1.05/($l+.05))>=(($l+.05)/.05)?'#ffffff':'#000000';
    $css.='.erp{--erp-on-brand:'.$on.'}';
    return $css;
}
add_action('wp_enqueue_scripts',function(){
    if(class_exists('Agah\\Erp\\Http\\SecurityController')&&\Agah\Erp\Http\SecurityController::isPage())wp_add_inline_style('agah-erp',agah_erp_presentation());
}
,30);
add_filter('body_class',function($classes){
    if(class_exists('Agah\\Erp\\Http\\SecurityController')&&\Agah\Erp\Http\SecurityController::isPage())$classes[]='agah-accounting-page';return $classes;
}
);
