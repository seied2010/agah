<?php
/** نمای مراحل ۴ تا ۶؛ دادهٔ شرکت از سرویس و خروجی صرفاً escapeشده است. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Db,Ctx,Request,Money,Jalali,Crypto,Audit,Settings,Business};
use Agah\Erp\Modules\Parties\PartyService;
use Agah\Erp\Modules\Catalog\CatalogService;
use Agah\Erp\Modules\Treasury\TreasuryService;
if(!defined('ABSPATH')) exit;
$data=PartyService::page($cid,Request::int('get','pg',1),Request::str('get','q',100));$edit=Request::int('get','edit');$item=$edit?PartyService::get($cid,$edit):[];
?>
<?php CommerceController::exportLink($cid,'parties'); ?>
<section class="erp-card"><h2>اشخاص</h2><?php self::search($cid,'parties'); ?><div class="erp-scroll"><table class="erp-table"><thead><tr><?php echo self::sortHead('code','کد').self::sortHead('name','نام'); ?><th>نقش</th><th>شناسهٔ پوشیده</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>
<?php foreach($data['rows'] as $r): ?><tr><td><?php echo esc_html($r['code']); ?></td><td><a href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'party_view','id'=>$r['id']])); ?>"><?php echo esc_html($r['name']); ?></a></td><td><?php echo esc_html(trim(($r['is_customer']?'مشتری، ':'').($r['is_supplier']?'تأمین‌کننده، ':'').($r['is_employee']?'پرسنل، ':''),'، ')); ?></td><td dir="ltr"><?php echo esc_html($r['national_id_hint']?:'—'); ?></td><td><?php echo $r['is_active']?'فعال':'غیرفعال'; ?></td><td><?php if(Ctx::can('party.edit')): ?><a href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'parties','edit'=>$r['id']])); ?>">ویرایش</a><?php endif; ?> <?php if(Ctx::can('party.delete')): ?><form method="post" class="inline"><?php self::form('module_party_delete',$cid,'parties',$r['id']); ?><button class="btn btn-outline btn-sm" type="submit">حذف بدون گردش</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php self::pages($data['pages'],$data['page']); ?></section>
<?php if(Ctx::can($edit?'party.edit':'party.create')): ?><section class="erp-card"><h3><?php echo $edit?'ویرایش شخص':'شخص جدید با کد خودکار'; ?></h3><form method="post" class="erp-form"><?php self::form('module_party_save',$cid,'parties',$edit);
self::input('name','نام',$item['name']??'','text','required maxlength="190"');self::select('kind','نوع',['real'=>'حقیقی','legal'=>'حقوقی'],$item['kind']??'real');self::input('national_id','کد ملی / شناسهٔ ملی جدید (خالی = حفظ قبلی)','','text','maxlength="11" dir="ltr" inputmode="numeric" autocomplete="off"');
foreach(['is_customer'=>'مشتری','is_supplier'=>'تأمین‌کننده','is_employee'=>'پرسنل','is_active'=>'فعال'] as $k=>$label): ?><label><input type="checkbox" name="<?php echo esc_attr($k); ?>" value="1" <?php checked(self::value($k,$item[$k]??(in_array($k,['is_supplier','is_employee'],true)?0:1)),1); ?>><?php echo esc_html($label); ?></label><?php endforeach;
foreach(['postal_code'=>'کد پستی','registration_no'=>'شماره ثبت','economic_code'=>'شماره اقتصادی','phone'=>'تلفن','email'=>'ایمیل','address'=>'نشانی'] as $k=>$label)self::input($k,$label,$item[$k]??'','text','maxlength="'.($k==='address'?500:($k==='email'?190:30)).'"');
self::input('credit_limit','سقف اعتبار؛ صفر یعنی بدون سقف ('.(Ctx::unit()==='rial'?'ریال':'تومان').')',Money::toInputValue((int)($item['credit_limit']??0),Ctx::unit()),'text','inputmode="decimal" required'); ?>
<label><input type="checkbox" name="clear_national_id" value="1"> حذف صریح شناسهٔ قبلی</label><button type="submit" class="btn btn-primary">ذخیرهٔ شخص</button></form></section><?php endif; ?>
