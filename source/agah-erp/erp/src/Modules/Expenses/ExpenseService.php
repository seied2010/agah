<?php
/** هزینه/درآمد با حساب و ابعاد واقعی؛ سند، پرداخت و ممیزی تحت همان تراکنش شرکت‌اند. */
namespace Agah\Erp\Modules\Expenses;
use Agah\Erp\Core\{Access,Audit,Business,Ctx,Db,Money,Request,Sequence,ValidationException,ConflictException};
use Agah\Erp\Modules\Accounting\{AccountService,FiscalService,VoucherService};
use Agah\Erp\Modules\Treasury\TreasuryService;
if(!defined('ABSPATH'))exit;
final class ExpenseService {
    /** سرفصل‌ها حساب فعال متناسب با درآمد/هزینه می‌گیرند؛ ساختار دارای گردش تغییر نمی‌کند. */
    public static function saveLookup($cid,$type,$id,array $in){
        return Access::write($cid,'expense.edit',function()use($cid,$type,$id,$in){
            if(!in_array($type,['category','dimension'],true))throw new ValidationException(['type'=>'نوع اطلاعات معتبر نیست.']);
            $table=$type==='category'?'expense_categories':'cost_dimensions';$old=$id?Business::row($table,$cid,$id,true):null;
            $name=Request::clean($in['name']??'',190);$code=Money::normDigits($in['code']??'');$kind=$in['kind']??'';
            if(mb_strlen($name)<2||!preg_match('/^[A-Za-z0-9_-]{1,30}$/D',$code)||!in_array($kind,$type==='category'?['expense','income']:['project','branch','center'],true))throw new ValidationException(['name'=>'نام، کد و نوع معتبر وارد کنید.']);
            if(Db::val('SELECT id FROM '.Db::t($table).' WHERE company_id=%d AND code=%s AND id<>%d'.($type==='dimension'?' AND kind=%s':''),...($type==='dimension'?[$cid,$code,$id,$kind]:[$cid,$code,$id])))throw new ValidationException(['code'=>'کد تکراری است.']);
            $data=['code'=>$code,'name'=>$name,'kind'=>$kind,'is_active'=>empty($in['is_active'])?0:1];
            if($type==='category'){
                $a=Business::row('accounts',$cid,Business::id($in['account_id']??0));
                $valid=$kind==='income'?['revenue','other_income']:['cogs','opex','other_expense','finance_cost','tax_expense'];
                if(!$a['is_active']||(int)$a['level']!==3||!in_array($a['stmt_class'],$valid,true))throw new ValidationException(['account_id'=>'حساب فعال و متناسب انتخاب کنید.']);$data['account_id']=(int)$a['id'];
                if($old&&Db::val('SELECT id FROM '.Db::t('expense_docs').' WHERE company_id=%d AND category_id=%d LIMIT 1',$cid,$id)&&($old['kind']!==$kind||(int)$old['account_id']!==(int)$a['id']))throw new ConflictException('ساختار سرفصل دارای گردش تغییر نمی‌کند.');
            }else{
                if($old&&$old['kind']!==$kind)throw new ConflictException('نوع بُعد سازمانی تغییر نمی‌کند.');
                $detail=$old?(int)$old['detail_id']:0;
                if($kind==='center'){
                    if(!$detail)$detail=Db::insert('details',['company_id'=>$cid,'code'=>'CC'.Sequence::next($cid,'cost_center',0),'name'=>$name,'kind'=>'cost_center','created_at'=>gmdate('Y-m-d H:i:s')]);
                    Db::update('details',['name'=>$name,'is_active'=>$data['is_active']],['company_id'=>$cid,'id'=>$detail]);
                }$data['detail_id']=$detail?:null;
            }
            $id=Business::save($table,$cid,$id,$data);Audit::log('expense.lookup.save',$type,$id,$old,$data,$cid);return $id;
        });
    }
    private static function dimension($cid,$id,$kind){if(!$id)return null;$d=Business::row('cost_dimensions',$cid,$id);if($d['kind']!==$kind||!$d['is_active'])throw new ValidationException([$kind.'_id'=>'بُعد فعال با نوع مناسب انتخاب کنید.']);return $d;}
    /** ثبت مستقیم issued؛ بدهی/طلب شخص مستقل از پرداخت ایجاد می‌شود و پرداخت اختیاری از خزانه تسویه می‌کند. */
    public static function issue($cid,array $in,array $payment=[]){
        return Access::write($cid,'expense.create',function()use($cid,$in,$payment){
            $category=Business::row('expense_categories',$cid,Business::id($in['category_id']??0));if(!$category['is_active'])throw new ConflictException('سرفصل غیرفعال است.');
            $date=Business::date($in['doc_date']??'');$due=Business::date($in['due_date']??$date);if($due<$date)throw new ValidationException(['due_date'=>'سررسید قبل از تاریخ برگه است.']);$fy=FiscalService::assertOpen($cid,$date);$amount=Business::amount($in['amount']??0,true);$expense=$category['kind']==='expense';
            $party=Business::row('parties',$cid,Business::id($in['party_id']??0));if(!$party['is_active']||!$party[$expense?'is_supplier':'is_customer'])throw new ValidationException(['party_id'=>'شخص فعال با نقش مناسب انتخاب کنید.']);
            $dims=[];foreach(['project','branch','center'] as $kind)$dims[$kind]=self::dimension($cid,Business::id($in[$kind.'_id']??0),$kind);
            $desc=Request::clean($in['description']??'',500);$id=Business::save('expense_docs',$cid,0,['kind'=>$category['kind'],'category_id'=>(int)$category['id'],'fiscal_year_id'=>(int)$fy['id'],'number'=>Sequence::next($cid,'expense',(int)$fy['id']),'doc_date'=>$date,'due_date'=>$due,'amount'=>$amount,'party_id'=>(int)$party['id'],'project_id'=>$dims['project']?$dims['project']['id']:null,'branch_id'=>$dims['branch']?$dims['branch']['id']:null,'center_id'=>$dims['center']?$dims['center']['id']:null,'description'=>$desc,'created_by'=>Ctx::uid()]);
            $categoryAccount=Business::row('accounts',$cid,(int)$category['account_id']);$categoryDetail=0;if($categoryAccount['detail_rule']!=='none')$categoryDetail=$categoryAccount['detail_kind']==='party'?(int)$party['detail_id']:($dims['center']?(int)$dims['center']['detail_id']:0);
            $counter=AccountService::bySysKey($cid,$expense?'payable':'receivable');
            $lines=[['account_id'=>(int)$category['account_id'],'detail_id'=>$categoryDetail,'debit'=>$expense?$amount:0,'credit'=>$expense?0:$amount,'description'=>$desc],['account_id'=>(int)$counter['id'],'detail_id'=>(int)$party['detail_id'],'debit'=>$expense?0:$amount,'credit'=>$expense?$amount:0,'description'=>$desc]];
            $vid=VoucherService::postSystem(['voucher_date'=>$date,'description'=>$desc,'source_type'=>'expense_doc','source_id'=>$id],$lines);Db::update('expense_docs',['voucher_id'=>$vid],['company_id'=>$cid,'id'=>$id]);
            if($payment)self::pay($cid,$id,$date,$payment);
            Audit::log('expense.issue','expense_doc',$id,null,['kind'=>$category['kind'],'amount'=>$amount,'voucher_id'=>$vid],$cid);return $id;
        });
    }
    /** تسویه فقط از اقلام واقعی خزانه؛ برگشت چک/ابطال خزانه مانده را زنده بازمی‌گرداند. */
    public static function paid($cid,$id){
        if(Ctx::cidOrZero()!==(int)$cid)throw new \Agah\Erp\Core\NotFoundException();
        return (int)Db::val('SELECT COALESCE(SUM(s.amount),0) FROM '.Db::t('expense_settlements').' s JOIN '.Db::t('treasury_docs').' d ON d.company_id=s.company_id AND d.id=s.doc_id JOIN '.Db::t('treasury_doc_items').' t ON t.company_id=s.company_id AND t.id=s.doc_item_id LEFT JOIN '.Db::t('cheques')." c ON c.company_id=s.company_id AND c.doc_item_id=s.doc_item_id WHERE s.company_id=%d AND s.expense_id=%d AND d.status='issued' AND (t.method<>'cheque' OR c.status IN ('pending','collected'))",$cid,$id);
    }
    public static function pay($cid,$id,$date,array $items){
        return Access::write($cid,'expense.edit',function()use($cid,$id,$date,$items){
            $d=Business::row('expense_docs',$cid,$id,true);if($d['status']!=='issued')throw new ConflictException('برگه باطل شده است.');Business::date($date);if($date<$d['doc_date'])throw new ValidationException(['doc_date'=>'تسویه قبل از برگه مجاز نیست.']);
            $sum=0;foreach($items as $item)$sum=Money::add($sum,Business::amount($item['amount']??0,true));if(!$sum||$sum>(int)$d['amount']-self::paid($cid,$id))throw new ValidationException(['amount'=>'مبلغ تسویه از مانده بیشتر است.']);
            $doc=TreasuryService::issue($cid,['kind'=>$d['kind']==='expense'?'payment':'receipt','doc_date'=>$date,'party_id'=>(int)$d['party_id'],'description'=>'تسویه هزینه/درآمد #'.$d['number']],$items);
            foreach(Db::rows('SELECT id,amount FROM '.Db::t('treasury_doc_items').' WHERE company_id=%d AND doc_id=%d',$cid,$doc) as $line)Business::save('expense_settlements',$cid,0,['expense_id'=>$id,'doc_id'=>$doc,'doc_item_id'=>(int)$line['id'],'amount'=>(int)$line['amount']]);
            Audit::log('expense.pay','expense_doc',$id,null,['treasury_doc'=>$doc,'amount'=>$sum],$cid);return $doc;
        });
    }
    public static function get($cid,$id){Access::need($cid,'expense.view');$d=Business::row('expense_docs',$cid,$id);$d['paid']=self::paid($cid,$id);$d['outstanding']=$d['status']==='issued'?(int)$d['amount']-$d['paid']:0;$d['images']=Db::rows('SELECT id,mime,byte_size FROM '.Db::t('expense_images').' WHERE company_id=%d AND expense_id=%d ORDER BY id',$cid,$id);return $d;}
    public static function voidDocument($cid,$id,$reason){
        return Access::write($cid,'expense.delete',function()use($cid,$id,$reason){$d=Business::row('expense_docs',$cid,$id,true);if($d['status']!=='issued')throw new ConflictException('برگه قبلاً باطل شده است.');$reason=Business::reason($reason);FiscalService::assertOpen($cid,$d['doc_date']);
            foreach(Db::rows('SELECT DISTINCT s.doc_id FROM '.Db::t('expense_settlements').' s JOIN '.Db::t('treasury_docs')." d ON d.company_id=s.company_id AND d.id=s.doc_id WHERE s.company_id=%d AND s.expense_id=%d AND d.status='issued'",$cid,$id) as $p)TreasuryService::voidDocument($cid,(int)$p['doc_id'],$reason);
            VoucherService::voidSystem((int)$d['voucher_id'],$reason);Db::update('expense_docs',['status'=>'void'],['company_id'=>$cid,'id'=>$id]);Audit::log('expense.void','expense_doc',$id,['status'=>'issued'],['reason'=>$reason],$cid);
        });
    }
    /** تصویر در DB خصوصی ذخیره می‌شود؛ SVG/فایل بزرگ/ابعاد انفجاری پذیرفته نمی‌شود و URL عمومی وجود ندارد. */
    public static function attach($cid,$id,$bytes){
        return Access::write($cid,'expense.edit',function()use($cid,$id,$bytes){Ctx::need('file.create');Business::row('expense_docs',$cid,$id,true);
            if(!is_string($bytes)||strlen($bytes)<1||strlen($bytes)>2097152)throw new ValidationException(['image'=>'تصویر حداکثر دو مگابایت باشد.']);$info=@getimagesizefromstring($bytes);$mime=$info['mime']??'';
            if(!$info||!in_array($mime,['image/jpeg','image/png','image/webp'],true)||$info[0]>10000||$info[1]>10000||$info[0]*$info[1]>20000000)throw new ValidationException(['image'=>'تصویر JPEG، PNG یا WebP معتبر با ابعاد مجاز انتخاب کنید.']);
            if((int)Db::val('SELECT COUNT(*) FROM '.Db::t('expense_images').' WHERE company_id=%d AND expense_id=%d',$cid,$id)>=10)throw new ValidationException(['image'=>'حداکثر ده تصویر برای هر برگه مجاز است.']);
            $image=Business::save('expense_images',$cid,0,['expense_id'=>$id,'mime'=>$mime,'sha256'=>hash('sha256',$bytes),'byte_size'=>strlen($bytes),'image_data'=>$bytes,'created_by'=>Ctx::uid()]);Audit::log('expense.image.add','expense_image',$image,null,['expense_id'=>$id,'bytes'=>strlen($bytes)],$cid);return $image;
        });
    }
    public static function image($cid,$id){Access::need($cid,'expense.view');Ctx::need('file.view');$image=Business::row('expense_images',$cid,$id);Business::row('expense_docs',$cid,(int)$image['expense_id']);return $image;}
    public static function page($cid,array $f=[],$page=1){
        Access::need($cid,'expense.view');$where='e.company_id=%d';$args=[$cid];
        foreach(['category_id','party_id','project_id','branch_id','center_id'] as $key)if(!empty($f[$key])){$where.=' AND e.'.$key.'=%d';$args[]=Business::id($f[$key]);}
        foreach(['from'=>'>=','to'=>'<='] as $key=>$op)if(!empty($f[$key])){$where.=' AND e.doc_date'.$op.'%s';$args[]=Business::date($f[$key]);}
        foreach(['kind'=>['expense','income'],'status'=>['issued','void']] as $key=>$allowed)if(!empty($f[$key])){if(!in_array($f[$key],$allowed,true))throw new ValidationException([$key=>'فیلتر معتبر نیست.']);$where.=' AND e.'.$key.'=%s';$args[]=$f[$key];}
        $base=' FROM '.Db::t('expense_docs').' e JOIN '.Db::t('expense_categories').' c ON c.company_id=e.company_id AND c.id=e.category_id JOIN '.Db::t('parties').' p ON p.company_id=e.company_id AND p.id=e.party_id WHERE '.$where;$page=max(1,(int)$page);
        $n=(int)Db::val('SELECT COUNT(*)'.$base,...$args);$sums=Db::row("SELECT COALESCE(SUM(IF(e.kind='expense',e.amount,0)),0) AS expenses,COALESCE(SUM(IF(e.kind='income',e.amount,0)),0) AS income".$base,...$args);
        $rows=Db::rows('SELECT e.*,c.name AS category_name,p.name AS party_name'.$base.' ORDER BY e.doc_date DESC,e.id DESC LIMIT 50 OFFSET %d',...array_merge($args,[($page-1)*50]));
        return ['rows'=>$rows,'total'=>$sums,'page'=>$page,'pages'=>(int)ceil($n/50),'count'=>$n];
    }
}
