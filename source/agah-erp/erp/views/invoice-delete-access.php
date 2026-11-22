<?php
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Ctx,Request};
use Agah\Erp\Modules\Company\MemberService;
if(!defined('ABSPATH'))exit;
$data=MemberService::invoiceDeletionAccess($cid,Request::int('get','pg',1),Request::str('get','q',100));
?>
<section class="erp-card">
<h2>دسترسی حذف فاکتور</h2>
<p>پیش‌فرض این مجوز فقط برای مدیر شرکت و مدیر فروشگاه فعال است. تنظیم فردی بر تنظیم گروه اولویت دارد. کاربر باید مجوز مشاهدهٔ همان نوع فاکتور را نیز داشته باشد؛ عضویت غیرفعال اجازهٔ کار نمی‌دهد.</p>
<p>این دسترسی امکان حذف فاکتور و برگشت آثار وابسته را می‌دهد. سابقهٔ فاکتور و تغییر دسترسی در گزارش حسابرسی باقی می‌ماند.</p>
<h3>تفویض به گروه کاربری</h3>
<form method="post" class="erp-form">
<?php self::form('module_invoice_delete_role',$cid,'invoice_delete_access'); ?>
<label>گروه کاربری<select name="role_id" required><?php foreach($data['roles'] as $role): ?><option value="<?php echo (int)$role['id']; ?>"><?php echo esc_html($role['name'].' — '.($role['allowed']?'مجاز':'غیرمجاز')); ?></option><?php endforeach; ?></select></label>
<label>دسترسی گروه<select name="allowed"><option value="1">اجازهٔ حذف فاکتور</option><option value="0">برداشتن اجازهٔ حذف فاکتور</option></select></label>
<button type="submit" class="btn btn-primary">ذخیرهٔ دسترسی گروه</button>
</form>
</section>
<section class="erp-card"><h3>تفویض به شخص مشخص</h3>
<?php self::search($cid,'invoice_delete_access'); ?>
<div class="erp-scroll"><table class="erp-table"><thead><tr><th>کاربر</th><th>گروه</th><th>دسترسی مؤثر</th><th>تنظیم فردی</th></tr></thead><tbody>
<?php foreach($data['rows'] as $member): $override=(int)$member['invoice_delete_override'];$allowed=$override===-1?(bool)$member['role_allowed']:$override===1; ?>
<tr><td><?php echo esc_html($member['display_name'].' ('.$member['user_login'].')'); ?></td><td><?php echo esc_html($member['role_name']); ?></td><td><?php echo esc_html($member['status']!=='active'?'عضویت غیرفعال':($allowed?'مجاز به حذف':'غیرمجاز')); ?></td><td>
<form method="post" class="erp-inline-form">
<?php self::form('module_invoice_delete_user',$cid,'invoice_delete_access'); ?><input type="hidden" name="user_id" value="<?php echo (int)$member['user_id']; ?>">
<label>حذف فاکتور برای <?php echo esc_html($member['display_name']); ?><select name="permission">
<?php foreach([-1=>'مطابق گروه کاربری',1=>'اجازهٔ فردی',0=>'ممنوع برای این شخص'] as $value=>$label): ?><option value="<?php echo (int)$value; ?>" <?php selected($override,$value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
</select></label><button type="submit" class="btn btn-outline">ذخیرهٔ دسترسی شخص</button></form>
</td></tr><?php endforeach; ?>
</tbody></table></div><?php self::pages($data['pages'],$data['page']); ?></section>
