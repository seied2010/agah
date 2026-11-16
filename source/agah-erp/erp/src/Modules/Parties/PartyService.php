<?php
/** پروندهٔ شخص و تفصیلی شناور آن اتمیک‌اند؛ هیچ خواندن عادی شناسهٔ ملی آشکار نمی‌دهد. */
namespace Agah\Erp\Modules\Parties;
use Agah\Erp\Core\{Db,Ctx,Access,Business,Money,Crypto,Audit,Request,ValidationException,ConflictException};
if(!defined('ABSPATH')) exit;
final class PartyService {
    /** کنترل کد ملی ۱۰رقمی و شناسهٔ ملی ۱۱رقمی، شامل رد ارقام تکراری. */
    public static function validNational($value,$kind){
        $v=Money::normDigits($value);$n=$kind==='real'?10:11;
        if(!preg_match('/^[0-9]{'.$n.'}$/D',$v)||count(array_unique(str_split($v)))===1)return false;
        if($n===10){$s=0;for($i=0;$i<9;$i++)$s+=(int)$v[$i]*(10-$i);$r=$s%11;return (int)$v[9]===($r<2?$r:11-$r);}
        $weights=[29,27,23,19,17,29,27,23,19,17];$s=0;$d=(int)$v[9]+2;for($i=0;$i<10;$i++)$s+=((int)$v[$i]+$d)*$weights[$i];$r=$s%11;return (int)$v[10]===($r===10?0:$r);
    }
    /** DTO عمومی فقط hint دارد؛ مقادیر رمز و ایندکس از خروجی حذف می‌شوند. */
    private static function safe($r){unset($r['national_id_enc'],$r['national_id_idx']);return $r;}
    public static function get($cid,$id){Access::need($cid,'party.view');return self::safe(Business::row('parties',$cid,$id));}
    public static function page($cid,$page=1,$q=''){Access::need($cid,'party.view');$d=Business::page('parties',$cid,$page,$q);$d['rows']=array_map([__CLASS__,'safe'],$d['rows']);return $d;}
    /** ایجاد کد شمارنده‌ای و تفصیلی مرجع؛ تغییر نام و وضعیت هر دو رکورد هم‌زمان است. */
    public static function save($cid,$id,array $in){return Access::write($cid,$id?'party.edit':'party.create',function()use($cid,$id,$in){
        $old=$id?Business::row('parties',$cid,$id,true):null;$name=Request::clean($in['name']??'',190);$kind=$in['kind']??'real';$errors=[];
        if(mb_strlen($name)<2)$errors['name']='نام شخص حداقل دو نویسه باشد.';
        if(!in_array($kind,['real','legal'],true))$errors['kind']='نوع شخص معتبر نیست.';
        if(empty($in['is_customer'])&&empty($in['is_supplier'])&&empty($in['is_employee']))$errors['is_customer']='حداقل یکی از نقش‌های مشتری، تأمین‌کننده یا پرسنل را انتخاب کنید.';
        $national=Money::normDigits(trim($in['national_id']??''));
        if($national!==''&&!self::validNational($national,$kind))$errors['national_id']='کد ملی/شناسهٔ ملی معتبر نیست.';
        if($old&&$old['kind']!==$kind&&$old['national_id_enc']&&$national===''&&empty($in['clear_national_id']))$errors['national_id']='برای تغییر نوع، شناسهٔ سازگار وارد یا قبلی را صریح حذف کنید.';
        $email=Request::clean($in['email']??'',190);if($email!==''&&!is_email($email))$errors['email']='ایمیل معتبر نیست.';
        if($errors)throw new ValidationException($errors);
        $new=['name'=>$name,'kind'=>$kind,'is_customer'=>empty($in['is_customer'])?0:1,'is_supplier'=>empty($in['is_supplier'])?0:1,'is_employee'=>empty($in['is_employee'])?0:1,'is_active'=>empty($in['is_active'])?0:1,'credit_limit'=>Business::amount($in['credit_limit']??0),'economic_code'=>Request::clean($in['economic_code']??'',30),'phone'=>Request::clean($in['phone']??'',40),'email'=>$email,'address'=>Request::clean($in['address']??'',500)];
        if($national!==''){
            $idx=Crypto::blind('national|'.$kind.'|'.$national);
            if(Db::val('SELECT id FROM '.Db::t('parties').' WHERE company_id=%d AND national_id_idx=%s AND id<>%d',$cid,$idx,$id))throw new ValidationException(['national_id'=>'این شناسه برای شخص دیگری ثبت شده است.']);
            $new+=['national_id_enc'=>Crypto::encrypt($national),'national_id_idx'=>$idx,'national_id_hint'=>Crypto::hint($national)];
        }elseif(!empty($in['clear_national_id']))$new+=['national_id_enc'=>'','national_id_idx'=>null,'national_id_hint'=>''];
        if($id){$code=$old['code'];$detail=$old['detail_id'];}
        else{$code='P'.str_pad((string)\Agah\Erp\Core\Sequence::next($cid,'party',0),6,'0',STR_PAD_LEFT);$detail=Db::insert('details',['company_id'=>$cid,'code'=>'party_'.$code,'name'=>$name,'kind'=>'party','is_active'=>$new['is_active'],'created_at'=>gmdate('Y-m-d H:i:s')]);$new+=['code'=>$code,'detail_id'=>$detail];}
        $postal=Money::normDigits($in['postal_code']??'');if($postal!==''&&!preg_match('/^\d{10}$/D',$postal))throw new ValidationException(['postal_code'=>'کد پستی باید ۱۰ رقم باشد.']);$new['postal_code']=$postal;$new['registration_no']=Request::clean($in['registration_no']??'',30);
        $id=Business::save('parties',$cid,$id,$new);Db::update('details',['name'=>$name,'is_active'=>$new['is_active'],'ref_type'=>'party','ref_id'=>$id],['company_id'=>$cid,'id'=>$detail]);
        Audit::log('party.save','party',$id,$old,$new,$cid);return $id;
    });}
    /** حذف بدون سابقهٔ حسابداری/خزانه/فاکتور؛ در غیر این صورت تنها غیرفعال‌سازی مجاز است. */
    public static function delete($cid,$id){Access::write($cid,'party.delete',function()use($cid,$id){$r=Business::row('parties',$cid,$id,true);foreach(['voucher_lines'=>'detail_id','treasury_docs'=>'party_id','invoices'=>'party_id'] as $table=>$col)if(Db::val('SELECT COUNT(*) FROM '.Db::t($table).' WHERE company_id=%d AND '.$col.'=%d',$cid,$table==='voucher_lines'?$r['detail_id']:$id))throw new ConflictException('شخص دارای سابقه حذف نمی‌شود؛ غیرفعال کنید.');Db::delete('parties',['company_id'=>$cid,'id'=>$id]);Db::delete('details',['company_id'=>$cid,'id'=>$r['detail_id']]);Audit::log('party.delete','party',$id,$r,null,$cid);});}
    /** افشای صریح و ممیزی‌شده؛ این مقدار هرگز در transient، URL یا audit ذخیره نمی‌شود. */
    public static function reveal($cid,$id){return Access::write($cid,'party.sensitive',function()use($cid,$id){$r=Business::row('parties',$cid,$id);Audit::log('party.sensitive.read','party',$id,null,['hint'=>$r['national_id_hint']],$cid);return Crypto::decrypt($r['national_id_enc']);});}
    /** پرونده مالی فقط از اسناد تأییدشده/قطعی و سوابق واقعی همین شرکت خوانده می‌شود. */
    public static function statement($cid,$id,$from='',$to='',$page=1){Access::need($cid,'party.view');$p=Business::row('parties',$cid,$id);if($from)Business::date($from);if($to)Business::date($to);if($from&&$to&&$from>$to)throw new ValidationException(['date'=>'بازه معکوس است.']);
        return Db::tx(function()use($cid,$id,$p,$from,$to,$page){
            $base=' FROM '.Db::t('voucher_lines').' l JOIN '.Db::t('vouchers')." v ON v.company_id=l.company_id AND v.id=l.voucher_id WHERE l.company_id=%d AND l.detail_id=%d AND v.status IN ('approved','final')";$args=[$cid,$p['detail_id']];
            $opening=$from?(int)Db::val('SELECT COALESCE(SUM(CAST(l.debit AS SIGNED)-CAST(l.credit AS SIGNED)),0)'.$base.' AND v.voucher_date<%s',...array_merge($args,[$from])):0;
            $range='';if($from){$range.=' AND v.voucher_date>=%s';$args[]=$from;}if($to){$range.=' AND v.voucher_date<=%s';$args[]=$to;}
            $totals=Db::row('SELECT COALESCE(SUM(l.debit),0) debit,COALESCE(SUM(l.credit),0) credit'.$base.$range,...$args);$balance=Money::add($opening,Money::add((int)$totals['debit'],-(int)$totals['credit']));
            $exposure=(int)Db::val('SELECT COALESCE(SUM(CAST(l.debit AS SIGNED)-CAST(l.credit AS SIGNED)),0) FROM '.Db::t('voucher_lines').' l JOIN '.Db::t('vouchers').' v ON v.company_id=l.company_id AND v.id=l.voucher_id JOIN '.Db::t('accounts')." a ON a.company_id=l.company_id AND a.id=l.account_id WHERE l.company_id=%d AND l.detail_id=%d AND v.status IN ('approved','final') AND a.sys_key IN ('receivable','notes_receivable')".($to?' AND v.voucher_date<=%s':''),...($to?[$cid,$p['detail_id'],$to]:[$cid,$p['detail_id']]));$exposure=max(0,Money::add(0,$exposure));
            $page=max(1,(int)$page);$count=(int)Db::val('SELECT COUNT(*)'.$base.$range,...$args);
            $rows=Db::rows('SELECT v.id voucher_id,v.number,v.voucher_date,l.description,l.debit,l.credit'.$base.$range.' ORDER BY v.voucher_date,v.id,l.line_no LIMIT 50 OFFSET %d',...array_merge($args,[($page-1)*50]));
            return ['party'=>self::safe($p),'opening'=>$opening,'balance'=>$balance,'total'=>$totals,'rows'=>$rows,'page'=>$page,'pages'=>(int)ceil($count/50),'credit_exposure'=>$exposure,'over_limit'=>(int)$p['credit_limit']>0&&$exposure>(int)$p['credit_limit'],'invoices'=>Db::rows('SELECT id,kind,status,number,amount,due_date FROM '.Db::t('invoices').' WHERE company_id=%d AND party_id=%d ORDER BY id DESC LIMIT 50',$cid,$id),'payments'=>Db::rows('SELECT id,kind,status,number,amount,doc_date FROM '.Db::t('treasury_docs').' WHERE company_id=%d AND party_id=%d ORDER BY id DESC LIMIT 50',$cid,$id)];
        });
    }
}
