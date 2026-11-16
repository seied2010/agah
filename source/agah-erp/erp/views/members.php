<?php
/** اعضای شرکت با نقش خوانا؛ کاربران حذف‌شده برای حفظ سابقه نمایش داده می‌شوند. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Db,Ctx,Request,Money,Jalali,Crypto,Audit,Settings};
use Agah\Erp\Modules\Accounting\{AccountService,DetailService,FiscalService,VoucherService,ReportService,ChartSeed};
use Agah\Erp\Modules\Company\{CompanyService,MemberService};
if(!defined('ABSPATH')) exit;
$data=MemberService::page($cid,Request::int('get','pg',1),Request::str('get','q',100));$members=$data['rows'];$roles=MemberService::roles($cid);$options=[];foreach($roles as $r) $options[$r['id']]=$r['name'];
?>
<section class="erp-card"><h2>اعضای شرکت</h2><?php self::search($cid,'members'); ?><div class="erp-scroll"><table class="erp-table"><thead><tr><th>کاربر</th><th>نقش</th><th>وضعیت</th><th>تغییر عضویت</th></tr></thead><tbody>
<?php foreach($members as $m): ?><tr><td><?php echo esc_html($m['display_name'].' ('.$m['user_login'].')'); ?></td><td><?php echo esc_html($m['role_name']); ?></td><td><?php echo esc_html(['active'=>'فعال','inactive'=>'غیرفعال','removed'=>'حذف عضویت'][$m['status']]??'—'); ?></td><td>
<?php if(Ctx::can('member.edit') || Ctx::can('member.delete')): ?><form method="post" class="erp-inline-form"><?php self::form('module_member_change',$cid,'members'); ?><input type="hidden" name="user_id" value="<?php echo (int)$m['user_id']; ?>">
<label>نقش<select name="role_id"><?php foreach($options as $id=>$name): ?><option value="<?php echo (int)$id; ?>" <?php selected($id,$m['role_id']); ?>><?php echo esc_html($name); ?></option><?php endforeach; ?></select></label>
<label>وضعیت<select name="status"><option value="active" <?php selected($m['status'],'active'); ?>>فعال</option><option value="inactive" <?php selected($m['status'],'inactive'); ?>>غیرفعال</option><?php if(Ctx::can('member.delete')): ?><option value="removed">حذف عضویت با حفظ سابقه</option><?php endif; ?></select></label><button class="btn btn-outline btn-sm" type="submit">اعمال</button></form><?php endif; ?>
</td></tr><?php endforeach; ?></tbody></table></div><?php self::pages($data['pages'],$data['page']); ?></section>
<?php if(Ctx::can('member.create')): ?><section class="erp-card"><h3>افزودن عضو</h3><p>برای کاربر موجود نام کاربری یا ایمیل را وارد کنید. برای کاربر جدید، نام کاربری لاتین، ایمیل و گذرواژه لازم است. نقش وردپرس او فقط مشترک خواهد بود.</p><form method="post" class="erp-form"><?php self::form('module_member_add',$cid,'members');
self::input('login','نام کاربری یا ایمیل','','text','required maxlength="60" autocomplete="off"');self::input('email','ایمیل کاربر جدید','','email','maxlength="190"');self::input('display_name','نام نمایشی کاربر جدید','','text','maxlength="190"');self::input('password','گذرواژهٔ کاربر جدید (حداقل ۱۰ نویسه، حرف و عدد)','','password','autocomplete="new-password"');self::select('role_id','نقش',$options); ?><button type="submit" class="btn btn-primary">افزودن عضو</button></form></section><?php endif; ?>
