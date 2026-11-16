<?php
/** سامانهٔ مؤدیان: وضعیت واقعی اتصال، سیاست نسخه‌دار، انتخاب برگه‌ها، صف و پیگیری، اصلاحیه/ابطال و ثبت دستی کارپوشه. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Ctx,Jalali,Money,Request};
use Agah\Erp\Modules\Moadian\MoadianService as MD;
use Agah\Erp\Modules\Trade\InvoiceService;
if(!defined('ABSPATH'))exit;
$state=MD::state();$pol=MD::policy($cid);$status=Request::str('get','status',12);$recs=MD::records($cid,$status,Request::int('get','pg',1));
$from=Request::str('get','from',10);$to=Request::str('get','to',10);$fromIso=$from?Jalali::parse($from):Jalali::addDays(Jalali::today(),-30);$toIso=$to?Jalali::parse($to):Jalali::today();
?>
<section class="erp-card"><h2>سامانهٔ مؤدیان</h2>
<p class="<?php echo $state['active']?'':'erp-err'; ?>"><b>وضعیت اتصال:</b> <?php echo esc_html($state['label']); ?></p>
<p class="muted">شمارهٔ داخلی برگه، شناسهٔ رسمی کالا/خدمت و شمارهٔ منحصربه‌فرد مالیاتی جدا نگه داشته می‌شوند. اتصال واقعی فقط با افزونهٔ اتصالِ دارای مستند رسمی و اعتبارنامهٔ شرکت فعال می‌شود؛ تا آن زمان می‌توانید برگه‌ها را در صف آماده کنید و پس از ارسال دستی در کارپوشه، نتیجه را این‌جا ثبت کنید.</p>
<p>سیاست مؤثر: نسخهٔ <?php echo esc_html(Money::toFa((string)$pol['version'])); ?> — انواع: <?php echo esc_html(implode('، ',array_map(fn($k)=>MD::KINDS[$k]??$k,$pol['kinds']))); ?> — <?php echo esc_html(MD::TYPES[(int)$pol['invoice_type']]??''); ?> — انتخاب خودکار هنگام صدور: <?php echo (int)$pol['auto_select']?'بله':'خیر'; ?>. <a href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'moadian_items'])); ?>">شناسهٔ رسمی کالا/خدمت</a></p>
<?php if(Ctx::can('moadian.approve')): ?><div class="erp-actions">
<form method="post" class="erp-inline-form"><?php self::form('module_moadian_send',$cid,'moadian'); ?><button type="submit" class="btn btn-primary" <?php echo $state['active']?'':'disabled'; ?>>ارسال صف به سامانه</button></form>
<form method="post" class="erp-inline-form"><?php self::form('module_moadian_poll',$cid,'moadian'); ?><button type="submit" class="btn btn-outline" <?php echo $state['active']?'':'disabled'; ?>>پیگیری نتیجه</button></form>
</div><?php endif; ?>
</section>

<?php if(Ctx::can('moadian.edit')): $cands=MD::candidates($cid,$fromIso,$toIso); ?>
<section class="erp-card"><h2>انتخاب برگه برای ارسال</h2>
<form method="get" class="erp-filter"><input type="hidden" name="page_id" value="<?php echo (int)get_option('agah_erp_page_id'); ?>"><input type="hidden" name="c" value="<?php echo (int)$cid; ?>"><input type="hidden" name="p" value="moadian"><label>از<input name="from" data-jalali maxlength="10" value="<?php echo esc_attr(Jalali::format($fromIso,false)); ?>"></label><label>تا<input name="to" data-jalali maxlength="10" value="<?php echo esc_attr(Jalali::format($toIso,false)); ?>"></label><button type="submit" class="btn btn-outline">نمایش</button></form>
<?php if($cands): ?><form method="post" class="erp-form"><?php self::form('module_moadian_select',$cid,'moadian'); ?>
<div class="erp-scroll"><table class="erp-table"><thead><tr><th>انتخاب</th><th>نوع</th><th>شماره</th><th>تاریخ</th><th>خریدار</th><th>مبلغ</th></tr></thead><tbody>
<?php foreach($cands as $i): ?><tr><td><input type="checkbox" name="ids[]" value="<?php echo (int)$i['id']; ?>" checked aria-label="انتخاب برگه"></td><td><?php echo esc_html(InvoiceService::KINDS[$i['kind']]??$i['kind']); ?></td><td><a href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'invoice_view','id'=>$i['id']])); ?>"><?php echo esc_html(Money::toFa((string)$i['number'])); ?></a></td><td><?php echo esc_html(Jalali::format($i['invoice_date'])); ?></td><td><?php echo esc_html($i['party_name']); ?></td><td><?php echo esc_html(Money::fmt((int)$i['amount'],Ctx::unit())); ?></td></tr><?php endforeach; ?>
</tbody></table></div><button type="submit" class="btn btn-primary">افزودن به صف (با اعتبارسنجی)</button></form>
<?php else: ?><p>در این بازه برگهٔ صادرشدهٔ مشمولِ خارج از صف نیست.</p><?php endif; ?>
</section>
<?php endif; ?>

<section class="erp-card"><h2>صف و سابقهٔ ارسال</h2>
<form method="get" class="erp-filter"><input type="hidden" name="page_id" value="<?php echo (int)get_option('agah_erp_page_id'); ?>"><input type="hidden" name="c" value="<?php echo (int)$cid; ?>"><input type="hidden" name="p" value="moadian"><label>وضعیت<select name="status"><option value="">همه</option><?php foreach(MD::STATUS as $k=>$l): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($status,$k); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?></select></label><button type="submit" class="btn btn-outline">نمایش</button></form>
<div class="erp-scroll"><table class="erp-table"><thead><tr><th>برگه</th><th>اقدام</th><th>وضعیت</th><th>سیاست</th><th>شمارهٔ مالیاتی</th><th>توضیح / خطا</th><th>عملیات</th></tr></thead><tbody>
<?php foreach($recs['rows'] as $r): ?><tr><td><a href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'invoice_view','id'=>$r['invoice_id']])); ?>"><?php echo esc_html((InvoiceService::KINDS[$r['kind']]??$r['kind']).' '.Money::toFa((string)$r['number'])); ?></a><br><small><?php echo esc_html(Jalali::format($r['invoice_date']).' — '.$r['party_name']); ?></small></td><td><?php echo esc_html(MD::ACTIONS[$r['action']]??$r['action']); ?></td><td><?php echo esc_html(MD::STATUS[$r['status']]??$r['status']); ?><?php if($r['source']==='manual'): ?><br><small>ثبت دستی کارپوشه</small><?php endif; ?></td><td><?php echo esc_html(Money::toFa((string)$r['policy_version'])); ?></td><td dir="ltr"><?php echo esc_html($r['tax_unique_id']?:'—'); ?></td><td class="erp-wrap"><?php echo $r['errors']!==''?'<span class="erp-err">'.esc_html($r['errors']).'</span>':esc_html($r['reason']); ?></td><td>
<?php if(in_array($r['status'],['blocked','queued'],true)&&Ctx::can('moadian.edit')): ?><form method="post" class="erp-inline-form"><?php self::form('module_moadian_revalidate',$cid,'moadian',$r['id']); ?><button type="submit" class="btn btn-outline btn-sm">بازبینی</button></form><?php if(!(int)$r['attempts']): ?><form method="post" class="erp-inline-form"><?php self::form('module_moadian_remove',$cid,'moadian',$r['id']); ?><button type="submit" class="btn btn-outline btn-sm" data-confirm="از صف خارج شود؟">خروج از صف</button></form><?php endif; ?><?php endif; ?>
<?php if(in_array($r['status'],['queued','sent'],true)&&Ctx::can('moadian.approve')): ?><details><summary>ثبت دستی نتیجهٔ کارپوشه</summary><form method="post" class="erp-form"><?php self::form('module_moadian_manual',$cid,'moadian',$r['id']); self::select('result','نتیجه',['accepted'=>'پذیرفته شد','rejected'=>'رد شد'],'accepted'); self::input('tax_unique_id','شمارهٔ منحصربه‌فرد مالیاتی (۲۲ نویسه)','','text','maxlength="40" dir="ltr"'); self::input('reference_no','شمارهٔ پیگیری','','text','maxlength="64" dir="ltr"'); self::input('note','توضیح / علت رد','','text','maxlength="300"'); ?><button type="submit" class="btn btn-outline btn-sm" data-confirm="نتیجهٔ ثبت‌شده در کارپوشه را با برچسب «دستی» ثبت می‌کنید؟">ثبت نتیجه</button></form></details><?php endif; ?>
</td></tr><?php endforeach; ?>
</tbody></table></div><?php self::pages($recs['pages'],$recs['page']); ?></section>

<section class="erp-card"><h2>سیاست ارسال (نسخه‌دار)</h2>
<?php $pols=MD::policies($cid); if($pols): ?><table class="erp-table"><thead><tr><th>نسخه</th><th>از تاریخ</th><th>انواع</th><th>نوع صورتحساب</th><th>انتخاب خودکار</th><th>یادداشت</th><th></th></tr></thead><tbody>
<?php foreach($pols as $p): ?><tr><td><?php echo esc_html(Money::toFa((string)$p['version'])); ?></td><td><?php echo esc_html(Jalali::format($p['effective_from'])); ?></td><td><?php echo esc_html(implode('، ',array_map(fn($k)=>MD::KINDS[$k]??$k,json_decode($p['kinds'],true)?:[]))); ?></td><td><?php echo esc_html(MD::TYPES[(int)$p['invoice_type']]??''); ?></td><td><?php echo (int)$p['auto_select']?'بله':'خیر'; ?></td><td><?php echo esc_html($p['note']); ?></td><td><?php if(Ctx::can('moadian.edit')): ?><form method="post" class="erp-inline-form"><?php self::form('module_moadian_policy_delete',$cid,'moadian',$p['id']); ?><button type="submit" class="btn btn-outline btn-sm" data-confirm="این نسخهٔ سیاست حذف شود؟">حذف</button></form><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table><?php else: ?><p class="muted">هنوز نسخه‌ای ثبت نشده و پیش‌فرض (فروش و برگشت، نوع اول، بدون انتخاب خودکار) اعمال می‌شود.</p><?php endif; ?>
<?php if(Ctx::can('moadian.edit')): ?><form method="post" class="erp-form"><?php self::form('module_moadian_policy',$cid,'moadian'); ?>
<fieldset><legend>انواع برگهٔ مشمول</legend><?php foreach(MD::KINDS as $k=>$l): ?><label><input type="checkbox" name="kinds[]" value="<?php echo esc_attr($k); ?>" <?php checked(in_array($k,$pol['kinds'],true)); ?>> <?php echo esc_html($l); ?></label> <?php endforeach; ?></fieldset>
<?php self::select('invoice_type','نوع صورتحساب',MD::TYPES,(int)$pol['invoice_type']); self::input('effective_from','از تاریخ',Jalali::format(Jalali::today(),false),'text','required data-jalali maxlength="10"'); ?>
<label><input type="checkbox" name="auto_select" value="1" <?php checked((int)$pol['auto_select']); ?>> انتخاب خودکار هر برگهٔ مشمول هنگام صدور</label>
<?php self::input('note','یادداشت تغییر','','text','maxlength="300"'); ?><button type="submit" class="btn btn-outline">ثبت نسخهٔ تازهٔ سیاست</button></form><?php endif; ?>
</section>
