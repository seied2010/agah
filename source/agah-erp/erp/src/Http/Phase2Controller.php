<?php
/** صفحه‌ها و عمل‌های فاز دوم در زنجیرهٔ کنترلر مشترک (PRG، nonce و توکن). هر بسته مسیر خودش را این‌جا اضافه می‌کند. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Ctx,Jalali,Request,Tabular,ValidationException};
use Agah\Erp\Modules\Accounting\RatioService;
use Agah\Erp\Modules\Company\{CompanyLifecycleService,CompanyPolicyService};
use Agah\Erp\Modules\Import\{BankMatchService,ImportService};
if(!defined('ABSPATH'))exit;
final class Phase2Controller {
    private static $upload=null;
    public static function nav(){return ['help'=>['راهنمای سامانه','dashboard.view'],'financial_ratios'=>['نسبت‌های مالی','report.view'],'import_vouchers'=>['ورود سند از Excel','voucher.create'],'import_bank'=>['صورتحساب بانک و ثبت هوشمند','treasury_account.edit'],'asset_custody'=>['تحویل و انتقال اموال','asset.view'],'hr_org'=>['چارت سازمانی و پرسنل','payroll.view'],'payroll_setup'=>['اقلام و تعرفه‌های حقوق','payroll.view'],'payroll_runs'=>['لیست حقوق دومرحله‌ای','payroll.view'],'referrals'=>['کارتابل ارجاع','dashboard.view'],'sales_controls'=>['سقف تخفیف و تغییر قیمت','role.edit'],'subscription'=>['اشتراک آگاه','company_settings.view'],'notifications'=>['مرکز اعلان‌ها','dashboard.view'],'import_migrate'=>['ورود از نرم‌افزار دیگر','voucher.create'],'moadian'=>['سامانهٔ مؤدیان','moadian.view'],'moadian_items'=>['شناسهٔ رسمی کالا/خدمت','moadian.view'],'integrations'=>['وضعیت اتصال‌های رسمی','company_settings.view'],'backups'=>['پشتیبان‌گیری','backup.view']];}
    /** صفحهٔ عمومی پیوند اشتراک برگه (بدون ورود): فقط همان برگه تا پیش از انقضا یا لغو. */
    public static function publicShare(){
        if(!isset($_GET['agah_share'])&&!isset($_GET['ag']))return;
        $share=$_GET['agah_share']??$_GET['ag'];
        nocache_headers();header('X-Robots-Tag: noindex, nofollow');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");header('Referrer-Policy: no-referrer');
        $ok=\Agah\Erp\Security\RateLimiter::consume('share|'.\Agah\Erp\Core\Request::ip(),60,600);
        $d=$ok?\Agah\Erp\Modules\Trade\SalesControlService::resolve(is_string($share)?wp_unslash($share):''):null;
        status_header($d?200:404);header('Content-Type: text/html; charset=UTF-8');
        require AGAH_ERP_DIR.'/views/share-invoice.php';exit;
    }
    /** صفحه‌های بدون پیوند منو (مثل رسید) با مجوز خودشان. */
    public static function cap($p){return self::nav()[$p][1]??(['asset_receipt'=>'asset.view','hr_profile'=>'payroll.view','nav_group'=>'dashboard.view'][$p]??null);}
    /** «CODE=مبلغ» در هر خط، مبلغ به واحد نمایش. */
    public static function itemsInput($text){$out=[];foreach(preg_split('/\r\n|\r|\n/',(string)$text) as $line){$line=trim($line);if($line==='')continue;if(!preg_match('/^([A-Za-z0-9_]{1,30})\s*=\s*(.+)$/u',$line,$m))throw new ValidationException(['items'=>'هر خط به شکل CODE=مبلغ باشد.']);$out[strtoupper($m[1])]=\Agah\Erp\Core\Money::fromInput(trim($m[2]),Ctx::unit());}return $out;}
    /** پارامترهای تعرفه از فرم (همان فیلدهای پارامتر سالانه). */
    private static function tariffInput(){$m=fn($k)=>\Agah\Erp\Core\Money::fromInput(Request::str('post',$k,40),Ctx::unit());$p=[];foreach(['employee_bp','employer_bp'] as $k)$p[$k]=\Agah\Erp\Core\Money::pctBp(Request::str('post',$k,10));$o=\Agah\Erp\Core\Money::normDigits(Request::str('post','overtime_percent',10));if(!preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/D',$o,$mm))throw new ValidationException(['overtime_percent'=>'ضریب اضافه‌کار معتبر نیست.']);$p['overtime_bp']=(int)$mm[1]*100+(int)str_pad($mm[2]??'',2,'0');$p['insurance_tax_numerator']=Request::int('post','insurance_tax_numerator',1);$p['insurance_tax_denominator']=Request::int('post','insurance_tax_denominator',1);$p['monthly_minutes']=Request::int('post','monthly_minutes');foreach(['insurance_ceiling','tax_exemption'] as $k)$p[$k]=$m($k);$p['brackets']=[];for($i=0;$i<8;$i++){if(Request::str('post','rate_'.$i,10)==='')continue;$u=Request::str('post','upper_'.$i,40);$p['brackets'][]=['upper'=>$u===''?null:$m('upper_'.$i),'rate_bp'=>\Agah\Erp\Core\Money::pctBp(Request::str('post','rate_'.$i,10))];}return $p;}
    /** خروجی‌های حقوق: فایل بانک (پرداخت‌کننده) و فهرست آزمایشی بیمه/مالیات (اطلاعات حساس). مبالغ به ریال. */
    public static function payrollExport($cid){
        $type=Request::str('get','payroll_export',10);$run=Request::int('get','run');if(!in_array($type,['bank','tax','insurance'],true))throw new ValidationException(['export'=>'نوع خروجی معتبر نیست.']);
        if(!wp_verify_nonce(Request::str('get','_n',100),'agah_payroll_export_'.$cid.'_'.$run.'_'.$type))throw new ValidationException(['export'=>'اعتبار خروجی منقضی است.']);
        Ctx::boot(get_current_user_id(),$cid);Tabular::$unit='rial';$label='آزمایشی — قالب رسمی پس از دریافت نمونه';
        if($type==='bank'){\Agah\Erp\Core\Access::need($cid,'payroll.pay');$b=\Agah\Erp\Modules\Payroll\PayrollRunService::bankCheck($cid,$run);if($b['errors'])throw new ValidationException(['bank'=>implode(' ',$b['errors'])]);$rows=[];foreach($b['rows'] as $r)$rows[]=[$r['iban'],Tabular::cell('amount',$r['amount']),$r['name'],$r['code']];Tabular::download('agah-payroll-bank-'.$run,['شبا','مبلغ (ریال)','نام','کد پرسنلی — '.$label],$rows,null,'bank');return;}
        $d=\Agah\Erp\Modules\Payroll\PayrollRunService::officialRows($cid,$run,$type,Request::int('get','version'));$rows=[];
        foreach($d['rows'] as $r){foreach($r as $i=>$v)if(is_int($v)&&$i>=($type==='insurance'?5:2)&&!($type==='tax'&&$i===5))$r[$i]=Tabular::cell('amount',$v);$rows[]=$r;}
        $h=$type==='insurance'?['کد کارگاه','شمارهٔ بیمه','کد ملی','نام','روز','دستمزد مشمول (ریال)','سهم بیمه‌شده (ریال)','سهم کارفرما (ریال) — '.$label]:['کد ملی','نام','ناخالص (ریال)','درآمد مشمول (ریال)','مالیات (ریال)','منبع مالیات — '.$label];
        Tabular::download('agah-payroll-'.$type.'-'.$run.'-v'.$d['version'],$h,$rows,null,$type);
    }
    public static function actions(){
        $v='voucher.create';$t='treasury_account.edit';
        return ['module_share_sms'=>'dashboard.view','module_backup_restore'=>'backup.export','module_ratio_configure'=>'company_settings.edit','module_ratio_snapshot'=>'report.export','module_ratio_snapshot_delete'=>'report.export',
        'module_company_policy'=>'company_settings.edit','module_company_suspend'=>'company_settings.delete','module_company_delete_empty'=>'company_settings.delete',
        'module_company_purge_request'=>'company_settings.delete','module_company_purge_cancel'=>'company_settings.delete','module_company_purge_confirm'=>'company_settings.delete',
        'module_import_voucher_upload'=>$v,'module_import_voucher_remap'=>$v,'module_import_voucher_refresh'=>$v,'module_import_voucher_post'=>$v,'module_import_voucher_cancel'=>$v,'module_import_voucher_delete'=>$v,
        'module_import_bank_upload'=>$t,'module_import_bank_remap'=>$t,'module_import_bank_refresh'=>$t,'module_import_bank_cancel'=>$t,'module_import_bank_delete'=>$t,
        'module_bank_confirm'=>$t,'module_bank_unmatch'=>$t,'module_bank_ignore'=>$t,'module_bank_restore'=>$t,'module_bank_post'=>$t,'module_bank_invoice'=>$t,
        'module_rule_save'=>$t,'module_rule_deactivate'=>$t,'module_rule_delete'=>$t,'module_memory_forget'=>$t,
        'module_custody_move'=>'asset.edit','module_custody_undo'=>'asset.delete','module_asset_category_save'=>'asset.edit','module_asset_category_delete'=>'asset.edit','module_asset_set_category'=>'asset.edit','module_asset_place_save'=>'asset.edit','module_asset_place_delete'=>'asset.edit',
        'module_hr_profile'=>'payroll.edit','module_hr_reveal'=>'payroll.sensitive','module_hr_unit_save'=>'payroll.edit','module_hr_unit_delete'=>'payroll.edit','module_hr_post_save'=>'payroll.edit','module_hr_post_delete'=>'payroll.edit','module_hr_assign'=>'payroll.edit',
        'module_payitem_seed'=>'payroll.edit','module_payitem_version'=>'payroll.edit','module_payitem_delete'=>'payroll.edit','module_tariff_save'=>'payroll.edit','module_payroll_workshop'=>'payroll.edit','module_contract_amend'=>'payroll.edit',
        'module_run_create'=>'payroll.edit','module_run_recalc'=>'payroll.edit','module_run_send'=>'payroll.approve','module_run_adjust'=>'payroll.approve','module_run_finalize'=>'payroll.approve','module_run_pay'=>'payroll.pay','module_run_reverse'=>'payroll.delete',
        'module_price_policy'=>'role.edit','module_price_seen'=>'sale_invoice.approve','module_referral_create'=>'dashboard.view','module_referral_act'=>'dashboard.view','module_share_create'=>'dashboard.view','module_share_revoke'=>'dashboard.view','module_shortcut_toggle'=>'dashboard.view','module_sub_order'=>'company_settings.edit','module_notify_read'=>'dashboard.view',
        'module_migrate_upload'=>'dashboard.view','module_migrate_remap'=>'dashboard.view','module_migrate_refresh'=>'dashboard.view','module_migrate_post'=>'dashboard.view','module_migrate_cancel'=>'dashboard.view','module_migrate_delete'=>'dashboard.view','module_migrate_forget'=>'account.edit',
        'module_moadian_policy'=>'moadian.edit','module_moadian_policy_delete'=>'moadian.edit','module_moadian_item_code'=>'moadian.edit','module_moadian_select'=>'moadian.edit','module_moadian_revalidate'=>'moadian.edit','module_moadian_remove'=>'moadian.edit',
        'module_moadian_send'=>'moadian.approve','module_moadian_poll'=>'moadian.approve','module_moadian_amend'=>'moadian.approve','module_moadian_manual'=>'moadian.approve'];
    }
    /** میانبرهای شخصی کاربر جاری در این شرکت (فقط صفحه‌هایی که هنوز مجوزشان را دارد). */
    public static function shortcuts($cid){$nav=ModuleController::nav();$out=[];foreach(\Agah\Erp\Core\Db::col('SELECT page_key FROM '.\Agah\Erp\Core\Db::t('user_shortcuts').' WHERE company_id=%d AND user_id=%d ORDER BY sort,id',$cid,Ctx::uid()) as $k)if(isset($nav[$k])&&Ctx::can($nav[$k][1]))$out[$k]=$nav[$k][0];return $out;}
    public static function view($p){return ['help'=>'help','financial_ratios'=>'finance-ratios','import_vouchers'=>'import-vouchers','import_bank'=>'import-bank','asset_custody'=>'asset-custody','asset_receipt'=>'asset-receipt','hr_org'=>'hr-org','hr_profile'=>'hr-profile','payroll_setup'=>'payroll-setup','payroll_runs'=>'payroll-runs','referrals'=>'trade-referrals','sales_controls'=>'trade-controls','nav_group'=>'nav-group','subscription'=>'subscription','notifications'=>'notifications','import_migrate'=>'import-migrate','moadian'=>'moadian','moadian_items'=>'moadian-items','integrations'=>'integrations','backups'=>'backup-center'][$p]??null;}

    /** دریافت پشتیبان رمزگذاری‌شده؛ این مسیر پیش از PRG اجرا می‌شود و هیچ گذرواژه‌ای را نگه نمی‌دارد. */
    public static function backupDownload($cid){
        $act='module_backup_download';
        if(!wp_verify_nonce(Request::str('post','_n',100),'agah_erp_'.$act))throw new ValidationException(['form'=>'نشست فرم منقضی شده است؛ صفحه را تازه کنید.']);
        Ctx::boot(get_current_user_id(),$cid);Ctx::need('backup.export');
        $scope=Request::str('post','backup_scope',10,'company');
        if(!in_array($scope,['company','all'],true))throw new ValidationException(['scope'=>'دامنهٔ پشتیبان معتبر نیست.']);
        if($scope==='all'&&!current_user_can('manage_options'))throw new \Agah\Erp\Core\ForbiddenException('پشتیبان همهٔ شرکت‌ها فقط برای مدیر سامانه است.');
        if(!\Agah\Erp\Core\Crypto::hasConfiguredKey())throw new ValidationException(['backup'=>'ابتدا کلید پایدار رمزنگاری را در مدیریت حسابداری فعال کنید.']);
        if(!\Agah\Erp\Security\RateLimiter::consume('portable-backup|'.get_current_user_id(),5,3600))throw new \Agah\Erp\Core\ConflictException('حداکثر پنج فایل پشتیبان در ساعت مجاز است.');
        $raw=function($name){return isset($_POST[$name])&&is_string($_POST[$name])?wp_unslash($_POST[$name]):'';};
        $password=$raw('backup_password');
        if(!hash_equals($password,$raw('backup_password_confirm')))throw new ValidationException(['backup_password_confirm'=>'تکرار گذرواژهٔ فایل یکسان نیست.']);
        $user=get_userdata(get_current_user_id());$current=$raw('confirm_password');
        if(!$user||$current===''||!wp_check_password($current,$user->user_pass,$user->ID))throw new ValidationException(['confirm_password'=>'گذرواژهٔ حساب کاربری درست نیست.']);
        $file=\Agah\Erp\Modules\Maintenance\PortableBackupService::create($scope,$cid,$password);
        nocache_headers();header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="'.sanitize_file_name($file['name']).'"');header('Content-Length: '.strlen($file['bytes']));header('X-Content-Type-Options: nosniff');header('X-Agah-Backup-SHA256: '.$file['sha256']);header('Referrer-Policy: no-referrer');
        echo $file['bytes'];
    }
    /** خروجی جامع و قابل‌خواندن شرکت؛ برای مطالعه است و جای فایل بازیابی رمزگذاری‌شده را نمی‌گیرد. */
    public static function companyDataExport($cid){
        if(!wp_verify_nonce(Request::str('get','_n',100),'agah_company_data_export_'.$cid))throw new ValidationException(['export'=>'اعتبار پیوند منقضی شده است؛ صفحه را تازه کنید.']);
        Ctx::boot(get_current_user_id(),$cid);Ctx::need('backup.export');
        $format=Request::str('get','format',10,'xlsx');if(!in_array($format,['xlsx','csv'],true))throw new ValidationException(['format'=>'قالب خروجی معتبر نیست.']);
        $rows=\Agah\Erp\Modules\Maintenance\PortableBackupService::readableRows($cid);
        Tabular::download('agah-company-'.$cid.'-readable-data',['بخش','نام فنی جدول','شناسه رکورد','اطلاعات خوانا (JSON)'],$rows,$format,'اطلاعات شرکت');
    }
    /** فایل پشتیبان بارگذاری‌شده برای بازیابی؛ فقط یک بار خوانده و در حافظهٔ همین درخواست نگه‌داری می‌شود. */
    public static function backupUpload(){
        if(self::$upload!==null)return self::$upload;
        if(!\Agah\Erp\Security\RateLimiter::consume('portable-restore-upload|'.Ctx::uid().'|'.Ctx::cid(),5,3600))throw new ValidationException(['backup_file'=>'سقف پنج تلاش بازیابی در ساعت پر شده است.']);
        $f=$_FILES['backup_file']??null;$max=\Agah\Erp\Modules\Maintenance\PortableBackupService::maxBytes();
        if(!is_array($f)||($f['error']??1)!==UPLOAD_ERR_OK||!is_string($f['tmp_name']??null)||!is_uploaded_file($f['tmp_name'])||($f['size']??0)<1||($f['size']??0)>$max)throw new ValidationException(['backup_file'=>'فایل پشتیبان معتبر و در محدودهٔ حجم مجاز انتخاب کنید.']);
        $name=is_string($f['name']??null)?wp_unslash($f['name']):'';if(substr(strtolower($name),-(strlen(\Agah\Erp\Modules\Maintenance\PortableBackupService::EXT)+1))!==('.'.\Agah\Erp\Modules\Maintenance\PortableBackupService::EXT))throw new ValidationException(['backup_file'=>'پسوند فایل باید .'.\Agah\Erp\Modules\Maintenance\PortableBackupService::EXT.' باشد.']);
        $bytes=file_get_contents($f['tmp_name'],false,null,0,$max+1);if(!is_string($bytes)||strlen($bytes)!==(int)$f['size'])throw new ValidationException(['backup_file'=>'خواندن فایل پشتیبان کامل نشد.']);
        return self::$upload=['name'=>$name,'bytes'=>$bytes];
    }
    /** خروجی Excel/CSV گزارش‌های اموال (AST-05). */
    public static function custodyExport($cid){
        $type=Request::str('get','custody_export',12);if(!in_array($type,['holdings','unassigned','history','clearance'],true))throw new ValidationException(['export'=>'نوع خروجی معتبر نیست.']);
        if(!wp_verify_nonce(Request::str('get','_n',100),'agah_custody_export_'.$cid.'_'.$type))throw new ValidationException(['export'=>'اعتبار خروجی منقضی است.']);
        Ctx::boot(get_current_user_id(),$cid);\Agah\Erp\Core\Access::need($cid,'asset.export');$fa=fn($d)=>$d?Jalali::format($d,false):'';
        $H=\Agah\Erp\Modules\Assets\AssetCustodyService::HOLDERS;$E=\Agah\Erp\Modules\Assets\AssetCustodyService::END_KINDS;
        if($type==='unassigned'){$rows=[];foreach(\Agah\Erp\Modules\Assets\AssetCustodyService::unassigned($cid) as $r)$rows[]=[$r['code'],$r['name'],(string)$r['category_name'],$fa($r['acquired_date']),Tabular::cell('cost',$r['cost']),$fa($r['last_return'])];Tabular::download('agah-assets-unassigned',['کد','نام','دسته','تاریخ خرید','بها','آخرین بازپس‌گیری'],$rows);return;}
        if($type==='history')$data=\Agah\Erp\Modules\Assets\AssetCustodyService::history($cid,self::custodyFilters(),1)['rows'];
        elseif($type==='clearance')$data=\Agah\Erp\Modules\Assets\AssetCustodyService::clearance($cid,Request::int('get','employee_id'))['history'];
        else $data=\Agah\Erp\Modules\Assets\AssetCustodyService::holdings($cid,Request::str('get','holder_type',10),Request::int('get','holder_id'));
        $rows=[];foreach($data as $r)$rows[]=[$r['asset_code'],$r['asset_name'],(string)$r['category_name'],$H[$r['holder_type']]??'',$r['holder_name'],$fa($r['effective_date']),$fa($r['ended_date']),$E[$r['end_kind']]??($r['status']==='void'?'باطل':''),(string)$r['receipt_no'],$r['delivered_by'],$r['received_by'],Tabular::cell('book_value',$r['book_value'])];
        Tabular::download('agah-assets-'.$type,['کد دارایی','نام','دسته','نوع متصدی','متصدی','از تاریخ','تا تاریخ','پایان','شمارهٔ رسید','تحویل‌دهنده','تحویل‌گیرنده','ارزش دفتری'],$rows);
    }
    public static function custodyFilters(){$f=['asset_id'=>Request::int('get','asset_id'),'holder_type'=>Request::str('get','holder_type',10),'holder_id'=>Request::int('get','holder_id'),'q'=>Request::str('get','q',100)];foreach(['from','to'] as $k){$v=Request::str('get',$k,10);$f[$k]=$v?Jalali::parse($v):'';}return $f;}
    /** فهرست فیلدهای انتخاب‌شده برای هر نوع از فرم: required[legal][]=field */
    public static function policyInput(){
        $out=['legal'=>[],'real'=>[]];$raw=$_POST['required']??[];if(!is_array($raw))return $out;
        foreach(['legal','real'] as $kind)foreach((array)($raw[$kind]??[]) as $field)if(is_string($field))$out[$kind][]=sanitize_key(wp_unslash($field));
        return $out;
    }
    /** فایل Excel بارگذاری‌شده؛ یک بار خوانده می‌شود تا اثر انگشتش در کلید تکرارناپذیر فرم هم بیاید. */
    public static function upload(){
        if(self::$upload!==null)return self::$upload;
        if(!\Agah\Erp\Security\RateLimiter::consume('import-upload|'.Ctx::uid().'|'.Ctx::cid(),30,3600))throw new ValidationException(['file'=>'سقف ۳۰ بارگذاری در ساعت پر شده است.']);
        $f=$_FILES['file']??null;
        if(!is_array($f)||($f['error']??1)!==UPLOAD_ERR_OK||!is_string($f['tmp_name']??null)||!is_uploaded_file($f['tmp_name'])||($f['size']??0)>\Agah\Erp\Core\XlsxReader::MAX_FILE)throw new ValidationException(['file'=>'فایل معتبر (.xlsx یا .csv) حداکثر ۵ مگابایت انتخاب کنید.']);
        return self::$upload=['name'=>is_string($f['name']??null)?wp_unslash($f['name']):'','bytes'=>(string)file_get_contents($f['tmp_name'],false,null,0,\Agah\Erp\Core\XlsxReader::MAX_FILE+1)];
    }
    /** فهرست مقادیر متنی یک فیلد آرایه‌ای فرم (مثل ids[] یا kinds[]). */
    private static function strings($name){$raw=$_POST[$name]??[];$out=[];if(is_array($raw))foreach($raw as $v)if(is_string($v))$out[]=sanitize_text_field(wp_unslash($v));return array_slice($out,0,500);}
    /** قالب خام ورود از نرم‌افزار دیگر برای هر نوع. */
    public static function migrateTemplate($cid){
        $kind=Request::str('get','migrate_template',10);
        if(!wp_verify_nonce(Request::str('get','_n',100),'agah_migrate_template_'.$cid.'_'.$kind))throw new ValidationException(['form'=>'پیوند منقضی شده است؛ صفحه را تازه کنید.']);
        Ctx::boot(get_current_user_id(),$cid);$t=\Agah\Erp\Modules\Import\MigrationService::template($cid,$kind);Tabular::download($t['name'],$t['headers'],$t['rows'],'xlsx',$t['name']);
    }
    private static function mapping(){$raw=$_POST['map']??[];$out=[];if(is_array($raw))foreach($raw as $k=>$v)if(is_string($k)&&is_scalar($v))$out[sanitize_key($k)]=(string)$v===''?'':(int)$v;return $out;}
    /** قالب خام و نسخه‌دار ورود سند. */
    public static function template($cid){
        if(!wp_verify_nonce(Request::str('get','_n',100),'agah_import_template_'.$cid))throw new ValidationException(['form'=>'پیوند منقضی شده است؛ صفحه را تازه کنید.']);
        Ctx::boot(get_current_user_id(),$cid);$t=ImportService::template($cid);Tabular::download(ImportService::TEMPLATE,$t['headers'],$t['rows'],'xlsx',ImportService::TEMPLATE);
    }
    public static function dispatch($act,$cid,$url){
        $id=Request::int('post','id');
        switch($act){
            case 'module_backup_restore':
                if(Ctx::roleKey()!=='company_admin')throw new \Agah\Erp\Core\ForbiddenException('بازیابی فقط برای مدیر شرکت مجاز است.');
                if(!Request::flag('post','restore_ack'))throw new ValidationException(['restore_ack'=>'تأیید آگاهی از جایگزینی اطلاعات الزامی است.']);
                if(!hash_equals((string)Ctx::company()['name'],Request::str('post','confirm_name',190)))throw new ValidationException(['confirm_name'=>'نام شرکت دقیقاً مطابق عنوان شرکت نیست.']);
                $raw=function($name){return isset($_POST[$name])&&is_string($_POST[$name])?wp_unslash($_POST[$name]):'';};$user=get_userdata(get_current_user_id());
                if(!$user||$raw('confirm_password')===''||!wp_check_password($raw('confirm_password'),$user->user_pass,$user->ID))throw new ValidationException(['confirm_password'=>'گذرواژهٔ حساب کاربری درست نیست.']);
                $u=self::backupUpload();\Agah\Erp\Modules\Maintenance\PortableBackupService::restoreCompanyBytes($u['bytes'],$raw('backup_password'),$cid,get_current_user_id());
                return agah_erp_url(['c'=>$cid,'p'=>'backups']);
            case 'module_ratio_configure':
                $tags=[];foreach((array)($_POST['ratio_tag']??[]) as $tid=>$tag)$tags[(int)$tid]=sanitize_key(wp_unslash($tag));
                RatioService::configure($cid,Request::str('post','credit_mode',20),$tags);return $url;
            case 'module_ratio_snapshot':RatioService::saveSnapshot($cid,Jalali::parse(Request::str('post','from',10)),Jalali::parse(Request::str('post','to',10)));return $url;
            case 'module_ratio_snapshot_delete':RatioService::deleteSnapshot($cid,$id);return $url;
            case 'module_company_policy':CompanyPolicyService::saveCompany($cid,self::policyInput(),Request::flag('post','use_own'));return $url;
            case 'module_company_suspend':CompanyLifecycleService::suspend($cid,Request::str('post','reason',300));return agah_erp_url();
            case 'module_company_delete_empty':CompanyLifecycleService::deleteEmpty($cid,Request::str('post','confirm_name',190));return agah_erp_url();
            case 'module_company_purge_request':CompanyLifecycleService::requestPurge($cid,Request::str('post','confirm_name',190),Request::str('post','reason',300));return $url;
            case 'module_company_purge_cancel':CompanyLifecycleService::cancelPurge($cid,$id);return $url;
            case 'module_company_purge_confirm':CompanyLifecycleService::confirmPurge($cid,$id,Request::str('post','confirm_name',190),is_string($_POST['confirm_password']??null)?wp_unslash($_POST['confirm_password']):'');return agah_erp_url();
            case 'module_import_voucher_upload':case 'module_import_bank_upload':
                $kind=$act==='module_import_voucher_upload'?'voucher':'bank';$u=self::upload();
                $new=ImportService::upload($cid,$kind,$u['name'],$u['bytes'],Request::str('post','unit',8,$kind==='bank'?'rial':Ctx::unit()),Request::int('post','treasury_account_id'));
                return agah_erp_url(['c'=>$cid,'p'=>$kind==='voucher'?'import_vouchers':'import_bank','id'=>$new]);
            case 'module_import_voucher_remap':case 'module_import_bank_remap':ImportService::remap($cid,$id,self::mapping());return $url;
            case 'module_import_voucher_refresh':case 'module_import_bank_refresh':ImportService::refresh($cid,$id);return $url;
            case 'module_import_voucher_post':ImportService::post($cid,$id,Request::str('post','snapshot',64));return $url;
            case 'module_import_voucher_cancel':case 'module_import_bank_cancel':ImportService::cancel($cid,$id);return $url;
            case 'module_import_voucher_delete':case 'module_import_bank_delete':ImportService::delete($cid,$id);return agah_erp_url(['c'=>$cid,'p'=>$act==='module_import_voucher_delete'?'import_vouchers':'import_bank']);
            case 'module_bank_confirm':BankMatchService::confirm($cid,$id,Request::int('post','line_id'));return $url;
            case 'module_bank_unmatch':BankMatchService::unmatch($cid,$id);return $url;
            case 'module_bank_ignore':BankMatchService::ignore($cid,$id,Request::str('post','reason',300));return $url;
            case 'module_bank_restore':BankMatchService::restore($cid,$id);return $url;
            case 'module_bank_post':BankMatchService::post($cid,$id,['account_id'=>(string)Request::int('post','account_id'),'detail_id'=>(string)Request::int('post','detail_id'),'description'=>Request::str('post','description',300),'source'=>Request::str('post','source',10),'rule_id'=>(string)Request::int('post','rule_id')]);return $url;
            case 'module_bank_invoice':BankMatchService::settleInvoice($cid,$id,Request::int('post','invoice_id'));return $url;
            case 'module_rule_save':BankMatchService::saveRule($cid,$id,['name'=>Request::str('post','rule_name',100),'contains_text'=>Request::str('post','contains_text',100),'direction'=>Request::str('post','direction',3),'account_id'=>(string)Request::int('post','account_id'),'detail_id'=>(string)Request::int('post','detail_id'),'priority'=>Request::int('post','priority',100)]);return $url;
            case 'module_rule_deactivate':BankMatchService::deactivateRule($cid,$id);return $url;
            case 'module_rule_delete':BankMatchService::deleteRule($cid,$id);return $url;
            case 'module_memory_forget':BankMatchService::forget($cid,$id);return $url;
            case 'module_custody_move':$type=Request::str('post','holder_type',10);
                \Agah\Erp\Modules\Assets\AssetCustodyService::move($cid,$id,['effective_date'=>Jalali::parse(Request::str('post','effective_date',10)),'holder_type'=>$type==='none'?'':$type,'holder_id'=>(string)Request::int('post','holder_'.$type),'delivered_by'=>Request::str('post','delivered_by',190),'received_by'=>Request::str('post','received_by',190),'note'=>Request::str('post','note',300)]);return $url;
            case 'module_custody_undo':\Agah\Erp\Modules\Assets\AssetCustodyService::undoLast($cid,$id,Request::str('post','reason',300));return $url;
            case 'module_asset_category_save':\Agah\Erp\Modules\Assets\AssetCustodyService::saveCategory($cid,$id,['code'=>Request::str('post','code',30),'name'=>Request::str('post','title',190),'is_active'=>Request::flag('post','is_active')]);return $url;
            case 'module_asset_category_delete':\Agah\Erp\Modules\Assets\AssetCustodyService::deleteCategory($cid,$id);return $url;
            case 'module_asset_set_category':\Agah\Erp\Modules\Assets\AssetCustodyService::setCategory($cid,$id,Request::int('post','category_id'));return $url;
            case 'module_asset_place_save':\Agah\Erp\Modules\Assets\AssetCustodyService::savePlace($cid,$id,['kind'=>Request::str('post','kind',10),'code'=>Request::str('post','code',30),'name'=>Request::str('post','title',190),'is_active'=>Request::flag('post','is_active')]);return $url;
            case 'module_asset_place_delete':\Agah\Erp\Modules\Assets\AssetCustodyService::deletePlace($cid,$id);return $url;
            case 'module_hr_profile':$in=[];foreach(['personnel_no','phone','email','education','address','insurance_no','iban','employment_status','national_id'] as $k)$in[$k]=Request::str('post',$k,500);$in['dependents']=Request::int('post','dependents');foreach(['hire_date','leave_date'] as $k){$v=Request::str('post',$k,10);$in[$k]=$v===''?'':Jalali::parse($v);}$in['clear_national_id']=Request::flag('post','clear_national_id');\Agah\Erp\Modules\Payroll\HrService::saveProfile($cid,$id,$in);return $url;
            case 'module_hr_unit_save':\Agah\Erp\Modules\Payroll\HrService::saveUnit($cid,$id,['code'=>Request::str('post','code',30),'name'=>Request::str('post','title',190),'parent_id'=>Request::int('post','parent_id'),'is_active'=>Request::flag('post','is_active')]);return $url;
            case 'module_hr_unit_delete':\Agah\Erp\Modules\Payroll\HrService::deleteUnit($cid,$id);return $url;
            case 'module_hr_post_save':\Agah\Erp\Modules\Payroll\HrService::savePost($cid,$id,['code'=>Request::str('post','code',30),'title'=>Request::str('post','title',190),'unit_id'=>(string)Request::int('post','unit_id'),'supervisor_post_id'=>Request::int('post','supervisor_post_id'),'is_active'=>Request::flag('post','is_active')]);return $url;
            case 'module_hr_post_delete':\Agah\Erp\Modules\Payroll\HrService::deletePost($cid,$id);return $url;
            case 'module_hr_assign':\Agah\Erp\Modules\Payroll\HrService::assign($cid,Request::int('post','employee_id'),Request::int('post','post_id'),Jalali::parse(Request::str('post','start_date',10)),Request::str('post','note',300));return $url;
            case 'module_payitem_seed':\Agah\Erp\Modules\Payroll\PayItemService::seedDefaults($cid,Jalali::parse(Request::str('post','effective_from',10)));return $url;
            case 'module_payitem_version':\Agah\Erp\Modules\Payroll\PayItemService::saveVersion($cid,['code'=>Request::str('post','code',30),'name'=>Request::str('post','title',190),'kind'=>Request::str('post','kind',10),'calc'=>Request::str('post','calc',10),'taxable'=>Request::flag('post','taxable'),'insurable'=>Request::flag('post','insurable'),'ceiling'=>\Agah\Erp\Core\Money::fromInput(Request::str('post','ceiling',40),Ctx::unit()),'effective_from'=>Jalali::parse(Request::str('post','effective_from',10)),'is_active'=>Request::flag('post','is_active')]);return $url;
            case 'module_payitem_delete':\Agah\Erp\Modules\Payroll\PayItemService::deleteVersion($cid,$id);return $url;
            case 'module_tariff_save':$p=self::tariffInput();foreach(['expense_account_id','salary_account_id','insurance_account_id','tax_account_id','deduction_account_id'] as $k)$p[$k]=Request::int('post',$k);\Agah\Erp\Modules\Payroll\PayItemService::saveTariff($cid,Request::int('post','year_id'),Jalali::parse(Request::str('post','effective_from',10)),$p);return $url;
            case 'module_payroll_workshop':$code=\Agah\Erp\Core\Money::normDigits(Request::str('post','workshop',15));if($code!==''&&!preg_match('/^[0-9]{5,15}$/D',$code))throw new ValidationException(['workshop'=>'کد کارگاه ۵ تا ۱۵ رقم باشد.']);\Agah\Erp\Core\Access::write($cid,'payroll.edit',function()use($cid,$code){$old=\Agah\Erp\Core\Settings::get('insurance_workshop_code',$cid);\Agah\Erp\Core\Settings::set('insurance_workshop_code',$code,$cid);\Agah\Erp\Core\Audit::log('payroll.workshop','company',$cid,['code'=>$old],['code'=>$code],$cid);});return $url;
            case 'module_contract_amend':$in=['contract_type'=>Request::str('post','contract_type',10)];foreach(['base_salary','benefits','hourly_rate','project_amount'] as $k){$v=Request::str('post',$k,40);if($v!=='')$in[$k]=\Agah\Erp\Core\Money::fromInput($v,Ctx::unit());}if(Request::int('post','weekly_minutes'))$in['weekly_minutes']=Request::int('post','weekly_minutes');if(trim(Request::text('post','items',2000))!=='')$in['items']=self::itemsInput(Request::text('post','items',2000));if($in['contract_type']==='')unset($in['contract_type']);\Agah\Erp\Modules\Payroll\PayrollService::amendContract($cid,$id,Jalali::parse(Request::str('post','from',10)),$in);return $url;
            case 'module_run_create':$new=\Agah\Erp\Modules\Payroll\PayrollRunService::create($cid,Request::str('post','period_key',7));return agah_erp_url(['c'=>$cid,'p'=>'payroll_runs','id'=>$new]);
            case 'module_run_recalc':\Agah\Erp\Modules\Payroll\PayrollRunService::recalculate($cid,$id);return $url;
            case 'module_run_send':\Agah\Erp\Modules\Payroll\PayrollRunService::send($cid,$id);return $url;
            case 'module_run_adjust':$tax=Request::str('post','tax_override',40);\Agah\Erp\Modules\Payroll\PayrollRunService::adjust($cid,$id,Request::int('post','employee_id'),['tax_override'=>$tax===''?null:\Agah\Erp\Core\Money::fromInput($tax,Ctx::unit()),'earning'=>\Agah\Erp\Core\Money::fromInput(Request::str('post','earning',40),Ctx::unit()),'deduction'=>\Agah\Erp\Core\Money::fromInput(Request::str('post','deduction',40),Ctx::unit()),'reason'=>Request::str('post','reason',300)]);return $url;
            case 'module_run_finalize':\Agah\Erp\Modules\Payroll\PayrollRunService::finalize($cid,$id,Jalali::parse(Request::str('post','doc_date',10)));return $url;
            case 'module_run_pay':\Agah\Erp\Modules\Payroll\PayrollRunService::pay($cid,$id,Request::int('post','treasury_account_id'),Jalali::parse(Request::str('post','doc_date',10)));return $url;
            case 'module_run_reverse':\Agah\Erp\Modules\Payroll\PayrollRunService::reverse($cid,$id,Request::str('post','reason',300));return $url;
            case 'module_price_policy':$pc=fn($k)=>\Agah\Erp\Core\Money::pctBp(Request::str('post',$k,10)?:'0');\Agah\Erp\Modules\Trade\SalesControlService::savePolicy($cid,$id,['max_discount_bp'=>$pc('max_discount'),'max_price_down_bp'=>$pc('max_price_down'),'max_price_up_bp'=>$pc('max_price_up'),'require_reason'=>Request::flag('post','require_reason'),'remove'=>Request::flag('post','remove')]);return $url;
            case 'module_price_seen':\Agah\Erp\Modules\Trade\SalesControlService::markSeen($cid);return $url;
            case 'module_referral_create':\Agah\Erp\Modules\Trade\SalesControlService::refer($cid,$id,Request::int('post','to_user'),Request::str('post','note',300));return $url;
            case 'module_referral_act':\Agah\Erp\Modules\Trade\SalesControlService::act($cid,$id,Request::str('post','action',10),Request::str('post','note',300));return $url;
            case 'module_share_create':$s=\Agah\Erp\Modules\Trade\SalesControlService::createShare($cid,$id,Request::int('post','hours',72));return add_query_arg('shared',$s['id'],$url);
            case 'module_share_revoke':\Agah\Erp\Modules\Trade\SalesControlService::revokeShare($cid,$id);return $url;
            case 'module_share_sms':\Agah\Erp\Modules\Trade\SalesControlService::shareSms($cid,$id,Request::str('post','sms_phone',40),Request::flag('post','sms_confirm'));return add_query_arg('sms_requested',1,$url);
            case 'module_sub_order':$o=\Agah\Erp\Modules\Subscription\SubscriptionService::order($cid,Request::str('post','plan',20),Request::str('post','period',6),Request::str('post','activity',20));return $o['pay_url']?:add_query_arg('ordered',$o['id'],$url);
            case 'module_migrate_upload':$u=self::upload();$dd=Request::str('post','doc_date',10);
                $new=\Agah\Erp\Modules\Import\MigrationService::upload($cid,Request::str('post','kind',10),Request::str('post','source_system',40),$u['name'],$u['bytes'],Request::str('post','unit',6),$dd!==''?Jalali::parse($dd):'');
                return agah_erp_url(['c'=>$cid,'p'=>'import_migrate','id'=>$new]);
            case 'module_migrate_remap':\Agah\Erp\Modules\Import\MigrationService::remap($cid,$id,self::mapping());return $url;
            case 'module_migrate_refresh':\Agah\Erp\Modules\Import\MigrationService::refresh($cid,$id);return $url;
            case 'module_migrate_post':\Agah\Erp\Modules\Import\MigrationService::post($cid,$id,Request::str('post','snapshot',64));return $url;
            case 'module_migrate_cancel':\Agah\Erp\Modules\Import\MigrationService::cancel($cid,$id);return $url;
            case 'module_migrate_delete':\Agah\Erp\Modules\Import\MigrationService::delete($cid,$id);return agah_erp_url(['c'=>$cid,'p'=>'import_migrate']);
            case 'module_migrate_forget':\Agah\Erp\Modules\Import\MigrationService::forget($cid,$id);return $url;
            case 'module_moadian_policy':\Agah\Erp\Modules\Moadian\MoadianService::savePolicy($cid,['kinds'=>self::strings('kinds'),'invoice_type'=>Request::int('post','invoice_type',1),'auto_select'=>Request::flag('post','auto_select'),'effective_from'=>Jalali::parse(Request::str('post','effective_from',10)),'note'=>Request::str('post','note',300)]);return $url;
            case 'module_moadian_policy_delete':\Agah\Erp\Modules\Moadian\MoadianService::deletePolicy($cid,$id);return $url;
            case 'module_moadian_item_code':\Agah\Erp\Modules\Moadian\MoadianService::setItemCode($cid,$id,Request::str('post','tax_item_code',20));return $url;
            case 'module_moadian_select':\Agah\Erp\Modules\Moadian\MoadianService::select($cid,array_map('intval',self::strings('ids')));return $url;
            case 'module_moadian_revalidate':\Agah\Erp\Modules\Moadian\MoadianService::revalidate($cid,$id);return $url;
            case 'module_moadian_remove':\Agah\Erp\Modules\Moadian\MoadianService::remove($cid,$id);return $url;
            case 'module_moadian_send':\Agah\Erp\Modules\Moadian\MoadianService::send($cid);return $url;
            case 'module_moadian_poll':\Agah\Erp\Modules\Moadian\MoadianService::poll($cid);return $url;
            case 'module_moadian_amend':\Agah\Erp\Modules\Moadian\MoadianService::amend($cid,$id,Request::str('post','action_kind',12),Request::str('post','reason',300));return $url;
            case 'module_moadian_manual':\Agah\Erp\Modules\Moadian\MoadianService::manualResult($cid,$id,Request::str('post','result',10),Request::str('post','tax_unique_id',40),Request::str('post','reference_no',64),Request::str('post','note',300));return $url;
            case 'module_notify_read':\Agah\Erp\Modules\Notify\NotificationService::markRead($cid,$id);return $url;
            case 'module_shortcut_toggle':$key=Request::str('post','page_key',30);if(!isset(ModuleController::nav()[$key]))throw new ValidationException(['page_key'=>'صفحه معتبر نیست.']);
                \Agah\Erp\Core\Access::write($cid,'dashboard.view',function()use($cid,$key){$t=\Agah\Erp\Core\Db::t('user_shortcuts');$ex=\Agah\Erp\Core\Db::val('SELECT id FROM '.$t.' WHERE company_id=%d AND user_id=%d AND page_key=%s',$cid,Ctx::uid(),$key);if($ex)\Agah\Erp\Core\Db::delete('user_shortcuts',['company_id'=>$cid,'id'=>$ex]);else{if((int)\Agah\Erp\Core\Db::val('SELECT COUNT(*) FROM '.$t.' WHERE company_id=%d AND user_id=%d',$cid,Ctx::uid())>=20)throw new ValidationException(['page_key'=>'حداکثر ۲۰ میانبر.']);\Agah\Erp\Core\Business::save('user_shortcuts',$cid,0,['user_id'=>Ctx::uid(),'page_key'=>$key,'sort'=>time()%100000]);}});return $url;
        }
        throw new ValidationException(['form'=>'عمل معتبر نیست.']);
    }
}
