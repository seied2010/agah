<?php
/**
 * ورود داده از نرم‌افزار دیگر (IMP-01..04، Q21): حساب‌ها، اشخاص و ماندهٔ افتتاحیه.
 *
 * - آداپتورهای نسخه‌دار: csv-v1 (تشخیص رمزگذاری و جداکننده) و xlsx-v1. فایل باینری/رمزگذاری‌شده/اختصاصی خوانده نمی‌شود.
 * - ترتیب: شناسایی قالب ← نگاشت ستون ← پیش‌نمایش (اجرای آزمایشی بدون هیچ ثبت) ← ورود جداگانه با کنترل snapshot.
 * - کد و ساختار مبدأ در import_map (source_system، source_code، target_uuid، mapping_version) جدا نگه داشته می‌شود.
 * - تکرار بی‌اثر است: فایل تکراری (اثر انگشت) پذیرفته نمی‌شود و کد منبعِ قبلاً واردشده «موجود» می‌ماند و دوباره ساخته نمی‌شود.
 * - حساب و شخص ردیف‌به‌ردیف وارد می‌شوند و دستهٔ دارای خطا «ناقص» می‌ماند؛ ماندهٔ افتتاحیه یا کامل و تراز است یا هیچ.
 */
namespace Agah\Erp\Modules\Import;
use Agah\Erp\Core\{Access,Audit,Business,ConflictException,Crypto,CsvReader,Ctx,Db,Money,NotFoundException,Request,ValidationException,XlsxReader};
use Agah\Erp\Modules\Accounting\{AccountService,FiscalService,VoucherService};
use Agah\Erp\Modules\Parties\PartyService;
if(!defined('ABSPATH'))exit;
final class MigrationService {
    const KINDS=['accounts'=>'حساب‌ها (کدینگ)','parties'=>'اشخاص','opening'=>'ماندهٔ افتتاحیه'];
    const ENTITY=['accounts'=>'account','parties'=>'party'];
    const FIELDS=[
        'accounts'=>['code'=>'کد حساب در مبدأ','name'=>'نام حساب','parent'=>'کد بالادستی در مبدأ','target'=>'کد معین آگاه','group'=>'کد کل آگاه'],
        'parties'=>['code'=>'کد شخص در مبدأ','name'=>'نام شخص','kind'=>'نوع (حقیقی/حقوقی)','national'=>'کد ملی یا شناسهٔ ملی','economic'=>'کد اقتصادی','phone'=>'تلفن','role'=>'نقش (مشتری/تأمین‌کننده/هر دو)'],
        'opening'=>['account'=>'کد حساب (مبدأ یا معین آگاه)','party'=>'کد شخص در مبدأ','description'=>'شرح','debit'=>'بدهکار','credit'=>'بستانکار'],
    ];
    const REQUIRED=['accounts'=>['code','name'],'parties'=>['code','name'],'opening'=>['account','debit','credit']];
    const ALIASES=['code'=>['کد','کدحساب','کدشخص','code'],'name'=>['نام','عنوان','نامحساب','نامشخص','شرححساب','name','title'],'parent'=>['کدبالادستی','کدپدر','parent'],'target'=>['کدمعینآگاه','معینآگاه','target'],'group'=>['کدکلآگاه','کلآگاه','group'],
        'kind'=>['نوع','نوعشخص','kind'],'national'=>['کدملی','شناسهملی','national'],'economic'=>['کداقتصادی','economic'],'phone'=>['تلفن','موبایل','تلفنهمراه','phone','mobile'],'role'=>['نقش','role'],
        'account'=>['کدحساب','حساب','account'],'party'=>['کدشخص','شخص','تفصیلی','party'],'description'=>['شرح','توضیحات','description'],'debit'=>['بدهکار','debit'],'credit'=>['بستانکار','credit']];
    const STATUS=['preview'=>'پیش‌نمایش','partial'=>'واردشده ناقص (ردیف خطادار مانده)','posted'=>'کامل واردشده','cancelled'=>'لغوشده'];
    const ROW_STATUS=['ok'=>'ساخته می‌شود','link'=>'به رکورد موجود آگاه نگاشت می‌شود','existing'=>'قبلاً وارد شده؛ تکرار نمی‌شود','error'=>'خطا','posted'=>'وارد شد'];
    const TEMPLATE='agah-migrate-v1';

    public static function cap($kind){return ['accounts'=>'account.create','parties'=>'party.create','opening'=>'voucher.create'][$kind]??'voucher.create';}
    private static function key($s){return str_replace(['ي','ك',' ',"\u{200C}","\u{0654}",'ـ','.','-','_','/','(',')','أ','ٔ'],['ی','ک','','','','','','','','','','','ا',''],mb_strtolower(trim((string)$s)));}
    private static function system($v){$v=Request::clean((string)$v,40);if(mb_strlen($v)<2)throw new ValidationException(['source_system'=>'نام نرم‌افزار مبدأ را بنویسید (مثلاً «هلو» یا «سپیدار»).']);return $v;}
    public static function automap($kind,array $headers){
        $map=[];$used=[];$keys=array_map([__CLASS__,'key'],$headers);
        foreach([true,false] as $exact)foreach(self::FIELDS[$kind] as $field=>$label){
            if(isset($map[$field]))continue;$aliases=array_merge([self::key($label)],self::ALIASES[$field]);
            foreach($keys as $i=>$h){if($h===''||isset($used[$i]))continue;foreach($aliases as $a)if($exact?$h===$a:($a!==''&&mb_strlen($a)>2&&mb_strpos($h,$a)!==false)){$map[$field]=$i;$used[$i]=1;continue 3;}}
        }
        return $map;
    }
    private static function cleanMapping($kind,array $mapping,$cols){
        $out=[];foreach(self::FIELDS[$kind] as $field=>$label){$v=$mapping[$field]??'';if($v===''||$v===null||(int)$v<0)continue;$v=(int)$v;if($v>=$cols)throw new ValidationException(['mapping'=>'ستون «'.$label.'» در فایل نیست.']);$out[$field]=$v;}
        foreach(self::REQUIRED[$kind] as $f)if(!isset($out[$f]))throw new ValidationException(['mapping'=>'ستون «'.self::FIELDS[$kind][$f].'» را مشخص کنید.']);
        if(count(array_unique($out))!==count($out))throw new ValidationException(['mapping'=>'یک ستون برای دو مورد انتخاب شده است.']);
        return $out;
    }

    public static function batches($cid){
        $kinds=array_values(array_filter(array_keys(self::KINDS),fn($k)=>Ctx::can(self::cap($k))));if(!$kinds)return [];
        return Db::rows('SELECT * FROM '.Db::t('import_batches').' WHERE company_id=%d AND kind IN ('.implode(',',array_fill(0,count($kinds),'%s')).') ORDER BY id DESC LIMIT 30',...array_merge([$cid],$kinds));
    }
    public static function batch($cid,$id){$b=Business::row('import_batches',$cid,$id);if(!isset(self::KINDS[$b['kind']]))throw new NotFoundException();Access::need($cid,self::cap($b['kind']));return $b;}
    public static function rows($cid,$batch){return Db::rows('SELECT * FROM '.Db::t('import_rows').' WHERE company_id=%d AND batch_id=%d ORDER BY row_no',$cid,(int)$batch);}
    public static function maps($cid,$system,$entity,$page=1){Access::need($cid,'account.view');$page=max(1,(int)$page);return Db::rows('SELECT * FROM '.Db::t('import_map').' WHERE company_id=%d AND source_system=%s AND entity=%s ORDER BY source_code LIMIT 100 OFFSET %d',$cid,$system,$entity,($page-1)*100);}
    public static function systems($cid){return Db::col('SELECT DISTINCT source_system FROM '.Db::t('import_map').' WHERE company_id=%d ORDER BY source_system',$cid);}

    /** آداپتور: XLSX با امضای ZIP، Excel قدیمی و قالب ناشناختهٔ باینری رد، باقی به‌عنوان متن CSV. */
    public static function readFile($name,$bytes){
        if(!is_string($name)||!preg_match('/^[^\/\\\\\x00-\x1f]{1,180}\.(xlsx|csv|txt)$/iuD',$name,$m))throw new ValidationException(['file'=>'فقط فایل .xlsx یا .csv پذیرفته می‌شود.']);
        $bytes=(string)$bytes;if($bytes==='')throw new ValidationException(['file'=>'فایل خالی است.']);
        if(strncmp($bytes,"\xD0\xCF\x11\xE0",4)===0)throw new ValidationException(['file'=>'فایل Excel قدیمی (.xls) یا رمزگذاری‌شده است؛ در Excel با قالب .xlsx یا CSV ذخیره کنید.']);
        if(strncmp($bytes,"PK\x03\x04",4)===0){$s=XlsxReader::read($bytes);return ['rows'=>$s['rows'],'adapter'=>'xlsx-v1','encoding'=>'utf-8'];}
        if(strtolower($m[1])==='xlsx')throw new ValidationException(['file'=>'محتوای فایل با پسوند .xlsx سازگار نیست.']);
        $c=CsvReader::read($bytes);return ['rows'=>$c['rows'],'adapter'=>'csv-v1','encoding'=>$c['encoding']];
    }
    /** بارگذاری و پیش‌نمایش (اجرای آزمایشی). نگاشت دستهٔ قبلی همان مبدأ و همان سرستون‌ها دوباره استفاده می‌شود. */
    public static function upload($cid,$kind,$system,$name,$bytes,$unit,$date=''){
        if(!isset(self::KINDS[$kind]))throw new ValidationException(['kind'=>'نوع ورود معتبر نیست.']);
        return Access::write($cid,self::cap($kind),function()use($cid,$kind,$system,$name,$bytes,$unit,$date){
            $system=self::system($system);if(!in_array($unit,['rial','toman'],true))throw new ValidationException(['unit'=>'واحد مبلغ فایل معتبر نیست.']);
            $options=[];if($kind==='opening'){$d=Business::date($date);FiscalService::assertOpen($cid,$d);$options['date']=$d;}
            $sha=hash('sha256',(string)$bytes);
            if($dup=Db::val('SELECT id FROM '.Db::t('import_batches')." WHERE company_id=%d AND kind=%s AND file_sha256=%s AND status<>'cancelled' LIMIT 1",$cid,$kind,$sha))throw new ConflictException('این فایل قبلاً وارد شده است (دستهٔ شمارهٔ '.(int)$dup.')؛ دستهٔ دوم ساخته نمی‌شود.');
            $f=self::readFile($name,$bytes);$rows=$f['rows'];if(count($rows)<2)throw new ValidationException(['file'=>'فایل جز سرستون ردیفی ندارد.']);
            $headerNo=array_key_first($rows);$headers=array_values($rows[$headerNo]);unset($rows[$headerNo]);
            $prev=Db::row('SELECT headers,mapping,mapping_version FROM '.Db::t('import_batches').' WHERE company_id=%d AND kind=%s AND source_system=%s ORDER BY id DESC LIMIT 1',$cid,$kind,$system);
            if($prev&&json_decode($prev['headers'],true)===$headers){$mapping=json_decode($prev['mapping'],true)?:[];$ver=(int)$prev['mapping_version'];}
            else{$mapping=self::automap($kind,$headers);$ver=self::nextVersion($cid,$kind,$system);}
            $id=Business::save('import_batches',$cid,0,['kind'=>$kind,'file_name'=>Request::clean($name,190),'file_sha256'=>$sha,'unit'=>$unit,'headers'=>wp_json_encode($headers,JSON_UNESCAPED_UNICODE),'mapping'=>wp_json_encode($mapping),'status'=>'preview','rows_total'=>count($rows),'adapter'=>$f['adapter'],'encoding'=>$f['encoding'],'source_system'=>$system,'mapping_version'=>$ver,'options'=>wp_json_encode($options),'created_by'=>Ctx::uid()]);
            foreach($rows as $no=>$cells)Business::save('import_rows',$cid,0,['batch_id'=>$id,'row_no'=>$no,'raw'=>wp_json_encode(array_values($cells),JSON_UNESCAPED_UNICODE),'status'=>'error']);
            self::analyze($cid,$id);
            Audit::log('migrate.upload','import_batch',$id,null,['kind'=>$kind,'system'=>$system,'file'=>$name,'sha256'=>$sha,'adapter'=>$f['adapter'],'encoding'=>$f['encoding'],'rows'=>count($rows)],$cid);
            return $id;
        });
    }
    private static function nextVersion($cid,$kind,$system){return (int)Db::val('SELECT COALESCE(MAX(mapping_version),0) FROM '.Db::t('import_batches').' WHERE company_id=%d AND kind=%s AND source_system=%s',$cid,$kind,$system)+1;}
    /** تغییر نگاشت = نسخهٔ تازهٔ نگاشت؛ پس از ورود هر ردیف ممنوع است. */
    public static function remap($cid,$id,array $mapping){
        $b=self::batch($cid,$id);
        return Access::write($cid,self::cap($b['kind']),function()use($cid,$id,$mapping){
            $b=Business::row('import_batches',$cid,$id,true);if($b['status']!=='preview')throw new ConflictException('نگاشت دستهٔ واردشده یا لغوشده تغییر نمی‌کند.');
            $map=self::cleanMapping($b['kind'],$mapping,count(json_decode($b['headers'],true)));
            Db::update('import_batches',['mapping'=>wp_json_encode($map),'mapping_version'=>self::nextVersion($cid,$b['kind'],$b['source_system']),'updated_at'=>gmdate('Y-m-d H:i:s')],['company_id'=>$cid,'id'=>$id]);
            self::analyze($cid,$id);Audit::log('migrate.remap','import_batch',$id,['mapping'=>json_decode($b['mapping'],true)],['mapping'=>$map],$cid);return true;
        });
    }
    public static function refresh($cid,$id){$b=self::batch($cid,$id);return Access::write($cid,self::cap($b['kind']),function()use($cid,$id){$b=Business::row('import_batches',$cid,$id,true);if(!in_array($b['status'],['preview','partial'],true))throw new ConflictException('این دسته بسته شده است.');self::analyze($cid,$id);return true;});}

    private static function cell(array $raw,array $map,$f){return isset($map[$f])?trim((string)($raw[$map[$f]]??'')):'';}
    private static function code($v){return mb_substr(Money::normDigits(trim((string)$v)),0,60);}
    private static function mapped($cid,$system,$entity,$code){return $code===''?null:Db::row('SELECT * FROM '.Db::t('import_map').' WHERE company_id=%d AND source_system=%s AND entity=%s AND source_code=%s',$cid,$system,$entity,$code);}

    /** تحلیل همهٔ ردیف‌ها بدون هیچ ثبت (اجرای آزمایشی)؛ خروجی snapshot همین نتیجه است. */
    private static function analyze($cid,$id){
        $b=Business::row('import_batches',$cid,$id);$kind=$b['kind'];$map=json_decode($b['mapping'],true)?:[];$sys=$b['source_system'];
        $missing=array_diff(self::REQUIRED[$kind],array_keys($map));$rows=self::rows($cid,$id);$parsed=[];
        // کد تکراری در فایل مبهم است (کدام ردیف درست است؟)؛ همهٔ ردیف‌های آن کد خطا می‌شوند.
        $seen=[];if($kind!=='opening'&&isset($map['code']))foreach($rows as $r){$k=self::code(self::cell(json_decode($r['raw'],true)?:[],$map,'code'));if($k!=='')$seen[$k]=($seen[$k]??0)+1;}
        foreach($rows as $r){
            if($r['status']==='posted'){$parsed[$r['id']]=['status'=>'posted','data'=>json_decode((string)$r['data'],true)?:[],'errors'=>[]];continue;}
            $raw=json_decode($r['raw'],true)?:[];$err=[];$d=[];$status='ok';
            if($missing)$err[]='نگاشت ستون‌ها کامل نیست.';
            elseif($kind==='accounts'){
                $d=['code'=>self::code(self::cell($raw,$map,'code')),'name'=>Request::clean(self::cell($raw,$map,'name'),190),'parent'=>self::code(self::cell($raw,$map,'parent')),'target'=>self::code(self::cell($raw,$map,'target')),'group'=>self::code(self::cell($raw,$map,'group'))];
                if($d['code']==='')$err[]='کد حساب مبدأ خالی است.';elseif(($seen[$d['code']]??0)>1)$err[]='کد «'.$d['code'].'» در فایل تکراری است.';
                if(mb_strlen($d['name'])<2)$err[]='نام حساب خالی است.';
                if(!$err&&self::mapped($cid,$sys,'account',$d['code']))$status='existing';
                elseif(!$err&&$d['target']!==''){$a=Db::row('SELECT id,level,is_active FROM '.Db::t('accounts').' WHERE company_id=%d AND code=%s',$cid,$d['target']);if(!$a||(int)$a['level']!==3||!(int)$a['is_active'])$err[]='معین فعال آگاه با کد «'.$d['target'].'» نیست.';else{$status='link';$d['account_id']=(int)$a['id'];}}
                elseif(!$err&&$d['group']!==''){$g=Db::row('SELECT id,level,code FROM '.Db::t('accounts').' WHERE company_id=%d AND code=%s',$cid,$d['group']);if(!$g||(int)$g['level']!==2)$err[]='حساب کل آگاه با کد «'.$d['group'].'» نیست.';else $d['group_id']=(int)$g['id'];}
                elseif(!$err)$err[]='برای نگاشت، «کد معین آگاه» یا برای ساخت معین تازه «کد کل آگاه» لازم است.';
            }elseif($kind==='parties'){
                $kindRaw=self::key(self::cell($raw,$map,'kind'));$role=self::key(self::cell($raw,$map,'role'));
                $d=['code'=>self::code(self::cell($raw,$map,'code')),'name'=>Request::clean(self::cell($raw,$map,'name'),190),'kind'=>(mb_strpos($kindRaw,'حقوقی')!==false||$kindRaw==='legal')?'legal':'real','national'=>Money::normDigits(self::cell($raw,$map,'national')),'economic'=>Request::clean(self::cell($raw,$map,'economic'),30),'phone'=>Request::clean(self::cell($raw,$map,'phone'),40)];
                $both=mb_strpos($role,'هردو')!==false||$role==='both';$sup=$both||mb_strpos($role,'تامین')!==false||mb_strpos($role,'فروشنده')!==false||$role==='supplier';
                $d['is_customer']=$both||!$sup?1:0;$d['is_supplier']=$sup?1:0;
                if($d['code']==='')$err[]='کد شخص مبدأ خالی است.';elseif(($seen[$d['code']]??0)>1)$err[]='کد «'.$d['code'].'» در فایل تکراری است.';
                if(mb_strlen($d['name'])<2)$err[]='نام شخص خالی است.';
                if($d['national']!==''&&!PartyService::validNational($d['national'],$d['kind']))$err[]='کد ملی/شناسهٔ ملی معتبر نیست.';
                if(!$err&&self::mapped($cid,$sys,'party',$d['code']))$status='existing';
                elseif(!$err&&$d['national']!==''&&($pid=(int)Db::val('SELECT id FROM '.Db::t('parties').' WHERE company_id=%d AND national_id_idx=%s',$cid,Crypto::blind('national|'.$d['kind'].'|'.$d['national'])))){$status='link';$d['party_id']=$pid;}
            }else{
                $d=['account'=>self::code(self::cell($raw,$map,'account')),'party'=>self::code(self::cell($raw,$map,'party')),'description'=>Request::clean(self::cell($raw,$map,'description'),500)];
                $m=self::mapped($cid,$sys,'account',$d['account']);
                $a=$m?Db::row('SELECT * FROM '.Db::t('accounts').' WHERE company_id=%d AND id=%d',$cid,(int)$m['target_id']):($d['account']!==''?Db::row('SELECT * FROM '.Db::t('accounts').' WHERE company_id=%d AND code=%s',$cid,$d['account']):null);
                if(!$a)$err[]='حساب «'.$d['account'].'» نه در نگاشت مبدأ هست نه در کدینگ آگاه.';elseif((int)$a['level']!==3||!(int)$a['is_active'])$err[]='حساب «'.$d['account'].'» معین فعال نیست.';
                $det=null;if($d['party']!==''){$pm=self::mapped($cid,$sys,'party',$d['party']);$det=$pm?Db::row('SELECT d.id,d.kind,d.is_active FROM '.Db::t('parties').' p JOIN '.Db::t('details').' d ON d.company_id=p.company_id AND d.id=p.detail_id WHERE p.company_id=%d AND p.id=%d',$cid,(int)$pm['target_id']):null;if(!$det)$err[]='شخص «'.$d['party'].'» در نگاشت مبدأ نیست؛ ابتدا اشخاص را وارد کنید.';}
                if($a&&!$err){if($a['detail_rule']==='required'&&!$det)$err[]='این حساب تفصیلی (شخص) لازم دارد.';if($a['detail_rule']==='none'&&$det)$err[]='این حساب تفصیلی نمی‌پذیرد.';if($det&&(!(int)$det['is_active']||($a['detail_kind']&&$a['detail_kind']!==$det['kind'])))$err[]='تفصیلی با حساب سازگار نیست.';}
                $dr=XlsxReader::amount(self::cell($raw,$map,'debit'),$b['unit']);$cr=XlsxReader::amount(self::cell($raw,$map,'credit'),$b['unit']);
                if($dr===null||$cr===null||$dr<0||$cr<0||$dr>Money::MAX||$cr>Money::MAX)$err[]='مبلغ بدهکار/بستانکار معتبر نیست.';elseif(($dr>0)===($cr>0))$err[]='هر ردیف فقط بدهکار یا فقط بستانکار و بزرگ‌تر از صفر باشد.';
                $d+=['account_id'=>$a?(int)$a['id']:0,'detail_id'=>$det?(int)$det['id']:0,'debit'=>(int)$dr,'credit'=>(int)$cr];
            }
            $parsed[$r['id']]=['status'=>$err?'error':$status,'data'=>$d,'errors'=>$err];
        }
        if($kind==='opening'&&$parsed){
            $dr=0;$cr=0;foreach($parsed as $p){$dr+=$p['data']['debit']??0;$cr+=$p['data']['credit']??0;}
            $gerr=[];if($dr!==$cr||$dr<=0)$gerr[]='ماندهٔ افتتاحیه تراز نیست (بدهکار '.Money::fmt($dr,$b['unit']).'، بستانکار '.Money::fmt($cr,$b['unit']).').';
            $fy=FiscalService::assertOpen($cid,json_decode((string)$b['options'],true)['date']??'');
            if(self::openingVoucher($cid,$b['source_system'],(int)$fy['id']))$gerr[]='ماندهٔ افتتاحیهٔ این سال مالی از همین مبدأ قبلاً وارد شده است؛ دوباره ساخته نمی‌شود.';
            if($gerr)foreach($parsed as &$p){$p['errors']=array_merge($p['errors'],$gerr);$p['status']='error';}unset($p);
        }
        $errors=0;$snap=[];
        foreach($parsed as $rid=>$p){if($p['status']==='error')$errors++;
            if($p['status']!=='posted')Db::update('import_rows',['status'=>$p['status'],'error'=>mb_substr(implode(' ',array_unique($p['errors'])),0,500),'data'=>wp_json_encode($p['data'],JSON_UNESCAPED_UNICODE),'updated_at'=>gmdate('Y-m-d H:i:s')],['company_id'=>$cid,'id'=>$rid]);
            $snap[]=[$rid,$p['status'],$p['data']];}
        $snapshot=hash('sha256',wp_json_encode([$map,$b['unit'],$b['options'],$snap]));
        Db::update('import_batches',['rows_error'=>$errors,'snapshot'=>$snapshot,'updated_at'=>gmdate('Y-m-d H:i:s')],['company_id'=>$cid,'id'=>$id]);
        return $snapshot;
    }
    /** سند افتتاحیهٔ زندهٔ قبلی همین مبدأ و همین سال (پیش‌نویس حذف‌شده یا سند باطل دیگر مانع ورود دوباره نیست). */
    private static function openingVoucher($cid,$system,$fyId){
        $m=self::mapped($cid,$system,'opening','fy-'.$fyId);if(!$m)return 0;
        return (int)Db::val('SELECT id FROM '.Db::t('vouchers')." WHERE company_id=%d AND id=%d AND status<>'void'",$cid,(int)$m['target_id']);
    }
    private static function remember($cid,array $b,$entity,$code,$name,$parent,$targetId,$link){
        if($entity==='opening')Db::delete('import_map',['company_id'=>$cid,'source_system'=>$b['source_system'],'entity'=>'opening','source_code'=>$code]);
        // جدول حساب‌ها uuid ندارد؛ شناسهٔ پایدار حساب از شرکت و شناسهٔ حساب ساخته می‌شود (همیشه برای یک حساب یکسان).
        if($entity==='account'){$h=hash('sha256','agah-account|'.$cid.'|'.$targetId);$uuid=substr($h,0,8).'-'.substr($h,8,4).'-5'.substr($h,13,3).'-a'.substr($h,17,3).'-'.substr($h,20,12);}
        else $uuid=(string)Db::val('SELECT uuid FROM '.Db::t($entity==='party'?'parties':'vouchers').' WHERE company_id=%d AND id=%d',$cid,$targetId);
        Business::save('import_map',$cid,0,['source_system'=>$b['source_system'],'entity'=>$entity,'source_code'=>$code,'source_name'=>mb_substr($name,0,190),'source_parent'=>$parent,'target_uuid'=>$uuid,'target_id'=>$targetId,'link'=>$link,'mapping_version'=>(int)$b['mapping_version'],'batch_id'=>(int)$b['id']]);
    }
    /** کد آزاد بعدی معین زیر یک حساب کل (کد کل + دو رقم). */
    private static function nextAccountCode($cid,array $group){
        $used=array_flip(Db::col('SELECT code FROM '.Db::t('accounts').' WHERE company_id=%d AND parent_id=%d',$cid,(int)$group['id']));
        for($i=1;$i<=99;$i++){$c=$group['code'].str_pad((string)$i,2,'0',STR_PAD_LEFT);if(!isset($used[$c]))return $c;}
        throw new ConflictException('زیر حساب کل «'.$group['code'].'» کد آزاد معین نمانده است.');
    }
    /**
     * ورود واقعی: تحلیل دوباره زیر قفل شرکت و برابری با snapshot پیش‌نمایش. هر ردیف با کد منبع در نگاشت ثبت می‌شود.
     * ماندهٔ افتتاحیه سند «افتتاحیه»ی پیش‌نویس می‌سازد (بیش از ۱۹۰ ردیف: چند سند، هر کدام با حساب افتتاحیه تراز).
     */
    public static function post($cid,$id,$snapshot){
        $b0=self::batch($cid,$id);
        return Access::write($cid,self::cap($b0['kind']),function()use($cid,$id,$snapshot){
            $b=Business::row('import_batches',$cid,$id,true);if(!in_array($b['status'],['preview','partial'],true))throw new ConflictException('این دسته وارد یا لغو شده است؛ دوباره وارد نمی‌شود.');
            $fresh=self::analyze($cid,$id);$b=Business::row('import_batches',$cid,$id);
            if(!is_string($snapshot)||!hash_equals($fresh,$snapshot))throw new ConflictException('داده یا کدینگ پس از پیش‌نمایش تغییر کرده است؛ پیش‌نمایش تازه را ببینید و دوباره تأیید کنید.');
            $rows=self::rows($cid,$id);$kind=$b['kind'];$made=0;
            if($kind==='opening'){
                if((int)$b['rows_error']>0)throw new ValidationException(['batch'=>'ماندهٔ افتتاحیه '.(int)$b['rows_error'].' ردیف خطادار دارد؛ هیچ سندی ساخته نشد.']);
                $date=json_decode((string)$b['options'],true)['date'];$fy=FiscalService::assertOpen($cid,$date);$lines=[];
                foreach($rows as $r){$d=json_decode($r['data'],true);$lines[]=['account_id'=>$d['account_id'],'detail_id'=>$d['detail_id'],'description'=>$d['description'],'debit'=>$d['debit'],'credit'=>$d['credit']];}
                $chunks=array_chunk($lines,190);$vids=[];$openAcc=count($chunks)>1?(int)AccountService::bySysKey($cid,'opening_balance')['id']:0;
                foreach($chunks as $i=>$chunk){
                    if($openAcc){$dr=0;$cr=0;foreach($chunk as $l){$dr+=$l['debit'];$cr+=$l['credit'];}if($dr!==$cr)$chunk[]=['account_id'=>$openAcc,'detail_id'=>0,'description'=>'تراز بخش '.($i+1),'debit'=>$dr<$cr?$cr-$dr:0,'credit'=>$dr>$cr?$dr-$cr:0];}
                    $vids[]=VoucherService::save($cid,0,['voucher_date'=>$date,'type'=>'opening','description'=>mb_substr('ماندهٔ افتتاحیه از '.$b['source_system'].' — دستهٔ ورود '.$id.(count($chunks)>1?' — بخش '.($i+1):''),0,500)],$chunk,0,'rial');
                }
                self::remember($cid,$b,'opening','fy-'.$fy['id'],'ماندهٔ افتتاحیه','',$vids[0],'created');
                foreach($rows as $r)Db::update('import_rows',['status'=>'posted','voucher_id'=>$vids[0],'decided_by'=>Ctx::uid(),'decided_at'=>gmdate('Y-m-d H:i:s')],['company_id'=>$cid,'id'=>$r['id']]);
                $made=count($vids);$status='posted';
            }else{
                $entity=self::ENTITY[$kind];
                foreach($rows as $r){
                    if(!in_array($r['status'],['ok','link'],true))continue;$d=json_decode($r['data'],true);
                    if($kind==='accounts'){
                        if($r['status']==='link')$target=(int)$d['account_id'];
                        else{$g=AccountService::get($cid,(int)$d['group_id']);$target=(int)AccountService::create($cid,['parent_id'=>(int)$g['id'],'code'=>self::nextAccountCode($cid,$g),'name'=>$d['name'],'detail_rule'=>'optional','detail_kind'=>'']);}
                        self::remember($cid,$b,'account',$d['code'],$d['name'],$d['parent'],$target,$r['status']==='link'?'linked':'created');
                    }else{
                        if($r['status']==='link')$target=(int)$d['party_id'];
                        else $target=(int)PartyService::save($cid,0,['name'=>$d['name'],'kind'=>$d['kind'],'national_id'=>$d['national'],'economic_code'=>$d['economic'],'phone'=>$d['phone'],'is_customer'=>$d['is_customer'],'is_supplier'=>$d['is_supplier'],'is_active'=>1]);
                        self::remember($cid,$b,'party',$d['code'],$d['name'],'',$target,$r['status']==='link'?'linked':'created');
                    }
                    Db::update('import_rows',['status'=>'posted','data'=>wp_json_encode($d+['target_id'=>$target],JSON_UNESCAPED_UNICODE),'decided_by'=>Ctx::uid(),'decided_at'=>gmdate('Y-m-d H:i:s')],['company_id'=>$cid,'id'=>$r['id']]);$made++;
                }
                if(!$made&&!(int)$b['rows_error'])throw new ConflictException('ردیف تازه‌ای برای ورود نیست؛ همهٔ کدها قبلاً وارد شده‌اند.');
                if(!$made)throw new ValidationException(['batch'=>'هیچ ردیف سالمی برای ورود نیست؛ خطاها را اصلاح کنید.']);
                $status=(int)$b['rows_error']>0?'partial':'posted';
            }
            Db::update('import_batches',['status'=>$status,'posted_by'=>Ctx::uid(),'posted_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')],['company_id'=>$cid,'id'=>$id]);
            Audit::log('migrate.post','import_batch',$id,null,['kind'=>$kind,'made'=>$made,'status'=>$status,'rows_error'=>(int)$b['rows_error'],'snapshot'=>$snapshot],$cid);
            return ['made'=>$made,'status'=>$status];
        });
    }
    /** لغو فقط دستهٔ بدون ردیف واردشده؛ حذف فقط دستهٔ لغوشده. */
    public static function cancel($cid,$id){$b=self::batch($cid,$id);return Access::write($cid,self::cap($b['kind']),function()use($cid,$id){$b=Business::row('import_batches',$cid,$id,true);if($b['status']!=='preview')throw new ConflictException('فقط دستهٔ واردنشده لغو می‌شود؛ رکوردهای واردشده از صفحهٔ خودشان حذف یا غیرفعال می‌شوند.');Db::update('import_batches',['status'=>'cancelled','updated_at'=>gmdate('Y-m-d H:i:s')],['company_id'=>$cid,'id'=>$id]);Audit::log('migrate.cancel','import_batch',$id,null,null,$cid);return true;});}
    public static function delete($cid,$id){$b=self::batch($cid,$id);return Access::write($cid,self::cap($b['kind']),function()use($cid,$id){$b=Business::row('import_batches',$cid,$id,true);if($b['status']!=='cancelled')throw new ConflictException('ابتدا دسته را لغو کنید.');Db::delete('import_batches',['company_id'=>$cid,'id'=>$id]);Audit::log('migrate.delete','import_batch',$id,['file'=>$b['file_name'],'sha256'=>$b['file_sha256']],null,$cid);return true;});}
    /**
     * حذف یک نگاشت (مثلاً نگاشت اشتباه به حساب موجود). رکورد آگاه حذف نمی‌شود؛ فقط پیوند کد مبدأ برداشته می‌شود
     * تا ورود بعدی همان کد را دوباره بررسی کند.
     */
    public static function forget($cid,$mapId){
        return Access::write($cid,'account.edit',function()use($cid,$mapId){
            $m=Business::row('import_map',$cid,$mapId,true);if($m['entity']==='opening'&&Db::val('SELECT id FROM '.Db::t('vouchers')." WHERE company_id=%d AND id=%d AND status<>'void'",$cid,(int)$m['target_id']))throw new ConflictException('سند افتتاحیهٔ این نگاشت هنوز معتبر است؛ ابتدا آن را حذف یا باطل کنید.');
            Db::delete('import_map',['company_id'=>$cid,'id'=>$mapId]);Audit::log('migrate.forget','import_map',$mapId,$m,null,$cid);return true;
        });
    }
    /** قالب خام هر نوع (نام برگه = نسخهٔ قالب). */
    public static function template($cid,$kind){
        if(!isset(self::KINDS[$kind]))throw new ValidationException(['kind'=>'نوع معتبر نیست.']);Access::need($cid,self::cap($kind));
        $sample=['accounts'=>[['1101','صندوق مرکزی','11','',''],['2101','حساب‌های پرداختنی تجاری','21','','']],'parties'=>[['C-001','نمونه مشتری','حقیقی','','','','مشتری']],'opening'=>[['1101','','مانده اول دوره — نمونه','1000',''],['2101','','مانده اول دوره — نمونه','','1000']]][$kind];
        return ['headers'=>array_values(self::FIELDS[$kind]),'rows'=>$sample,'name'=>self::TEMPLATE.'-'.$kind];
    }
}
