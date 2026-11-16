<?php
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Ctx,Db,Jalali,Money,Request,FiscalContext};
use Agah\Erp\Modules\Manufacturing\ProductionService;
if(!defined('ABSPATH'))exit;
$data=ProductionService::page($cid,Request::int('get','pg',1));
?>
<section class="erp-card"><h2>دستورهای تولید</h2><p>ایجاد دستور موجودی را تغییر نمی‌دهد. «شروع تولید» مواد واقعی را مصرف می‌کند؛ «تکمیل تولید» محصول را با بهای واقعی به انبار می‌آورد. دستورهای در جریان سال‌های پیشین نیز نمایش داده می‌شوند.</p><?php require __DIR__.'/production-order-table.php';self::pages($data['pages'],$data['page']); ?></section>
<?php if(Ctx::can('production.create')): ?>
<section class="erp-card"><h3>دستور تولید جدید</h3><form method="post" class="erp-form"><?php self::form('module_production_plan',$cid,$p);$boms=[0=>'انتخاب فرمول فعال'];foreach(Db::rows('SELECT id,code,name,revision FROM '.Db::t('production_boms').' WHERE company_id=%d AND is_active=1 ORDER BY code',$cid) as $r)$boms[$r['id']]=$r['code'].' — '.$r['name'].' / نسخه '.$r['revision'];$warehouses=[0=>'انتخاب انبار'];foreach(Db::rows('SELECT id,name FROM '.Db::t('warehouses').' WHERE company_id=%d AND is_active=1 ORDER BY code',$cid) as $r)$warehouses[$r['id']]=$r['name'];self::select('bom_id','فرمول ساخت',$boms);self::input('planned_qty','مقدار محصول برنامه‌ریزی‌شده','1','text','required inputmode="decimal"');self::input('doc_date','تاریخ دستور',Jalali::format(FiscalContext::today($cid),false),'text','required data-jalali dir="ltr"');self::select('material_warehouse_id','انبار مواد اولیه',$warehouses);self::select('warehouse_id','انبار محصول',$warehouses);self::input('description','شرح دستور'); ?><button type="submit" class="btn btn-primary">ایجاد دستور تولید</button></form></section><?php endif; ?>
