<?php
namespace Agah\Mobile;
use Agah\Erp\Core\{Db,Ctx,Business,Money,AppException,ValidationException,NotFoundException};
use Agah\Erp\Modules\Accounting\{FiscalService,AccountService,VoucherService,ReportService,FinancialService};
use Agah\Erp\Modules\Parties\PartyService;
use Agah\Erp\Modules\Catalog\CatalogService;
use Agah\Erp\Modules\Trade\InvoiceService;
use Agah\Erp\Modules\Treasury\TreasuryService;
use Agah\Erp\Modules\Inventory\StockService;
use Agah\Erp\Modules\Expenses\ExpenseService;
use Agah\Erp\Modules\Dashboard\DashboardService;
if (!defined('ABSPATH')) exit;

final class Api {
    public static function register() {
        add_action('rest_api_init',function () {
            self::route('/auth/login','POST',function ($r) { return Session::login(self::body($r)); },true);
            self::route('/auth/refresh','POST',function ($r) { return Session::refresh(self::body($r)); },true);
            self::route('/auth/logout','POST',function () { return Session::revoke(); });
            self::route('/auth/devices','GET',function () { return ['rows'=>Session::devices()]; });
            self::route('/auth/devices/(?P<id>[1-9][0-9]*)','DELETE',function ($r) { return Session::revoke((int)$r['id']); });
            self::route('/companies','GET',[__CLASS__,'companies']);
            $base='/companies/(?P<company>[1-9][0-9]*)';
            self::route($base.'/context','GET',[__CLASS__,'context']);
            self::route($base.'/dashboard','GET',function ($r) { [$c,$y]=self::scope($r,true); return DashboardService::summary($c,max($y['start_date'],min(gmdate('Y-m-d'),$y['end_date']))); });
            self::route($base.'/operations/(?P<operation>[A-Za-z0-9-]{20,100})','GET',function ($r) { [$c]=self::scope($r); return Operations::status($c,$r['operation']); });
            self::route($base.'/operations/(?P<operation>[A-Za-z0-9-]{20,100})','DELETE',function ($r) { [$c]=self::scope($r); return Operations::cancel($c,$r['operation']); });
            self::route($base.'/lookups/(?P<lookup>[a-z_]+)','GET',[__CLASS__,'lookups']);
            self::route($base.'/reports/(?P<report>[a-z0-9_]+)','GET',[__CLASS__,'report']);
            self::route($base.'/reports/(?P<report>[a-z0-9_]+)/export','GET',['Agah\\Mobile\\Exports','report']);
            self::route($base.'/attachments/(?P<target>invoice|voucher|party|expense)/(?P<id>[1-9][0-9]*)','GET',['Agah\\Mobile\\Files','listing']);
            self::route($base.'/attachments/(?P<target>invoice|voucher|party|expense)/(?P<id>[1-9][0-9]*)','POST',['Agah\\Mobile\\Files','upload']);
            self::route($base.'/files/(?P<id>[1-9][0-9]*)','GET',['Agah\\Mobile\\Files','download']);
            self::route($base.'/barcode','GET',function ($r) {
                [$c]=self::scope($r); Ctx::need('item.view'); $code=$r->get_param('code');
                if (!is_string($code)||strlen($code)>80) throw new ValidationException(['code'=>'بارکد معتبر نیست.']);
                $id=Db::val('SELECT id FROM '.Db::t('items').' WHERE company_id=%d AND barcode=%s',$c,$code);
                if (!$id) throw new NotFoundException();
                return CatalogService::get($c,'item',(int)$id);
            });
            $entity=$base.'/(?P<entity>parties|items|invoices|treasury|expenses|vouchers|stock)';
            self::route($entity,'GET',[__CLASS__,'listing']);
            self::route($entity.'/(?P<id>[1-9][0-9]*)','GET',[__CLASS__,'detail']);
            self::route($entity,'POST',[__CLASS__,'save']);
            self::route($entity.'/(?P<id>[1-9][0-9]*)','PUT',[__CLASS__,'save']);
            self::route($entity.'/(?P<id>[1-9][0-9]*)/(?P<action>issue|approve|finalize|reopen|void)','POST',[__CLASS__,'transition']);
        });
    }
    private static function route($path,$method,$handler,$public=false) {
        register_rest_route('agah-mobile/v1',$path,[
            'methods'=>$method,
            'permission_callback'=>function ($r) use ($public) {
                try {
                    if (!is_ssl()&&wp_get_environment_type()!=='local') throw new AppException('ارتباط HTTPS لازم است.',403);
                    return $public?true:Session::authenticate($r);
                } catch (AppException $e) { return new \WP_Error('agah_mobile_auth',$e->getMessage(),['status'=>$e->status]); }
            },
            'callback'=>function ($r) use ($handler) {
                $requestId=bin2hex(random_bytes(8));
                try {
                    $data=call_user_func($handler,$r);
                    if ($data instanceof \WP_REST_Response) $response=$data;
                    else $response=new \WP_REST_Response(['data'=>self::wire($data),'api_version'=>'1','request_id'=>$requestId]);
                } catch (AppException $e) {
                    $response=new \WP_REST_Response(['error'=>['message'=>$e->getMessage(),'fields'=>$e instanceof ValidationException?$e->errors:(object)[]],'request_id'=>$requestId],$e->status);
                } catch (\Throwable $e) {
                    // Do not log body, tokens, passwords, SQL or exception messages.
                    error_log('AGAH mobile internal error '.$requestId.' '.get_class($e));
                    $response=new \WP_REST_Response(['error'=>['message'=>'خطای داخلی؛ با شناسهٔ پیگیری به پشتیبانی اطلاع دهید.'],'request_id'=>$requestId],500);
                } finally { Ctx::reset(); }
                $response->header('Cache-Control','no-store, private');
                $response->header('X-Content-Type-Options','nosniff');
                $response->header('X-Request-ID',$requestId);
                return $response;
            }
        ]);
    }
    public static function body($r) {
        if (strlen($r->get_body())>524288) throw new AppException('درخواست بیش از اندازه بزرگ است.',413);
        $in=$r->get_json_params();
        if (!is_array($in)) throw new ValidationException(['body'=>'بدنهٔ JSON معتبر لازم است.']);
        Operations::canonical($in);
        return $in;
    }
    public static function wire($value) {
        if (is_int($value)) return (string)$value;
        if (is_float($value)) throw new \LogicException('Floating point API output');
        if (is_array($value)) foreach ($value as &$v) $v=self::wire($v);
        return $value;
    }
    public static function scope($r,$yearRequired=false) {
        $cid=Business::id($r['company']); Ctx::boot(get_current_user_id(),$cid); Policy::need($cid);
        $id=Business::id($r->get_param('year_id')??'0');
        $year=$id?FiscalService::get($cid,$id):null;
        if ($yearRequired&&!$year) throw new ValidationException(['year_id'=>'سال مالی را انتخاب کنید.']);
        return [$cid,$year];
    }
    public static function companies() {
        $rows=Db::rows('SELECT c.id,c.name,c.display_unit FROM '.Db::t('companies').' c JOIN '.Db::t('company_members')." m ON m.company_id=c.id WHERE m.user_id=%d AND m.status='active' AND c.status='active' ORDER BY c.name",get_current_user_id());
        return ['rows'=>array_values(array_filter($rows,function ($c) { return Policy::mode((int)$c['id'],get_current_user_id())!=='off'; }))];
    }
    public static function context($r) {
        [$c]=self::scope($r); $company=Ctx::company();
        $caps=[];
        foreach (\Agah\Erp\Security\Caps::all() as $key=>$value) {
            $cap=is_string($key)?$key:$value;
            if (is_string($cap)&&\Agah\Erp\Security\Caps::isValid($cap)&&Ctx::can($cap)) $caps[]=$cap;
        }
        return ['company'=>array_intersect_key($company,array_flip(['id','name','display_unit'])),'years'=>FiscalService::all($c),'mode'=>Policy::mode($c,get_current_user_id()),'capabilities'=>$caps,'money_scale'=>'100','quantity_scale'=>'10000','recovery_url'=>wp_lostpassword_url()];
    }
    private static function filters($r,$year) {
        $f=[];
        foreach (['from','to','status','q','mode','account_id','detail_id','party_id','no_from','no_to'] as $k) {
            $v=$r->get_param($k);
            if ($v!==null) { if (!is_scalar($v)||strlen((string)$v)>150) throw new ValidationException([$k=>'فیلتر معتبر نیست.']); $f[$k]=(string)$v; }
        }
        if ($year) {
            $f['fiscal_year_id']=$year['id'];
            $f['from']=max($f['from']??$year['start_date'],$year['start_date']);
            $f['to']=min($f['to']??$year['end_date'],$year['end_date']);
        }
        if (isset($f['from'],$f['to'])&&$f['from']>$f['to']) throw new ValidationException(['to'=>'بازهٔ تاریخ معتبر نیست.']);
        return $f;
    }
    private static function page($r) { return max(1,min(100000,Business::id($r->get_param('page')??'1'))); }
    public static function listing($r) {
        $entity=$r['entity']; [$c,$year]=self::scope($r,!in_array($entity,['parties','items'],true));
        $p=self::page($r); $f=self::filters($r,$year); $q=$f['q']??'';
        switch ($entity) {
            case 'parties': return PartyService::page($c,$p,$q);
            case 'items': return CatalogService::page($c,'item',$p,$q);
            case 'invoices': return InvoiceService::page($c,(string)($r->get_param('kind')??'sale'),$f,$p);
            case 'treasury': return TreasuryService::documents($c,(string)($r->get_param('kind')??'receipt'),$p,(int)$year['id']);
            case 'expenses': return ExpenseService::page($c,$f,$p);
            case 'vouchers': return ReportService::vouchers($c,$f,$p);
            case 'stock': return StockService::inventory($c,$p)+['scope'=>'current_all_periods'];
        }
        throw new NotFoundException();
    }
    private static function record($entity,$c,$id) {
        switch ($entity) {
            case 'parties': return PartyService::get($c,$id);
            case 'items': return CatalogService::get($c,'item',$id);
            case 'invoices': return InvoiceService::get($c,$id);
            case 'treasury': return TreasuryService::get($c,$id);
            case 'expenses': return ExpenseService::get($c,$id);
            case 'vouchers': return VoucherService::get($c,$id);
        }
        throw new NotFoundException();
    }
    public static function detail($r) {
        [$c,$year]=self::scope($r); $row=self::record($r['entity'],$c,Business::id($r['id']));
        if ($year&&isset($row['fiscal_year_id'])&&(int)$row['fiscal_year_id']!==(int)$year['id']) throw new NotFoundException();
        return ['record'=>$row,'etag'=>Operations::etag($row)];
    }
    public static function lookups($r) {
        [$c]=self::scope($r); $name=$r['lookup']; $p=self::page($r); $q=(string)($r->get_param('q')??'');
        if (strlen($q)>150) throw new ValidationException(['q'=>'جست‌وجو کوتاه‌تر باشد.']);
        switch ($name) {
            case 'parties': return PartyService::page($c,$p,$q);
            case 'items': return CatalogService::page($c,'item',$p,$q);
            case 'units': return CatalogService::page($c,'unit',$p,$q);
            case 'groups': return CatalogService::page($c,'group',$p,$q);
            case 'taxes': return CatalogService::page($c,'tax',$p,$q);
            case 'accounts': Ctx::need('account.view'); return Business::page('accounts',$c,$p,$q,' AND level=3 AND is_active=1');
            case 'warehouses': return ['rows'=>StockService::warehouses($c)];
            case 'treasury_accounts': return TreasuryService::accounts($c,$p,$q);
            case 'expense_categories': Ctx::need('expense.view'); return Business::page('expense_categories',$c,$p,$q);
            case 'details': Ctx::need('detail.view'); return Business::page('details',$c,$p,$q);
        }
        throw new NotFoundException();
    }
    /** Convert only documented exact-scaled fields; never cast mixed or oversized numeric strings. */
    public static function numeric(array $in) {
        $keys=['amount','debit','credit','unit_price','sale_price','purchase_price','trade_price','discount_amount','extra_cost','credit_limit','qty_scaled','cost'];
        foreach ($in as $k=>&$v) {
            if (is_array($v)) $v=self::numeric($v);
            elseif (in_array($k,$keys,true)) {
                if (!is_string($v)||!preg_match('/^[0-9]{1,16}$/D',$v)||strlen(ltrim($v,'0'))>16||(int)$v>Money::MAX) throw new ValidationException([$k=>'عدد صحیح مقیاس‌شده در محدودهٔ مجاز لازم است.']);
                $v=(int)$v;
            }
        }
        return $in;
    }
    private static function documentYear($in,$year,$entity) {
        $field=$entity==='invoices'?'invoice_date':($entity==='vouchers'?'voucher_date':'doc_date');
        $date=Business::date($in[$field]??'');
        if (!$year||$date<$year['start_date']||$date>$year['end_date']) throw new ValidationException([$field=>'تاریخ باید داخل سال مالی انتخاب‌شده باشد.']);
    }
    public static function save($r) {
        $entity=$r['entity']; [$c,$year]=self::scope($r,!in_array($entity,['parties','items'],true));
        $id=Business::id($r['id']??'0'); $body=Payload::validate($entity,self::body($r)); $in=self::numeric($body);
        if (!in_array($entity,['parties','items'],true)) self::documentYear($in,$year,$entity);
        $kind=$in['kind']??'';
        $caps=['parties'=>'party','items'=>'item','invoices'=>InvoiceService::resource($entity==='invoices'?$kind:'sale'),'treasury'=>in_array($kind,['receipt','payment','transfer'],true)?$kind:'receipt','expenses'=>'expense','vouchers'=>'voucher','stock'=>'stock'];
        if ($id&&in_array($entity,['treasury','expenses','stock'],true)) throw new AppException('این برگه با اصلاح مستقیم تغییر نمی‌کند؛ از ابطال مجاز استفاده کنید.',409);
        $cap=$caps[$entity].'.'.($id?'edit':'create');
        return Operations::execute($c,$cap,$r->get_header('idempotency-key'),$entity.'.save',[$body,$r->get_header('if-match'),$id,$year['id']??0],function () use ($r,$entity,$c,$id,$in,$year) {
            if ($id) {
                $current=self::record($entity,$c,$id); Operations::match($r->get_header('if-match'),$current);
                if ($year&&isset($current['fiscal_year_id'])&&(int)$current['fiscal_year_id']!==(int)$year['id']) throw new NotFoundException();
            }
            switch ($entity) {
                case 'parties': $out=PartyService::save($c,$id,$in); break;
                case 'items': $out=CatalogService::save($c,'item',$id,$in); break;
                case 'invoices': $out=InvoiceService::save($c,$id,$in,$in['lines']??[],(int)($current['revision']??0)); break;
                case 'treasury': $out=TreasuryService::issue($c,$in,$in['items']??[],$in['allocations']??[]); break;
                case 'expenses': $out=ExpenseService::issue($c,$in,$in['payment']??[]); break;
                case 'vouchers': $out=VoucherService::save($c,$id,$in,$in['lines']??[],(int)($current['revision']??0),'rial'); break;
                case 'stock': $out=StockService::issue($c,$in,$in['lines']??[]); break;
                default: throw new NotFoundException();
            }
            return ['id'=>(string)$out,'entity'=>$entity,'state'=>'committed'];
        });
    }
    public static function transition($r) {
        [$c,$year]=self::scope($r,true); $entity=$r['entity']; $id=Business::id($r['id']); $action=$r['action']; $body=Payload::action(self::body($r));
        $record=self::record($entity,$c,$id);
        if (isset($record['fiscal_year_id'])&&(int)$record['fiscal_year_id']!==(int)$year['id']) throw new NotFoundException();
        $allowed=$entity==='vouchers'?['approve','finalize','reopen','void']:($entity==='invoices'?['issue','void']:['void']);
        if (!in_array($action,$allowed,true)) throw new NotFoundException();
        if ($entity==='invoices') $cap=InvoiceService::resource($record['kind']).'.'.($action==='issue'?'create':'delete');
        elseif ($entity==='vouchers') $cap='voucher.'.$action;
        elseif ($entity==='treasury') $cap=$record['kind'].'.delete';
        elseif ($entity==='expenses') $cap='expense.delete';
        else throw new NotFoundException();
        if (($entity!=='vouchers'&&!in_array($action,['issue','void'],true))||($entity!=='invoices'&&$entity!=='vouchers'&&$action!=='void')) throw new NotFoundException();
        return Operations::execute($c,$cap,$r->get_header('idempotency-key'),$entity.'.'.$action,[$id,$body,$r->get_header('if-match')],function () use ($r,$entity,$c,$id,$action,$body) {
            $current=self::record($entity,$c,$id); Operations::match($r->get_header('if-match'),$current); $reason=$body['reason']??'';
            if ($entity==='vouchers') VoucherService::transition($c,$id,$action,$reason,(int)$current['revision']);
            elseif ($entity==='invoices'&&$action==='issue') InvoiceService::issue($c,$id,(int)$current['revision']);
            elseif ($entity==='invoices') InvoiceService::voidDocument($c,$id,$reason);
            elseif ($entity==='treasury') TreasuryService::voidDocument($c,$id,$reason);
            elseif ($entity==='expenses') ExpenseService::voidDocument($c,$id,$reason);
            return ['id'=>(string)$id,'entity'=>$entity,'state'=>'committed'];
        });
    }
    public static function report($r) {
        [$c,$year]=self::scope($r,true); $f=self::filters($r,$year); $p=self::page($r);
        switch ($r['report']) {
            case 'ledger': return ReportService::ledger($c,$f,$p);
            case 'parties': return FinancialService::parties($c,$f['from'],$f['to'],$p);
            case 'statements': return FinancialService::statements($c,$f['from'],$f['to']);
            case 'inventory': return StockService::inventory($c,$p);
            case 'kardex': return StockService::kardex($c,Business::id($r->get_param('warehouse_id')??'0'),Business::id($r->get_param('item_id')??'0'),$p)+['scope'=>'all_periods'];
            case 'party_statement': return PartyService::statement($c,Business::id($r->get_param('party_id')??'0'),$f['from'],$f['to'],$p);
            case 'sales': return InvoiceService::page($c,'sale',array_merge($f,['status'=>'issued']),$p);
            case 'purchases': return InvoiceService::page($c,'purchase',array_merge($f,['status'=>'issued']),$p);
            case 'expenses': return ExpenseService::page($c,array_merge($f,['status'=>'issued']),$p);
            case 'receipts': case 'payments':
                $kind=$r['report']==='receipts'?'receipt':'payment'; Ctx::need($kind.'.view');
                $where='d.company_id=%d AND d.kind=%s AND d.status=\'issued\' AND d.fiscal_year_id=%d AND d.doc_date BETWEEN %s AND %s';
                $args=[$c,$kind,$year['id'],$f['from'],$f['to']];
                $count=(int)Db::val('SELECT COUNT(*) FROM '.Db::t('treasury_docs').' d WHERE '.$where,...$args);
                $rows=Db::rows('SELECT d.id,d.number,d.doc_date,d.description,d.amount,p.name party_name FROM '.Db::t('treasury_docs').' d LEFT JOIN '.Db::t('parties').' p ON p.company_id=d.company_id AND p.id=d.party_id WHERE '.$where.' ORDER BY d.doc_date DESC,d.id DESC LIMIT 50 OFFSET %d',...array_merge($args,[($p-1)*50]));
                return ['rows'=>$rows,'page'=>$p,'pages'=>(int)ceil($count/50),'count'=>$count];
        }
        throw new NotFoundException();
    }
}
