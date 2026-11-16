<?php
/** ممیزی صفحه‌بندی‌شده؛ فیلترها prepared و جزئیات حساس پیش‌تر در Audit پوشانده شده‌اند. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Db,Ctx,Request,Money,Jalali,Crypto,Audit,Settings};
use Agah\Erp\Modules\Accounting\{AccountService,DetailService,FiscalService,VoucherService,ReportService,ChartSeed};
use Agah\Erp\Modules\Company\{CompanyService,MemberService};
if(!defined('ABSPATH')) exit;
$where='company_id=%d';$args=[$cid];
$uid=Request::int('get','user_id');$event=Request::str('get','event',60);$entity=Request::str('get','entity_type',30);
if($uid) {$where.=' AND user_id=%d';$args[]=$uid;}
foreach(['event'=>$event,'entity_type'=>$entity] as $col=>$v) if($v!=='') {$where.=' AND '.$col.'=%s';$args[]=$v;}
foreach(['from'=>'>=','to'=>'<='] as $key=>$op) if($v=Request::str('get',$key,10)) {$where.=' AND created_at'.$op.'%s';$args[]=Jalali::parse($v).($key==='from'?' 00:00:00':' 23:59:59');}
$pg=max(1,Request::int('get','pg',1));$count=(int)Db::val('SELECT COUNT(*) FROM '.Db::t('audit_log').' WHERE '.$where,...$args);
$rows=Db::rows('SELECT * FROM '.Db::t('audit_log').' WHERE '.$where.' ORDER BY id DESC LIMIT 50 OFFSET %d',...array_merge($args,[($pg-1)*50]));
$bad=Audit::verify($cid);
?>
<section class="erp-card"><h2>گزارش ممیزی و سلامت زنجیره</h2><p class="<?php echo $bad===null?'erp-ok':'erp-err'; ?>"><?php echo $bad===null?'زنجیرهٔ ممیزی سالم است.':'هشدار: شکست زنجیره از ردیف '.esc_html(Money::toFa($bad)); ?></p>
<form method="get" action="<?php echo esc_url(agah_erp_url()); ?>" class="erp-filter"><input type="hidden" name="page_id" value="<?php echo (int)get_option('agah_erp_page_id'); ?>"><input type="hidden" name="c" value="<?php echo (int)$cid; ?>"><input type="hidden" name="p" value="audit">
<label>شناسهٔ کاربر<input name="user_id" type="number" min="0" value="<?php echo $uid?:''; ?>"></label><label>رویداد<input name="event" value="<?php echo esc_attr($event); ?>" maxlength="60" dir="ltr"></label><label>نوع موجودیت<input name="entity_type" value="<?php echo esc_attr($entity); ?>" maxlength="30" dir="ltr"></label>
<?php foreach(['from'=>'از تاریخ شمسی','to'=>'تا تاریخ شمسی'] as $key=>$label): ?><label><?php echo esc_html($label); ?><input name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr(Request::str('get',$key,10)); ?>" data-jalali dir="ltr"></label><?php endforeach; ?><button type="submit" class="btn btn-outline">اعمال فیلتر</button></form>
<div class="erp-scroll"><table class="erp-table"><thead><tr><th>زمان UTC</th><th>کاربر</th><th>رویداد</th><th>موجودیت</th><th>جزئیات</th></tr></thead><tbody>
<?php foreach($rows as $r): $u=get_userdata((int)$r['user_id']); ?><tr><td><?php echo esc_html(Jalali::format(substr($r['created_at'],0,10)).' '.substr($r['created_at'],11)); ?></td><td><?php echo esc_html($u?$u->display_name:'—'); ?></td><td dir="ltr"><?php echo esc_html($r['event']); ?></td><td><?php echo esc_html($r['entity_type'].' #'.$r['entity_id']); ?></td><td><details><summary>مقادیر قبلی و جدید</summary><pre dir="ltr"><?php echo esc_html(wp_json_encode(['old'=>json_decode($r['old_json']??'null',true),'new'=>json_decode($r['new_json']??'null',true)],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)); ?></pre><p dir="ltr"><?php echo esc_html($r['ip']); ?></p></details></td></tr><?php endforeach; ?></tbody></table></div><?php self::pages((int)ceil($count/50),$pg); ?></section>
