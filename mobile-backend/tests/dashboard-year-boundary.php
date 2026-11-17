<?php
/** A rolled-back fixture proves old-year entries do not get counted again after carry-forward. */
if(wp_get_environment_type()!=='local') throw new RuntimeException('Local only');
use Agah\Erp\Core\{Ctx,Db};
use Agah\Erp\Modules\Accounting\{FiscalService,VoucherService,AccountService};
use Agah\Erp\Modules\Dashboard\DashboardService;
$u=get_user_by('login','mobiletest');wp_set_current_user($u->ID);
$c=(int)Db::val('SELECT id FROM '.Db::t('companies').' WHERE name=%s','تست مستقل اپ');Ctx::boot($u->ID,$c);
$year=FiscalService::forDate($c,'2026-04-01');$result=[];
try {
    Db::tx(function () use ($c,$year,&$result) {
        $old=FiscalService::forDate($c,'2025-04-01');
        if(!$old) {$id=FiscalService::createYear($c,1404);$old=FiscalService::get($c,$id);}
        $before=DashboardService::summary($c,$year['start_date'])['cards']['موجودی بانک و صندوق'];
        $cash=AccountService::bySysKey($c,'cash')['id'];$capital=AccountService::bySysKey($c,'capital')['id'];
        $detail=(int)Db::val('SELECT detail_id FROM '.Db::t('treasury_accounts').' WHERE company_id=%d AND code=%s',$c,'MOB');
        $id=VoucherService::save($c,0,['voucher_date'=>$old['start_date'],'description'=>'Rolled back prior-year regression'],[['account_id'=>$cash,'detail_id'=>$detail,'debit'=>10000,'credit'=>0],['account_id'=>$capital,'debit'=>0,'credit'=>10000]],0,'rial');
        $v=VoucherService::get($c,$id);VoucherService::transition($c,$id,'approve','local regression',(int)$v['revision']);
        $after=DashboardService::summary($c,$year['start_date'])['cards']['موجودی بانک و صندوق'];
        $result=['before'=>$before,'after'=>$after,'passed'=>$before===$after];
        throw new RuntimeException('rollback-fixture');
    });
} catch(RuntimeException $e) {if($e->getMessage()!=='rollback-fixture') throw $e;}
echo wp_json_encode($result);
if(!$result['passed']) throw new RuntimeException('Prior-year balance was counted in current-year dashboard');
