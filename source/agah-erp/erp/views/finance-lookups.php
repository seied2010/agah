<?php
/** نمای مراحل۱۰–۱۲؛ داده واقعی از سرویس، فرم با PRG مشترک، خروجی escape و فونت محلی است. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Business,Ctx,Db,Jalali,Money,Request,Settings};
use Agah\Erp\Modules\Expenses\ExpenseService;
use Agah\Erp\Modules\Accounting\{FinancialService,ClosingService,FiscalService};
use Agah\Erp\Modules\Dashboard\DashboardService;
if(!defined('ABSPATH'))exit;
$dimension=$p==='cost_dimensions';$table=$dimension?'cost_dimensions':'expense_categories';$edit=Request::int('get','edit');$r=$edit?Business::row($table,$cid,$edit):[];$rows=Db::rows('SELECT * FROM '.Db::t($table).' WHERE company_id=%d ORDER BY code',$cid);$kinds=$dimension?['project'=>'پروژه','branch'=>'شعبه','center'=>'مرکز هزینه']:['expense'=>'هزینه','income'=>'درآمد'];
?>
<section class="erp-card"><h2><?php echo $dimension?'پروژه، شعبه و مرکز هزینه':'سرفصل هزینه و درآمد'; ?></h2><div class="erp-scroll"><table class="erp-table"><thead><tr><th>کد</th><th>نام</th><th>نوع</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody><?php foreach($rows as $row): ?><tr><td><?php echo esc_html($row['code']); ?></td><td><?php echo esc_html($row['name']); ?></td><td><?php echo esc_html($kinds[$row['kind']]); ?></td><td><?php echo $row['is_active']?'فعال':'غیرفعال'; ?></td><td><?php if(Ctx::can('expense.edit')): ?><a href="<?php echo esc_url(agah_erp_url(['c'=>$cid,'p'=>$p,'edit'=>$row['id']])); ?>">ویرایش</a><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php if(Ctx::can('expense.edit')): ?><section class="erp-card"><form method="post" class="erp-form"><?php self::form('module_expense_lookup',$cid,$p,$edit); ?><input type="hidden" name="entity" value="<?php echo $dimension?'dimension':'category'; ?>"><?php self::input('code','کد',$r['code']??'','text','required');self::input('name','نام',$r['name']??'','text','required');self::select('kind','نوع',$kinds,$r['kind']??array_key_first($kinds));
if(!$dimension){$accounts=[0=>'انتخاب معین متناسب'];foreach(Db::rows('SELECT id,code,name FROM '.Db::t('accounts')." WHERE company_id=%d AND level=3 AND is_active=1 AND stmt_class IN ('revenue','other_income','cogs','opex','other_expense','finance_cost','tax_expense') ORDER BY code",$cid) as $a)$accounts[$a['id']]=$a['code'].' — '.$a['name'];self::select('account_id','حساب دفتر',$accounts,$r['account_id']??0);}
?><label><input type="checkbox" name="is_active" value="1" <?php checked(self::value('is_active',$r['is_active']??1),1); ?>>فعال</label><button class="btn btn-primary" type="submit">ذخیره</button></form></section><?php endif; ?>
