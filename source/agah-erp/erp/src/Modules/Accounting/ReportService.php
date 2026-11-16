<?php
/** دفاتر و تراز از خطوط approved/final؛ گزارش‌ها شرکت‌محور و دارای ماندهٔ پیش از بازه‌اند. */
namespace Agah\Erp\Modules\Accounting;
use Agah\Erp\Core\{Db,Access,Money,ValidationException,NotFoundException};
if(!defined('ABSPATH')) exit;
final class ReportService {
    const MODES=['journal'=>'دفتر روزنامه','general'=>'دفتر کل','subsidiary'=>'دفتر معین','activity'=>'گردش حساب','trial2'=>'تراز آزمایشی دو ستونی','trial4'=>'تراز آزمایشی چهار ستونی','trial6'=>'تراز آزمایشی شش ستونی','trial8'=>'تراز آزمایشی هشت ستونی'];
    public static function columns($mode){
        $base=['code'=>'کد','name'=>'حساب'];
        if($mode==='trial2')return $base+['balance_debit'=>'مانده بدهکار','balance_credit'=>'مانده بستانکار'];
        $end=['debit'=>'بدهکار بازه','credit'=>'بستانکار بازه','balance_debit'=>'مانده بدهکار','balance_credit'=>'مانده بستانکار'];
        if($mode==='trial8')return $base+['year_debit'=>'افتتاحیه بدهکار','year_credit'=>'افتتاحیه بستانکار','prior_debit'=>'گردش پیش از بازه بدهکار','prior_credit'=>'گردش پیش از بازه بستانکار']+$end;
        if($mode==='trial6')return $base+['opening_debit'=>'مانده اول بدهکار','opening_credit'=>'مانده اول بستانکار']+$end;
        if($mode==='trial4')return $base+$end;
        if(in_array($mode,['general','subsidiary'],true))return $base+['opening'=>'مانده اول']+$end;
        return ['voucher_date'=>'تاریخ','number'=>'شماره سند']+$base+['detail_name'=>'تفصیلی','description'=>'شرح','debit'=>'بدهکار','credit'=>'بستانکار','balance'=>'مانده'];
    }
    /** فیلترها فقط مقادیرند؛ هیچ نام ستون یا SQL از ورودی ساخته نمی‌شود. بازهٔ شمارهٔ سند با بازهٔ تاریخ اشتراک دارد. */
    private static function filters($cid,array $f) {
        $sql='v.company_id=%d AND l.company_id=%d AND a.company_id=%d AND v.status IN (\'approved\',\'final\')';$args=[$cid,$cid,$cid];
        if(!empty($f['fiscal_year_id'])){FiscalService::get($cid,(int)$f['fiscal_year_id']);$sql.=' AND v.fiscal_year_id=%d';$args[]=(int)$f['fiscal_year_id'];}
        $from=$f['from']??'';$to=$f['to']??'';
        foreach(['from'=>$from,'to'=>$to] as $key=>$date) if($date!=='') {
            $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
            if(!$d || $d->format('Y-m-d')!==$date) throw new ValidationException([$key=>'تاریخ معتبر نیست.']);
        }
        if($from && $to && $from>$to) throw new ValidationException(['to'=>'پایان بازه قبل از شروع است.']);
        [$nf,$nt]=self::numbers($f);
        $account=(int)($f['account_id']??0);$detail=(int)($f['detail_id']??0);
        if($account) {
            $a=AccountService::get($cid,$account);$sql.=' AND a.code LIKE %s';$args[]=$a['code'].'%';
        }
        if($detail) {
            DetailService::get($cid,$detail);$sql.=' AND l.detail_id=%d';$args[]=$detail;
        }
        return [$sql,$args,$from,$to,$nf,$nt];
    }
    /** از شماره / تا شمارهٔ سند؛ صفر یعنی بدون محدودیت. */
    private static function numbers(array $f) {
        $out=[];
        foreach(['no_from','no_to'] as $key){$v=$f[$key]??0;if($v===''||$v===null)$v=0;if(!is_int($v)&&!(is_string($v)&&preg_match('/^\d{1,9}$/D',$v)))throw new ValidationException([$key=>'شمارهٔ سند معتبر نیست.']);$out[]=(int)$v;}
        if($out[0]&&$out[1]&&$out[0]>$out[1]) throw new ValidationException(['no_to'=>'شمارهٔ پایان کمتر از شمارهٔ شروع است.']);
        return $out;
    }
    private static function joins() {
        return ' FROM '.Db::t('voucher_lines').' l JOIN '.Db::t('vouchers').' v ON v.company_id=l.company_id AND v.id=l.voucher_id JOIN '.Db::t('accounts').' a ON a.company_id=l.company_id AND a.id=l.account_id LEFT JOIN '.Db::t('details').' d ON d.company_id=l.company_id AND d.id=l.detail_id';
    }
    /** فهرست اسناد شامل پیش‌نویس‌هاست؛ وضعیت، جست‌وجو و مرتب‌سازی در سرور اعمال می‌شوند. */
    public static function vouchers($cid,array $f,$page=1) {
        Access::need($cid,'voucher.view');
        $where='company_id=%d';$args=[$cid];
        if(!empty($f['status'])) {
            if(!isset(VoucherService::STATES[$f['status']])) throw new ValidationException(['status'=>'وضعیت معتبر نیست.']);
            $where.=' AND status=%s';$args[]=$f['status'];
        }
        foreach(['from'=>'>=','to'=>'<='] as $key=>$op) if(!empty($f[$key])) {
            $date=$f[$key];$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
            if(!$d || $d->format('Y-m-d')!==$date) throw new ValidationException([$key=>'تاریخ معتبر نیست.']);
            $where.=' AND voucher_date'.$op.'%s';$args[]=$date;
        }
        [$nf,$nt]=self::numbers($f);if($nf){$where.=' AND number>=%d';$args[]=$nf;}if($nt){$where.=' AND number<=%d';$args[]=$nt;}
        if(!empty($f['q'])) {global $wpdb;$where.=' AND (description LIKE %s OR CAST(number AS CHAR)=%s)';$args[]='%'.$wpdb->esc_like($f['q']).'%';$args[]=$f['q'];}
        $sort=($f['sort']??'date')==='number'?'number':'voucher_date';$dir=($f['dir']??'desc')==='asc'?'ASC':'DESC';
        $count=(int)Db::val('SELECT COUNT(*) FROM '.Db::t('vouchers').' WHERE '.$where,...$args);
        $page=max(1,(int)$page);$rows=Db::rows('SELECT * FROM '.Db::t('vouchers').' WHERE '.$where.' ORDER BY '.$sort.' '.$dir.',id '.$dir.' LIMIT 50 OFFSET %d',...array_merge($args,[($page-1)*50]));
        return ['rows'=>$rows,'count'=>$count,'page'=>$page,'pages'=>(int)ceil($count/50)];
    }
    /**
     * گزارش در یک snapshot خوانده می‌شود تا خطوط، مانده و جمع از commitهای متفاوت نیایند.
     * «پیش از بازه» یعنی پیش از تاریخ شروع یا پیش از شمارهٔ شروع؛ «داخل بازه» بقیهٔ خطوط تا تاریخ و شمارهٔ پایان است.
     */
    public static function ledger($cid,array $f,$page=1,$size=50) {
        Access::need($cid,'report.view');$mode=$f['mode']??'journal';
        if(!isset(self::MODES[$mode])) throw new ValidationException(['mode'=>'نوع گزارش معتبر نیست.']);
        return Db::tx(function()use($cid,$f,$page,$size,$mode){
            [$base,$args,$from,$to,$nf,$nt]=self::filters($cid,$f);$join=self::joins();
            $bp=[];$ba=[];if($from){$bp[]='v.voucher_date<%s';$ba[]=$from;}if($nf){$bp[]='v.number<%d';$ba[]=$nf;}
            $before=$bp?'('.implode(' OR ',$bp).')':'1=0';
            $inside=$bp?'NOT '.$before:'1=1';$ia=$ba;if($nt){$inside='('.$inside.' AND v.number<=%d)';$ia[]=$nt;}
            $scope=$base;$sa=$args;if($to){$scope.=' AND v.voucher_date<=%s';$sa[]=$to;}
            $range=$scope.' AND '.$inside;$rargs=array_merge($sa,$ia);
            $opening=$bp?Db::val('SELECT COALESCE(SUM(CAST(l.debit AS SIGNED)-CAST(l.credit AS SIGNED)),0)'.$join.' WHERE '.$scope.' AND '.$before,...array_merge($sa,$ba)):'0';
            $opening=self::integer($opening);
            $total=Db::row('SELECT COALESCE(SUM(l.debit),0) AS debit,COALESCE(SUM(l.credit),0) AS credit'.$join.' WHERE '.$range,...$rargs);
            if(in_array($mode,['trial6','trial8'],true)){
                $opening=self::integer(Db::val('SELECT COALESCE(SUM(CASE WHEN (v.type=\'opening\' OR '.$before.') THEN CAST(l.debit AS SIGNED)-CAST(l.credit AS SIGNED) ELSE 0 END),0)'.$join.' WHERE '.$scope,...array_merge($ba,$sa)));
                $total=Db::row('SELECT COALESCE(SUM(l.debit),0) AS debit,COALESCE(SUM(l.credit),0) AS credit'.$join.' WHERE '.$range." AND v.type<>'opening'",...$rargs);
            }
            $total=['debit'=>self::integer($total['debit']),'credit'=>self::integer($total['credit'])];
            $page=max(1,(int)$page);$size=max(1,min(1000,(int)$size));$offset=($page-1)*$size;
            $balance=Money::add($opening,Money::add($total['debit'],-$total['credit']));
            if(in_array($mode,['trial2','trial4','trial6','trial8','general','subsidiary'],true)) {
                // کل در سطح ۲ و معین/تراز در سطح ۳؛ همهٔ حساب‌های گزارش فقط از همین شرکت‌اند.
                $code=$mode==='general'?'LEFT(a.code,3)':'a.code';
                $sumSql='SELECT '.$code.' AS code, SUM(CASE WHEN '.$before.' THEN CAST(l.debit AS SIGNED)-CAST(l.credit AS SIGNED) ELSE 0 END) AS opening, SUM(CASE WHEN '.$inside.' THEN l.debit ELSE 0 END) AS debit, SUM(CASE WHEN '.$inside.' THEN l.credit ELSE 0 END) AS credit';
                $sargs=array_merge($ba,$ia,$ia);
                if(in_array($mode,['trial6','trial8'],true)){
                    $sumSql='SELECT '.$code.' AS code, SUM(CASE WHEN v.type=\'opening\' THEN CAST(l.debit AS SIGNED)-CAST(l.credit AS SIGNED) ELSE 0 END) AS year_opening, SUM(CASE WHEN v.type<>\'opening\' AND '.$before.' THEN l.debit ELSE 0 END) AS prior_debit, SUM(CASE WHEN v.type<>\'opening\' AND '.$before.' THEN l.credit ELSE 0 END) AS prior_credit, SUM(CASE WHEN v.type<>\'opening\' AND '.$inside.' THEN l.debit ELSE 0 END) AS debit, SUM(CASE WHEN v.type<>\'opening\' AND '.$inside.' THEN l.credit ELSE 0 END) AS credit';
                    $sargs=array_merge($ba,$ba,$ia,$ia);
                }
                $sargs=array_merge($sargs,$sa);
                $query=$sumSql.$join.' WHERE '.$scope.' GROUP BY '.$code;
                $count=(int)Db::val('SELECT COUNT(*) FROM ('.$query.') grouped_accounts',...$sargs);
                $rows=Db::rows($query.' ORDER BY code LIMIT %d OFFSET %d',...array_merge($sargs,[$size,$offset]));
                foreach($rows as &$r) {
                    $r['name']=(string)Db::val('SELECT name FROM '.Db::t('accounts').' WHERE company_id=%d AND code=%s',$cid,$r['code']);
                    if(in_array($mode,['trial6','trial8'],true)){foreach(['year_opening','prior_debit','prior_credit'] as $key)$r[$key]=self::integer($r[$key]);$r['opening']=Money::add($r['year_opening'],Money::add($r['prior_debit'],-$r['prior_credit']));$r['year_debit']=max(0,$r['year_opening']);$r['year_credit']=max(0,-$r['year_opening']);}
                    $r['opening']=self::integer($r['opening']);$r['opening_debit']=max(0,$r['opening']);$r['opening_credit']=max(0,-$r['opening']);$r['debit']=self::integer($r['debit']);$r['credit']=self::integer($r['credit']);
                    $r['balance']=Money::add($r['opening'],Money::add($r['debit'],-$r['credit']));
                    $r['balance_debit']=max(0,$r['balance']);$r['balance_credit']=max(0,-$r['balance']);
                }
                unset($r);
                $carry=$opening;
            } else {
                $count=(int)Db::val('SELECT COUNT(*)'.$join.' WHERE '.$range,...$rargs);
                $carry=$opening;
                if($offset) {
                    $prior=Db::rows('SELECT l.debit,l.credit'.$join.' WHERE '.$range.' ORDER BY v.voucher_date,v.number,v.id,l.line_no LIMIT %d',...array_merge($rargs,[$offset]));
                    foreach($prior as $r) $carry=Money::add($carry,Money::add((int)$r['debit'],-(int)$r['credit']));
                }
                $rows=Db::rows('SELECT v.id AS voucher_id,v.number,v.voucher_date,a.code,a.name,l.description,l.debit,l.credit,d.name AS detail_name'.$join.' WHERE '.$range.' ORDER BY v.voucher_date,v.number,v.id,l.line_no LIMIT %d OFFSET %d',...array_merge($rargs,[$size,$offset]));
                $running=$carry;
                foreach($rows as &$r) { $r['debit']=(int)$r['debit'];$r['credit']=(int)$r['credit'];$running=Money::add($running,Money::add($r['debit'],-$r['credit']));$r['balance']=$running; }unset($r);
            }
            return ['rows'=>$rows,'count'=>$count,'pages'=>(int)ceil($count/$size),'page'=>$page,'opening'=>$opening,'carry'=>$carry,'total'=>$total,'closing'=>$balance,'mode'=>$mode,'from'=>$from,'to'=>$to,'no_from'=>$nf,'no_to'=>$nt];
        });
    }
    /** SQL SUM رشته برمی‌گرداند؛ پیش از cast از خروج از دامنهٔ مجاز جلوگیری می‌شود. */
    private static function integer($v) {
        $s=ltrim((string)$v,'-');
        if(!ctype_digit($s) || strlen($s)>16 || (int)$s>Money::MAX) throw new \OverflowException('Report aggregate out of range');
        return (int)$v;
    }
}
