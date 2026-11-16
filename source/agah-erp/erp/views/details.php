<?php
/** فهرست و فرم تفصیلی؛ شناسهٔ ویرایش قبل از نمایش هم به شرکت جاری محدود است. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Db,Ctx,Request,Money,Jalali,Crypto,Audit,Settings};
use Agah\Erp\Modules\Accounting\{AccountService,DetailService,FiscalService,VoucherService,ReportService,ChartSeed};
use Agah\Erp\Modules\Company\{CompanyService,MemberService};
if(!defined('ABSPATH')) exit;
$data=DetailService::page($cid,Request::int('get','pg',1),Request::str('get','q',100));$rows=$data['rows'];$edit=Request::int('get','edit');$item=$edit?DetailService::get($cid,$edit):[];
?>
<section class="erp-card"><h2>تفصیلی‌های شناور، مراکز هزینه و پروژه‌ها</h2><?php self::search($cid,'details'); ?><div class="erp-scroll"><table class="erp-table"><thead><tr><th>کد</th><th>نام</th><th>نوع</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr><td><?php echo esc_html(Money::toFa($r['code'])); ?></td><td><?php echo esc_html($r['name']); ?></td><td><?php echo esc_html(DetailService::KINDS[$r['kind']]??$r['kind']); ?></td><td><?php echo $r['is_active']?'فعال':'غیرفعال'; ?></td><td><?php if(Ctx::can('detail.edit')): ?><a href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'details','edit'=>$r['id']])); ?>">ویرایش</a><?php endif; ?> <?php if(Ctx::can('detail.delete')): ?><form method="post" class="inline"><?php self::form('module_detail_delete',$cid,'details',$r['id']); ?><button type="submit" class="btn btn-outline btn-sm">حذف بدون گردش</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php self::pages($data['pages'],$data['page']); ?></section>
<?php if(Ctx::can($edit?'detail.edit':'detail.create')): ?><section class="erp-card"><h3><?php echo $edit?'ویرایش تفصیلی':'تفصیلی جدید'; ?></h3><form method="post" class="erp-form"><?php self::form('module_detail_save',$cid,'details',$edit);self::input('code','کد',$item['code']??'','text','required maxlength="20"');self::input('name','نام',$item['name']??'','text','required maxlength="190"');self::select('kind','نوع',DetailService::KINDS,$item['kind']??'other'); ?>
<label><input name="is_active" type="checkbox" value="1" <?php checked(self::value('is_active',$item['is_active']??1),1); ?>> فعال</label><button type="submit" class="btn btn-primary">ذخیرهٔ تفصیلی</button></form></section><?php endif; ?>
