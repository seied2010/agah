<?php
if(wp_get_environment_type()!=='local')throw new RuntimeException('Local only');
use Agah\Erp\Core\{Ctx,Db};
use Agah\Erp\Modules\Accounting\AccountService;
use Agah\Erp\Modules\Parties\PartyService;
use Agah\Erp\Modules\Expenses\ExpenseService;
use Agah\Erp\Modules\Files\FileService;
$im=imagecreatetruecolor(2,2);ob_start();imagepng($im);$png=ob_get_clean();$out=[];
foreach(['main'=>['mobiletest','تست مستقل اپ'],'foreign'=>['othermobile','شرکت بیگانه']] as $key=>$which) {
    $u=get_user_by('login',$which[0]);wp_set_current_user($u->ID);$c=(int)Db::val('SELECT id FROM '.Db::t('companies').' WHERE name=%s',$which[1]);Ctx::boot($u->ID,$c);
    $party=PartyService::save($c,0,['name'=>'Receipt image fixture '.wp_generate_uuid4(),'kind'=>'real','is_supplier'=>1,'is_customer'=>1,'is_active'=>1]);
    $category=ExpenseService::saveLookup($c,'category',0,['name'=>'Receipt image category','code'=>'IMG'.substr(wp_generate_uuid4(),0,8),'kind'=>'expense','account_id'=>AccountService::bySysKey($c,'other_expense')['id'],'is_active'=>1]);
    $date=Db::val('SELECT start_date FROM '.Db::t('fiscal_years').' WHERE company_id=%d ORDER BY id LIMIT 1',$c);
    $expense=ExpenseService::issue($c,['category_id'=>$category,'party_id'=>$party,'doc_date'=>$date,'due_date'=>$date,'amount'=>10000,'description'=>'Legacy receipt image fixture']);
    $image=ExpenseService::attach($c,$expense,$png);$file=FileService::attach($c,'expense',$expense,'general.png',$png);
    $out[$key]=['company'=>(string)$c,'expense'=>(string)$expense,'image'=>(string)$image,'file'=>(string)$file];
}
$out['sha256']=hash('sha256',$png);echo wp_json_encode($out);
