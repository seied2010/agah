<?php
/** صورتحساب بانک و ثبت هوشمند مرحلهٔ ۱: تطبیق سه‌وضعیتی، پیشنهاد (فاکتور، قاعده، حافظه، طرف‌حساب) و «سند بزن» به پیش‌نویس. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Business,Ctx,Db,Jalali,Money,Request};
use Agah\Erp\Modules\Import\{BankMatchService,ImportService};
if(!defined('ABSPATH'))exit;
$id=Request::int('get','id');$focus=Request::int('get','row');$unit=Ctx::unit();
$banks=[0=>'انتخاب حساب بانکی'];foreach(Db::rows('SELECT id,name FROM '.Db::t('treasury_accounts')." WHERE company_id=%d AND kind='bank' AND is_active=1 ORDER BY code",$cid) as $a)$banks[$a['id']]=$a['name'];
$accounts=Db::rows('SELECT id,code,name,detail_rule,detail_kind FROM '.Db::t('accounts').' WHERE company_id=%d AND level=3 AND is_active=1 ORDER BY code',$cid);
$details=Db::rows('SELECT id,code,name,kind FROM '.Db::t('details').' WHERE company_id=%d AND is_active=1 ORDER BY code LIMIT 3000',$cid);
$pick=function($account=0,$detail=0)use($accounts,$details){
    echo '<span data-pick><label>حساب معین<select name="account_id" data-account required><option value="">انتخاب حساب</option>';foreach($accounts as $a)echo '<option value="'.(int)$a['id'].'" data-rule="'.esc_attr($a['detail_rule']).'" data-kind="'.esc_attr($a['detail_kind']).'" '.selected((int)$account,(int)$a['id'],false).'>'.esc_html($a['code'].' — '.$a['name']).'</option>';echo '</select></label>';
    echo '<label>تفصیلی<select name="detail_id" data-detail><option value="0">بدون تفصیلی</option>';foreach($details as $d)echo '<option value="'.(int)$d['id'].'" data-kind="'.esc_attr($d['kind']).'" '.selected((int)$detail,(int)$d['id'],false).'>'.esc_html($d['code'].' — '.$d['name']).'</option>';echo '</select></label></span>';
};
$dirLabel=['in'=>'واریز','out'=>'برداشت'];
?>
<section class="erp-card"><h2>صورتحساب بانک و ثبت هوشمند</h2>
<p class="muted">فایل Excel صورتحساب را وارد کنید. هر تراکنش با دفتر همان بانک مقایسه می‌شود: «تطبیق‌شده» (یک سند هم‌مبلغ در ±۳ روز)، «مبهم» (چند گزینه؛ شما انتخاب می‌کنید) یا «باز». برای ردیف باز، سامانه از فاکتورهای باز، قاعده‌ها، حافظهٔ تصمیم‌های قبلی و نام اشخاص پیشنهاد می‌دهد و «سند بزن» فقط سند پیش‌نویس می‌سازد. هیچ داده‌ای از سرور شما بیرون نمی‌رود.</p>
<form method="post" class="erp-form" enctype="multipart/form-data"><?php self::form('module_import_bank_upload',$cid,'import_bank'); self::select('treasury_account_id','حساب بانکی',$banks,0); ?>
<label>فایل .xlsx صورتحساب (حداکثر ۵ مگابایت)<input type="file" name="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required><?php self::error('file'); ?></label>
<?php self::select('unit','واحد مبالغ فایل',['rial'=>'ریال','toman'=>'تومان'],'rial'); ?>
<button type="submit" class="btn btn-primary">بارگذاری و تطبیق</button></form>
</section>

<?php if($id): $b=ImportService::batch($cid,$id,'bank');$acc=Business::row('treasury_accounts',$cid,$b['treasury_account_id']);$headers=json_decode($b['headers'],true)?:[];$map=json_decode($b['mapping'],true)?:[];$open=$b['status']==='active';
$rows=array_map(function($r)use($cid){return BankMatchService::live($cid,$r);},ImportService::rows($cid,$id));$counts=array_count_values(array_column($rows,'status'));
$filter=Request::str('get','st',12);$shown=array_values(array_filter($rows,function($r)use($filter){return $filter===''||$r['status']===$filter;}));$pg=max(1,Request::int('get','pg',1));$pages=max(1,(int)ceil(count($shown)/100));$shown=array_slice($shown,($pg-1)*100,100); ?>
<section class="erp-card"><h2>دستهٔ <?php echo esc_html(Money::toFa((string)$b['id'])); ?> — <?php echo esc_html($acc['name'].' — '.$b['file_name']); ?></h2>
<p>وضعیت: <b><?php echo esc_html(ImportService::STATUS[$b['status']]??$b['status']); ?></b> —
<?php foreach(ImportService::ROW_STATUS as $k=>$label): if(empty($counts[$k]))continue; ?><a href="<?php echo esc_url(add_query_arg(['st'=>$k,'pg'=>false,'row'=>false])); ?>"><?php echo esc_html($label.': '.Money::toFa((string)$counts[$k])); ?></a> · <?php endforeach; ?><a href="<?php echo esc_url(add_query_arg(['st'=>false,'pg'=>false,'row'=>false])); ?>">همه</a></p>
<?php if($open): ?>
<details <?php echo (int)$b['rows_error']?'open':''; ?>><summary>نگاشت ستون‌ها (آداپتور این بانک)</summary>
<form method="post" class="erp-form"><?php self::form('module_import_bank_remap',$cid,'import_bank',$b['id']); ?>
<?php foreach(ImportService::FIELDS['bank'] as $field=>$label): ?><label><?php echo esc_html($label); ?><select name="map[<?php echo esc_attr($field); ?>]"><option value="">— ندارد —</option><?php foreach($headers as $i=>$h): ?><option value="<?php echo (int)$i; ?>" <?php selected(isset($map[$field])&&(int)$map[$field]===(int)$i); ?>><?php echo esc_html($h!==''?$h:'ستون '.($i+1)); ?></option><?php endforeach; ?></select></label><?php endforeach; ?>
<p class="muted">یا «مبلغ علامت‌دار» یا هر دو ستون واریز و برداشت. پس از اولین تصمیم روی ردیف‌ها نگاشت قفل می‌شود.</p>
<button type="submit" class="btn btn-outline">اعمال نگاشت و تطبیق دوباره</button></form></details>
<div class="erp-actions"><form method="post" class="erp-inline-form"><?php self::form('module_import_bank_refresh',$cid,'import_bank',$b['id']); ?><button type="submit" class="btn btn-outline">تطبیق دوباره با دفتر فعلی</button></form>
<form method="post" class="erp-inline-form"><?php self::form('module_import_bank_cancel',$cid,'import_bank',$b['id']); ?><button type="submit" class="btn btn-outline" data-confirm="این دسته لغو می‌شود. ادامه می‌دهید؟">لغو دسته</button></form></div>
<?php elseif($b['status']==='cancelled'): ?><form method="post" class="erp-inline-form"><?php self::form('module_import_bank_delete',$cid,'import_bank',$b['id']); ?><button type="submit" class="btn btn-outline" data-confirm="دستهٔ لغوشده و ردیف‌هایش حذف می‌شوند. ادامه می‌دهید؟">حذف دسته</button></form><?php endif; ?>

<?php if($focus&&$open): $fr=null;foreach($rows as $r)if((int)$r['id']===$focus)$fr=$r; if($fr&&$fr['status']==='open'): $sug=BankMatchService::suggest($cid,$fr,$acc);$first=null;foreach($sug as $s)if(isset($s['account_id'])){$first=$s;break;} ?>
<div class="erp-card"><h3>سند بزن — ردیف <?php echo esc_html(Money::toFa((string)$fr['row_no'])); ?>: <?php echo esc_html(($dirLabel[$fr['direction']]??'').' '.Money::fmt((int)$fr['amount'],$unit).' '.Money::unitLabel($unit).' — '.Jalali::format($fr['txn_date'])); ?></h3>
<p><?php echo esc_html($fr['description']); ?></p>
<form method="post" class="erp-form"><?php self::form('module_bank_post',$cid,'import_bank',$fr['id']); ?><input type="hidden" name="source" value="<?php echo esc_attr($first['source']??'manual'); ?>"><input type="hidden" name="rule_id" value="<?php echo (int)($first['rule_id']??0); ?>">
<p class="muted">طرف مقابل حساب بانک (<?php echo esc_html($fr['direction']==='in'?'بستانکار':'بدهکار'); ?>)<?php echo $first?' — پیش‌فرض از '.esc_html($first['label']):''; ?>. اگر حساب را عوض کنید، منبع «انتخاب دستی» ثبت می‌شود.</p>
<?php $pick($first['account_id']??0,$first['detail_id']??0); self::input('description','شرح سند',$fr['description'],'text','maxlength="300"'); ?>
<button type="submit" class="btn btn-primary">سند بزن (پیش‌نویس)</button></form></div>
<?php endif; endif; ?>

<table class="erp-table"><thead><tr><th>ردیف</th><th>تاریخ</th><th>جهت</th><th>مبلغ (<?php echo esc_html(Money::unitLabel($unit)); ?>)</th><th>شرح / مرجع</th><th>وضعیت و اقدام</th></tr></thead><tbody>
<?php foreach($shown as $r): $st=$r['status']; ?><tr><td><?php echo esc_html(Money::toFa((string)$r['row_no'])); ?></td><td><?php echo esc_html($r['txn_date']?Jalali::format($r['txn_date']):''); ?></td><td><?php echo esc_html($dirLabel[$r['direction']]??''); ?></td><td><?php echo esc_html((int)$r['amount']?Money::fmt((int)$r['amount'],$unit):''); ?></td><td><?php echo esc_html($r['description']); ?><?php if($r['reference']!==''): ?><br><small dir="ltr"><?php echo esc_html($r['reference']); ?></small><?php endif; ?></td><td>
<b><?php echo esc_html(ImportService::ROW_STATUS[$st]??$st); ?></b><?php if(!empty($r['stale'])): ?> <small class="erp-err">(سند یا تطبیق قبلی حذف یا باطل شده است)</small><?php endif; ?>
<?php if($r['error']!==''&&in_array($st,['error','duplicate','ignored'],true)): ?><br><small><?php echo esc_html($r['error']); ?></small><?php endif; ?>
<?php if($r['decision']!==''&&isset(BankMatchService::SOURCES[$r['decision']])): ?><br><small class="muted">تصمیم: <?php echo esc_html(BankMatchService::SOURCES[$r['decision']]); ?></small><?php endif; ?>
<?php if($st==='matched'&&$r['match_line_id']): $v=Db::row('SELECT v.id,v.number FROM '.Db::t('voucher_lines').' l JOIN '.Db::t('vouchers').' v ON v.company_id=l.company_id AND v.id=l.voucher_id WHERE l.company_id=%d AND l.id=%d',$cid,$r['match_line_id']); if($v): ?><br><a href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'voucher_view','id'=>$v['id']])); ?>">سند <?php echo esc_html(Money::toFa((string)$v['number'])); ?></a><?php endif; endif; ?>
<?php if($st==='posted'&&$r['voucher_id']): ?><br><a href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'voucher_view','id'=>$r['voucher_id']])); ?>">سند پیش‌نویس — برای تأیید باز کنید</a><?php endif; ?>
<?php if($open): ?>
<?php if($st==='ambiguous'): $cand=BankMatchService::candidates($cid,$acc,$r); ?><form method="post" class="erp-form"><?php self::form('module_bank_confirm',$cid,'import_bank',$r['id']); ?><?php foreach($cand as $i=>$c): ?><label><input type="radio" name="line_id" value="<?php echo (int)$c['id']; ?>" <?php checked($i,0); ?>> سند <?php echo esc_html(Money::toFa((string)$c['number']).' — '.Jalali::format($c['voucher_date']).' — '.$c['description']); ?></label><?php endforeach; ?><button type="submit" class="btn btn-outline btn-sm">تأیید انتخاب</button></form><?php endif; ?>
<?php if($st==='matched'&&!$r['treasury_doc_id']): ?><form method="post" class="erp-inline-form"><?php self::form('module_bank_unmatch',$cid,'import_bank',$r['id']); ?><button type="submit" class="btn btn-outline btn-sm">برداشتن تطبیق</button></form><?php endif; ?>
<?php if($st==='open'): foreach(BankMatchService::suggest($cid,$r,$acc) as $s): if($s['source']==='invoice'): ?><form method="post" class="erp-inline-form"><?php self::form('module_bank_invoice',$cid,'import_bank',$r['id']); ?><input type="hidden" name="invoice_id" value="<?php echo (int)$s['invoice_id']; ?>"><button type="submit" class="btn btn-outline btn-sm" data-confirm="برگهٔ <?php echo $r['direction']==='in'?'دریافت':'پرداخت'; ?> با تسویهٔ این فاکتور صادر می‌شود. ادامه می‌دهید؟"><?php echo esc_html($s['label']); ?></button></form>
<?php else: ?><form method="post" class="erp-inline-form"><?php self::form('module_bank_post',$cid,'import_bank',$r['id']); ?><input type="hidden" name="source" value="<?php echo esc_attr($s['source']); ?>"><input type="hidden" name="rule_id" value="<?php echo (int)($s['rule_id']??0); ?>"><input type="hidden" name="account_id" value="<?php echo (int)$s['account_id']; ?>"><input type="hidden" name="detail_id" value="<?php echo (int)$s['detail_id']; ?>"><button type="submit" class="btn btn-outline btn-sm">سند بزن: <?php echo esc_html($s['label']); ?></button></form><?php endif; endforeach; ?>
<a class="btn btn-outline btn-sm" href="<?php echo esc_url(add_query_arg(['row'=>$r['id']])); ?>">سند بزن با انتخاب حساب</a><?php endif; ?>
<?php if(in_array($st,['open','ambiguous','matched','duplicate','skipped'],true)&&!$r['treasury_doc_id']): ?><details><summary>کنار گذاشتن</summary><form method="post" class="erp-form"><?php self::form('module_bank_ignore',$cid,'import_bank',$r['id']); ?><input name="reason" required minlength="5" maxlength="300" placeholder="دلیل" aria-label="دلیل کنار گذاشتن"><button type="submit" class="btn btn-outline btn-sm">کنار بگذار</button></form></details><?php endif; ?>
<?php if($st==='ignored'): ?><form method="post" class="erp-inline-form"><?php self::form('module_bank_restore',$cid,'import_bank',$r['id']); ?><button type="submit" class="btn btn-outline btn-sm">بازگرداندن</button></form><?php endif; ?>
<?php endif; ?>
</td></tr><?php endforeach; ?>
</tbody></table><?php self::pages($pages,$pg); ?>
</section>
<?php endif; ?>

<section class="erp-card"><h2>دسته‌های اخیر</h2><table class="erp-table"><thead><tr><th>شماره</th><th>بانک</th><th>فایل</th><th>وضعیت</th><th>ردیف / خطا</th><th>زمان</th></tr></thead><tbody>
<?php foreach(ImportService::batches($cid,'bank') as $x): ?><tr><td><a href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'import_bank','id'=>$x['id']])); ?>"><?php echo esc_html(Money::toFa((string)$x['id'])); ?></a></td><td><?php echo esc_html((string)$x['treasury_name']); ?></td><td><?php echo esc_html($x['file_name']); ?></td><td><?php echo esc_html(ImportService::STATUS[$x['status']]??$x['status']); ?></td><td><?php echo esc_html(Money::toFa($x['rows_total'].' / '.$x['rows_error'])); ?></td><td><?php echo esc_html(Jalali::format(substr(get_date_from_gmt($x['created_at']),0,10))); ?></td></tr><?php endforeach; ?>
</tbody></table></section>

<section class="erp-card"><h2>قاعده‌های ثبت (کارمزد، هزینه و …)</h2>
<p class="muted">اگر شرح تراکنش عبارت قاعده را داشته باشد، حساب قاعده پیشنهاد می‌شود. ویرایش، نسخهٔ تازه می‌سازد و نسخهٔ قبلی برای ردپای تصمیم‌ها می‌ماند. قاعدهٔ به‌کاررفته حذف نمی‌شود؛ غیرفعالش کنید.</p>
<?php $edit=Request::int('get','rule');$er=$edit?Business::row('posting_rules',$cid,$edit):null; ?>
<table class="erp-table"><thead><tr><th>اولویت</th><th>نام</th><th>اگر شرح شامل</th><th>جهت</th><th>حساب</th><th>نسخه</th><th></th></tr></thead><tbody>
<?php foreach(BankMatchService::rules($cid) as $ru): ?><tr><td><?php echo esc_html(Money::toFa((string)$ru['priority'])); ?></td><td><?php echo esc_html($ru['name']); ?></td><td><?php echo esc_html($ru['contains_text']); ?></td><td><?php echo esc_html(['in'=>'واریز','out'=>'برداشت','any'=>'هر دو'][$ru['direction']]??''); ?></td><td><?php echo esc_html($ru['account_code'].' — '.$ru['account_name'].($ru['detail_name']?' / '.$ru['detail_name']:'')); ?></td><td><?php echo esc_html(Money::toFa((string)$ru['version'])); ?></td><td>
<a class="btn btn-outline btn-sm" href="<?php echo esc_url(add_query_arg(['rule'=>$ru['id']])); ?>">ویرایش</a>
<form method="post" class="erp-inline-form"><?php self::form('module_rule_deactivate',$cid,'import_bank',$ru['id']); ?><button type="submit" class="btn btn-outline btn-sm">غیرفعال</button></form>
<form method="post" class="erp-inline-form"><?php self::form('module_rule_delete',$cid,'import_bank',$ru['id']); ?><button type="submit" class="btn btn-outline btn-sm" data-confirm="قاعده و همهٔ نسخه‌هایش حذف می‌شود (فقط اگر هرگز به کار نرفته باشد). ادامه می‌دهید؟">حذف</button></form></td></tr><?php endforeach; ?>
</tbody></table>
<form method="post" class="erp-form"><?php self::form('module_rule_save',$cid,'import_bank',$er['id']??0); ?><h3><?php echo $er?'ویرایش قاعده (نسخهٔ تازه)':'قاعدهٔ تازه'; ?></h3>
<?php self::input('rule_name','نام قاعده',$er['name']??'','text','required maxlength="100"'); self::input('contains_text','اگر شرح شامل این عبارت بود',$er['contains_text']??'','text','required maxlength="100"'); self::select('direction','جهت',['any'=>'هر دو','in'=>'واریز','out'=>'برداشت'],$er['direction']??'any'); $pick($er['account_id']??0,$er['detail_id']??0); self::input('priority','اولویت (عدد کمتر = زودتر)',$er['priority']??100,'number','min="1" max="1000" required'); ?>
<button type="submit" class="btn btn-primary">ذخیرهٔ قاعده</button></form>
</section>

<section class="erp-card"><h2>حافظهٔ یادگیری</h2><p class="muted">پس از هر «سند بزن»، حساب انتخابی برای شرح‌های مشابه (بدون عدد و شماره پیگیری) به خاطر سپرده می‌شود.</p>
<table class="erp-table"><thead><tr><th>شرح نمونه</th><th>جهت</th><th>حساب</th><th>تعداد</th><th></th></tr></thead><tbody>
<?php foreach(BankMatchService::memory($cid) as $m): ?><tr><td><?php echo esc_html($m['sample']); ?></td><td><?php echo esc_html($dirLabel[$m['direction']]??''); ?></td><td><?php echo esc_html($m['account_code'].' — '.$m['account_name']); ?></td><td><?php echo esc_html(Money::toFa((string)$m['hits'])); ?></td><td><form method="post" class="erp-inline-form"><?php self::form('module_memory_forget',$cid,'import_bank',$m['id']); ?><button type="submit" class="btn btn-outline btn-sm">فراموش کن</button></form></td></tr><?php endforeach; ?>
</tbody></table></section>
