<?php
/**
 * دستهٔ ورود Excel مشترک برای «سند» و «صورتحساب بانک».
 * - فایل خام نگه داشته نمی‌شود؛ اثر انگشت فایل از ورود دوبارهٔ همان فایل جلوگیری می‌کند.
 * - نگاشت ستون‌ها خودکار پیشنهاد و دستی اصلاح می‌شود؛ هر تغییر نگاشت، پیش‌نمایش را از نو می‌سازد.
 * - ورود سند: پیش‌نمایش کامل با کنترل تراز هر سند؛ ردیف خطادار مانع صدور است؛ صدور اقدام جدای انسان است و
 *   فقط وقتی انجام می‌شود که اعتبارسنجی تازه دقیقاً همان پیش‌نمایش تأییدشده (snapshot) را بدهد. خروجی فقط سند پیش‌نویس است.
 */
namespace Agah\Erp\Modules\Import;
use Agah\Erp\Core\{Access,Audit,Business,ConflictException,Ctx,Db,Money,NotFoundException,Request,ValidationException,XlsxReader};
use Agah\Erp\Modules\Accounting\{FiscalService,VoucherService};
if(!defined('ABSPATH'))exit;
final class ImportService {
    const KINDS=['voucher'=>'سند حسابداری','bank'=>'صورتحساب بانک'];
    const FIELDS=[
        'voucher'=>['group'=>'شمارهٔ سند در فایل','date'=>'تاریخ','account'=>'کد حساب معین','detail'=>'کد تفصیلی','description'=>'شرح','debit'=>'بدهکار','credit'=>'بستانکار'],
        'bank'=>['date'=>'تاریخ','description'=>'شرح','deposit'=>'واریز (ستون بستانکار صورتحساب)','withdrawal'=>'برداشت (ستون بدهکار صورتحساب)','amount'=>'مبلغ علامت‌دار (+ واریز، − برداشت)','reference'=>'شمارهٔ پیگیری / مرجع'],
    ];
    const REQUIRED=['voucher'=>['group','date','account','debit','credit'],'bank'=>['date']];
    const ALIASES=['group'=>['شمارهسند','ردیفسند','سند','گروه'],'date'=>['تاریخ','تاریخسند','تاریختراکنش','date'],'account'=>['کدحساب','کدمعین','حساب','account'],'detail'=>['کدتفصیلی','تفصیلی','detail'],'description'=>['شرح','توضیحات','شرحتراکنش','بابت','description'],
        'debit'=>['بدهکار','debit'],'credit'=>['بستانکار','credit'],'deposit'=>['بستانکار','واریز','واریزی','مبلغواریز','deposit'],'withdrawal'=>['بدهکار','برداشت','برداشتی','مبلغبرداشت','withdrawal'],'amount'=>['مبلغ','amount'],'reference'=>['شمارهپیگیری','پیگیری','مرجع','شمارهمرجع','شمارهسندبانک','reference']];
    const TEMPLATE='agah-voucher-v1';
    const STATUS=['preview'=>'پیش‌نمایش','active'=>'در حال تطبیق','posted'=>'صادرشده','cancelled'=>'لغوشده'];
    const ROW_STATUS=['ok'=>'سالم','error'=>'خطا','posted'=>'سند پیش‌نویس ساخته شد','matched'=>'تطبیق‌شده','ambiguous'=>'مبهم','open'=>'باز','duplicate'=>'تکراری','skipped'=>'بدون مبلغ','ignored'=>'کنار گذاشته شد'];

    public static function cap($kind){return $kind==='voucher'?'voucher.create':'treasury_account.edit';}
    private static function key($s){return str_replace(['ي','ك',' ',"\u{200C}","\u{0654}",'ـ','.','-','_','/','(',')'],['ی','ک','','','','','','','','','',''],mb_strtolower(trim((string)$s)));}
    /** نگاشت پیشنهادی: برابری کامل نام ستون با نام‌های رایج، سپس دربرداشتن. */
    public static function automap($kind,array $headers){
        $map=[];$used=[];$keys=array_map([__CLASS__,'key'],$headers);
        foreach([true,false] as $exact)foreach(self::FIELDS[$kind] as $field=>$label){
            if(isset($map[$field]))continue;
            foreach($keys as $i=>$h){if($h===''||isset($used[$i]))continue;foreach(self::ALIASES[$field] as $a)if($exact?$h===$a:mb_strpos($h,$a)!==false){$map[$field]=$i;$used[$i]=1;continue 3;}}
        }
        return $map;
    }
    private static function cleanMapping($kind,array $mapping,$cols){
        $out=[];foreach(self::FIELDS[$kind] as $field=>$label){$v=$mapping[$field]??'';if($v===''||$v===null||(int)$v<0)continue;$v=(int)$v;if($v>=$cols)throw new ValidationException(['mapping'=>'ستون انتخاب‌شده برای «'.$label.'» در فایل نیست.']);$out[$field]=$v;}
        foreach(self::REQUIRED[$kind] as $field)if(!isset($out[$field]))throw new ValidationException(['mapping'=>'ستون «'.self::FIELDS[$kind][$field].'» را مشخص کنید.']);
        if($kind==='bank'&&!isset($out['amount'])&&!isset($out['deposit'],$out['withdrawal']))throw new ValidationException(['mapping'=>'یا ستون «مبلغ علامت‌دار» یا هر دو ستون واریز و برداشت لازم است.']);
        if(count(array_unique($out))!==count($out))throw new ValidationException(['mapping'=>'یک ستون برای دو مورد انتخاب شده است.']);
        return $out;
    }

    public static function batches($cid,$kind){Access::need($cid,self::cap($kind));return Db::rows('SELECT b.*,t.name AS treasury_name FROM '.Db::t('import_batches').' b LEFT JOIN '.Db::t('treasury_accounts').' t ON t.company_id=b.company_id AND t.id=b.treasury_account_id WHERE b.company_id=%d AND b.kind=%s ORDER BY b.id DESC LIMIT 30',$cid,$kind);}
    public static function batch($cid,$id,$kind=null){$b=Business::row('import_batches',$cid,$id);if($kind&&$b['kind']!==$kind)throw new NotFoundException();Access::need($cid,self::cap($b['kind']));return $b;}
    public static function rows($cid,$batch){return Db::rows('SELECT * FROM '.Db::t('import_rows').' WHERE company_id=%d AND batch_id=%d ORDER BY row_no',$cid,(int)$batch);}

    /** بارگذاری: اعتبار فایل، ردّ فایل تکراری، ذخیرهٔ ردیف‌های خام و ساخت پیش‌نمایش. */
    public static function upload($cid,$kind,$name,$bytes,$unit,$treasuryAccount=0){
        if(!isset(self::KINDS[$kind]))throw new ValidationException(['kind'=>'نوع ورود معتبر نیست.']);
        return Access::write($cid,self::cap($kind),function()use($cid,$kind,$name,$bytes,$unit,$treasuryAccount){
            if(!is_string($name)||!preg_match('/^[^\/\\\\\x00-\x1f]{1,180}\.xlsx$/iuD',$name))throw new ValidationException(['file'=>'فقط فایل با پسوند .xlsx پذیرفته می‌شود.']);
            if(!in_array($unit,['rial','toman'],true))throw new ValidationException(['unit'=>'واحد مبلغ فایل معتبر نیست.']);
            $sha=hash('sha256',(string)$bytes);
            if($dup=Db::val('SELECT id FROM '.Db::t('import_batches')." WHERE company_id=%d AND kind=%s AND file_sha256=%s AND status<>'cancelled' LIMIT 1",$cid,$kind,$sha))throw new ConflictException('این فایل قبلاً وارد شده است (دستهٔ شمارهٔ '.(int)$dup.')؛ رکورد دوم ساخته نمی‌شود.');
            $sheet=XlsxReader::read((string)$bytes);$rows=$sheet['rows'];if(count($rows)<2)throw new ValidationException(['file'=>'فایل جز سرستون ردیفی ندارد.']);
            $headerNo=array_key_first($rows);$headers=$rows[$headerNo];unset($rows[$headerNo]);
            $account=null;
            if($kind==='bank'){$account=Business::row('treasury_accounts',$cid,Business::id($treasuryAccount));if($account['kind']!=='bank'||!$account['is_active'])throw new ValidationException(['treasury_account_id'=>'حساب بانکی فعال انتخاب کنید.']);}
            // آداپتور قالب: اگر سرستون‌ها با دستهٔ قبلی همین حساب یکی باشد، نگاشت همان دسته دوباره استفاده می‌شود.
            $mapping=null;
            if($account){$prev=Db::row('SELECT headers,mapping FROM '.Db::t('import_batches')." WHERE company_id=%d AND kind='bank' AND treasury_account_id=%d ORDER BY id DESC LIMIT 1",$cid,$account['id']);if($prev&&json_decode($prev['headers'],true)===array_values($headers))$mapping=json_decode($prev['mapping'],true);}
            $mapping=$mapping?:self::automap($kind,$headers);
            $id=Business::save('import_batches',$cid,0,['kind'=>$kind,'file_name'=>Request::clean($name,190),'file_sha256'=>$sha,'treasury_account_id'=>$account?(int)$account['id']:null,'unit'=>$unit,'headers'=>wp_json_encode(array_values($headers),JSON_UNESCAPED_UNICODE),'mapping'=>wp_json_encode($mapping),'status'=>$kind==='voucher'?'preview':'active','rows_total'=>count($rows),'created_by'=>Ctx::uid()]);
            foreach($rows as $no=>$cells)Business::save('import_rows',$cid,0,['batch_id'=>$id,'row_no'=>$no,'raw'=>wp_json_encode(array_values($cells),JSON_UNESCAPED_UNICODE),'status'=>'error','treasury_account_id'=>$account?(int)$account['id']:null]);
            self::analyze($cid,$id,true);
            Audit::log('import.upload','import_batch',$id,null,['kind'=>$kind,'file'=>$name,'sha256'=>$sha,'rows'=>count($rows),'sheet'=>$sheet['sheet']],$cid);
            return $id;
        });
    }
    /** تغییر نگاشت ستون‌ها؛ پس از اولین تصمیم روی ردیف‌ها ممنوع است تا ردپای تصمیم بی‌معنا نشود. */
    public static function remap($cid,$id,array $mapping){
        return Access::write($cid,self::cap(self::batch($cid,$id)['kind']),function()use($cid,$id,$mapping){
            $b=Business::row('import_batches',$cid,$id,true);if(!in_array($b['status'],['preview','active'],true))throw new ConflictException('نگاشت دستهٔ صادرشده یا لغوشده تغییر نمی‌کند.');
            if(Db::val('SELECT id FROM '.Db::t('import_rows')." WHERE company_id=%d AND batch_id=%d AND (voucher_id IS NOT NULL OR treasury_doc_id IS NOT NULL OR decision IN ('manual','ignored')) LIMIT 1",$cid,$id))throw new ConflictException('پس از ثبت تصمیم روی ردیف‌ها نگاشت تغییر نمی‌کند؛ دسته را لغو و فایل را دوباره وارد کنید.');
            $map=self::cleanMapping($b['kind'],$mapping,count(json_decode($b['headers'],true)));
            Db::update('import_batches',['mapping'=>wp_json_encode($map),'updated_at'=>gmdate('Y-m-d H:i:s')],['company_id'=>$cid,'id'=>$id]);
            self::analyze($cid,$id,true);Audit::log('import.remap','import_batch',$id,['mapping'=>json_decode($b['mapping'],true)],['mapping'=>$map],$cid);return true;
        });
    }
    /** بازسازی پیش‌نمایش با وضعیت فعلی حساب‌ها (مثلاً پس از اصلاح کدینگ). */
    public static function refresh($cid,$id){return Access::write($cid,self::cap(self::batch($cid,$id)['kind']),function()use($cid,$id){$b=Business::row('import_batches',$cid,$id,true);if(!in_array($b['status'],['preview','active'],true))throw new ConflictException('این دسته بسته شده است.');self::analyze($cid,$id,$b['kind']==='voucher');return true;});}

    private static function cell(array $raw,array $map,$field){return isset($map[$field])?trim((string)($raw[$map[$field]]??'')):'';}
    /** خواندن ردیف‌ها با نگاشت فعلی و ذخیرهٔ نتیجه؛ برای بانک، تطبیق را هم اجرا می‌کند. خروجی: snapshot. */
    private static function analyze($cid,$id,$reset){
        $b=Business::row('import_batches',$cid,$id);
        if($b['kind']==='bank')return BankMatchService::analyze($cid,$b,$reset);
        $map=json_decode($b['mapping'],true)?:[];$rows=self::rows($cid,$id);$parsed=[];$groups=[];
        $missing=array_diff(self::REQUIRED['voucher'],array_keys($map));
        foreach($rows as $r){
            $raw=json_decode($r['raw'],true)?:[];$err=[];$d=['group'=>self::cell($raw,$map,'group'),'description'=>Request::clean(self::cell($raw,$map,'description'),500)];
            if($missing)$err[]='نگاشت ستون‌ها کامل نیست.';
            else{
                if($d['group']===''||mb_strlen($d['group'])>40)$err[]='شمارهٔ سند در فایل خالی یا طولانی است.';
                $d['date']=XlsxReader::date(self::cell($raw,$map,'date'));if(!$d['date'])$err[]='تاریخ معتبر نیست.';
                $code=Money::normDigits(self::cell($raw,$map,'account'));$a=$code===''?null:Db::row('SELECT id,level,is_active,detail_rule,detail_kind FROM '.Db::t('accounts').' WHERE company_id=%d AND code=%s',$cid,$code);
                if(!$a)$err[]='کد حساب «'.$code.'» در کدینگ نیست.';elseif((int)$a['level']!==3||!(int)$a['is_active'])$err[]='حساب «'.$code.'» معین فعال نیست.';
                $dcode=Money::normDigits(self::cell($raw,$map,'detail'));$det=$dcode===''?null:Db::row('SELECT id,kind,is_active FROM '.Db::t('details').' WHERE company_id=%d AND code=%s',$cid,$dcode);
                if($dcode!==''&&!$det)$err[]='کد تفصیلی «'.$dcode.'» پیدا نشد.';
                if($a&&!$err){
                    if($a['detail_rule']==='required'&&!$det)$err[]='این حساب تفصیلی لازم دارد.';
                    if($a['detail_rule']==='none'&&$det)$err[]='این حساب تفصیلی نمی‌پذیرد.';
                    if($det&&(!(int)$det['is_active']||($a['detail_kind']&&$a['detail_kind']!==$det['kind'])))$err[]='تفصیلی با حساب سازگار یا فعال نیست.';
                }
                $d['account_id']=$a?(int)$a['id']:0;$d['detail_id']=$det?(int)$det['id']:0;
                $dr=XlsxReader::amount(self::cell($raw,$map,'debit'),$b['unit']);$cr=XlsxReader::amount(self::cell($raw,$map,'credit'),$b['unit']);
                if($dr===null||$cr===null||$dr<0||$cr<0||$dr>Money::MAX||$cr>Money::MAX)$err[]='مبلغ بدهکار/بستانکار معتبر نیست.';
                elseif(($dr>0)===($cr>0))$err[]='هر ردیف فقط بدهکار یا فقط بستانکار و بزرگ‌تر از صفر باشد.';
                $d['debit']=(int)$dr;$d['credit']=(int)$cr;
                if($d['date']){try{FiscalService::assertOpen($cid,$d['date']);}catch(\Throwable $e){$err[]='سال یا دورهٔ مالی این تاریخ باز نیست.';}}
            }
            $parsed[$r['id']]=['row'=>$r,'data'=>$d,'errors'=>$err];if($d['group']!=='')$groups[$d['group']][]=$r['id'];
        }
        foreach($groups as $g=>$ids){
            $dr=0;$cr=0;$dates=[];foreach($ids as $rid){$dr+=$parsed[$rid]['data']['debit']??0;$cr+=$parsed[$rid]['data']['credit']??0;$dates[$parsed[$rid]['data']['date']??'']=1;}
            $gerr=[];if(count($ids)<2)$gerr[]='سند «'.$g.'» کمتر از دو ردیف دارد.';if($dr!==$cr||$dr<=0)$gerr[]='سند «'.$g.'» تراز نیست (بدهکار '.Money::fmt($dr,$b['unit']).'، بستانکار '.Money::fmt($cr,$b['unit']).').';if(count($dates)>1)$gerr[]='ردیف‌های سند «'.$g.'» تاریخ یکسان ندارند.';
            if($gerr)foreach($ids as $rid)$parsed[$rid]['errors']=array_merge($parsed[$rid]['errors'],$gerr);
        }
        $errors=0;$snap=[];
        foreach($parsed as $rid=>$p){$status=$p['errors']?'error':'ok';if($status==='error')$errors++;
            Db::update('import_rows',['status'=>$status,'error'=>mb_substr(implode(' ',array_unique($p['errors'])),0,500),'group_key'=>mb_substr($p['data']['group'],0,40),'txn_date'=>$p['data']['date']??null,'description'=>$p['data']['description'],'data'=>wp_json_encode($p['data'],JSON_UNESCAPED_UNICODE),'updated_at'=>gmdate('Y-m-d H:i:s')],['company_id'=>$cid,'id'=>$rid]);
            $snap[]=[(int)$p['row']['row_no'],$status,$p['data']];}
        $snapshot=hash('sha256',wp_json_encode([$map,$b['unit'],$snap]));
        Db::update('import_batches',['rows_error'=>$errors,'snapshot'=>$snapshot,'updated_at'=>gmdate('Y-m-d H:i:s')],['company_id'=>$cid,'id'=>$id]);
        return $snapshot;
    }
    /** خلاصهٔ پیش‌نمایش: اسناد گروه‌بندی‌شده با جمع‌ها. */
    public static function preview($cid,$id){
        $b=self::batch($cid,$id,'voucher');$groups=[];
        foreach(self::rows($cid,$id) as $r){$d=json_decode((string)$r['data'],true)?:[];$g=$r['group_key']!==''?$r['group_key']:'—';$groups[$g]['rows'][]=$r+['parsed'=>$d];$groups[$g]['debit']=($groups[$g]['debit']??0)+(int)($d['debit']??0);$groups[$g]['credit']=($groups[$g]['credit']??0)+(int)($d['credit']??0);}
        return ['batch'=>$b,'groups'=>$groups];
    }
    /**
     * صدور: اقدام جدای انسان؛ اعتبارسنجی دوباره زیر قفل شرکت و برابری با snapshot پیش‌نمایشی که کاربر دیده است.
     * هر سند گروه یک «پیش‌نویس» دستی می‌شود (قابل ویرایش و تأیید در گردش عادی اسناد). دستهٔ صادرشده دوباره صادر نمی‌شود.
     */
    public static function post($cid,$id,$snapshot){
        return Access::write($cid,'voucher.create',function()use($cid,$id,$snapshot){
            $b=Business::row('import_batches',$cid,$id,true);if($b['kind']!=='voucher')throw new NotFoundException();
            if($b['status']!=='preview')throw new ConflictException('این دسته قبلاً صادر یا لغو شده است؛ سند دوباره ساخته نمی‌شود.');
            $fresh=self::analyze($cid,$id,false);$b=Business::row('import_batches',$cid,$id);
            if((int)$b['rows_error']>0)throw new ValidationException(['batch'=>'دسته '.(int)$b['rows_error'].' ردیف خطادار دارد؛ هیچ سندی صادر نشد.']);
            if(!is_string($snapshot)||!hash_equals($fresh,$snapshot))throw new ConflictException('داده یا کدینگ پس از پیش‌نمایش تغییر کرده است؛ پیش‌نمایش تازه را ببینید و دوباره تأیید کنید.');
            $groups=[];foreach(self::rows($cid,$id) as $r)$groups[$r['group_key']][]=$r;$made=[];
            foreach($groups as $g=>$rows){
                $d0=json_decode($rows[0]['data'],true);$lines=[];foreach($rows as $r){$d=json_decode($r['data'],true);$lines[]=['account_id'=>$d['account_id'],'detail_id'=>$d['detail_id'],'description'=>$d['description'],'debit'=>$d['debit'],'credit'=>$d['credit']];}
                $desc=$d0['description']!==''?$d0['description']:'ورود از Excel';$vid=VoucherService::save($cid,0,['voucher_date'=>$d0['date'],'description'=>mb_substr($desc.' — دستهٔ ورود '.$id.'، سند '.$g,0,500)],$lines,0,'rial');
                foreach($rows as $r)Db::update('import_rows',['status'=>'posted','voucher_id'=>$vid,'decided_by'=>Ctx::uid(),'decided_at'=>gmdate('Y-m-d H:i:s')],['company_id'=>$cid,'id'=>$r['id']]);$made[]=$vid;
            }
            Db::update('import_batches',['status'=>'posted','posted_by'=>Ctx::uid(),'posted_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')],['company_id'=>$cid,'id'=>$id]);
            Audit::log('import.post','import_batch',$id,null,['vouchers'=>$made,'snapshot'=>$snapshot],$cid);return $made;
        });
    }
    /** لغو: فقط دستهٔ بدون سند یا برگهٔ ساخته‌شده؛ دستهٔ لغوشده دیگر مانع ورود دوبارهٔ همان فایل نیست. */
    public static function cancel($cid,$id){
        return Access::write($cid,self::cap(self::batch($cid,$id)['kind']),function()use($cid,$id){
            $b=Business::row('import_batches',$cid,$id,true);if(!in_array($b['status'],['preview','active'],true))throw new ConflictException('فقط دستهٔ باز لغو می‌شود.');
            if(Db::val('SELECT id FROM '.Db::t('import_rows').' WHERE company_id=%d AND batch_id=%d AND (voucher_id IS NOT NULL OR treasury_doc_id IS NOT NULL) LIMIT 1',$cid,$id))throw new ConflictException('از این دسته سند یا برگه ساخته شده است؛ لغو نمی‌شود.');
            Db::update('import_batches',['status'=>'cancelled','updated_at'=>gmdate('Y-m-d H:i:s')],['company_id'=>$cid,'id'=>$id]);Audit::log('import.cancel','import_batch',$id,null,null,$cid);return true;
        });
    }
    /** حذف: فقط دستهٔ لغوشده؛ ردیف‌ها با آن حذف می‌شوند و رویداد در ممیزی می‌ماند. */
    public static function delete($cid,$id){
        return Access::write($cid,self::cap(self::batch($cid,$id)['kind']),function()use($cid,$id){
            $b=Business::row('import_batches',$cid,$id,true);if($b['status']!=='cancelled')throw new ConflictException('ابتدا دسته را لغو کنید؛ دستهٔ صادرشده برای ردپای اسناد می‌ماند.');
            Db::delete('import_batches',['company_id'=>$cid,'id'=>$id]);Audit::log('import.delete','import_batch',$id,['file'=>$b['file_name'],'sha256'=>$b['file_sha256']],null,$cid);return true;
        });
    }
    /** قالب خام و نسخه‌دار ورود سند (نام برگه = نسخه). */
    public static function template($cid){
        Access::need($cid,'voucher.create');
        $acc=Db::rows('SELECT code FROM '.Db::t('accounts')." WHERE company_id=%d AND level=3 AND is_active=1 AND detail_rule<>'required' ORDER BY code LIMIT 2",$cid);$a1=$acc[0]['code']??'';$a2=$acc[1]['code']??'';
        return ['headers'=>array_values(self::FIELDS['voucher']),'rows'=>[['1','1405/01/15',$a1,'','نمونه — این ردیف را پاک کنید','1000',''],['1','1405/01/15',$a2,'','نمونه — این ردیف را پاک کنید','','1000']]];
    }
}
