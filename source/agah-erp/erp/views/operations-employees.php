<?php
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Business,Ctx,Db,Jalali,Money,Request};
use Agah\Erp\Modules\Files\FileService;
use Agah\Erp\Modules\Payroll\PayrollService;
use Agah\Erp\Modules\Assets\AssetService;
if(!defined('ABSPATH'))exit;
$edit=Request::int('get','edit');$row=$edit?Business::row('employees',$cid,$edit):[];$data=Business::page('employees',$cid,Request::int('get','pg',1),Request::str('get','q',100));
?>
<section class="erp-card"><h2>کارکنان</h2><?php self::search($cid,$p); ?><div class="erp-scroll"><table class="erp-table"><thead><tr><?php echo self::sortHead('code','کد').self::sortHead('name','نام'); ?><th>وضعیت</th><th>عملیات</th></tr></thead><tbody><?php foreach($data['rows'] as $e): ?><tr><td><?php echo esc_html($e['code']); ?></td><td><?php echo esc_html($e['name']); ?></td><td><?php echo $e['is_active']?'فعال':'غیرفعال'; ?></td><td><?php if(Ctx::can('payroll.edit')): ?><a href="<?php echo esc_url(add_query_arg('edit',$e['id'])); ?>">ویرایش</a><?php endif; ?> <a href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'hr_profile','id'=>$e['id']])); ?>">پرونده</a></td></tr><?php endforeach; ?></tbody></table></div><?php self::pages($data['pages'],$data['page']); ?></section>
<?php if(Ctx::can($edit?'payroll.edit':'payroll.create')): ?><section class="erp-card"><form method="post" class="erp-form"><?php self::form('module_payroll_employee',$cid,$p,$edit);self::input('code','کد',$row['code']??'','text','required');self::input('name','نام و نام خانوادگی',$row['name']??'','text','required'); ?><label><input type="checkbox" name="is_active" value="1" <?php checked(self::value('is_active',$row['is_active']??1),1); ?>>فعال</label><button type="submit" class="btn btn-primary">ذخیره کارمند</button></form></section><?php endif; ?>
