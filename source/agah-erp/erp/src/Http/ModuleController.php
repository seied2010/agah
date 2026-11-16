<?php
/** مسیریاب مراحل ۲ و ۳؛ POST با nonce مجزا، زمینه، مجوز سرویس، توکن اتمیک و PRG؛ HTML در views است. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Db,Ctx,Request,Money,Jalali,Crypto,Idempotency,AppException,ValidationException,Access,Audit,Settings};
use Agah\Erp\Modules\Company\{CompanyService,MemberService};
use Agah\Erp\Modules\Accounting\{AccountService,DetailService,FiscalService,VoucherService,ReportService};
if(!defined('ABSPATH')) exit;
final class ModuleController {
    private static $old=[];
    private static $errors=[];
    private static $noticeLoaded=false;
    public static function register() {
        add_action('template_redirect',[__CLASS__,'route'],0);
        add_action('wp_enqueue_scripts',function(){if(SecurityController::isPage()) { wp_enqueue_script('agah-erp-search',AGAH_ERP_URL.'/assets/select-search.js',[],AGAH_ERP_VERSION,true);wp_enqueue_script('agah-erp-forms',AGAH_ERP_URL.'/assets/forms.js',[],AGAH_ERP_VERSION,true);wp_enqueue_script('agah-erp-commerce',AGAH_ERP_URL.'/assets/commerce.js',['agah-erp-forms'],AGAH_ERP_VERSION,true); }});
    }
    public static function nav() {
        return array_merge(CommerceController::nav(), ['settings'=>['تنظیمات شرکت','company_settings.view'],'members'=>['اعضای شرکت','member.view'],'roles'=>['نقش‌ها','role.view'],'details'=>['تفصیلی‌ها','detail.view'],'vouchers'=>['اسناد حسابداری','voucher.view'],'reports'=>['دفاتر و تراز','report.view']]);
    }
    private static function asyncRequest(){return isset($_SERVER['HTTP_X_AGAH_FORM'])&&$_SERVER['HTTP_X_AGAH_FORM']==='1';}
    private static function key() {return 'agah_module_'.get_current_user_id().'_'.substr(hash('sha256',wp_get_session_token()),0,20);}
    /** بازگشت محدود به مسیر سامانه؛ هیچ نشانی دلخواه از POST استفاده نمی‌شود. */
    public static function route() {
        if(!SecurityController::isPage() || !is_user_logged_in()) return;
        if(!Request::isPost() && Request::int('get','c') && !SecurityController::blocked()) {
            try {
                Ctx::boot(get_current_user_id(),Request::int('get','c'));
                $page=Request::str('get','p',30,'dashboard');
                $caps=array_merge(self::nav(),['dashboard'=>['','dashboard.view'],'accounts'=>['','account.view'],'fiscal'=>['','fiscal_year.view'],'audit'=>['','audit.view'],'voucher_new'=>['','voucher.create'],'voucher_edit'=>['','voucher.edit'],'voucher_view'=>['','voucher.view']]);
                if(isset($caps[$page])) Ctx::need(TradeController::cap($page)?:$caps[$page][1]);
                elseif(!CommerceController::preflight($page,Request::int('get','c')) && $page!=='security') throw new \Agah\Erp\Core\NotFoundException();
                $cid=Request::int('get','c');CommerceController::preflight($page,$cid);$id=Request::int('get','id');$edit=Request::int('get','edit');
                if(in_array($page,['voucher_view','voucher_edit'],true)) VoucherService::get($cid,$id);
                if($page==='details' && $edit) DetailService::get($cid,$edit);
                if($page==='accounts' && $edit) AccountService::get($cid,$edit);
                if($page==='roles' && ($edit || Request::int('get','copy'))) {
                    $rid=$edit?:Request::int('get','copy');
                    if(!Db::val('SELECT id FROM '.Db::t('roles').' WHERE company_id=%d AND id=%d',$cid,$rid)) throw new \Agah\Erp\Core\NotFoundException();
                }
            }
            catch(AppException $e){wp_die(esc_html($e->getMessage()),'',['response'=>$e->status]);}
        }
        if(!Request::isPost() && Request::str('get','legal_export')!==''){try{LegalController::output(Request::int('get','c'));}catch(AppException $e){status_header($e->status);echo esc_html($e->getMessage());}catch(\Throwable $e){error_log('[agah-legal-output] '.get_class($e));status_header(500);echo 'خطای دریافت گزارش';}exit;}
        if(!Request::isPost() && (Request::int('get','file_download') || Request::str('get','operations_export')!=='')){try{OperationsController::output(Request::int('get','c'));}catch(AppException $e){status_header($e->status);echo esc_html($e->getMessage());}catch(\Throwable $e){error_log('[agah-operations-output] '.get_class($e));status_header(500);echo 'خطای دریافت خروجی';}exit;}
        if(!Request::isPost() && (Request::str('get','finance_export')!=='' || Request::int('get','finance_image'))) {try{if(Request::int('get','finance_image'))FinanceController::image(Request::int('get','c'));else FinanceController::export(Request::int('get','c'));}catch(AppException $e){status_header($e->status);echo esc_html($e->getMessage());}catch(\Throwable $e){error_log('[agah-finance-output] '.get_class($e));status_header(500);echo 'خطای دریافت خروجی';}exit;}
        if(!Request::isPost() && Request::flag('get','production_export')){try{ProductionController::export(Request::int('get','c'));}catch(AppException $e){status_header($e->status);echo esc_html($e->getMessage());}catch(\Throwable $e){error_log('[agah-production-output] '.get_class($e));status_header(500);echo 'خطای دریافت گزارش تولید';}exit;}
        if(!Request::isPost() && Request::str('get','trade_export')!==''){try{TradeController::export(Request::int('get','c'));}catch(AppException $e){status_header($e->status);echo esc_html($e->getMessage());}catch(\Throwable $e){error_log('[agah-trade-export] '.get_class($e));status_header(500);echo 'خطای خروجی';}exit;}
        if(!Request::isPost() && Request::str('get','business_export')!==''){try{CommerceController::export(Request::int('get','c'));}catch(AppException $e){status_header($e->status);echo esc_html($e->getMessage());}catch(\Throwable $e){error_log('[agah-business-export] '.get_class($e));status_header(500);echo 'خطای خروجی';}exit;}
        if(!Request::isPost() && Request::str('get','payroll_export')!==''){try{Phase2Controller::payrollExport(Request::int('get','c'));}catch(AppException $e){status_header($e->status);echo esc_html($e instanceof ValidationException?implode(' ',$e->errors):$e->getMessage());}catch(\Throwable $e){error_log('[agah-payroll-export] '.get_class($e));status_header(500);echo 'خطای دریافت خروجی';}exit;}
        if(!Request::isPost() && Request::str('get','custody_export')!==''){try{Phase2Controller::custodyExport(Request::int('get','c'));}catch(AppException $e){status_header($e->status);echo esc_html($e instanceof ValidationException?implode(' ',$e->errors):$e->getMessage());}catch(\Throwable $e){error_log('[agah-custody-export] '.get_class($e));status_header(500);echo 'خطای دریافت خروجی';}exit;}
        if(!Request::isPost() && Request::str('get','import_template')!==''){try{Phase2Controller::template(Request::int('get','c'));}catch(AppException $e){status_header($e->status);echo esc_html($e instanceof ValidationException?implode(' ',$e->errors):$e->getMessage());}catch(\Throwable $e){error_log('[agah-import-template] '.get_class($e));status_header(500);echo 'خطای دریافت قالب';}exit;}
        if(!Request::isPost() && Request::str('get','migrate_template')!==''){try{Phase2Controller::migrateTemplate(Request::int('get','c'));}catch(AppException $e){status_header($e->status);echo esc_html($e instanceof ValidationException?implode(' ',$e->errors):$e->getMessage());}catch(\Throwable $e){error_log('[agah-migrate-template] '.get_class($e));status_header(500);echo 'خطای دریافت قالب';}exit;}
        if(!Request::isPost() && Request::flag('get','company_data_export')){try{Phase2Controller::companyDataExport(Request::int('get','c'));}catch(AppException $e){status_header($e->status);echo esc_html($e instanceof ValidationException?implode(' ',$e->errors):$e->getMessage());}catch(\Throwable $e){error_log('[agah-company-data-export] '.get_class($e));status_header(500);echo 'خطای دریافت خروجی جامع';}exit;}
        if(Request::isPost() && Request::str('post','act',40)==='module_backup_download'){
            try{Phase2Controller::backupDownload(Request::int('post','c'));}
            catch(AppException $e){$errors=$e instanceof ValidationException?$e->errors:['form'=>$e->getMessage()];if(self::asyncRequest())wp_send_json(['errors'=>$errors,'redirect'=>null],$e->status);status_header($e->status);echo esc_html(implode('، ',$errors));}
            catch(\Throwable $e){$ref=Crypto::token(4);error_log('[agah-portable-backup] '.$ref.' '.get_class($e).': '.$e->getMessage());$message='ساخت پشتیبان کامل نشد؛ شناسهٔ پیگیری: '.$ref;if(self::asyncRequest())wp_send_json(['errors'=>['form'=>$message],'redirect'=>null],500);status_header(500);echo esc_html($message);}
            exit;
        }
        if(!Request::isPost() && Request::str('get','export')!=='') {self::export();exit;}
        if(!Request::isPost()) return;
        $act=Request::str('post','act',40);
        $legacy=['company_create','account_create','account_toggle','account_delete','fy_create','period_toggle'];
        if(strpos($act,'module_')!==0 && !in_array($act,$legacy,true)) return;
        $cid=Request::int('post','c');$back=Request::str('post','back',30,'dashboard');
        $allowed=array_merge(array_keys(self::nav()),['hr_profile','asset_receipt','production_order','voucher_new','voucher_edit','voucher_view','accounts','fiscal','audit','party_view','treasury_new','treasury_view','cheque_print','invoice_new','invoice_view','expense_view','payslip_view','asset_view','asset_new']);
        if(!in_array($back,$allowed,true)) $back='dashboard';
        $args=['c'=>$cid,'p'=>$back];if($back==='invoice_new')$args['kind']=Request::str('post','kind',24,'sale');if($back==='treasury_new')$args['kind']=Request::str('post','kind',10,'receipt');
        if(in_array($back,['production_boms','roles','details','accounts','parties','items','units','groups','taxes','treasury_accounts','expense_categories','cost_dimensions'],true) && Request::int('get','edit')) $args['edit']=Request::int('get','edit');$backId=Request::int('post','back_id');if($backId) $args['id']=$backId;
        if($act==='module_file_attach'&&$back==='files'){$args['target_type']=Request::str('post','target_type',10);$args['target_id']=Request::int('post','target_id');}
        $url=agah_erp_url($args);$notice=['errors'=>[],'old'=>[],'message'=>''];
        try {
            if(!wp_verify_nonce(Request::str('post','_n',100),'agah_erp_'.$act)) throw new ValidationException(['form'=>'نشست فرم منقضی شده است؛ صفحه را تازه کنید.']);
            if($cid) Ctx::boot(get_current_user_id(),$cid);
            elseif($act!=='company_create') throw new \Agah\Erp\Core\NotFoundException();
            $required=[
                'company_create'=>'', 'account_create'=>'account.create','account_toggle'=>'account.edit','account_delete'=>'account.delete','fy_create'=>'fiscal_year.create','period_toggle'=>'fiscal_year.edit',
                'module_year_select'=>'fiscal_year.view','module_year_delete'=>'fiscal_year.approve','module_company_save'=>'company_settings.edit','module_voucher_print_settings'=>'company_settings.edit','module_member_add'=>'member.create',
                'module_member_change'=>Request::str('post','status')==='removed'?'member.delete':'member.edit',
                'module_role_save'=>'role.edit','module_role_delete'=>'role.edit',
                'module_detail_save'=>Request::int('post','id')?'detail.edit':'detail.create','module_detail_delete'=>'detail.delete',
                'module_account_update'=>'account.edit','module_year_status'=>'fiscal_year.approve',
                'module_voucher_save'=>Request::int('post','id')?'voucher.edit':'voucher.create','module_voucher_copy'=>'voucher.create','module_voucher_delete'=>'voucher.delete',
                'module_voucher_state'=>['approve'=>'voucher.approve','finalize'=>'voucher.finalize','reopen'=>'voucher.reopen','void'=>'voucher.void'][Request::str('post','transition')]??'voucher.edit',
            ];
            $required=array_merge($required,CommerceController::actions());if($act==='module_invoice_convert')$required[$act]=TradeController::convertCap($cid,Request::int('post','id'));
            if(!isset($required[$act])) throw new ValidationException(['form'=>'عمل نامعتبر است.']);
            if($required[$act]) Ctx::need($required[$act]);
            elseif(!current_user_can('manage_options') && !Settings::flag('allow_self_company')) throw new \Agah\Erp\Core\ForbiddenException();
            if($act==='module_invoice_official'){TradeController::official($cid,Request::int('post','id'));exit;}
            if($act==='module_hr_reveal'){Ctx::need('payroll.sensitive');if(!\Agah\Erp\Security\RateLimiter::consume('hr-reveal|'.get_current_user_id(),10,300))throw new ValidationException(['form'=>'تلاش بیش از حد.']);$value=\Agah\Erp\Modules\Payroll\HrService::revealNational($cid,Request::int('post','id'));wp_die('<p dir="ltr">'.esc_html($value?:'ثبت نشده').'</p>','کد ملی',['response'=>200]);}
            if($act==='module_party_reveal'){if(!\Agah\Erp\Security\RateLimiter::consume('party-reveal|'.get_current_user_id(),10,300))throw new ValidationException(['form'=>'تلاش بیش از حد.']);$value=\Agah\Erp\Modules\Parties\PartyService::reveal($cid,Request::int('post','id'));wp_die('<p dir="ltr">'.esc_html($value?:'ثبت نشده').'</p>','شناسهٔ ملی',['response'=>200]);}
            if($act==='module_legal_sensitive'){Ctx::need('party.sensitive');if(!\Agah\Erp\Security\RateLimiter::consume('legal-sensitive|'.get_current_user_id().'|'.$cid,5,3600))throw new ValidationException(['form'=>'حداکثر پنج خروجی هویتی در ساعت مجاز است.']);LegalController::output($cid,true);exit;}
            if($act==='module_company_purge_confirm')\Agah\Erp\Modules\Company\CompanyLifecycleService::checkPassword(is_string($_POST['confirm_password']??null)?wp_unslash($_POST['confirm_password']):'');
            $payload=wp_unslash($_POST);unset($payload['_n'],$payload['_idem'],$payload['confirm_password']);if(in_array($act,['module_import_voucher_upload','module_import_bank_upload','module_migrate_upload'],true))$payload['_file_hash']=hash('sha256',Phase2Controller::upload()['bytes']);if($act==='module_backup_restore')$payload['_file_hash']=hash('sha256',Phase2Controller::backupUpload()['bytes']);if($act==='module_expense_image')$payload['_image_hash']=hash('sha256',FinanceController::upload());if($act==='module_file_attach'){$u=OperationsController::upload();$payload['_file_hash']=hash('sha256',$u['bytes']);$payload['_file_name']=$u['name'];}$hash=hash('sha256',wp_json_encode($payload));
            $token=Request::str('post','_idem',200);
            $url=Db::tx(function()use($act,$cid,$token,$hash,$url){
                $old=Idempotency::claim($token,get_current_user_id(),$cid,$act,$hash);
                if($old!==null) return $old;
                $result=self::dispatch($act,$cid,$url);Idempotency::complete($token,$result);return $result;
            });
            $notice['message']='عملیات با موفقیت انجام شد.';
        } catch(ValidationException $e) {$notice['errors']=$e->errors;}
        catch(AppException $e) {if($e instanceof \Agah\Erp\Core\NotFoundException && !self::asyncRequest()) wp_die(esc_html($e->getMessage()),'',['response'=>404]);$notice['errors']=['form'=>$e->getMessage()];}
        catch(\InvalidArgumentException $e) {$notice['errors']=['form'=>$e->getMessage()];}
        catch(\Throwable $e) {$ref=Crypto::token(4);error_log('[agah-module] '.$ref.' '.get_class($e).': '.$e->getMessage());$notice['errors']=['form'=>'خطای داخلی؛ شناسهٔ پیگیری: '.$ref];}
        if(self::asyncRequest()) {wp_send_json(['errors'=>$notice['errors'],'redirect'=>$notice['errors']?null:$url],$notice['errors']?422:200);}
        if($notice['errors']) {
            $notice['old']=wp_unslash($_POST);
            foreach(['require_2fa','persian_digits','is_active','clear_national_id','is_customer','is_supplier','is_employee','confirmed','advance'] as $flag) if(!isset($notice['old'][$flag])) $notice['old'][$flag]='0';
            if($act==='module_role_save' && !isset($notice['old']['caps'])) $notice['old']['caps']=[];
            unset($notice['old']['password'],$notice['old']['confirm_password'],$notice['old']['backup_password'],$notice['old']['backup_password_confirm'],$notice['old']['national_id'],$notice['old']['_n'],$notice['old']['_idem']);
            // شناسهٔ محرمانه و گذرواژه بعد از خطا دوباره وارد می‌شوند؛ در transient هم نگهداری نمی‌شوند.
        }
        set_transient(self::key(),Crypto::encrypt(wp_json_encode($notice)),120);
        wp_safe_redirect($url);exit;
    }
    /** ورودی متن/عدد از Request؛ آرایهٔ خطوط جدا بررسی می‌شود تا array injection خطای عمومی نسازد. */
    private static function dispatch($act,$cid,$url) {
        $id=Request::int('post','id');
        if(in_array($act,['company_create','account_create','account_toggle','account_delete','fy_create','period_toggle'],true)) { Page::dispatch($act,$cid);return $url; }
        switch($act) {
            case 'module_year_select': \Agah\Erp\Core\FiscalContext::select($cid,Request::int('post','jy'));return agah_erp_url(['c'=>$cid]);
            case 'module_year_delete': FiscalService::deleteUnused($cid,$id);return $url;
            case 'module_company_save':
                $in=[];foreach(['name','legal_name','kind','display_unit','activity_type','national_id','economic_code','registration_no','phone','address','postal_code','default_due_days','voucher_prefix','invoice_prefix'] as $key) $in[$key]=Request::str('post',$key,500);
                foreach(['require_2fa','persian_digits','clear_national_id'] as $key) $in[$key]=Request::flag('post',$key)?'1':'0';
                CompanyService::update($cid,$in);break;
            case 'module_voucher_print_settings':
                \Agah\Erp\Core\Access::write($cid,'company_settings.edit',function()use($cid){
                    $values=[];foreach(['voucher_sign_1','voucher_sign_2','voucher_sign_3'] as $key){$values[$key]=Request::str('post',$key,80);if($values[$key]==='')throw new ValidationException([$key=>'عنوان امضا را وارد کنید.']);}
                    $old=[];foreach($values as $key=>$value){$old[$key]=Settings::get($key,$cid);Settings::set($key,$value,$cid);}
                    Audit::log('voucher.print.settings','company',$cid,$old,$values,$cid);
                });break;
            case 'module_member_add':
                $pass=isset($_POST['password']) && is_string($_POST['password'])?wp_unslash($_POST['password']):'';
                MemberService::add($cid,['login'=>Request::str('post','login',60),'email'=>Request::str('post','email',190),'display_name'=>Request::str('post','display_name',190),'password'=>$pass,'role_id'=>Request::int('post','role_id')]);break;
            case 'module_member_change': MemberService::change($cid,Request::int('post','user_id'),Request::int('post','role_id'),Request::str('post','status',10));break;
            case 'module_role_save':
                $caps=isset($_POST['caps']) && is_array($_POST['caps'])?wp_unslash($_POST['caps']):[];
                MemberService::saveRole($cid,$id,Request::str('post','name',100),$caps);break;
            case 'module_role_delete': MemberService::deleteRole($cid,$id);break;
            case 'module_detail_save': DetailService::save($cid,$id,['name'=>Request::str('post','name',190),'code'=>Request::str('post','code',20),'kind'=>Request::str('post','kind',20),'is_active'=>Request::flag('post','is_active')]);break;
            case 'module_detail_delete': DetailService::delete($cid,$id);break;
            case 'module_account_update':
                $in=[];foreach(['name','code','stmt_class','detail_rule','detail_kind'] as $key) $in[$key]=Request::str('post',$key,190);
                AccountService::update($cid,$id,$in);break;
            case 'module_year_status': FiscalService::setYearStatus($cid,$id,Request::str('post','status',10),Request::str('post','reason',300));break;
            case 'module_voucher_save':
                $raw=isset($_POST['lines']) && is_array($_POST['lines'])?wp_unslash($_POST['lines']):[];$lines=[];
                if(count($raw)>200) throw new ValidationException(['lines'=>'حداکثر ۲۰۰ ردیف مجاز است.']);
                foreach($raw as $line) {
                    if(!is_array($line)) throw new ValidationException(['lines'=>'ردیف معتبر نیست.']);
                    $out=[];foreach(['account_id','detail_id','description','debit','credit'] as $key) {
                        if(isset($line[$key]) && !is_scalar($line[$key])) throw new ValidationException(['lines'=>'مقدار ردیف معتبر نیست.']);
                        $out[$key]=(string)($line[$key]??'');
                    }
                    if($out['detail_id']==='') $out['detail_id']='0';$lines[]=$out;
                }
                $new=VoucherService::save($cid,$id,['voucher_date'=>Jalali::parse(Request::str('post','voucher_date',10)),'description'=>Request::str('post','description',500),'type'=>Request::str('post','type',12)],$lines,Request::int('post','revision'),Ctx::unit());
                return agah_erp_url(['c'=>$cid,'p'=>'voucher_view','id'=>$new]);
            case 'module_voucher_state': VoucherService::transition($cid,$id,Request::str('post','transition',12),Request::str('post','reason',300),Request::int('post','revision'));break;
            case 'module_voucher_copy': return agah_erp_url(['c'=>$cid,'p'=>'voucher_view','id'=>VoucherService::copy($cid,$id)]);
            case 'module_voucher_delete': VoucherService::delete($cid,$id,Request::int('post','revision'));return agah_erp_url(['c'=>$cid,'p'=>'vouchers']);
            default: return CommerceController::dispatch($act,$cid,$url);
        }
        return $url;
    }
    /** viewها فقط از فهرست ثابت بارگذاری می‌شوند؛ مسیر از ورودی کاربر ساخته نمی‌شود. */
    public static function render($p,$cid) {
        $views=['settings'=>'settings','members'=>'members','roles'=>'roles','details'=>'details','vouchers'=>'vouchers','voucher_new'=>'voucher-form','voucher_edit'=>'voucher-form','voucher_view'=>'voucher-view','reports'=>'reports','audit'=>'audit'];
        $businessView=CommerceController::view($p);
        if($businessView)$views[$p]=$businessView;
        if(!isset($views[$p])) return false;
        $caps=self::nav();$cap=$caps[$p][1]??($p==='party_view'?'party.view':($p==='treasury_new'?Request::str('get','kind',10,'receipt').'.create':($p==='treasury_view'?\Agah\Erp\Modules\Treasury\TreasuryService::get($cid,Request::int('get','id'))['kind'].'.view':($p==='cheque_print'?'cheque.view':($p==='audit'?'audit.view':($p==='voucher_new'?'voucher.create':($p==='voucher_edit'?'voucher.edit':'voucher.view')))))));
        $cap=ProductionController::cap($p)?:TradeController::cap($p)?:$cap;
        Access::need($cid,$cap);
        self::notices();
        require AGAH_ERP_DIR.'/views/'.$views[$p].'.php';return true;
    }
    /** یک‌بار در هر پاسخ؛ فرم‌های قدیمی نیز خطاها و مقدارهای بازگشتی را دریافت می‌کنند. */
    public static function notices() {
        if(self::$noticeLoaded) return;
        self::$noticeLoaded=true;
        $blob=get_transient(self::key());delete_transient(self::key());$notice=$blob?json_decode(Crypto::decrypt($blob),true):[];
        self::$old=is_array($notice['old']??null)?$notice['old']:[];self::$errors=is_array($notice['errors']??null)?$notice['errors']:[];
        if(!empty($notice['message'])) echo '<p class="erp-flash ok" role="status">'.esc_html($notice['message']).'</p>';
        foreach(self::$errors as $key=>$message) echo '<p class="erp-err" role="alert" data-error-field="'.esc_attr($key).'">'.esc_html($message).'</p>';
    }
    public static function value($key,$default='') {return self::$old[$key==='name'?'erp_name':$key]??$default;}
    public static function error($key) {if(isset(self::$errors[$key])) echo '<small class="erp-err" role="alert">'.esc_html(self::$errors[$key]).'</small>';}
    public static function form($action,$cid,$back,$id=0) {
        echo '<input type="hidden" name="act" value="'.esc_attr($action).'"><input type="hidden" name="c" value="'.(int)$cid.'"><input type="hidden" name="back" value="'.esc_attr($back).'"><input type="hidden" name="id" value="'.(int)$id.'"><input type="hidden" name="back_id" value="'.(int)Request::int('get','id').'"><input type="hidden" name="_n" value="'.esc_attr(wp_create_nonce('agah_erp_'.$action)).'"><input type="hidden" name="_idem" value="'.esc_attr(Idempotency::issue(get_current_user_id(),$cid,$action)).'">';
    }
    public static function input($key,$label,$default='',$type='text',$attrs='') {
        echo '<label>'.esc_html($label).'<input name="'.esc_attr($key==='name'?'erp_name':$key).'" type="'.esc_attr($type).'" value="'.esc_attr(self::value($key,$default)).'" '.$attrs.'>';self::error($key);echo '</label>';
    }
    public static function select($key,$label,array $options,$default='') {
        $value=self::value($key,$default);echo '<label>'.esc_html($label).'<select name="'.esc_attr($key).'">';
        foreach($options as $k=>$label) echo '<option value="'.esc_attr($k).'" '.selected((string)$value,(string)$k,false).'>'.esc_html($label).'</option>';
        echo '</select>';self::error($key);echo '</label>';
    }
    /** فیلتر GET مشترک برای فهرست‌ها؛ نام q با query var رزروشدهٔ وردپرس تداخل ندارد. */
    public static function search($cid,$page) {
        echo '<form method="get" action="'.esc_url(agah_erp_url()).'" class="erp-filter"><input type="hidden" name="page_id" value="'.(int)get_option('agah_erp_page_id').'"><input type="hidden" name="c" value="'.(int)$cid.'"><input type="hidden" name="p" value="'.esc_attr($page).'"><label>جست‌وجوی کد یا نام<input name="q" maxlength="100" value="'.esc_attr(Request::str('get','q',100)).'"></label><button type="submit" class="btn btn-outline">جست‌وجو</button></form>';
    }
    /** سرستون مرتب‌شونده (UX-05): کلیک دوم جهت را برمی‌گرداند؛ صفحه‌بندی از اول. */
    public static function sortHead($key,$label){$cur=Request::str('get','sort',10);$dir=strtolower(Request::str('get','dir',4))==='desc'?'desc':'asc';$next=$cur===$key&&$dir==='asc'?'desc':'asc';$mark=$cur===$key?($dir==='asc'?' ▲':' ▼'):'';return '<th aria-sort="'.($cur===$key?($dir==='asc'?'ascending':'descending'):'none').'"><a href="'.esc_url(add_query_arg(['sort'=>$key,'dir'=>$next,'pg'=>false])).'">'.esc_html($label.$mark).'</a></th>';}
    public static function pages($pages,$page) {
        if($pages<2) return;
        echo '<nav class="erp-pagination" aria-label="صفحه‌بندی">';
        if($page>1) echo '<a href="'.esc_url(add_query_arg('pg',$page-1)).'">قبلی</a> ';
        echo '<span>'.esc_html(Money::toFa($page.' / '.$pages)).'</span> ';
        if($page<$pages) echo '<a href="'.esc_url(add_query_arg('pg',$page+1)).'">بعدی</a>';
        echo '</nav>';
    }
    /** تاریخ فیلتر شمسی و شناسه‌ها پیش از رسیدن به SQL اعتبارسنجی می‌شوند. */
    public static function filters() {
        $f=['mode'=>Request::str('get','mode',20,'journal'),'q'=>Request::str('get','q',100),'status'=>Request::str('get','status',10),'sort'=>Request::str('get','sort',10),'dir'=>Request::str('get','dir',4),'account_id'=>Request::int('get','account_id'),'detail_id'=>Request::int('get','detail_id')];
        foreach(['from','to'] as $k) {$v=Request::str('get',$k,10);$f[$k]=$v?Jalali::parse($v):'';}
        foreach(['no_from','no_to'] as $k) $f[$k]=Money::normDigits(Request::str('get',$k,12));
        return \Agah\Erp\Core\FiscalContext::range(Ctx::cid(),$f);
    }
    /** خروجی CSV یا SpreadsheetML واقعی قابل‌بازکردن در Excel؛ دادهٔ کاربر هرگز فرمول CSV نمی‌شود. */
    private static function export() {
        try {
            $cid=Request::int('get','c');Ctx::boot(get_current_user_id(),$cid);Ctx::need('report.export');
            if(!wp_verify_nonce(Request::str('get','_n',100),'agah_report_export_'.$cid)) throw new ValidationException(['form'=>'اعتبار خروجی پایان یافته است.']);
            $format=Request::str('get','export',10);if(!in_array($format,['csv','excel','xlsx'],true)) throw new ValidationException(['format'=>'قالب خروجی معتبر نیست.']);
            $f=self::filters();$columns=ReportService::columns($f['mode']);
            $rows=Db::tx(function()use($cid,$f,$columns){
                $first=ReportService::ledger($cid,$f,1,1000);if($first['count']>10000)throw new ValidationException(['export'=>'بازه خروجی را به کمتر از ۱۰هزار ردیف محدود کنید.']);$rows=[];
                for($page=1;$page<=max(1,$first['pages']);$page++)foreach(($page===1?$first:ReportService::ledger($cid,$f,$page,1000))['rows'] as $r){$row=[];foreach($columns as $key=>$label){$v=$r[$key]??'';if($key==='voucher_date')$v=Jalali::format($v,false);elseif(!in_array($key,['code','name','detail_name','description','number'],true))$v=new \Agah\Erp\Core\DecimalCell(Money::toInputValue((int)$v,Ctx::unit()));$row[]=$v;}$rows[]=$row;}
                return $rows;
            });
            \Agah\Erp\Core\Tabular::download('agah-ledger',array_values($columns),$rows,$format);

        }catch(AppException $e){status_header($e->status);echo esc_html($e->getMessage());}
        catch(\Throwable $e){error_log('[agah-export] '.get_class($e));status_header(500);echo 'خروجی انجام نشد.';}
    }
    private static function exportRow($out,array $row,$format) {
        if($format==='csv') {
            foreach($row as &$v) if(is_string($v) && preg_match('/^[\s]*[=+\-@]/u',$v)) $v="'".$v;unset($v);
            fputcsv($out,$row,',','"','');return;
        }
        fwrite($out,'<Row>');foreach($row as $v) fwrite($out,'<Cell><Data ss:Type="'.(is_int($v)?'Number':'String').'">'.htmlspecialchars((string)$v,ENT_XML1|ENT_QUOTES,'UTF-8').'</Data></Cell>');fwrite($out,'</Row>');
    }
}
