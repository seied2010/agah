<?php
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Db,Money,Jalali};
if(!defined('ABSPATH'))exit;
$boms=[0=>'انتخاب فرمول فعال'];foreach(Db::rows('SELECT id,code,name,revision FROM '.Db::t('production_boms').' WHERE company_id=%d AND is_active=1 ORDER BY code',$cid) as $r)$boms[$r['id']]=$r['code'].' — '.$r['name'].' / نسخه '.$r['revision'];
$warehouses=[0=>'انتخاب انبار'];foreach(Db::rows('SELECT id,name FROM '.Db::t('warehouses').' WHERE company_id=%d AND is_active=1 ORDER BY code',$cid) as $r)$warehouses[$r['id']]=$r['name'];
?>
<form method="post" class="erp-form"><?php self::form('module_production_plan',$cid,'production_order',$o['id']); ?><input type="hidden" name="revision" value="<?php echo (int)$o['revision']; ?>"><?php self::select('bom_id','فرمول ساخت',$boms,$o['bom_id']);self::input('planned_qty','مقدار محصول برنامه‌ریزی‌شده',Money::qtyToDb((int)$o['planned_qty']),'text','required inputmode="decimal"');self::input('doc_date','تاریخ دستور',Jalali::format($o['doc_date'],false),'text','required data-jalali dir="ltr"');self::select('material_warehouse_id','انبار مواد اولیه',$warehouses,$o['lines'][0]['warehouse_id']??0);self::select('warehouse_id','انبار محصول',$warehouses,$o['warehouse_id']);self::input('description','شرح دستور',$o['description']); ?><button type="submit" class="btn btn-primary">ذخیره برنامه تولید</button></form>
