<?php
/**
 * تحویل، بازپس‌گیری و انتقال اموال (AST-01..05).
 * - هر «دورهٔ تحویل» یک رکورد است: متصدی (کارمند، واحد/محل یا انبار)، تاریخ مؤثر، تحویل‌دهنده و گیرنده، رسید شماره‌دار با کد تأیید.
 * - در هر لحظه حداکثر یک متصدی جاری (کلید یکتای active_slot) و تاریخ هر انتقال از دورهٔ قبلی عقب‌تر نیست؛ پس هیچ تاریخی دو متصدی ندارد.
 * - انتقال هیچ اثری بر بهای تمام‌شده، استهلاک، وضعیت یا سند دارایی ندارد (سند حسابداری نمی‌سازد).
 * - تاریخچه هرگز حذف نمی‌شود؛ اشتباه فقط با ابطال آخرین اقدام (با دلیل و ممیزی) اصلاح می‌شود.
 */
namespace Agah\Erp\Modules\Assets;
use Agah\Erp\Core\{Access,Audit,Business,ConflictException,Crypto,Ctx,Db,Jalali,Money,NotFoundException,Request,Sequence,ValidationException};
if(!defined('ABSPATH'))exit;
final class AssetCustodyService {
    const HOLDERS=['employee'=>'کارمند','place'=>'واحد / محل','warehouse'=>'انبار'];
    const PLACE_KINDS=['unit'=>'واحد سازمانی','site'=>'محل'];
    const END_KINDS=['transfer'=>'انتقال','return'=>'بازپس‌گیری','disposal'=>'واگذاری دارایی'];

    // ------------------------------------------------------------ دسته و محل

    public static function categories($cid,$active=false){Access::need($cid,'asset.view');return Db::rows('SELECT * FROM '.Db::t('asset_categories').' WHERE company_id=%d'.($active?' AND is_active=1':'').' ORDER BY code',$cid);}
    public static function places($cid,$active=false){Access::need($cid,'asset.view');return Db::rows('SELECT * FROM '.Db::t('asset_places').' WHERE company_id=%d'.($active?' AND is_active=1':'').' ORDER BY code',$cid);}
    private static function codeName(array $in){
        $code=Request::clean(Money::normDigits($in['code']??''),30);$name=Request::clean($in['name']??'',190);
        if(!preg_match('/^[A-Za-z0-9_-]{1,30}$/D',$code))throw new ValidationException(['code'=>'کد فقط حروف لاتین، عدد، خط تیره و زیرخط (حداکثر ۳۰).']);
        if(mb_strlen($name)<2)throw new ValidationException(['name'=>'نام حداقل دو نویسه باشد.']);return [$code,$name];
    }
    public static function saveCategory($cid,$id,array $in){
        return Access::write($cid,'asset.edit',function()use($cid,$id,$in){
            $old=$id?Business::row('asset_categories',$cid,$id,true):null;[$code,$name]=self::codeName($in);
            if(Db::val('SELECT id FROM '.Db::t('asset_categories').' WHERE company_id=%d AND code=%s AND id<>%d',$cid,$code,(int)$id))throw new ValidationException(['code'=>'کد دسته تکراری است.']);
            $new=['code'=>$code,'name'=>$name,'is_active'=>empty($in['is_active'])?0:1];$id=Business::save('asset_categories',$cid,$id,$new);Audit::log('asset.category.save','asset_category',$id,$old,$new,$cid);return $id;
        });
    }
    public static function deleteCategory($cid,$id){
        return Access::write($cid,'asset.edit',function()use($cid,$id){
            $r=Business::row('asset_categories',$cid,$id,true);if(Db::val('SELECT id FROM '.Db::t('assets').' WHERE company_id=%d AND category_id=%d LIMIT 1',$cid,$id))throw new ConflictException('این دسته به دارایی داده شده است؛ غیرفعالش کنید.');
            Db::delete('asset_categories',['company_id'=>$cid,'id'=>$id]);Audit::log('asset.category.delete','asset_category',$id,$r,null,$cid);return true;
        });
    }
    public static function setCategory($cid,$asset,$category){
        return Access::write($cid,'asset.edit',function()use($cid,$asset,$category){
            $a=Business::row('assets',$cid,$asset,true);$c=$category?Business::row('asset_categories',$cid,$category):null;if($c&&!(int)$c['is_active'])throw new ValidationException(['category_id'=>'دستهٔ فعال انتخاب کنید.']);
            Db::update('assets',['category_id'=>$c?(int)$c['id']:null,'updated_at'=>gmdate('Y-m-d H:i:s')],['company_id'=>$cid,'id'=>$asset]);Audit::log('asset.category.set','asset',$asset,['category_id'=>$a['category_id']],['category_id'=>$c?(int)$c['id']:null],$cid);return true;
        });
    }
    public static function savePlace($cid,$id,array $in){
        return Access::write($cid,'asset.edit',function()use($cid,$id,$in){
            $old=$id?Business::row('asset_places',$cid,$id,true):null;[$code,$name]=self::codeName($in);$kind=$in['kind']??'site';if(!isset(self::PLACE_KINDS[$kind]))throw new ValidationException(['kind'=>'نوع معتبر نیست.']);
            if(Db::val('SELECT id FROM '.Db::t('asset_places').' WHERE company_id=%d AND code=%s AND id<>%d',$cid,$code,(int)$id))throw new ValidationException(['code'=>'کد تکراری است.']);
            $new=['kind'=>$kind,'code'=>$code,'name'=>$name,'is_active'=>empty($in['is_active'])?0:1];
            if($old&&!$new['is_active']&&Db::val('SELECT id FROM '.Db::t('asset_custody')." WHERE company_id=%d AND holder_type='place' AND holder_id=%d AND status='active' LIMIT 1",$cid,$id))throw new ConflictException('اموالی نزد این واحد/محل است؛ ابتدا آن‌ها را منتقل کنید.');
            $id=Business::save('asset_places',$cid,$id,$new);Audit::log('asset.place.save','asset_place',$id,$old,$new,$cid);return $id;
        });
    }
    public static function deletePlace($cid,$id){
        return Access::write($cid,'asset.edit',function()use($cid,$id){
            $r=Business::row('asset_places',$cid,$id,true);if(Db::val('SELECT id FROM '.Db::t('asset_custody')." WHERE company_id=%d AND holder_type='place' AND holder_id=%d LIMIT 1",$cid,$id))throw new ConflictException('این واحد/محل در تاریخچهٔ تحویل آمده است؛ غیرفعالش کنید.');
            Db::delete('asset_places',['company_id'=>$cid,'id'=>$id]);Audit::log('asset.place.delete','asset_place',$id,$r,null,$cid);return true;
        });
    }

    // ------------------------------------------------------------ متصدی

    /** گزینه‌های فعال هر نوع متصدی: نوع => [شناسه => نام] */
    public static function holders($cid){
        Access::need($cid,'asset.view');$out=[];
        foreach(Db::rows('SELECT id,code,name FROM '.Db::t('employees').' WHERE company_id=%d AND is_active=1 ORDER BY name',$cid) as $r)$out['employee'][(int)$r['id']]=$r['name'].' ('.$r['code'].')';
        foreach(Db::rows('SELECT id,code,name,kind FROM '.Db::t('asset_places').' WHERE company_id=%d AND is_active=1 ORDER BY code',$cid) as $r)$out['place'][(int)$r['id']]=$r['name'].' — '.self::PLACE_KINDS[$r['kind']];
        foreach(Db::rows('SELECT id,code,name FROM '.Db::t('warehouses').' WHERE company_id=%d AND is_active=1 ORDER BY code',$cid) as $r)$out['warehouse'][(int)$r['id']]='انبار '.$r['name'];
        return $out;
    }
    private static function holder($cid,$type,$id){
        $table=['employee'=>'employees','place'=>'asset_places','warehouse'=>'warehouses'][$type]??null;if(!$table)throw new ValidationException(['holder_type'=>'نوع متصدی معتبر نیست.']);
        $r=Business::row($table,$cid,Business::id($id));if(!(int)$r['is_active'])throw new ValidationException(['holder_id'=>'متصدی انتخاب‌شده غیرفعال است.']);
        return ['type'=>$type,'id'=>(int)$r['id'],'name'=>($type==='warehouse'?'انبار ':'').$r['name']];
    }
    public static function current($cid,$asset,$lock=false){return Db::row('SELECT * FROM '.Db::t('asset_custody')." WHERE company_id=%d AND asset_id=%d AND status='active'".($lock?' FOR UPDATE':''),$cid,$asset)?:null;}
    private static function sign($cid,array $a,$parts){return Crypto::blind('asset-receipt|'.$cid.'|'.(int)$a['id'].'|'.$a['code'].'|'.implode('|',$parts));}
    public static function code($hash){return strtoupper(implode('-',str_split(substr((string)$hash,0,16),4)));}

    /**
     * تحویل یا انتقال به متصدی تازه، یا بازپس‌گیری (بدون متصدی تازه).
     * $in: effective_date (Y-m-d)، holder_type/holder_id (خالی = بازپس‌گیری)، delivered_by، received_by، note.
     */
    public static function move($cid,$asset,array $in){
        return Access::write($cid,'asset.edit',function()use($cid,$asset,$in){
            $a=Business::row('assets',$cid,$asset,true);if($a['status']!=='active')throw new ConflictException('فقط دارایی فعال تحویل یا منتقل می‌شود.');
            $date=Business::date($in['effective_date']??'');if($date<$a['acquired_date'])throw new ValidationException(['effective_date'=>'تاریخ تحویل پیش از تاریخ خرید دارایی است.']);if($date>Jalali::today())throw new ValidationException(['effective_date'=>'تاریخ تحویل در آینده نیست.']);
            $cur=self::current($cid,$asset,true);$type=(string)($in['holder_type']??'');$to=$type===''?null:self::holder($cid,$type,$in['holder_id']??0);
            if(!$cur&&!$to)throw new ValidationException(['holder_id'=>'دارایی متصدی ندارد؛ متصدی تازه را انتخاب کنید.']);
            if($cur&&$to&&$cur['holder_type']===$to['type']&&(int)$cur['holder_id']===$to['id'])throw new ConflictException('دارایی هم‌اکنون نزد همین متصدی است.');
            $last=Db::val('SELECT MAX(GREATEST(effective_date,COALESCE(ended_date,effective_date))) FROM '.Db::t('asset_custody')." WHERE company_id=%d AND asset_id=%d AND status<>'void'",$cid,$asset);
            if($last&&$date<$last)throw new ValidationException(['effective_date'=>'تاریخ نباید پیش از آخرین جابه‌جایی ('.Jalali::format($last).') باشد.']);
            $deliveredDefault=$cur?$cur['holder_name']:'اموال شرکت (بدون متصدی)';$note=Request::clean($in['note']??'',300);
            $delivered=Request::clean($in['delivered_by']??'',190)?:$deliveredDefault;$now=gmdate('Y-m-d H:i:s');$result=['ended'=>null,'started'=>null];
            if($cur){
                $end=['status'=>'ended','active_slot'=>null,'ended_date'=>$date,'end_kind'=>$to?'transfer':'return','updated_at'=>$now];
                if(!$to){$no=Sequence::next($cid,'asset_receipt',0);$recv=Request::clean($in['received_by']??'',190)?:'اموال شرکت';$end+=['return_receipt_no'=>$no,'return_received_by'=>$recv,'return_hash'=>self::sign($cid,$a,['return',$no,$cur['holder_type'],$cur['holder_id'],$cur['holder_name'],$date,$delivered,$recv])];}
                Db::update('asset_custody',$end,['company_id'=>$cid,'id'=>$cur['id']]);$result['ended']=(int)$cur['id'];
            }
            if($to){
                $no=Sequence::next($cid,'asset_receipt',0);$recv=Request::clean($in['received_by']??'',190)?:$to['name'];
                $result['started']=Business::save('asset_custody',$cid,0,['asset_id'=>(int)$asset,'holder_type'=>$to['type'],'holder_id'=>$to['id'],'holder_name'=>$to['name'],'effective_date'=>$date,'status'=>'active','active_slot'=>1,'receipt_no'=>$no,'delivered_by'=>$delivered,'received_by'=>$recv,'note'=>$note,'receipt_hash'=>self::sign($cid,$a,['deliver',$no,$to['type'],$to['id'],$to['name'],$date,$delivered,$recv]),'prev_id'=>$cur?(int)$cur['id']:null,'created_by'=>Ctx::uid()]);
            }
            Audit::log($to?($cur?'asset.custody.transfer':'asset.custody.assign'):'asset.custody.return','asset',$asset,$cur?['holder'=>$cur['holder_type'].':'.$cur['holder_id'],'custody'=>(int)$cur['id']]:null,['holder'=>$to?$to['type'].':'.$to['id']:null,'date'=>$date,'custody'=>$result['started'],'note'=>$note],$cid);
            return $result;
        });
    }
    /** ابطال آخرین اقدام دارایی با دلیل: تحویل/انتقال آخر باطل و دورهٔ قبلی دوباره جاری می‌شود؛ بازپس‌گیری آخر برگردانده می‌شود. */
    public static function undoLast($cid,$asset,$reason){
        return Access::write($cid,'asset.delete',function()use($cid,$asset,$reason){
            $a=Business::row('assets',$cid,$asset,true);$reason=Business::reason($reason);
            $last=Db::row('SELECT * FROM '.Db::t('asset_custody')." WHERE company_id=%d AND asset_id=%d AND status<>'void' ORDER BY id DESC LIMIT 1 FOR UPDATE",$cid,$asset);if(!$last)throw new NotFoundException();
            $now=gmdate('Y-m-d H:i:s');
            if($last['status']==='ended'){
                if($last['end_kind']!=='return')throw new ConflictException('آخرین اقدام قابل ابطال نیست.');if($a['status']!=='active')throw new ConflictException('دارایی فعال نیست.');
                Db::update('asset_custody',['status'=>'active','active_slot'=>1,'ended_date'=>null,'end_kind'=>null,'return_receipt_no'=>null,'return_received_by'=>'','return_hash'=>'','updated_at'=>$now],['company_id'=>$cid,'id'=>$last['id']]);
                Audit::log('asset.custody.undo_return','asset',$asset,['custody'=>(int)$last['id'],'return_receipt'=>(int)$last['return_receipt_no']],['reason'=>$reason],$cid);return true;
            }
            Db::update('asset_custody',['status'=>'void','active_slot'=>null,'void_reason'=>$reason,'updated_at'=>$now],['company_id'=>$cid,'id'=>$last['id']]);
            if($last['prev_id']){$p=Business::row('asset_custody',$cid,$last['prev_id'],true);if($p['status']==='ended'&&$p['end_kind']==='transfer')Db::update('asset_custody',['status'=>'active','active_slot'=>1,'ended_date'=>null,'end_kind'=>null,'updated_at'=>$now],['company_id'=>$cid,'id'=>$p['id']]);}
            Audit::log('asset.custody.void','asset',$asset,['custody'=>(int)$last['id'],'receipt'=>(int)$last['receipt_no']],['reason'=>$reason],$cid);return true;
        });
    }
    /** واگذاری دارایی (فروش/اسقاط) دورهٔ جاری را می‌بندد؛ از AssetService::dispose داخل همان تراکنش صدا زده می‌شود. */
    public static function endOnDisposal($cid,$asset,$date){
        $cur=self::current($cid,$asset,true);if(!$cur)return;
        Db::update('asset_custody',['status'=>'ended','active_slot'=>null,'ended_date'=>max($date,$cur['effective_date']),'end_kind'=>'disposal','updated_at'=>gmdate('Y-m-d H:i:s')],['company_id'=>$cid,'id'=>$cur['id']]);
    }

    // ------------------------------------------------------------ گزارش‌ها (AST-05)

    private static function base(){return 'SELECT c.*,a.code AS asset_code,a.name AS asset_name,a.cost,a.status AS asset_status,g.name AS category_name,a.cost-COALESCE((SELECT SUM(e.amount) FROM '.Db::t('asset_events')." e WHERE e.company_id=a.company_id AND e.asset_id=a.id AND e.kind='depreciation' AND e.status='issued'),0) AS book_value FROM ".Db::t('asset_custody').' c JOIN '.Db::t('assets').' a ON a.company_id=c.company_id AND a.id=c.asset_id LEFT JOIN '.Db::t('asset_categories').' g ON g.company_id=a.company_id AND g.id=a.category_id';}
    /** اقلام جاری نزد یک متصدی، یا همهٔ اقلام جاری گروه‌بندی‌شده بر حسب متصدی. */
    public static function holdings($cid,$type='',$holder=0){
        Access::need($cid,'asset.view');$where=" WHERE c.company_id=%d AND c.status='active'";$args=[$cid];
        if($type!==''){if(!isset(self::HOLDERS[$type]))throw new ValidationException(['holder_type'=>'نوع متصدی معتبر نیست.']);$where.=' AND c.holder_type=%s';$args[]=$type;if($holder){$where.=' AND c.holder_id=%d';$args[]=(int)$holder;}}
        return Db::rows(self::base().$where.' ORDER BY c.holder_type,c.holder_name,a.code LIMIT 5000',...$args);
    }
    /** دارایی‌های فعال بدون متصدی جاری. */
    public static function unassigned($cid){
        Access::need($cid,'asset.view');
        return Db::rows('SELECT a.id,a.code,a.name,a.acquired_date,a.cost,g.name AS category_name,(SELECT MAX(x.ended_date) FROM '.Db::t('asset_custody')." x WHERE x.company_id=a.company_id AND x.asset_id=a.id AND x.status='ended') AS last_return FROM ".Db::t('assets').' a LEFT JOIN '.Db::t('asset_categories')." g ON g.company_id=a.company_id AND g.id=a.category_id WHERE a.company_id=%d AND a.status='active' AND NOT EXISTS (SELECT 1 FROM ".Db::t('asset_custody')." c WHERE c.company_id=a.company_id AND c.asset_id=a.id AND c.status='active') ORDER BY a.code LIMIT 5000",$cid);
    }
    /** تاریخچهٔ قابل جست‌وجو: دارایی، نوع/متصدی، بازهٔ تاریخ و متن (نام دارایی، متصدی، تحویل‌دهنده/گیرنده، یادداشت). */
    public static function history($cid,array $f=[],$page=1){
        Access::need($cid,'asset.view');global $wpdb;$where=' WHERE c.company_id=%d';$args=[$cid];
        if(!empty($f['asset_id'])){$where.=' AND c.asset_id=%d';$args[]=(int)$f['asset_id'];}
        if(!empty($f['holder_type'])){if(!isset(self::HOLDERS[$f['holder_type']]))throw new ValidationException(['holder_type'=>'نوع متصدی معتبر نیست.']);$where.=' AND c.holder_type=%s';$args[]=$f['holder_type'];if(!empty($f['holder_id'])){$where.=' AND c.holder_id=%d';$args[]=(int)$f['holder_id'];}}
        if(!empty($f['from'])){$where.=' AND COALESCE(c.ended_date,%s)>=%s';$args[]='9999-12-31';$args[]=Business::date($f['from']);}
        if(!empty($f['to'])){$where.=' AND c.effective_date<=%s';$args[]=Business::date($f['to']);}
        if(!empty($f['q'])){$like='%'.$wpdb->esc_like(Request::clean($f['q'],100)).'%';$where.=' AND (a.code LIKE %s OR a.name LIKE %s OR c.holder_name LIKE %s OR c.delivered_by LIKE %s OR c.received_by LIKE %s OR c.note LIKE %s)';array_push($args,$like,$like,$like,$like,$like,$like);}
        $page=max(1,(int)$page);$n=(int)Db::val('SELECT COUNT(*) FROM '.Db::t('asset_custody').' c JOIN '.Db::t('assets').' a ON a.company_id=c.company_id AND a.id=c.asset_id'.$where,...$args);
        return ['rows'=>Db::rows(self::base().$where.' ORDER BY c.effective_date DESC,c.id DESC LIMIT 100 OFFSET %d',...array_merge($args,[($page-1)*100])),'count'=>$n,'page'=>$page,'pages'=>(int)ceil($n/100)];
    }
    /** فهرست تسویه‌حساب کارمند: اقلام جاری (باید بازپس گرفته شوند) و همهٔ سوابق او. */
    public static function clearance($cid,$employee){
        Access::need($cid,'asset.view');$e=Business::row('employees',$cid,$employee);
        return ['employee'=>$e,'held'=>self::holdings($cid,'employee',(int)$e['id']),'history'=>self::history($cid,['holder_type'=>'employee','holder_id'=>(int)$e['id']])['rows']];
    }
    public static function receipt($cid,$id){Access::need($cid,'asset.view');$r=Db::row(self::base().' WHERE c.company_id=%d AND c.id=%d',$cid,(int)$id);if(!$r)throw new NotFoundException();return $r;}
    /** بررسی رسید: شماره و کد تأیید باید با رکورد ثبت‌شده و امضای آن برابر باشد. */
    public static function verify($cid,$number,$code){
        Access::need($cid,'asset.view');$number=(int)Money::normDigits($number);$code=strtoupper(preg_replace('/[^0-9A-Fa-f]/','',Money::normDigits($code)));
        if(!$number||strlen($code)!==16)return null;
        foreach(Db::rows('SELECT id,asset_id,receipt_no,receipt_hash,return_receipt_no,return_hash,status FROM '.Db::t('asset_custody').' WHERE company_id=%d AND (receipt_no=%d OR return_receipt_no=%d)',$cid,$number,$number) as $r){
            $hash=(int)$r['receipt_no']===$number?$r['receipt_hash']:$r['return_hash'];if(hash_equals(strtoupper(substr($hash,0,16)),$code))return $r+['kind'=>(int)$r['receipt_no']===$number?'deliver':'return'];
        }
        return null;
    }
    public static function assets($cid){Access::need($cid,'asset.view');return Db::rows('SELECT a.id,a.code,a.name,a.status,a.category_id FROM '.Db::t('assets')." a WHERE a.company_id=%d AND a.status='active' ORDER BY a.code LIMIT 5000",$cid);}
}
