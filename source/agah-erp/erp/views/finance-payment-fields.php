<?php
/** نمای مراحل۱۰–۱۲؛ داده واقعی از سرویس، فرم با PRG مشترک، خروجی escape و فونت محلی است. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Business,Ctx,Db,Jalali,Money,Request,Settings};
use Agah\Erp\Modules\Expenses\ExpenseService;
use Agah\Erp\Modules\Accounting\{FinancialService,ClosingService,FiscalService};
use Agah\Erp\Modules\Dashboard\DashboardService;
if(!defined('ABSPATH'))exit;
$options=[0=>'بدون تسویه فوری'];foreach(Db::rows('SELECT id,name FROM '.Db::t('treasury_accounts').' WHERE company_id=%d AND is_active=1 ORDER BY code',$cid) as $a)$options[$a['id']]=$a['name'];
self::select('treasury_account_id','بانک/صندوق',$options);self::select('method','روش',['transfer'=>'حواله','cash'=>'نقد','card'=>'کارت','cheque'=>'چک']);self::input('payment_amount','مبلغ تسویه (خالی = بدون تسویه)','','text','inputmode="decimal"');self::input('cheque_no','شماره چک');self::input('cheque_due','سررسید چک شمسی','','text','data-jalali');
