<?php
/** ماتریس منبع×عمل؛ نقش‌های سیستمی قفل‌اند و برای کپی از دادهٔ همان نقش استفاده می‌شود. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Db,Ctx,Request,Money,Jalali,Crypto,Audit,Settings};
use Agah\Erp\Modules\Accounting\{AccountService,DetailService,FiscalService,VoucherService,ReportService,ChartSeed};
use Agah\Erp\Modules\Company\{CompanyService,MemberService};
if(!defined('ABSPATH')) exit;
$roles=MemberService::roles($cid);$edit=Request::int('get','edit');$copy=Request::int('get','copy');$selected=null;
foreach($roles as $r) if((int)$r['id']===($edit?:$copy)) $selected=$r;
if(($edit||$copy) && !$selected) throw new \Agah\Erp\Core\NotFoundException();
?>
<section class="erp-card"><h2>نقش‌ها و مجوزها</h2><div class="erp-scroll"><table class="erp-table"><thead><tr><th>نام</th><th>نوع</th><th>عملیات</th></tr></thead><tbody>
<?php foreach($roles as $r): ?><tr><td><?php echo esc_html($r['name']); ?></td><td><?php echo $r['is_system']?'سیستمی':'سفارشی'; ?></td><td><?php if(Ctx::can('role.edit')): ?><a href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'roles','copy'=>$r['id']])); ?>">ساخت کپی</a>
<?php if(!$r['is_system']): ?> | <a href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'roles','edit'=>$r['id']])); ?>">ویرایش</a><form method="post" class="inline"><?php self::form('module_role_delete',$cid,'roles',$r['id']); ?><button type="submit" class="btn btn-outline btn-sm">حذف نقش بدون سابقه</button></form><?php endif; endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php if(Ctx::can('role.edit') && (!$selected || !$edit || !$selected['is_system'])): ?>
<section class="erp-card"><h3><?php echo $edit?'ویرایش نقش سفارشی':'ساخت نقش سفارشی'; ?></h3><form method="post" class="erp-form erp-role-form"><?php self::form('module_role_save',$cid,'roles',$edit);self::input('name','نام نقش',$selected?($copy?'کپی '.$selected['name']:$selected['name']):'','text','required maxlength="100"'); ?>
<div class="erp-scroll"><table class="erp-table"><thead><tr><th>منبع</th><?php foreach(['view'=>'مشاهده','create'=>'ایجاد','edit'=>'ویرایش','delete'=>'حذف','approve'=>'تأیید','export'=>'خروجی','finalize'=>'قطعی','reopen'=>'بازگشایی','void'=>'ابطال','sensitive'=>'اطلاعات حساس','confirm'=>'تأیید نرخ'] as $a=>$label): ?><th><?php echo esc_html($label); ?></th><?php endforeach; ?></tr></thead><tbody>
<?php $chosen=self::value('caps',$selected['caps']??[]);foreach(\Agah\Erp\Security\Caps::resources() as $resource=>$meta): ?><tr><th><?php echo esc_html($meta[0]); ?></th><?php foreach(['view','create','edit','delete','approve','export','finalize','reopen','void','sensitive','confirm'] as $action): ?><td><?php $cap=$resource.'.'.$action;if(in_array($action,$meta[2],true) && Ctx::can($cap)): ?><input type="checkbox" name="caps[]" value="<?php echo esc_attr($cap); ?>" aria-label="<?php echo esc_attr($meta[0].' '.$action); ?>" <?php checked(in_array($cap,$chosen,true)); ?>><?php else: ?>—<?php endif; ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></div><button type="submit" class="btn btn-primary">ذخیرهٔ نقش</button></form></section><?php endif; ?>
