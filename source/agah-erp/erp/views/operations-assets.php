<?php
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Business,Ctx,Db,Jalali,Money,Request};
use Agah\Erp\Modules\Files\FileService;
use Agah\Erp\Modules\Payroll\PayrollService;
use Agah\Erp\Modules\Assets\AssetService;
if(!defined('ABSPATH'))exit;
$data=AssetService::page($cid,Request::int('get','pg',1),Request::str('get','q',100));
?>
<section class="erp-card"><h2>دفتر دارایی‌های ثابت</h2><p>مبالغ <?php echo Ctx::unit()==='rial'?'ریال':'تومان'; ?>؛ استهلاک ماهانه از ماه بهره‌برداری، ماه واگذاری بدون استهلاک. در روش نزولی، مانده استهلاک‌پذیر در آخرین ماه عمر مفید مستهلک می‌شود.</p><?php if(Ctx::can('asset.create')): ?><a class="btn btn-primary" href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'asset_new'])); ?>">ثبت خرید دارایی</a><?php endif; ?><?php OperationsController::exportLink($cid,'assets');self::search($cid,$p); ?><div class="erp-scroll"><table class="erp-table"><thead><tr><?php echo self::sortHead('code','کد').self::sortHead('name','نام'); ?><th>روش</th><th>بهای خرید</th><th>استهلاک انباشته</th><th>ارزش دفتری پیش از واگذاری</th><th>وضعیت</th></tr></thead><tbody><?php foreach($data['rows'] as $a): ?><tr><td><a href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>'asset_view','id'=>$a['id']])); ?>"><?php echo esc_html($a['code']); ?></a></td><td><?php echo esc_html($a['name']); ?></td><td><?php echo esc_html(['straight'=>'خط مستقیم','declining'=>'نزولی','none'=>'بدون استهلاک'][$a['method']]); ?></td><?php foreach(['cost','accum','book_value'] as $k): ?><td><?php echo esc_html(Money::fmt((int)$a[$k],Ctx::unit())); ?></td><?php endforeach; ?><td><?php echo esc_html(['active'=>'فعال','disposed'=>'واگذارشده','void'=>'باطل'][$a['status']]); ?></td></tr><?php endforeach; ?></tbody></table></div><?php self::pages($data['pages'],$data['page']); ?></section>
