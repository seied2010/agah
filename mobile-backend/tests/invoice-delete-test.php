<?php
/** Disposable integration: permissions, actual postings and all-or-nothing reversal. */
if(wp_get_environment_type()!=='local')throw new RuntimeException('Local only');
ob_start();require __DIR__.'/business-fixture.php';$f=json_decode(ob_get_clean(),true);
use Agah\Erp\Core\{Ctx,Db,Business,ForbiddenException,NotFoundException,ConflictException};
use Agah\Erp\Modules\Company\MemberService;
use Agah\Erp\Modules\Trade\{InvoiceService,SalesControlService};
use Agah\Erp\Modules\Catalog\CatalogService;
use Agah\Erp\Modules\Parties\PartyService;
use Agah\Erp\Modules\Inventory\StockService;
use Agah\Erp\Modules\Treasury\TreasuryService;
use Agah\Erp\Modules\Accounting\VoucherService;
$passed=[];$check=function($label,$ok)use(&$passed){if(!$ok)throw new RuntimeException('FAIL '.$label);$passed[]=$label;};
$denied=function($label,callable $fn,$class=ForbiddenException::class)use($check){try{$fn();}catch(Throwable $e){$check($label,$e instanceof $class);return;}$check($label,false);};
$c=(int)$f['company'];$admin=get_user_by('login','mobiletest');$date=$f['year']['start_date'];$tag=bin2hex(random_bytes(5));
$boot=function($uid)use($c){wp_set_current_user($uid);Ctx::boot($uid,$c);};
$users=[];
foreach(['uxcashier'=>'cashier','uxaccountant'=>'accountant','uxshopmanager'=>'shop_manager','uxviewer'=>'viewer'] as $login=>$role){
 $u=get_user_by('login',$login);$uid=$u?$u->ID:wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>'Disposable-UX-2026-Only','role'=>'subscriber']);if(is_wp_error($uid))throw new RuntimeException('Test user failed');
 $rid=(int)Db::val('SELECT id FROM '.Db::t('roles').' WHERE company_id=%d AND role_key=%s',$c,$role);
 Db::exec('INSERT INTO '.Db::t('company_members')." (company_id,user_id,role_id,status,invoice_delete_override,created_at) VALUES (%d,%d,%d,'active',-1,%s) ON DUPLICATE KEY UPDATE role_id=VALUES(role_id),status='active',invoice_delete_override=-1",$c,$uid,$rid,gmdate('Y-m-d H:i:s'));$users[$role]=(int)$uid;
}
$cashierRole=(int)Db::val('SELECT id FROM '.Db::t('roles')." WHERE company_id=%d AND role_key='cashier'",$c);
$boot($admin->ID);MemberService::setRoleInvoiceDeletion($c,$cashierRole,false);
foreach(['cashier','accountant','viewer'] as $role){$boot($users[$role]);$check('default '.$role.' cannot delete',!Ctx::can('invoice_delete.delete')&&!Ctx::can('sale_invoice.delete'));}
$boot($users['shop_manager']);$check('store manager may delete but cannot delegate',Ctx::can('invoice_delete.delete')&&!Ctx::can('invoice_delete.edit'));
$boot($admin->ID);$check('company admin can delete and delegate',Ctx::can('invoice_delete.delete')&&Ctx::can('invoice_delete.edit'));
MemberService::setRoleInvoiceDeletion($c,$cashierRole,true);$boot($users['cashier']);$check('group delegation takes effect on next request',Ctx::can('invoice_delete.delete'));
$denied('deleter cannot delegate to self',function()use($c,$users){MemberService::setUserInvoiceDeletion($c,$users['cashier'],1);});
$boot($admin->ID);MemberService::setUserInvoiceDeletion($c,$users['cashier'],0);$boot($users['cashier']);$check('individual deny overrides group grant',!Ctx::can('invoice_delete.delete'));
$boot($admin->ID);MemberService::setRoleInvoiceDeletion($c,$cashierRole,false);MemberService::setUserInvoiceDeletion($c,$users['cashier'],1);$boot($users['cashier']);$check('individual delegation works without broad role rights',Ctx::can('invoice_delete.delete')&&!Ctx::can('receipt.delete')&&!Ctx::can('payment.delete')&&!Ctx::can('voucher.void'));
$boot($admin->ID);$denied('foreign membership cannot be delegated',function()use($c){MemberService::setUserInvoiceDeletion($c,99999999,1);},NotFoundException::class);
$foreignRole=(int)Db::val('SELECT id FROM '.Db::t('roles').' WHERE company_id<>%d LIMIT 1',$c);
$denied('foreign group cannot be delegated',function()use($c,$foreignRole){MemberService::setRoleInvoiceDeletion($c,$foreignRole,true);},NotFoundException::class);
$party=PartyService::save($c,0,['name'=>'مشتری آزمون حذف '.$tag,'kind'=>'real','is_customer'=>1,'is_supplier'=>1,'is_active'=>1,'credit_limit'=>0]);
$makeItem=function($goods=false)use($c,$f,$tag){static $n=0;$n++;return CatalogService::save($c,'item',0,['code'=>'UXD'.$tag.$n,'name'=>'قلم آزمون حذف '.$tag.$n,'kind'=>$goods?'goods':'service','unit_id'=>(int)$f['unit'],'sale_account_id'=>(int)$f['sale_account'],'purchase_account_id'=>(int)$f['purchase_account'],'is_active'=>1,'sale_price'=>20000,'purchase_price'=>10000,'trade_price'=>0]);};
$save=function($item,$kind='sale',$goods=false)use($c,$party,$date,$f){return InvoiceService::save($c,0,['kind'=>$kind,'party_id'=>$party,'invoice_date'=>$date,'due_date'=>$date,'discount_amount'=>0,'extra_cost'=>0,'cost_basis'=>'value'],[['item_id'=>$item,'warehouse_id'=>$goods?(int)$f['warehouse']:0,'qty_scaled'=>20000,'unit_price'=>20000,'discount_amount'=>0]]);};
$record=function($id)use($c){return Business::row('invoices',$c,$id);};
$balances=function()use($c){$out=[];foreach(Db::rows('SELECT l.account_id,l.detail_id,SUM(l.debit)-SUM(l.credit) AS balance FROM '.Db::t('voucher_lines').' l JOIN '.Db::t('vouchers')." v ON v.company_id=l.company_id AND v.id=l.voucher_id WHERE l.company_id=%d AND v.status IN ('approved','final') GROUP BY l.account_id,l.detail_id",$c) as $r)if((int)$r['balance'])$out[$r['account_id'].':'.$r['detail_id']]=(int)$r['balance'];ksort($out);return $out;};
$goods=$makeItem(true);StockService::issue($c,['kind'=>'receipt','doc_date'=>$date],[['warehouse_id'=>(int)$f['warehouse'],'item_id'=>$goods,'qty_scaled'=>100000,'cost'=>100000]]);
$beforeStock=StockService::balanceInternal($c,(int)$f['warehouse'],$goods);$beforeLedger=$balances();
$inv=$save($goods,'sale',true);InvoiceService::issue($c,$inv,(int)$record($inv)['revision'],[['method'=>'cash','treasury_account_id'=>(int)$f['cash'],'amount'=>15000],['method'=>'card','treasury_account_id'=>(int)$f['bank'],'amount'=>25000]]);
$issued=$record($inv);$share=SalesControlService::createShare($c,$inv,72);$check('sale created real stock and ledger effects',$balances()!==$beforeLedger&&StockService::balanceInternal($c,(int)$f['warehouse'],$goods)['qty']===80000);
$boot($users['viewer']);$denied('unauthorized service deletion rejected',function()use($c,$inv,$issued){InvoiceService::deleteDocument($c,$inv,(int)$issued['revision'],'حذف بدون دسترسی');});
$boot($users['cashier']);$denied('stale revision rejected before reversal',function()use($c,$inv){InvoiceService::deleteDocument($c,$inv,0,'نسخه قدیمی آزمون');},ConflictException::class);
InvoiceService::deleteDocument($c,$inv,(int)$issued['revision'],'حذف آزمون با تفویض فردی');
$after=$record($inv);$check('delegated cashier deletes issued invoice',!empty($after['deleted_at'])&&$after['status']==='void');
$check('dependent reversal authority expires',!InvoiceService::allowsDependentReversal($c,'invoice',$inv));
$boot($admin->ID);$check('all account and party balances restored exactly',$balances()===$beforeLedger);$check('stock quantity and valuation restored exactly',StockService::balanceInternal($c,(int)$f['warehouse'],$goods)===$beforeStock);
$check('no active settlements remain',!(int)Db::val('SELECT COUNT(*) FROM '.Db::t('settlements')." WHERE company_id=%d AND invoice_id=%d AND status='active'",$c,$inv));
$check('linked treasury and accounting vouchers void',!(int)Db::val('SELECT COUNT(*) FROM '.Db::t('settlements').' s JOIN '.Db::t('treasury_docs')." d ON d.company_id=s.company_id AND d.id=s.doc_id JOIN ".Db::t('vouchers')." v ON v.company_id=d.company_id AND v.id=d.voucher_id WHERE s.company_id=%d AND s.invoice_id=%d AND (d.status<>'void' OR v.status<>'void')",$c,$inv));
$check('share links revoked',!(int)Db::val('SELECT COUNT(*) FROM '.Db::t('share_links').' WHERE company_id=%d AND invoice_id=%d AND revoked_at IS NULL',$c,$inv));
$check('audit and original lines retained',(int)Db::val('SELECT COUNT(*) FROM '.Db::t('audit_log')." WHERE company_id=%d AND event='invoice.delete' AND entity_id=%d",$c,$inv)===1 && (int)Db::val('SELECT COUNT(*) FROM '.Db::t('invoice_lines').' WHERE company_id=%d AND invoice_id=%d',$c,$inv)>0);
$normal=InvoiceService::page($c,'sale');$archive=InvoiceService::page($c,'sale',['status'=>'deleted']);$check('removed from current list, available in archive',!in_array((string)$inv,array_column($normal['rows'],'id'),true)&&in_array((string)$inv,array_column($archive['rows'],'id'),true));
$denied('duplicate delete rejected without extra reversal',function()use($c,$inv,$after){InvoiceService::deleteDocument($c,$inv,(int)$after['revision'],'تکرار حذف آزمون');},ConflictException::class);
$service=$makeItem();$draft=$save($service);InvoiceService::deleteDocument($c,$draft,(int)$record($draft)['revision'],'حذف پیش نویس آزمایشی');$check('draft deletion leaves no financial posting',!empty($record($draft)['deleted_at'])&&!$record($draft)['voucher_id']);

// Force failure after treasury reversal has begun: the later stock movement blocks the whole transaction.
$other=$makeItem(true);StockService::issue($c,['kind'=>'receipt','doc_date'=>$date],[['warehouse_id'=>(int)$f['warehouse'],'item_id'=>$other,'qty_scaled'=>100000,'cost'=>100000]]);
$blocked=$save($other,'sale',true);InvoiceService::issue($c,$blocked,(int)$record($blocked)['revision'],[['method'=>'cash','treasury_account_id'=>(int)$f['cash'],'amount'=>40000]]);
StockService::issue($c,['kind'=>'receipt','doc_date'=>$date],[['warehouse_id'=>(int)$f['warehouse'],'item_id'=>$other,'qty_scaled'=>10000,'cost'=>10000]]);
$beforeBlocked=$record($blocked);$blockedLedger=$balances();$blockedStock=StockService::balanceInternal($c,(int)$f['warehouse'],$other);
$boot($users['cashier']);$denied('dependent stock movement blocks deletion',function()use($c,$blocked,$beforeBlocked){InvoiceService::deleteDocument($c,$blocked,(int)$beforeBlocked['revision'],'آزمون برگشت تراکنش');},ConflictException::class);
$check('failed reversal scope cleared',!InvoiceService::allowsDependentReversal($c,'invoice',$blocked));
$boot($admin->ID);$check('failure restores invoice unchanged',$record($blocked)===$beforeBlocked);$check('failure restores all ledger and stock changes',$balances()===$blockedLedger&&StockService::balanceInternal($c,(int)$f['warehouse'],$other)===$blockedStock);
$check('failure restores active settlement and receipt',(int)Db::val('SELECT COUNT(*) FROM '.Db::t('settlements').' s JOIN '.Db::t('treasury_docs')." d ON d.company_id=s.company_id AND d.id=s.doc_id WHERE s.company_id=%d AND s.invoice_id=%d AND s.status='active' AND d.status='issued'",$c,$blocked)===1);

$a=$save($service);$b=$save($service);foreach([$a,$b] as $id)InvoiceService::issue($c,$id,(int)$record($id)['revision']);
TreasuryService::issue($c,['kind'=>'receipt','party_id'=>$party,'doc_date'=>$date],[['method'=>'cash','treasury_account_id'=>(int)$f['cash'],'amount'=>80000]],[['invoice_id'=>$a,'amount'=>40000],['invoice_id'=>$b,'amount'=>40000]]);
$sharedBefore=$record($a);$denied('shared payment blocks deletion',function()use($c,$a,$sharedBefore){InvoiceService::deleteDocument($c,$a,(int)$sharedBefore['revision'],'حذف با تسویه مشترک');},ConflictException::class);$check('shared payment failure keeps original invoice',$record($a)===$sharedBefore);
$partial=$save($service);InvoiceService::issue($c,$partial,(int)$record($partial)['revision']);TreasuryService::issue($c,['kind'=>'receipt','party_id'=>$party,'doc_date'=>$date],[['method'=>'cash','treasury_account_id'=>(int)$f['cash'],'amount'=>50000]],[['invoice_id'=>$partial,'amount'=>40000]]);
$partialBefore=$record($partial);$denied('unallocated receipt balance is protected',function()use($c,$partial,$partialBefore){InvoiceService::deleteDocument($c,$partial,(int)$partialBefore['revision'],'حذف با مانده تخصیص نیافته');},ConflictException::class);$check('partial allocation failure keeps invoice unchanged',$record($partial)===$partialBefore);
$cheque=$save($service);$beforeCheque=$balances();InvoiceService::issue($c,$cheque,(int)$record($cheque)['revision'],[['method'=>'cheque','treasury_account_id'=>(int)$f['bank'],'amount'=>40000,'due_date'=>$date,'cheque_no'=>'UX-'.$tag,'bank_name'=>'بانک آزمون']]);
$boot($users['cashier']);InvoiceService::deleteDocument($c,$cheque,(int)$record($cheque)['revision'],'حذف فاکتور دارای چک');$boot($admin->ID);
$check('cheque receipt and notes ledger reversed',$balances()===$beforeCheque && !(int)Db::val('SELECT COUNT(*) FROM '.Db::t('cheques').' ch JOIN '.Db::t('settlements')." s ON s.company_id=ch.company_id AND s.doc_id=ch.doc_id WHERE s.company_id=%d AND s.invoice_id=%d AND ch.status<>'void'",$c,$cheque));
MemberService::setUserInvoiceDeletion($c,$users['cashier'],0);$boot($users['cashier']);$denied('revocation effective immediately',function()use($c,$b,$record){InvoiceService::deleteDocument($c,$b,(int)$record($b)['revision'],'آزمون لغو دسترسی');});
$denied('revoked user cannot view deleted archive',function()use($c){InvoiceService::page($c,'sale',['status'=>'deleted']);});
$boot($admin->ID);MemberService::setUserInvoiceDeletion($c,$users['cashier'],-1);
echo wp_json_encode(['count'=>count($passed),'passed'=>$passed,'company'=>$c,'draft'=>$draft,'issued_deleted'=>$inv,'blocked'=>$blocked,'active'=>$b,'cashier'=>$users['cashier']]);
