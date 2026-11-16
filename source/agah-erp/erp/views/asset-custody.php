<?php
/** تحویل، بازپس‌گیری و انتقال اموال؛ گزارش اقلام نزد هر متصدی، اموال بدون متصدی، تسویه‌حساب کارمند و تاریخچهٔ قابل جست‌وجو. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Ctx,Db,Jalali,Money,Request};
use Agah\Erp\Modules\Assets\AssetCustodyService as S;
if(!defined('ABSPATH'))exit;
$tab=Request::str('get','tab',12,'holdings');$unit=Ctx::unit();$edit=Ctx::can('asset.edit');
$holders=S::holders($cid);$assets=S::assets($cid);
$current=[];foreach(Db::rows('SELECT asset_id,holder_name FROM '.Db::t('asset_custody')." WHERE company_id=%d AND status='active'",$cid) as $r)$current[(int)$r['asset_id']]=$r['holder_name'];
$export=function($type,$extra=[])use($cid){if(!Ctx::can('asset.export'))return;foreach(['xlsx'=>'Excel XLSX','csv'=>'CSV'] as $f=>$l)echo '<a class="btn btn-outline btn-sm erp-no-print" href="'.esc_url(add_query_arg(array_merge($extra,['custody_export'=>$type,'format'=>$f,'_n'=>wp_create_nonce('agah_custody_export_'.$cid.'_'.$type)]))).'">دانلود '.$l.'</a> ';echo '<button type="button" class="btn btn-outline btn-sm erp-no-print" data-print>چاپ</button>';};
$H=S::HOLDERS;$E=S::END_KINDS;
$table=function(array $rows,$withHolder=true)use($cid,$unit,$H,$E){
    echo '<table class="erp-table"><thead><tr><th>دارایی</th><th>دسته</th>'.($withHolder?'<th>متصدی</th>':'').'<th>از</th><th>تا</th><th>رسید</th><th>تحویل‌دهنده ← گیرنده</th><th>ارزش دفتری</th></tr></thead><tbody>';
    foreach($rows as $r){echo '<tr><td><a href="'.esc_url(agah_erp_url(['c'=>$cid,'p'=>'asset_view','id'=>$r['asset_id']])).'">'.esc_html($r['asset_code'].' — '.$r['asset_name']).'</a></td><td>'.esc_html((string)$r['category_name']).'</td>'.($withHolder?'<td>'.esc_html(($H[$r['holder_type']]??'').': '.$r['holder_name']).'</td>':'').'<td>'.esc_html(Jalali::format($r['effective_date'])).'</td><td>'.esc_html($r['status']==='void'?'باطل: '.$r['void_reason']:($r['ended_date']?Jalali::format($r['ended_date']).' ('.($E[$r['end_kind']]??'').')':'جاری')).'</td><td><a href="'.esc_url(agah_erp_url(['c'=>$cid,'p'=>'asset_receipt','id'=>$r['id']])).'">'.esc_html(Money::toFa((string)$r['receipt_no'])).'</a>'.($r['return_receipt_no']?' / '.esc_html(Money::toFa((string)$r['return_receipt_no'])):'').'</td><td>'.esc_html($r['delivered_by'].' ← '.$r['received_by']).'</td><td>'.esc_html(Money::fmt((int)$r['book_value'],$unit)).'</td></tr>';}
    echo '</tbody></table>';
};
?>
<section class="erp-card"><h2>تحویل و انتقال اموال</h2>
<p class="muted">جابه‌جایی اموال میان کارمند، واحد/محل و انبار فقط سابقهٔ نگهداری را ثبت می‌کند و بر بها، استهلاک و ارزش دفتری اثری ندارد. در هر تاریخ هر دارایی حداکثر یک متصدی دارد. برای هر تحویل و بازپس‌گیری رسید شماره‌دار با کد تأیید صادر می‌شود.</p>
<nav class="erp-tabs erp-no-print"><?php foreach(['holdings'=>'اقلام نزد متصدی','unassigned'=>'اموال بدون متصدی','clearance'=>'تسویه‌حساب کارمند','history'=>'تاریخچه','settings'=>'دسته‌ها و محل‌ها'] as $k=>$l): ?><a class="btn btn-sm <?php echo $tab===$k?'btn-primary':'btn-outline'; ?>" href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'asset_custody','tab'=>$k])); ?>"><?php echo esc_html($l); ?></a> <?php endforeach; ?></nav>
</section>

<?php if($edit&&in_array($tab,['holdings','unassigned'],true)): ?>
<section class="erp-card erp-no-print"><h3>تحویل، انتقال یا بازپس‌گیری</h3>
<form method="post" class="erp-form"><?php self::form('module_custody_move',$cid,'asset_custody'); ?>
<label>دارایی<select name="id" required><option value="">انتخاب دارایی</option><?php foreach($assets as $a): ?><option value="<?php echo (int)$a['id']; ?>" <?php selected(Request::int('get','asset_id'),(int)$a['id']); ?>><?php echo esc_html($a['code'].' — '.$a['name'].' ('.($current[(int)$a['id']]??'بدون متصدی').')'); ?></option><?php endforeach; ?></select></label>
<?php self::select('holder_type','اقدام',['employee'=>'تحویل به کارمند','place'=>'تحویل به واحد / محل','warehouse'=>'تحویل به انبار','none'=>'بازپس‌گیری (بدون متصدی تازه)'],'employee'); ?>
<?php foreach(['employee','place','warehouse'] as $type): ?><div data-show-for="holder_type:<?php echo esc_attr($type); ?>"><?php self::select('holder_'.$type,$H[$type],[0=>'انتخاب']+($holders[$type]??[]),0); ?></div><?php endforeach; ?>
<?php self::input('effective_date','تاریخ مؤثر',Jalali::format(Jalali::today(),false),'text','required data-jalali maxlength="10"'); self::input('delivered_by','تحویل‌دهنده (خالی = متصدی فعلی)','','text','maxlength="190"'); self::input('received_by','تحویل‌گیرنده (خالی = متصدی تازه)','','text','maxlength="190"'); self::input('note','یادداشت (وضعیت ظاهری، لوازم همراه …)','','text','maxlength="300"'); ?>
<button type="submit" class="btn btn-primary">ثبت و صدور رسید</button></form></section>
<?php endif; ?>

<?php if($tab==='holdings'): $type=Request::str('get','holder_type',10);$hid=Request::int('get','holder_id');$rows=S::holdings($cid,$type,$hid); ?>
<section class="erp-card"><h3>اقلام جاری نزد متصدیان</h3>
<form method="get" action="<?php echo esc_url(agah_erp_url()); ?>" class="erp-filter erp-no-print"><input type="hidden" name="page_id" value="<?php echo (int)get_option('agah_erp_page_id'); ?>"><input type="hidden" name="c" value="<?php echo (int)$cid; ?>"><input type="hidden" name="p" value="asset_custody"><input type="hidden" name="tab" value="holdings">
<label>نوع متصدی<select name="holder_type"><option value="">همه</option><?php foreach($H as $k=>$l): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($type,$k); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?></select></label>
<label>متصدی<select name="holder_id"><option value="0">همه</option><?php foreach($holders as $k=>$list)foreach($list as $i=>$n): ?><option value="<?php echo (int)$i; ?>" <?php selected($type===$k&&$hid===(int)$i); ?>><?php echo esc_html($H[$k].': '.$n); ?></option><?php endforeach; ?></select></label><button type="submit" class="btn btn-outline">نمایش</button></form>
<?php $export('holdings',['holder_type'=>$type,'holder_id'=>$hid]); $table($rows); ?>
<p><?php echo esc_html('تعداد: '.Money::toFa((string)count($rows))); ?></p></section>
<?php elseif($tab==='unassigned'): $rows=S::unassigned($cid); ?>
<section class="erp-card"><h3>اموال فعال بدون متصدی</h3><?php $export('unassigned'); ?>
<table class="erp-table"><thead><tr><th>کد</th><th>نام</th><th>دسته</th><th>تاریخ خرید</th><th>بها</th><th>آخرین بازپس‌گیری</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><a href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'asset_custody','tab'=>'unassigned','asset_id'=>$r['id']])); ?>"><?php echo esc_html($r['code']); ?></a></td><td><?php echo esc_html($r['name']); ?></td><td><?php echo esc_html((string)$r['category_name']); ?></td><td><?php echo esc_html(Jalali::format($r['acquired_date'])); ?></td><td><?php echo esc_html(Money::fmt((int)$r['cost'],$unit)); ?></td><td><?php echo esc_html($r['last_return']?Jalali::format($r['last_return']):'—'); ?></td></tr><?php endforeach; ?></tbody></table></section>
<?php elseif($tab==='clearance'): $emp=Request::int('get','employee_id'); ?>
<section class="erp-card"><h3>تسویه‌حساب کارمند</h3>
<form method="get" action="<?php echo esc_url(agah_erp_url()); ?>" class="erp-filter erp-no-print"><input type="hidden" name="page_id" value="<?php echo (int)get_option('agah_erp_page_id'); ?>"><input type="hidden" name="c" value="<?php echo (int)$cid; ?>"><input type="hidden" name="p" value="asset_custody"><input type="hidden" name="tab" value="clearance">
<label>کارمند<select name="employee_id"><option value="0">انتخاب</option><?php foreach(Db::rows('SELECT id,code,name FROM '.Db::t('employees').' WHERE company_id=%d ORDER BY name',$cid) as $e): ?><option value="<?php echo (int)$e['id']; ?>" <?php selected($emp,(int)$e['id']); ?>><?php echo esc_html($e['name'].' ('.$e['code'].')'); ?></option><?php endforeach; ?></select></label><button type="submit" class="btn btn-outline">نمایش</button></form>
<?php if($emp): $c=S::clearance($cid,$emp); ?>
<p><b><?php echo esc_html($c['employee']['name']); ?></b> — <?php echo $c['held']?'<span class="erp-err">'.esc_html(Money::toFa((string)count($c['held'])).' قلم هنوز نزد این کارمند است؛ پیش از تسویه بازپس بگیرید.').'</span>':'هیچ قلمی نزد این کارمند نیست؛ از نظر اموال تسویه است.'; ?></p>
<?php if($c['held']){echo '<h4>اقلام جاری</h4>';$table($c['held'],false);} ?>
<h4>سابقهٔ کامل</h4><?php $export('clearance',['employee_id'=>$emp]); $table($c['history'],false); endif; ?></section>
<?php elseif($tab==='history'): $f=Phase2Controller::custodyFilters();$h=S::history($cid,$f,Request::int('get','pg',1)); ?>
<section class="erp-card"><h3>تاریخچهٔ جابه‌جایی</h3>
<form method="get" action="<?php echo esc_url(agah_erp_url()); ?>" class="erp-filter erp-no-print"><input type="hidden" name="page_id" value="<?php echo (int)get_option('agah_erp_page_id'); ?>"><input type="hidden" name="c" value="<?php echo (int)$cid; ?>"><input type="hidden" name="p" value="asset_custody"><input type="hidden" name="tab" value="history">
<label>جست‌وجو (دارایی، متصدی، تحویل‌دهنده، یادداشت)<input name="q" maxlength="100" value="<?php echo esc_attr($f['q']); ?>"></label>
<label>از تاریخ<input name="from" data-jalali maxlength="10" value="<?php echo esc_attr($f['from']?Jalali::format($f['from'],false):''); ?>"></label><label>تا تاریخ<input name="to" data-jalali maxlength="10" value="<?php echo esc_attr($f['to']?Jalali::format($f['to'],false):''); ?>"></label>
<label>دارایی<select name="asset_id"><option value="0">همه</option><?php foreach(Db::rows('SELECT id,code,name FROM '.Db::t('assets').' WHERE company_id=%d ORDER BY code LIMIT 5000',$cid) as $a): ?><option value="<?php echo (int)$a['id']; ?>" <?php selected($f['asset_id'],(int)$a['id']); ?>><?php echo esc_html($a['code'].' — '.$a['name']); ?></option><?php endforeach; ?></select></label>
<button type="submit" class="btn btn-outline">جست‌وجو</button></form>
<?php $export('history',array_filter(['q'=>$f['q'],'asset_id'=>$f['asset_id'],'from'=>Request::str('get','from',10),'to'=>Request::str('get','to',10)])); $table($h['rows']); self::pages($h['pages'],$h['page']); ?>
<?php if(Ctx::can('asset.delete')&&$f['asset_id']): ?><details class="erp-no-print"><summary>ابطال آخرین اقدام این دارایی</summary><form method="post" class="erp-form"><?php self::form('module_custody_undo',$cid,'asset_custody',$f['asset_id']); ?><input name="reason" required minlength="5" maxlength="300" placeholder="دلیل ابطال" aria-label="دلیل ابطال"><button type="submit" class="btn btn-outline" data-confirm="آخرین تحویل/انتقال یا بازپس‌گیری این دارایی باطل و وضعیت قبلی برگردانده می‌شود. ادامه می‌دهید؟">ابطال</button></form></details><?php endif; ?>
</section>
<?php elseif($tab==='settings'): $cats=S::categories($cid);$places=S::places($cid); ?>
<section class="erp-card"><h3>دسته‌های دارایی</h3>
<table class="erp-table"><thead><tr><th>کد</th><th>نام</th><th>وضعیت</th><th></th></tr></thead><tbody><?php foreach($cats as $g): ?><tr><td><?php echo esc_html($g['code']); ?></td><td><?php echo esc_html($g['name']); ?></td><td><?php echo $g['is_active']?'فعال':'غیرفعال'; ?></td><td><?php if($edit): ?><form method="post" class="erp-inline-form"><?php self::form('module_asset_category_delete',$cid,'asset_custody',$g['id']); ?><button type="submit" class="btn btn-outline btn-sm" data-confirm="دسته حذف شود؟ (فقط اگر به دارایی داده نشده باشد)">حذف</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table>
<?php if($edit): ?><form method="post" class="erp-form"><?php self::form('module_asset_category_save',$cid,'asset_custody'); self::input('code','کد','','text','required maxlength="30" dir="ltr"'); self::input('title','نام دسته','','text','required maxlength="190"'); ?><label><input type="checkbox" name="is_active" value="1" checked> فعال</label><button type="submit" class="btn btn-primary">افزودن دسته</button></form>
<h4>تعیین دستهٔ دارایی</h4><form method="post" class="erp-form"><?php self::form('module_asset_set_category',$cid,'asset_custody'); ?><label>دارایی<select name="id" required><?php foreach($assets as $a): ?><option value="<?php echo (int)$a['id']; ?>"><?php echo esc_html($a['code'].' — '.$a['name']); ?></option><?php endforeach; ?></select></label><label>دسته<select name="category_id"><option value="0">بدون دسته</option><?php foreach($cats as $g)if($g['is_active']): ?><option value="<?php echo (int)$g['id']; ?>"><?php echo esc_html($g['name']); ?></option><?php endif; ?></select></label><button type="submit" class="btn btn-outline">ذخیره</button></form><?php endif; ?></section>
<section class="erp-card"><h3>واحدها و محل‌ها</h3>
<table class="erp-table"><thead><tr><th>کد</th><th>نام</th><th>نوع</th><th>وضعیت</th><th></th></tr></thead><tbody><?php foreach($places as $pl): ?><tr><td><?php echo esc_html($pl['code']); ?></td><td><?php echo esc_html($pl['name']); ?></td><td><?php echo esc_html(S::PLACE_KINDS[$pl['kind']]??''); ?></td><td><?php echo $pl['is_active']?'فعال':'غیرفعال'; ?></td><td><?php if($edit): ?><form method="post" class="erp-inline-form"><?php self::form('module_asset_place_delete',$cid,'asset_custody',$pl['id']); ?><button type="submit" class="btn btn-outline btn-sm" data-confirm="حذف شود؟ (فقط اگر در تاریخچه نیامده باشد)">حذف</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table>
<?php if($edit): ?><form method="post" class="erp-form"><?php self::form('module_asset_place_save',$cid,'asset_custody'); self::select('kind','نوع',S::PLACE_KINDS,'site'); self::input('code','کد','','text','required maxlength="30" dir="ltr"'); self::input('title','نام','','text','required maxlength="190"'); ?><label><input type="checkbox" name="is_active" value="1" checked> فعال</label><button type="submit" class="btn btn-primary">افزودن</button></form><?php endif; ?></section>
<?php endif; ?>
