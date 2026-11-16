<?php
/**
 * صورتحساب بانک و ثبت هوشمند مرحلهٔ ۱ (بدون هوش مصنوعی و بدون سرویس بیرونی).
 * - هر ردیف: «تکراری» (همان حساب/تاریخ/مبلغ/جهت/مرجع در دستهٔ دیگر)، «تطبیق‌شده» (دقیقاً یک ردیف دفتر بانک با همان مبلغ و جهت
 *   در بازهٔ ±۳ روز)، «مبهم» (چند نامزد؛ فقط فرد مجاز یکی را انتخاب می‌کند) یا «باز».
 * - برای ردیف باز، پیشنهاد به ترتیب: فاکتور با ماندهٔ دقیقاً برابر، قاعدهٔ نسخه‌دار، حافظهٔ یادگیری، نام طرف‌حساب در شرح.
 * - «سند بزن» فقط سند پیش‌نویس می‌سازد؛ قطعی شدن با تأیید انسان در گردش عادی اسناد است. تسویهٔ فاکتور از مسیر برگهٔ دریافت/پرداخت.
 * - هر تصمیم کاربر، زمان و منبع پیشنهاد (و نسخهٔ قاعده) را نگه می‌دارد و در ممیزی ثبت می‌شود.
 */
namespace Agah\Erp\Modules\Import;
use Agah\Erp\Core\{Access,Audit,Business,ConflictException,Ctx,Db,Money,NotFoundException,Request,ValidationException,XlsxReader};
use Agah\Erp\Modules\Accounting\{AccountService,VoucherService};
use Agah\Erp\Modules\Treasury\TreasuryService;
use Agah\Erp\Modules\Trade\InvoiceService;
if(!defined('ABSPATH'))exit;
final class BankMatchService {
    const WINDOW=3;
    const SOURCES=['manual'=>'انتخاب دستی','rule'=>'قاعده','memory'=>'حافظهٔ یادگیری','party'=>'نام طرف‌حساب','invoice'=>'فاکتور','auto'=>'خودکار'];

    /** متن یکنواخت برای مقایسه: حروف عربی به فارسی، ارقام فارسی به لاتین، حذف نشانه‌ها. */
    public static function norm($s){
        $s=strtr((string)$s,['ي'=>'ی','ك'=>'ک','ة'=>'ه','ۀ'=>'ه','أ'=>'ا','إ'=>'ا',"\u{200C}"=>' ','۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']);
        return trim(preg_replace('/\s+/u',' ',preg_replace('/[^\p{L}\p{N}]+/u',' ',mb_strtolower($s))));
    }
    /** اثر انگشت حافظه: شش واژهٔ اول شرح بدون عدد (شماره پیگیری و تاریخ در آن اثر ندارد). */
    public static function fingerprint($s){$t=trim(preg_replace('/\s+/u',' ',preg_replace('/\p{N}+/u',' ',self::norm($s))));if(mb_strlen($t)<3)return null;return hash('sha256',implode(' ',array_slice(explode(' ',$t),0,6)));}

    private static function account($cid,$id){return Business::row('treasury_accounts',$cid,$id);}
    /** نامزدهای دفتر: ردیف سند تأییدشدهٔ همان بانک، همان مبلغ و جهت، در بازهٔ زمانی، و بدون پیوند به ردیف دیگر. */
    public static function candidates($cid,array $acc,array $row,array $taken=[]){
        if(!$row['txn_date']||!(int)$row['amount'])return [];
        $from=(new \DateTimeImmutable($row['txn_date']))->modify('-'.self::WINDOW.' days')->format('Y-m-d');$to=(new \DateTimeImmutable($row['txn_date']))->modify('+'.self::WINDOW.' days')->format('Y-m-d');
        $in=$row['direction']==='in';
        $rows=Db::rows('SELECT l.id,l.voucher_id,l.description,l.debit,l.credit,v.number,v.voucher_date FROM '.Db::t('voucher_lines').' l JOIN '.Db::t('vouchers')." v ON v.company_id=l.company_id AND v.id=l.voucher_id WHERE l.company_id=%d AND l.account_id=%d AND l.detail_id=%d AND v.status IN ('approved','final') AND v.voucher_date BETWEEN %s AND %s AND l.debit=%d AND l.credit=%d
            AND NOT EXISTS (SELECT 1 FROM ".Db::t('import_rows').' x JOIN '.Db::t('import_batches')." xb ON xb.company_id=x.company_id AND xb.id=x.batch_id WHERE x.company_id=l.company_id AND x.match_line_id=l.id AND x.status='matched' AND xb.status<>'cancelled' AND x.id<>%d)
            ORDER BY ABS(DATEDIFF(v.voucher_date,%s)),l.id LIMIT 10",$cid,$acc['account_id'],$acc['detail_id'],$from,$to,$in?(int)$row['amount']:0,$in?0:(int)$row['amount'],(int)$row['id'],$row['txn_date']);
        return array_values(array_filter($rows,function($l)use($taken){return !isset($taken[(int)$l['id']]);}));
    }
    /** خواندن و تطبیق ردیف‌ها. $reset=false یعنی تصمیم‌های انسانی و ردیف‌های سندخورده دست نمی‌خورند. */
    public static function analyze($cid,array $b,$reset){
        $map=json_decode($b['mapping'],true)?:[];$acc=self::account($cid,$b['treasury_account_id']);$unit=$b['unit'];
        $mapped=isset($map['date'])&&(isset($map['amount'])||isset($map['deposit'],$map['withdrawal']));
        $cell=function($raw,$f)use($map){return isset($map[$f])?trim((string)($raw[$map[$f]]??'')):'';};
        $errors=0;$taken=[];
        foreach(ImportService::rows($cid,$b['id']) as $r){
            if(!$reset&&(in_array($r['status'],['posted','ignored'],true)||in_array($r['decision'],['manual','invoice'],true))){if($r['match_line_id'])$taken[(int)$r['match_line_id']]=1;continue;}
            $raw=json_decode($r['raw'],true)?:[];$err=[];
            $date=$mapped?XlsxReader::date($cell($raw,'date')):null;if($mapped&&!$date)$err[]='تاریخ معتبر نیست.';
            $amount=0;$dir='';
            if($mapped){
                if(isset($map['amount'])){$v=XlsxReader::amount($cell($raw,'amount'),$unit);if($v===null)$err[]='مبلغ معتبر نیست.';else{$amount=abs($v);$dir=$v>0?'in':($v<0?'out':'');}}
                else{$dep=XlsxReader::amount($cell($raw,'deposit'),$unit);$wd=XlsxReader::amount($cell($raw,'withdrawal'),$unit);
                    if($dep===null||$wd===null||$dep<0||$wd<0)$err[]='مبلغ واریز/برداشت معتبر نیست.';elseif($dep>0&&$wd>0)$err[]='هر دو ستون واریز و برداشت مقدار دارند.';else{$amount=max($dep,$wd);$dir=$dep>0?'in':($wd>0?'out':'');}}
                if($amount>Money::MAX)$err[]='مبلغ بیش از حد مجاز است.';
            }else $err[]='نگاشت ستون‌ها کامل نیست.';
            $row=['id'=>(int)$r['id'],'txn_date'=>$date,'amount'=>$err?0:$amount,'direction'=>$dir,'reference'=>Request::clean(Money::normDigits($cell($raw,'reference')),80),'description'=>Request::clean($cell($raw,'description'),500)];
            $status='open';$match=null;$data=[];$msg='';
            if($err){$status='error';$msg=implode(' ',$err);$errors++;}
            elseif(!$amount){$status='skipped';}
            elseif($dup=Db::row('SELECT r.row_no,r.batch_id FROM '.Db::t('import_rows').' r JOIN '.Db::t('import_batches')." b ON b.company_id=r.company_id AND b.id=r.batch_id WHERE r.company_id=%d AND r.treasury_account_id=%d AND r.txn_date=%s AND r.amount=%d AND r.direction=%s AND r.batch_id<>%d AND b.status<>'cancelled' AND r.status NOT IN ('error','duplicate','skipped') AND (%s='' OR r.reference='' OR r.reference=%s) LIMIT 1",$cid,$acc['id'],$date,$amount,$dir,$b['id'],$row['reference'],$row['reference'])){$status='duplicate';$msg='تکرار ردیف '.(int)$dup['row_no'].' از دستهٔ '.(int)$dup['batch_id'].'؛ دوباره ثبت نمی‌شود.';}
            else{$c=self::candidates($cid,$acc,$row,$taken);if(count($c)===1){$status='matched';$match=(int)$c[0]['id'];$taken[$match]=1;}elseif(count($c)>1){$status='ambiguous';$data['candidates']=array_map('intval',array_column($c,'id'));}}
            Db::update('import_rows',['status'=>$status,'error'=>mb_substr($msg,0,500),'txn_date'=>$date,'direction'=>$dir,'amount'=>$row['amount'],'reference'=>$row['reference'],'description'=>$row['description'],'data'=>wp_json_encode($data),'match_line_id'=>$match,'decision'=>$match?'auto':'','rule_id'=>null,'voucher_id'=>null,'treasury_doc_id'=>null,'decided_by'=>0,'decided_at'=>null,'updated_at'=>gmdate('Y-m-d H:i:s')],['company_id'=>$cid,'id'=>$r['id']]);
        }
        Db::update('import_batches',['rows_error'=>$errors,'updated_at'=>gmdate('Y-m-d H:i:s')],['company_id'=>$cid,'id'=>$b['id']]);
        $amb=(int)Db::val('SELECT COUNT(*) FROM '.Db::t('import_rows')." WHERE company_id=%d AND batch_id=%d AND status='ambiguous'",$cid,$b['id']);
        if($amb)\Agah\Erp\Modules\Notify\NotificationService::notify($cid,\Agah\Erp\Modules\Notify\NotificationService::holders($cid,'treasury_account.edit'),'reconcile',$amb.' تراکنش مبهم در صورتحساب بانک منتظر انتخاب است','',agah_erp_url(['c'=>$cid,'p'=>'import_bank','id'=>$b['id']]),'bank-amb|'.$b['id']);
        return '';
    }
    /** وضعیت زنده: اگر سند پیش‌نویس حذف یا ابطال شده یا ردیف دفتر از بین رفته باشد، ردیف دوباره «باز» دیده می‌شود. */
    public static function live($cid,array $r){
        if($r['status']==='posted'&&$r['voucher_id']&&!in_array(Db::val('SELECT status FROM '.Db::t('vouchers').' WHERE company_id=%d AND id=%d',$cid,$r['voucher_id']),['draft','approved','final'],true)){$r['status']='open';$r['stale']=1;}
        if($r['status']==='matched'&&$r['match_line_id']&&!in_array(Db::val('SELECT v.status FROM '.Db::t('voucher_lines').' l JOIN '.Db::t('vouchers').' v ON v.company_id=l.company_id AND v.id=l.voucher_id WHERE l.company_id=%d AND l.id=%d',$cid,$r['match_line_id']),['approved','final'],true)){$r['status']='open';$r['stale']=1;}
        return $r;
    }
    private static function lockRow($cid,$id){
        $r=Business::row('import_rows',$cid,$id,true);$b=Business::row('import_batches',$cid,$r['batch_id'],true);
        if($b['kind']!=='bank')throw new NotFoundException();if($b['status']!=='active')throw new ConflictException('این دسته بسته شده است.');
        return [self::live($cid,$r),$b,self::account($cid,$b['treasury_account_id'])];
    }
    private static function decide($cid,$id,array $values,$event,array $audit){
        Db::update('import_rows',$values+['decided_by'=>Ctx::uid(),'decided_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')],['company_id'=>$cid,'id'=>$id]);
        Audit::log($event,'import_row',$id,null,$audit,$cid);
    }

    // ------------------------------------------------------------ پیشنهادها

    public static function suggest($cid,array $r,array $acc){
        $out=[];if($r['status']!=='open'||!(int)$r['amount'])return $out;$in=$r['direction']==='in';$text=self::norm($r['description']);
        $kinds=$in?['sale','purchase_return']:['purchase','sale_return'];
        foreach(Db::rows('SELECT i.id,i.kind,i.number,i.party_id,p.name AS party_name FROM '.Db::t('invoices').' i JOIN '.Db::t('parties')." p ON p.company_id=i.company_id AND p.id=i.party_id WHERE i.company_id=%d AND i.status='issued' AND i.voucher_id IS NOT NULL AND i.kind IN ('".implode("','",$kinds)."') AND i.amount>=%d ORDER BY i.due_date,i.id LIMIT 200",$cid,(int)$r['amount']) as $i){
            if(InvoiceService::outstanding($cid,(int)$i['id'])!==(int)$r['amount'])continue;
            $out[]=['source'=>'invoice','invoice_id'=>(int)$i['id'],'label'=>'تسویهٔ فاکتور '.Money::toFa((string)$i['number']).' — '.$i['party_name'],'named'=>$text!==''&&mb_strpos($text,self::norm($i['party_name']))!==false];
            if(count($out)>=5)break;
        }
        usort($out,function($a,$b){return (int)$b['named']-(int)$a['named'];});
        if($rule=self::matchRule($cid,$r))$out[]=['source'=>'rule','rule_id'=>(int)$rule['id'],'account_id'=>(int)$rule['account_id'],'detail_id'=>(int)$rule['detail_id'],'label'=>'قاعدهٔ «'.$rule['name'].'» (نسخهٔ '.Money::toFa((string)$rule['version']).')'];
        if($m=self::recall($cid,$r['description'],$r['direction']))$out[]=['source'=>'memory','account_id'=>(int)$m['account_id'],'detail_id'=>(int)$m['detail_id'],'label'=>'حافظه: «'.$m['sample'].'» ('.Money::toFa((string)$m['hits']).' بار)'];
        if($text!==''){$sys=$in?'receivable':'payable';$a=AccountService::bySysKey($cid,$sys);
            foreach(Db::rows('SELECT id,name,detail_id FROM '.Db::t('parties').' WHERE company_id=%d AND is_active=1 AND '.($in?'is_customer':'is_supplier').'=1 ORDER BY id LIMIT 2000',$cid) as $p){$n=self::norm($p['name']);if(mb_strlen($n)>=3&&mb_strpos($text,$n)!==false){$out[]=['source'=>'party','account_id'=>(int)$a['id'],'detail_id'=>(int)$p['detail_id'],'label'=>($in?'دریافت از ':'پرداخت به ').$p['name']];if(count($out)>=8)break;}}}
        return $out;
    }

    // ------------------------------------------------------------ تصمیم‌ها

    /** تأیید یکی از نامزدهای دفتر برای ردیف مبهم (یا باز)؛ فقط فرد دارای مجوز ویرایش حساب بانک. */
    public static function confirm($cid,$id,$lineId){
        return Access::write($cid,'treasury_account.edit',function()use($cid,$id,$lineId){
            [$r,$b,$acc]=self::lockRow($cid,$id);if(!in_array($r['status'],['ambiguous','open'],true))throw new ConflictException('این ردیف منتظر انتخاب نیست.');
            $ids=array_map('intval',array_column(self::candidates($cid,$acc,$r),'id'));if(!in_array((int)$lineId,$ids,true))throw new ValidationException(['line_id'=>'ردیف دفتر انتخاب‌شده نامزد معتبر این تراکنش نیست.']);
            self::decide($cid,$id,['status'=>'matched','match_line_id'=>(int)$lineId,'decision'=>'manual','voucher_id'=>null],'import.bank.confirm',['line'=>(int)$lineId,'candidates'=>$ids]);return true;
        });
    }
    public static function unmatch($cid,$id){
        return Access::write($cid,'treasury_account.edit',function()use($cid,$id){
            [$r]=self::lockRow($cid,$id);if($r['status']!=='matched'||$r['treasury_doc_id'])throw new ConflictException('فقط تطبیق بدون برگهٔ ساخته‌شده برداشته می‌شود.');
            self::decide($cid,$id,['status'=>'open','match_line_id'=>null,'decision'=>'manual'],'import.bank.unmatch',['line'=>(int)$r['match_line_id']]);return true;
        });
    }
    public static function ignore($cid,$id,$reason){
        return Access::write($cid,'treasury_account.edit',function()use($cid,$id,$reason){
            [$r]=self::lockRow($cid,$id);if(in_array($r['status'],['posted','ignored'],true)||$r['treasury_doc_id'])throw new ConflictException('این ردیف قابل کنار گذاشتن نیست.');
            $reason=Business::reason($reason);self::decide($cid,$id,['status'=>'ignored','error'=>$reason,'match_line_id'=>null],'import.bank.ignore',['reason'=>$reason]);return true;
        });
    }
    public static function restore($cid,$id){
        return Access::write($cid,'treasury_account.edit',function()use($cid,$id){
            [$r,$b,$acc]=self::lockRow($cid,$id);if($r['status']!=='ignored')throw new ConflictException('فقط ردیف کنارگذاشته بازگردانده می‌شود.');
            $c=self::candidates($cid,$acc,$r);$status=count($c)===1?'matched':(count($c)>1?'ambiguous':'open');
            self::decide($cid,$id,['status'=>$status,'error'=>'','match_line_id'=>$status==='matched'?(int)$c[0]['id']:null,'decision'=>$status==='matched'?'auto':'','data'=>wp_json_encode($status==='ambiguous'?['candidates'=>array_map('intval',array_column($c,'id'))]:[])],'import.bank.restore',[]);return true;
        });
    }
    /**
     * «سند بزن»: سند پیش‌نویس دوطرفه (بانک ↔ حساب انتخابی). منبع پیشنهاد و نسخهٔ قاعده ثبت و حساب انتخابی در حافظه یاد گرفته می‌شود.
     * انتخاب نهایی همیشه با انسان است؛ پیشنهاد فقط پیش‌فرض فرم است.
     */
    public static function post($cid,$id,array $in){
        return Access::write($cid,'treasury_account.edit',function()use($cid,$id,$in){
            [$r,$b,$acc]=self::lockRow($cid,$id);if($r['status']!=='open'||!(int)$r['amount']||!$r['txn_date'])throw new ConflictException('برای این ردیف سند زده نمی‌شود (باز نیست یا مبلغ/تاریخ ندارد).');
            $source=$in['source']??'manual';if(!isset(self::SOURCES[$source])||in_array($source,['invoice','auto'],true))$source='manual';
            $account=Business::id($in['account_id']??0);$detail=Business::id($in['detail_id']??0);
            // منبع فقط وقتی ثبت می‌شود که حساب واقعاً همان پیشنهاد باشد؛ وگرنه «انتخاب دستی».
            $ruleId=null;if($source==='rule'){$rule=Business::row('posting_rules',$cid,Business::id($in['rule_id']??0));if((int)$rule['account_id']===$account&&(int)$rule['detail_id']===$detail)$ruleId=(int)$rule['id'];else $source='manual';}
            if($source==='memory'){$m=self::recall($cid,$r['description'],$r['direction']);if(!$m||(int)$m['account_id']!==$account||(int)$m['detail_id']!==$detail)$source='manual';}
            $desc=Request::clean($in['description']??'',300);if($desc==='')$desc=$r['description']!==''?$r['description']:'تراکنش بانک';
            $amount=(int)$r['amount'];$inflow=$r['direction']==='in';
            $lines=[['account_id'=>(int)$acc['account_id'],'detail_id'=>(int)$acc['detail_id'],'description'=>$desc,'debit'=>$inflow?$amount:0,'credit'=>$inflow?0:$amount],['account_id'=>$account,'detail_id'=>$detail,'description'=>$desc,'debit'=>$inflow?0:$amount,'credit'=>$inflow?$amount:0]];
            $vid=VoucherService::save($cid,0,['voucher_date'=>$r['txn_date'],'description'=>mb_substr($desc.' — صورتحساب '.$acc['name'].($r['reference']!==''?'، مرجع '.$r['reference']:''),0,500)],$lines,0,'rial');
            self::learn($cid,$r['description'],$r['direction'],$account,$detail);
            self::decide($cid,$id,['status'=>'posted','voucher_id'=>$vid,'decision'=>$source,'rule_id'=>$ruleId],'import.bank.voucher',['voucher'=>$vid,'source'=>$source,'rule'=>$ruleId,'account'=>$account,'detail'=>$detail]);
            return $vid;
        });
    }
    /** تسویهٔ فاکتور: برگهٔ دریافت/پرداخت با روش حواله و تخصیص به همان فاکتور؛ ردیف به ردیف بانکی سند برگه پیوند می‌خورد. */
    public static function settleInvoice($cid,$id,$invoiceId){
        return Access::write($cid,'treasury_account.edit',function()use($cid,$id,$invoiceId){
            [$r,$b,$acc]=self::lockRow($cid,$id);if($r['status']!=='open'||!(int)$r['amount'])throw new ConflictException('این ردیف باز نیست.');
            $inv=Business::row('invoices',$cid,Business::id($invoiceId));$in=$r['direction']==='in';
            if(!in_array($inv['kind'],$in?['sale','purchase_return']:['purchase','sale_return'],true))throw new ValidationException(['invoice_id'=>'نوع فاکتور با جهت تراکنش سازگار نیست.']);
            $doc=TreasuryService::issue($cid,['kind'=>$in?'receipt':'payment','doc_date'=>$r['txn_date'],'party_id'=>(int)$inv['party_id'],'description'=>mb_substr('صورتحساب '.$acc['name'].' — فاکتور '.$inv['number'].($r['reference']!==''?'، مرجع '.$r['reference']:''),0,500)],[['method'=>'transfer','treasury_account_id'=>(int)$acc['id'],'amount'=>(int)$r['amount'],'reference'=>$r['reference']]],[['invoice_id'=>(int)$inv['id'],'amount'=>(int)$r['amount']]]);
            $vid=(int)Db::val('SELECT voucher_id FROM '.Db::t('treasury_docs').' WHERE company_id=%d AND id=%d',$cid,$doc);
            $line=(int)Db::val('SELECT id FROM '.Db::t('voucher_lines').' WHERE company_id=%d AND voucher_id=%d AND account_id=%d AND detail_id=%d LIMIT 1',$cid,$vid,$acc['account_id'],$acc['detail_id']);
            self::decide($cid,$id,['status'=>'matched','match_line_id'=>$line?:null,'treasury_doc_id'=>$doc,'decision'=>'invoice'],'import.bank.invoice',['invoice'=>(int)$inv['id'],'treasury_doc'=>$doc]);
            return $doc;
        });
    }

    // ------------------------------------------------------------ قاعده‌ها (نسخه‌دار)

    public static function rules($cid,$all=false){Access::need($cid,'treasury_account.edit');return Db::rows('SELECT r.*,a.code AS account_code,a.name AS account_name,d.name AS detail_name FROM '.Db::t('posting_rules').' r JOIN '.Db::t('accounts').' a ON a.company_id=r.company_id AND a.id=r.account_id LEFT JOIN '.Db::t('details').' d ON d.company_id=r.company_id AND d.id=r.detail_id WHERE r.company_id=%d'.($all?'':' AND r.is_active=1').' ORDER BY r.priority,r.id',$cid);}
    public static function matchRule($cid,array $r){
        $text=self::norm($r['description']);if($text==='')return null;
        foreach(Db::rows('SELECT * FROM '.Db::t('posting_rules').' WHERE company_id=%d AND is_active=1 ORDER BY priority,id',$cid) as $rule){
            if($rule['direction']!=='any'&&$rule['direction']!==$r['direction'])continue;$needle=self::norm($rule['contains_text']);
            if($needle!==''&&mb_strpos($text,$needle)!==false)return $rule;
        }
        return null;
    }
    /** ذخیره: قاعدهٔ تازه نسخهٔ ۱ است؛ ویرایش، نسخهٔ بعدی می‌سازد و نسخهٔ قبلی غیرفعال اما ماندگار است. */
    public static function saveRule($cid,$id,array $in){
        return Access::write($cid,'treasury_account.edit',function()use($cid,$id,$in){
            $old=$id?Business::row('posting_rules',$cid,$id,true):null;if($old&&!$old['is_active'])throw new ConflictException('فقط نسخهٔ فعال قاعده ویرایش می‌شود.');
            $name=Request::clean($in['name']??'',100);$text=Request::clean($in['contains_text']??'',100);$dir=$in['direction']??'any';$priority=(int)($in['priority']??100);
            $errors=[];if(mb_strlen($name)<2)$errors['name']='نام قاعده حداقل دو نویسه باشد.';if(mb_strlen(self::norm($text))<2)$errors['contains_text']='عبارت جست‌وجو حداقل دو نویسه باشد.';if(!in_array($dir,['in','out','any'],true))$errors['direction']='جهت معتبر نیست.';if($priority<1||$priority>1000)$errors['priority']='اولویت بین ۱ تا ۱۰۰۰ باشد.';
            $a=Business::row('accounts',$cid,Business::id($in['account_id']??0));$detail=Business::id($in['detail_id']??0);
            if((int)$a['level']!==3||!(int)$a['is_active'])$errors['account_id']='حساب معین فعال انتخاب کنید.';
            elseif($a['detail_rule']==='required'&&!$detail)$errors['detail_id']='این حساب تفصیلی لازم دارد.';elseif($a['detail_rule']==='none'&&$detail)$errors['detail_id']='این حساب تفصیلی نمی‌پذیرد.';
            if($detail){$d=Business::row('details',$cid,$detail);if(!(int)$d['is_active']||($a['detail_kind']&&$a['detail_kind']!==$d['kind']))$errors['detail_id']='تفصیلی با حساب سازگار نیست.';}
            if($errors)throw new ValidationException($errors);
            $new=['name'=>$name,'contains_text'=>$text,'direction'=>$dir,'account_id'=>(int)$a['id'],'detail_id'=>$detail?:null,'priority'=>$priority,'version'=>$old?(int)$old['version']+1:1,'root_id'=>$old?((int)$old['root_id']?:(int)$old['id']):0,'is_active'=>1,'created_by'=>Ctx::uid()];
            if($old)Db::update('posting_rules',['is_active'=>0,'updated_at'=>gmdate('Y-m-d H:i:s')],['company_id'=>$cid,'id'=>$old['id']]);
            $nid=Business::save('posting_rules',$cid,0,$new);Audit::log($old?'posting_rule.version':'posting_rule.create','posting_rule',$nid,$old,$new,$cid);return $nid;
        });
    }
    public static function deactivateRule($cid,$id){return Access::write($cid,'treasury_account.edit',function()use($cid,$id){$r=Business::row('posting_rules',$cid,$id,true);Db::update('posting_rules',['is_active'=>0,'updated_at'=>gmdate('Y-m-d H:i:s')],['company_id'=>$cid,'id'=>$id]);Audit::log('posting_rule.deactivate','posting_rule',$id,$r,null,$cid);return true;});}
    /** حذف فقط وقتی هیچ نسخه‌ای از قاعده در تصمیمی به کار نرفته باشد؛ وگرنه فقط غیرفعال می‌شود تا ردپای تصمیم بماند. */
    public static function deleteRule($cid,$id){
        return Access::write($cid,'treasury_account.edit',function()use($cid,$id){
            $r=Business::row('posting_rules',$cid,$id,true);$root=(int)$r['root_id']?:(int)$r['id'];
            $ids=array_map('intval',Db::col('SELECT id FROM '.Db::t('posting_rules').' WHERE company_id=%d AND (id=%d OR root_id=%d)',$cid,$root,$root));
            if(Db::val('SELECT id FROM '.Db::t('import_rows').' WHERE company_id=%d AND rule_id IN ('.Db::in($ids).') LIMIT 1',$cid))throw new ConflictException('این قاعده در تصمیم‌های ثبت‌شده به کار رفته است؛ به‌جای حذف، غیرفعالش کنید.');
            Db::exec('DELETE FROM '.Db::t('posting_rules').' WHERE company_id=%d AND id IN ('.Db::in($ids).')',$cid);Audit::log('posting_rule.delete','posting_rule',$id,$r,['versions'=>$ids],$cid);return true;
        });
    }

    // ------------------------------------------------------------ حافظهٔ یادگیری

    public static function learn($cid,$description,$direction,$account,$detail){
        $fp=self::fingerprint($description);if(!$fp||!in_array($direction,['in','out'],true))return;
        $old=Db::row('SELECT id,account_id,detail_id,hits FROM '.Db::t('posting_memory').' WHERE company_id=%d AND fingerprint=%s AND direction=%s FOR UPDATE',$cid,$fp,$direction);$now=gmdate('Y-m-d H:i:s');
        if($old){$same=(int)$old['account_id']===(int)$account&&(int)$old['detail_id']===(int)$detail;Db::update('posting_memory',['account_id'=>(int)$account,'detail_id'=>$detail?:null,'hits'=>$same?(int)$old['hits']+1:1,'last_used_at'=>$now,'updated_at'=>$now],['company_id'=>$cid,'id'=>$old['id']]);}
        else Business::save('posting_memory',$cid,0,['fingerprint'=>$fp,'sample'=>mb_substr(self::norm($description),0,200),'direction'=>$direction,'account_id'=>(int)$account,'detail_id'=>$detail?:null,'hits'=>1,'last_used_at'=>$now]);
    }
    public static function recall($cid,$description,$direction){
        $fp=self::fingerprint($description);if(!$fp)return null;
        $m=Db::row('SELECT m.* FROM '.Db::t('posting_memory').' m JOIN '.Db::t('accounts').' a ON a.company_id=m.company_id AND a.id=m.account_id WHERE m.company_id=%d AND m.fingerprint=%s AND m.direction=%s AND a.is_active=1 AND a.level=3',$cid,$fp,$direction);
        return $m?:null;
    }
    public static function memory($cid){Access::need($cid,'treasury_account.edit');return Db::rows('SELECT m.*,a.code AS account_code,a.name AS account_name FROM '.Db::t('posting_memory').' m JOIN '.Db::t('accounts').' a ON a.company_id=m.company_id AND a.id=m.account_id WHERE m.company_id=%d ORDER BY m.hits DESC,m.id DESC LIMIT 200',$cid);}
    public static function forget($cid,$id){return Access::write($cid,'treasury_account.edit',function()use($cid,$id){$m=Business::row('posting_memory',$cid,$id,true);Db::delete('posting_memory',['company_id'=>$cid,'id'=>$id]);Audit::log('posting_memory.delete','posting_memory',$id,$m,null,$cid);return true;});}
}
