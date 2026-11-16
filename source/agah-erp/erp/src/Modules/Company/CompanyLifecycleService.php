<?php
/**
 * پایان عمر شرکت: تعلیق، حذف شرکت بی‌داده، پاک‌سازی دومرحله‌ای شرکت داده‌دار.
 *
 * - «بی‌داده» یعنی جز ساختار پایه (نقش، عضو، تنظیم، کدینگ، سال مالی، واحد و …) هیچ رکورد عملیاتی ندارد.
 * - پاک‌سازی شرکت داده‌دار:
 *   ۱) درخواست: شمارش دقیق رکوردها و بررسی موانع. هنوز هیچ داده‌ای حذف نشده است.
 *   ۲) تأیید جداگانه با نام دقیق شرکت و گذرواژهٔ همان کاربر، حداکثر ۲۴ ساعت پس از درخواست. از این لحظه شرکت قفل می‌شود.
 *   ۳) کار دسته‌ای قابل ادامه: ابتدا بایگانی رمزشدهٔ همهٔ رکوردها ساخته و دوباره خوانده و با شمارش زنده تطبیق می‌شود؛
 *      اگر تطبیق نشود، چیزی حذف نمی‌شود و شرکت دوباره فعال می‌شود. سپس حذف دسته‌ای انجام می‌شود.
 * - موانع: دورهٔ نگهداری قانونی (قابل تنظیم مدیر سامانه)، اتصال فعال فروشگاه، رزرو یا سفارش باز، چک در جریان.
 *   در حضور مانع فقط «تعلیق» ممکن است و هیچ داده‌ای حذف نمی‌شود.
 * - گزارش ممیزی شرکت هرگز حذف نمی‌شود.
 */
namespace Agah\Erp\Modules\Company;
use Agah\Erp\Core\{Access,Audit,Business,ConflictException,Crypto,Ctx,Db,ForbiddenException,Jalali,NotFoundException,Schema,ValidationException};
use Agah\Erp\Security\{Auth,RateLimiter};
if(!defined('ABSPATH'))exit;
final class CompanyLifecycleService {
    /** ساختار پایهٔ هر شرکت؛ رکورد در این جدول‌ها شرکت را «داده‌دار» نمی‌کند. */
    const STRUCTURE=['roles','company_members','settings','sequences','fiscal_years','fiscal_periods','accounts','details','units','item_groups','tax_rates','warehouses','cost_dimensions','expense_categories','payroll_params','idempotency','audit_heads','audit_log','connect_requests'];
    /** هرگز حذف نمی‌شوند: سابقهٔ ممیزی. */
    const KEEP=['audit_log','audit_heads'];
    /** ردیف در هر تکهٔ بایگانی؛ فقط آزمون آن را کوچک می‌کند تا ادامه‌پذیری چندتکه‌ای سنجیده شود. */
    public static $chunk=1000;
    const REQUEST_TTL=86400;
    const LABELS=['vouchers'=>'اسناد حسابداری','voucher_lines'=>'ردیف‌های سند','parties'=>'اشخاص','items'=>'کالا و خدمات','invoices'=>'فاکتورها','invoice_lines'=>'ردیف‌های فاکتور','treasury_accounts'=>'حساب‌های بانک و صندوق','treasury_docs'=>'دریافت و پرداخت','treasury_doc_items'=>'ردیف‌های دریافت و پرداخت','cheques'=>'چک‌ها','stock_docs'=>'برگه‌های انبار','stock_moves'=>'گردش انبار','expense_docs'=>'هزینه و درآمد','files'=>'پیوست‌ها','employees'=>'پرسنل','payslips'=>'فیش حقوق','assets'=>'دارایی‌ها','connect_clients'=>'اتصال فروشگاه','connect_orders'=>'سفارش‌های فروشگاه','production_orders'=>'دستورهای تولید','ratio_snapshots'=>'نسخه‌های ثابت نسبت مالی'];
    const STATUS=['requested'=>'در انتظار تأیید','running'=>'در حال اجرا','failed'=>'ناموفق (چیزی حذف نشد)','cancelled'=>'لغوشده','done'=>'انجام شد'];
    private static $tables=null;

    /** جدول‌هایی که ستون company_id دارند؛ از خود پایگاه داده خوانده می‌شود تا جدول تازه از قلم نیفتد. */
    public static function tables(){
        if(self::$tables!==null)return self::$tables;
        $have=array_flip(array_map('strtolower',Db::col('SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME=%s','company_id')));
        $out=[];foreach(Schema::tables() as $t)if(isset($have[strtolower(Db::t($t))]))$out[]=$t;
        return self::$tables=$out;
    }
    /** شمارش رکوردهای شرکت در همهٔ جدول‌ها و موانع حذف. */
    public static function inventory($cid){
        $counts=[];foreach(self::tables() as $t){$n=(int)Db::val('SELECT COUNT(*) FROM '.Db::t($t).' WHERE company_id=%d',$cid);if($n)$counts[$t]=$n;}
        return ['counts'=>$counts,'data'=>array_diff_key($counts,array_flip(self::STRUCTURE)),'blockers'=>self::blockers($cid)];
    }
    public static function blockers($cid){
        $b=[];$now=gmdate('Y-m-d H:i:s');
        if(Db::val('SELECT id FROM '.Db::t('connect_clients')." WHERE company_id=%d AND status='active' LIMIT 1",$cid))$b[]='اتصال فعال فروشگاه وجود دارد؛ ابتدا اتصال‌ها را لغو کنید.';
        if(Db::val('SELECT id FROM '.Db::t('connect_reservations')." WHERE company_id=%d AND (status='paid' OR (status='held' AND expires_at>%s)) LIMIT 1",$cid,$now))$b[]='رزرو فعال فروش اینترنتی وجود دارد.';
        if(Db::val('SELECT id FROM '.Db::t('connect_orders')." WHERE company_id=%d AND (status IN ('paid','review') OR refund_due=1) LIMIT 1",$cid))$b[]='سفارش فروشگاهِ تعیین‌تکلیف‌نشده یا استرداد ثبت‌نشده وجود دارد.';
        if(Db::val('SELECT id FROM '.Db::t('connect_refunds')." WHERE company_id=%d AND status IN ('awaiting','review') LIMIT 1",$cid))$b[]='استرداد فروشگاهِ در انتظار دریافت کالا یا بررسی وجود دارد.';
        if(Db::val('SELECT id FROM '.Db::t('cheques')." WHERE company_id=%d AND status='pending' LIMIT 1",$cid))$b[]='چک در جریان وجود دارد.';
        $years=self::retentionYears();
        if($years>0){$since=(new \DateTimeImmutable(Jalali::today()))->modify('-'.$years.' years')->format('Y-m-d');
            if(Db::val('SELECT id FROM '.Db::t('vouchers')." WHERE company_id=%d AND status IN ('approved','final') AND voucher_date>=%s LIMIT 1",$cid,$since))$b[]='اسناد شماره‌دار این شرکت هنوز در دورهٔ نگهداری قانونی '.$years.' ساله‌اند؛ فقط تعلیق ممکن است.';}
        return $b;
    }
    public static function retentionYears(){return max(0,min(30,(int)get_option('agah_company_retention_years',10)));}
    public static function setRetention($years){
        if(!current_user_can('manage_options'))throw new ForbiddenException();
        if(!is_numeric($years)||(int)$years<0||(int)$years>30)throw new ValidationException(['retention'=>'دورهٔ نگهداری بین ۰ تا ۳۰ سال باشد.']);
        $old=self::retentionYears();update_option('agah_company_retention_years',(int)$years,false);Audit::log('company.retention','platform',0,['years'=>$old],['years'=>(int)$years],0);
    }
    private static function confirmName($cid,$typed){
        $name=(string)Db::val('SELECT name FROM '.Db::t('companies').' WHERE id=%d',$cid);
        if($name===''||trim((string)$typed)!==$name)throw new ValidationException(['confirm_name'=>'نام شرکت را دقیقاً همان‌طور که ثبت شده وارد کنید.']);
        return $name;
    }
    /** بازتأیید هویت پیش از کار برگشت‌ناپذیر. کنترلر آن را پیش از تراکنش صدا می‌زند تا شمارش تلاش ناموفق با بازگشت تراکنش پاک نشود. */
    public static function checkPassword($password){
        $uid=get_current_user_id();
        if(!RateLimiter::consume('company-danger|'.$uid,5,900))throw new ConflictException('تعداد تلاش بیش از حد است؛ پانزده دقیقهٔ دیگر تلاش کنید.');
        $user=get_userdata($uid);if(!$user||!is_string($password)||$password===''||!wp_check_password($password,$user->user_pass,$user->ID))throw new ValidationException(['confirm_password'=>'گذرواژه درست نیست.']);
    }
    private static function serviceUsers($cid){return array_map('intval',Db::col('SELECT service_user_id FROM '.Db::t('connect_clients').' WHERE company_id=%d',$cid));}
    /** کاربر سرویس هر اتصال فقط مخصوص همان شرکت است و با شرکت حذف می‌شود؛ کاربر عادی هرگز حذف نمی‌شود. */
    private static function dropServiceUsers(array $ids){
        require_once ABSPATH.'wp-admin/includes/user.php';
        foreach($ids as $uid)if($uid&&get_user_meta((int)$uid,Auth::SERVICE_META,true))wp_delete_user((int)$uid);
    }

    // ---------------------------------------------------------------- تعلیق

    /** تعلیق: هیچ عضوی (و هیچ فروشگاه متصلی) وارد شرکت نمی‌شود و هیچ داده‌ای حذف نمی‌شود. بازگشایی فقط با مدیر سامانه. */
    public static function suspend($cid,$reason){
        return Access::write($cid,'company_settings.delete',function()use($cid,$reason){
            $reason=Business::reason($reason);
            Db::update('companies',['status'=>'suspended','updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>$cid]);
            Audit::log('company.suspend','company',$cid,['status'=>'active'],['status'=>'suspended','reason'=>$reason],$cid);return true;
        });
    }
    public static function reactivate($cid){
        if(!current_user_can('manage_options'))throw new ForbiddenException();
        return Db::tx(function()use($cid){
            $c=Db::row('SELECT * FROM '.Db::t('companies').' WHERE id=%d FOR UPDATE',$cid);if(!$c)throw new NotFoundException();
            if($c['status']!=='suspended')throw new ConflictException('فقط شرکت تعلیق‌شده بازگشایی می‌شود.');
            Db::update('companies',['status'=>'active','updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>$cid]);
            Audit::log('company.reactivate','company',$cid,['status'=>'suspended'],['status'=>'active'],$cid);return true;
        });
    }

    // ---------------------------------------------------------------- حذف شرکت بی‌داده

    public static function deleteEmpty($cid,$typedName){
        $users=[];
        Access::write($cid,'company_settings.delete',function()use($cid,$typedName,&$users){
            $name=self::confirmName($cid,$typedName);$inv=self::inventory($cid);
            if($inv['data'])throw new ConflictException('این شرکت داده دارد؛ از «پاک‌سازی شرکت داده‌دار» استفاده کنید.');
            if($inv['blockers'])throw new ConflictException(implode(' ',$inv['blockers']));
            $users=self::serviceUsers($cid);
            Db::update('companies',['status'=>'deleting','updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>$cid]);
            Audit::log('company.delete','company',$cid,['name'=>$name,'counts'=>$inv['counts']],null,$cid);
        });
        Ctx::reset();
        while(!self::wipe($cid,microtime(true)+20));
        self::dropServiceUsers($users);
        return true;
    }
    /** اگر حذف شرکت بی‌داده نیمه‌کاره قطع شده باشد، مدیر سامانه آن را تمام می‌کند. */
    public static function resumeDelete($cid){
        if(!current_user_can('manage_options'))throw new ForbiddenException();
        if(Db::val('SELECT status FROM '.Db::t('companies').' WHERE id=%d',$cid)!=='deleting')throw new ConflictException('این شرکت در حال حذف نیست.');
        $users=self::serviceUsers($cid);while(!self::wipe($cid,microtime(true)+20));self::dropServiceUsers($users);
        Audit::log('company.delete.resume','company',$cid,null,null,$cid);return true;
    }

    /**
     * حذف همهٔ رکوردهای شرکت جز ممیزی. بررسی کلید خارجی فقط در همین اتصال و موقتاً خاموش می‌شود:
     * همهٔ کلیدهای خارجی ترکیبیِ همان شرکت‌اند و همهٔ رکوردهای آن شرکت حذف می‌شوند، پس رکورد یتیم نمی‌ماند؛
     * دلیل: حدود ۷۰ کلید خارجی و وابستگی‌های چرخه‌ای، ترتیب حذف ثابت را شکننده می‌کند.
     * true یعنی تمام شد؛ false یعنی زمان این نوبت تمام شد و نوبت بعد ادامه می‌دهد.
     */
    private static function wipe($cid,$deadline,$batch=500){
        Db::raw('SET FOREIGN_KEY_CHECKS=0');
        try{
            Db::exec('DELETE rc FROM '.Db::t('role_caps').' rc JOIN '.Db::t('roles').' r ON r.id=rc.role_id WHERE r.company_id=%d',$cid);
            Db::exec('DELETE n FROM '.Db::t('connect_nonces').' n JOIN '.Db::t('connect_clients').' c ON c.id=n.client_id WHERE c.company_id=%d',$cid);
            // زمان فقط پس از حذف واقعی سنجیده می‌شود تا جدول خالی هر نوبت را هدر ندهد و کار همیشه جلو برود.
            foreach(array_diff(self::tables(),self::KEEP) as $t){
                while(true){
                    $n=(int)Db::exec('DELETE FROM '.Db::t($t).' WHERE company_id=%d LIMIT %d',$cid,$batch);
                    if($n>0&&microtime(true)>$deadline)return false;
                    if($n<$batch)break;
                }
            }
            foreach(array_diff(self::tables(),self::KEEP) as $t)if(Db::val('SELECT 1 FROM '.Db::t($t).' WHERE company_id=%d LIMIT 1',$cid))return false;
            Db::exec('DELETE FROM '.Db::t('companies').' WHERE id=%d',$cid);
            return true;
        }finally{Db::raw('SET FOREIGN_KEY_CHECKS=1');}
    }

    // ---------------------------------------------------------------- پاک‌سازی شرکت داده‌دار

    public static function purges($cid){Access::need($cid,'company_settings.view');return Db::rows('SELECT id,target_company_id,company_name,status,stage,inventory,reason,error,requested_by,confirmed_by,created_at,updated_at FROM '.Db::t('company_purges').' WHERE target_company_id=%d ORDER BY id DESC LIMIT 20',$cid);}
    public static function openRequest($cid){return Db::row('SELECT id,status,inventory,reason,created_at FROM '.Db::t('company_purges')." WHERE target_company_id=%d AND status='requested' ORDER BY id DESC LIMIT 1",$cid);}

    /** مرحلهٔ ۱: درخواست با شمارش و بررسی موانع؛ هیچ داده‌ای حذف نمی‌شود. */
    public static function requestPurge($cid,$typedName,$reason){
        return Access::write($cid,'company_settings.delete',function()use($cid,$typedName,$reason){
            $name=self::confirmName($cid,$typedName);$reason=Business::reason($reason);$inv=self::inventory($cid);
            if($inv['blockers'])throw new ConflictException(implode(' ',$inv['blockers']));
            if(Db::val('SELECT id FROM '.Db::t('company_purges')." WHERE target_company_id=%d AND status IN ('requested','running') LIMIT 1",$cid))throw new ConflictException('درخواست پاک‌سازی باز برای این شرکت وجود دارد.');
            $now=gmdate('Y-m-d H:i:s');
            $id=Db::insert('company_purges',['target_company_id'=>$cid,'company_name'=>$name,'status'=>'requested','inventory'=>wp_json_encode($inv['counts']),'reason'=>$reason,'requested_by'=>Ctx::uid(),'created_at'=>$now,'updated_at'=>$now]);
            Audit::log('company.purge.request','company',$cid,null,['purge'=>$id,'counts'=>$inv['counts'],'reason'=>$reason],$cid);
            \Agah\Erp\Modules\Notify\NotificationService::notify($cid,\Agah\Erp\Modules\Notify\NotificationService::holders($cid,'company_settings.delete'),'sensitive','درخواست پاک‌سازی کامل شرکت ثبت شد',$reason,agah_erp_url(['c'=>$cid,'p'=>'settings']),'purge|'.$id,true);return $id;
        });
    }
    public static function cancelPurge($cid,$id){
        return Access::write($cid,'company_settings.delete',function()use($cid,$id){
            $p=Db::row('SELECT * FROM '.Db::t('company_purges').' WHERE id=%d AND target_company_id=%d FOR UPDATE',$id,$cid);if(!$p)throw new NotFoundException();
            if($p['status']!=='requested')throw new ConflictException('فقط درخواست تأییدنشده لغو می‌شود.');
            Db::update('company_purges',['status'=>'cancelled','updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>$id]);Audit::log('company.purge.cancel','company',$cid,null,['purge'=>$id],$cid);return true;
        });
    }
    /** مرحلهٔ ۲: تأیید با نام شرکت و گذرواژه؛ شرکت قفل می‌شود و کار دسته‌ای شروع می‌شود. */
    public static function confirmPurge($cid,$id,$typedName,$password){
        Access::need($cid,'company_settings.delete');self::checkPassword($password);
        Access::write($cid,'company_settings.delete',function()use($cid,$id,$typedName){
            self::confirmName($cid,$typedName);
            $p=Db::row('SELECT * FROM '.Db::t('company_purges').' WHERE id=%d AND target_company_id=%d FOR UPDATE',$id,$cid);
            if(!$p||$p['status']!=='requested')throw new ConflictException('درخواست پاک‌سازی آمادهٔ تأیید نیست.');
            if(strtotime($p['created_at'].' UTC')<time()-self::REQUEST_TTL)throw new ConflictException('درخواست بیش از ۲۴ ساعت پیش ثبت شده است؛ آن را لغو و دوباره درخواست کنید.');
            if($b=self::blockers($cid))throw new ConflictException(implode(' ',$b));
            Db::update('company_purges',['status'=>'running','stage'=>'archive','manifest'=>'{}','service_users'=>wp_json_encode(self::serviceUsers($cid)),'confirmed_by'=>Ctx::uid(),'updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>$id]);
            Db::update('companies',['status'=>'purging','updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>$cid]);
            Audit::log('company.purge.confirm','company',$cid,null,['purge'=>$id],$cid);
        });
        // اجرای واقعی بیرون از تراکنش درخواست انجام می‌شود (WP-Cron، صفحهٔ ورود کاربر یا دکمهٔ مدیر سامانه)؛
        // چون درخواست وب ممکن است وسط کار قطع شود و همهٔ کار دسته‌ای نباید به یک تراکنش طولانی وابسته باشد.
        if(!wp_next_scheduled('agah_company_purge',[(int)$id]))wp_schedule_single_event(time()+30,'agah_company_purge',[(int)$id]);
        return true;
    }

    /** مرحلهٔ ۳: یک نوبت از کار دسته‌ای. هر نوبت از همان‌جا که مانده ادامه می‌دهد؛ دو نوبت هم‌زمان اجرا نمی‌شوند. */
    public static function run($id,$seconds=20){
        $id=(int)$id;$lock='agah_purge_'.substr(hash('sha256',Db::t('company_purges')),0,16).'_'.$id;
        if(1!==(int)Db::val('SELECT GET_LOCK(%s,0)',$lock))return false;
        try{
            $p=Db::row('SELECT * FROM '.Db::t('company_purges').' WHERE id=%d',$id);
            if(!$p||$p['status']!=='running')return $p&&$p['status']==='done';
            $deadline=microtime(true)+max(0,(float)$seconds);$cid=(int)$p['target_company_id'];
            if($p['stage']==='archive'){
                if(!self::archiveStep($p,$deadline))return self::later($id);
                $p=Db::row('SELECT * FROM '.Db::t('company_purges').' WHERE id=%d',$id);
                if($error=self::verify($p)){self::fail($p,$error);return false;}
                Db::update('company_purges',['stage'=>'wipe','updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>$id]);
                Audit::log('company.purge.archived','company',$cid,null,['purge'=>$id,'manifest'=>json_decode((string)$p['manifest'],true)],$cid);
            }
            if(!self::wipe($cid,$deadline))return self::later($id);
            self::dropServiceUsers((array)json_decode((string)$p['service_users'],true));
            Db::update('company_purges',['status'=>'done','stage'=>'','updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>$id]);
            Audit::log('company.purge.done','company',$cid,['name'=>$p['company_name']],['purge'=>$id],$cid);
            return true;
        }finally{Db::val('SELECT RELEASE_LOCK(%s)',$lock);}
    }
    private static function later($id){
        Db::update('company_purges',['updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>$id]);
        if(!wp_next_scheduled('agah_company_purge',[$id]))wp_schedule_single_event(time()+60,'agah_company_purge',[$id]);
        return false;
    }
    /** ادامهٔ همهٔ پاک‌سازی‌های نیمه‌کاره (پشتیبان WP-Cron روزانه). */
    public static function resumeAll(){foreach(Db::col('SELECT id FROM '.Db::t('company_purges')." WHERE status='running'") as $id)self::run((int)$id,20);}

    /** فهرست بایگانی: همهٔ جدول‌های شرکتی جز ممیزی (که خودش حذف نمی‌شود)، به‌علاوهٔ مجوزهای نقش و ردیف خود شرکت. */
    private static function archiveList(){return array_merge(array_values(array_diff(self::tables(),self::KEEP)),['role_caps','companies']);}
    private static function primaryKey($t){
        $cols=Db::col('SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND CONSTRAINT_NAME=%s ORDER BY ORDINAL_POSITION',Db::t($t),'PRIMARY');
        if(!$cols)throw new ConflictException('کلید اصلی جدول '.$t.' پیدا نشد.');
        foreach($cols as $c)if(!preg_match('/^[a-z0-9_]+$/D',$c))throw new ConflictException('نام ستون نامعتبر.');
        return '`'.implode('`,`',$cols).'`';
    }
    private static function select($t,$cid,$offset){
        if($t==='companies')return $offset?[]:Db::rows('SELECT * FROM '.Db::t('companies').' WHERE id=%d',$cid);
        if($t==='role_caps')return Db::rows('SELECT rc.* FROM '.Db::t('role_caps').' rc JOIN '.Db::t('roles').' r ON r.id=rc.role_id WHERE r.company_id=%d ORDER BY rc.role_id,rc.cap LIMIT %d OFFSET %d',$cid,self::$chunk,$offset);
        return Db::rows('SELECT * FROM '.Db::t($t).' WHERE company_id=%d ORDER BY '.self::primaryKey($t).' LIMIT %d OFFSET %d',$cid,self::$chunk,$offset);
    }
    private static function liveCount($t,$cid){
        if($t==='companies')return (int)Db::val('SELECT COUNT(*) FROM '.Db::t('companies').' WHERE id=%d',$cid);
        if($t==='role_caps')return (int)Db::val('SELECT COUNT(*) FROM '.Db::t('role_caps').' rc JOIN '.Db::t('roles').' r ON r.id=rc.role_id WHERE r.company_id=%d',$cid);
        return (int)Db::val('SELECT COUNT(*) FROM '.Db::t($t).' WHERE company_id=%d',$cid);
    }
    /** متن ساده و قطعی یک تکه؛ رشتهٔ غیر UTF-8 (مثل محتوای پیوست) به base64 نشانه‌دار تبدیل می‌شود. */
    private static function encode(array $rows){
        foreach($rows as &$r)foreach($r as $k=>$v)if(is_string($v)&&!mb_check_encoding($v,'UTF-8'))$r[$k]=['b64'=>base64_encode($v)];unset($r);
        $plain=wp_json_encode($rows,JSON_UNESCAPED_UNICODE);if(!is_string($plain))throw new ConflictException('ساخت بایگانی ناموفق بود.');return $plain;
    }
    private static function archiveStep(array $p,$deadline){
        $id=(int)$p['id'];$cid=(int)$p['target_company_id'];$manifest=json_decode((string)$p['manifest'],true)?:[];$list=self::archiveList();
        foreach($list as $t){
            $m=$manifest[$t]??['rows'=>0,'chunks'=>0,'done'=>false];if($m['done'])continue;
            while(true){
                $rows=self::select($t,$cid,$m['rows']);
                if(!$rows){$m['done']=true;$manifest[$t]=$m;break;}
                $plain=self::encode($rows);
                Db::exec('DELETE FROM '.Db::t('company_archive_chunks').' WHERE purge_id=%d AND table_name=%s AND chunk_no=%d',$id,$t,$m['chunks']);
                Db::insert('company_archive_chunks',['purge_id'=>$id,'table_name'=>$t,'chunk_no'=>$m['chunks'],'row_count'=>count($rows),'sha256'=>hash('sha256',$plain),'payload'=>Crypto::encrypt(gzcompress($plain,6))]);
                $m['rows']+=count($rows);$m['chunks']++;
                if(count($rows)<self::$chunk||$t==='companies')$m['done']=true;
                $manifest[$t]=$m;Db::update('company_purges',['manifest'=>wp_json_encode($manifest),'updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>$id]);
                if(microtime(true)>$deadline)return $m['done']&&$t===end($list);
                if($m['done'])break;
            }
            Db::update('company_purges',['manifest'=>wp_json_encode($manifest)],['id'=>$id]);
        }
        return true;
    }
    /** هر تکه دوباره خوانده، رمزگشایی و با اثر انگشت مقایسه می‌شود؛ جمع ردیف‌ها باید با شمارش زندهٔ شرکت قفل‌شده برابر باشد. */
    private static function verify(array $p){
        $id=(int)$p['id'];$cid=(int)$p['target_company_id'];$manifest=json_decode((string)$p['manifest'],true)?:[];
        foreach(self::archiveList() as $t){
            $m=$manifest[$t]??null;if(!$m||empty($m['done']))return 'بایگانی جدول '.$t.' کامل نیست.';
            $sum=0;
            for($n=0;$n<(int)$m['chunks'];$n++){
                $c=Db::row('SELECT row_count,sha256,payload FROM '.Db::t('company_archive_chunks').' WHERE purge_id=%d AND table_name=%s AND chunk_no=%d',$id,$t,$n);
                $plain=$c?@gzuncompress((string)Crypto::decrypt($c['payload'])):false;
                if(!is_string($plain)||!hash_equals($c['sha256'],hash('sha256',$plain))||count((array)json_decode($plain,true))!==(int)$c['row_count'])return 'تکهٔ '.$n.' جدول '.$t.' سالم خوانده نشد.';
                $sum+=(int)$c['row_count'];
            }
            if($sum!==(int)$m['rows']||$sum!==self::liveCount($t,$cid))return 'شمار ردیف‌های بایگانی جدول '.$t.' با داده برابر نیست.';
        }
        return null;
    }
    /** شکست پیش از حذف: بایگانی ناقص پاک و شرکت دوباره فعال می‌شود؛ هیچ داده‌ای حذف نشده است. */
    private static function fail(array $p,$error){
        Db::exec('DELETE FROM '.Db::t('company_archive_chunks').' WHERE purge_id=%d',(int)$p['id']);
        Db::update('company_purges',['status'=>'failed','stage'=>'','error'=>mb_substr($error,0,500),'updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>(int)$p['id']]);
        Db::update('companies',['status'=>'active','updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>(int)$p['target_company_id']]);
        Audit::log('company.purge.failed','company',(int)$p['target_company_id'],null,['purge'=>(int)$p['id'],'error'=>$error],(int)$p['target_company_id']);
    }

    // ---------------------------------------------------------------- مدیر سامانه

    public static function allPurges(){if(!current_user_can('manage_options'))throw new ForbiddenException();return Db::rows('SELECT p.id,p.target_company_id,p.company_name,p.status,p.stage,p.manifest,p.error,p.archive_deleted_at,p.created_at,p.updated_at,(SELECT COUNT(*) FROM '.Db::t('company_archive_chunks').' a WHERE a.purge_id=p.id) AS chunks FROM '.Db::t('company_purges').' p ORDER BY p.id DESC LIMIT 50');}
    /** بایگانی فقط پس از پایان یا شکست، با گذرواژه و نام شرکت، و با ثبت در ممیزی حذف می‌شود. */
    public static function deleteArchive($purgeId,$typedName,$password){
        if(!current_user_can('manage_options'))throw new ForbiddenException();self::checkPassword($password);
        return Db::tx(function()use($purgeId,$typedName){
            $p=Db::row('SELECT * FROM '.Db::t('company_purges').' WHERE id=%d FOR UPDATE',$purgeId);if(!$p)throw new NotFoundException();
            if(!in_array($p['status'],['done','failed','cancelled'],true))throw new ConflictException('بایگانی پاک‌سازی در حال اجرا حذف نمی‌شود.');
            if(trim((string)$typedName)!==$p['company_name'])throw new ValidationException(['confirm_name'=>'نام شرکت را دقیقاً وارد کنید.']);
            $n=Db::exec('DELETE FROM '.Db::t('company_archive_chunks').' WHERE purge_id=%d',$purgeId);
            Db::update('company_purges',['archive_deleted_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>$purgeId]);
            Audit::log('company.archive.delete','company',(int)$p['target_company_id'],null,['purge'=>(int)$purgeId,'chunks'=>(int)$n],(int)$p['target_company_id']);return true;
        });
    }
    /** شرکت‌های غیرفعال کاربر (تعلیق، در حال حذف) و پاک‌سازی‌های اخیر او، برای صفحهٔ ورود. */
    public static function closedFor($uid){
        $companies=Db::rows('SELECT c.id,c.name,c.status FROM '.Db::t('company_members').' m JOIN '.Db::t('companies')." c ON c.id=m.company_id WHERE m.user_id=%d AND m.status='active' AND c.status<>'active' ORDER BY c.name",$uid);
        $purges=Db::rows('SELECT id,company_name,status,stage,error,updated_at FROM '.Db::t('company_purges')." WHERE (requested_by=%d OR confirmed_by=%d) AND status IN ('running','failed','done') AND updated_at>=%s ORDER BY id DESC LIMIT 10",$uid,$uid,gmdate('Y-m-d H:i:s',time()-30*86400));
        return ['companies'=>$companies,'purges'=>$purges];
    }
}
