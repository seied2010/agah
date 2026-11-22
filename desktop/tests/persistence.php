<?php
require '/bundle/app/wp-load.php';
use Agah\Erp\Core\{Ctx,Db,FiscalContext};
use Agah\Erp\Modules\Accounting\VoucherService;
$admin=get_user_by('login','agah');wp_set_current_user($admin->ID);Ctx::boot($admin->ID,1);
if(($argv[1]??'')==='create'){
    $accounts=Db::col('SELECT id FROM '.Db::t('accounts')." WHERE company_id=1 AND level=3 AND detail_rule='none' AND is_active=1 LIMIT 2");
    $id=VoucherService::save(1,0,['voucher_date'=>FiscalContext::today(1),'description'=>'آزمون ماندگاری نسخه محلی'],[['account_id'=>(int)$accounts[0],'debit'=>100000,'credit'=>0],['account_id'=>(int)$accounts[1],'debit'=>0,'credit'=>100000]]);
    file_put_contents('/state/test-voucher-id.txt',(string)$id);echo "PASS draft voucher written to local database\n";
}else{
    $v=VoucherService::get(1,(int)file_get_contents('/state/test-voucher-id.txt'));
    if((int)$v['total_debit']!==100000||count($v['lines'])!==2)throw new RuntimeException('Persistence check failed');
    echo "PASS voucher and amounts preserved after repeated initialization\n";
}
