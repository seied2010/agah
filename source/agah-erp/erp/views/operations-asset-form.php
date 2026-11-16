<?php
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Business,Ctx,Db,Jalali,Money,Request};
use Agah\Erp\Modules\Files\FileService;
use Agah\Erp\Modules\Payroll\PayrollService;
use Agah\Erp\Modules\Assets\AssetService;
if(!defined('ABSPATH'))exit;
?>
<section class="erp-card"><h2>ثبت خرید دارایی</h2><p>خرید همراه با سند حسابداری ثبت می‌شود؛ اگر قبلاً همین خرید را در سند دیگری ثبت کرده‌اید، دوباره ثبت نکنید. حساب مقابل می‌تواند بدهی فروشنده یا بانک باشد؛ این فرم برگه پرداخت خزانه نمی‌سازد.</p><form method="post" class="erp-form"><?php self::form('module_asset_acquire',$cid,$p);self::input('code','کد دارایی','','text','required');self::input('name','نام دارایی','','text','required');foreach(['acquired_date'=>'تاریخ خرید','start_date'=>'تاریخ بهره‌برداری'] as $k=>$l)self::input($k,$l,Jalali::format(\Agah\Erp\Core\FiscalContext::today($cid),false),'text','required');foreach(['cost'=>'بهای خرید','residual'=>'ارزش اسقاط'] as $k=>$l)self::input($k,$l.' ('.(Ctx::unit()==='rial'?'ریال':'تومان').')','','text','required');self::select('method','روش',['straight'=>'خط مستقیم','declining'=>'نزولی','none'=>'بدون استهلاک']);self::input('life_months','عمر مفید (ماه)','','number','required min="1" max="1200"');self::input('annual_percent','نرخ سالانه روش نزولی (درصد)',0,'text','required');
foreach(['asset_account_id'=>['حساب بهای دارایی',['asset_noncurrent']],'contra_account_id'=>['حساب استهلاک انباشته',['contra_asset']],'expense_account_id'=>['حساب هزینه استهلاک',['opex','other_expense']],'account_id'=>['حساب مقابل خرید',['asset_current','liability_current','equity']]] as $k=>$v)self::select($k,$v[0],OperationsController::accounts($cid,$v[1]));self::select('detail_id','تفصیلی حساب مقابل',OperationsController::details($cid)); ?><button type="submit" class="btn btn-primary">ثبت خرید و سند</button></form></section>
