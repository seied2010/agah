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
        add_filter('rest_post_dispatch',function ($response,$server,$request) {
            if(strpos($request->get_route(),'/agah-mobile/v1/')===0&&$response instanceof \WP_HTTP_Response) {
                $response->header('Cache-Control','no-store, private');
                $response->header('X-Content-Type-Options','nosniff');
            }
            return $response;
        },10,3);
        add_action('rest_api_init',function () {
            self::route('/auth/login','POST',function ($r) { return Session::login(self::body($r)); },true);
            self::route('/auth/refresh','POST',function ($r) { return Session::refresh(self::body($r)); },true);
            self::route('/auth/logout','POST',function () { return Session::revoke(); });
            self::route('/auth/devices','GET',function () { return ['rows'=>Session::devices()]; });
            self::route('/auth/devices/(?P<id>[1-9][0-9]*)','DELETE',function ($r) { return Session::revoke((int)self::pathValue($r,'id')); });
            self::route('/companies','GET',[__CLASS__,'companies']);
            $base='/companies/(?P<company>[1-9][0-9]*)';
            self::route($base.'/context','GET',[__CLASS__,'context']);
            self::route($base.'/dashboard','GET',[__CLASS__,'dashboard']);
            self::route($base.'/operations/(?P<operation>[A-Za-z0-9-]{20,100})','GET',function ($r) { [$c]=self::scope($r); return Operations::status($c,self::pathValue($r,'operation')); });
            self::route($base.'/operations/(?P<operation>[A-Za-z0-9-]{20,100})','DELETE',function ($r) { [$c]=self::scope($r); return Operations::cancel($c,self::pathValue($r,'operation')); });
            self::route($base.'/lookups/(?P<lookup>[a-z_]+)','GET',[__CLASS__,'lookups']);
            self::route($base.'/lookups/(?P<lookup>[a-z_]+)/(?P<id>[1-9][0-9]*)','GET',[__CLASS__,'lookupLabel']);
            self::route($base.'/reports/(?P<report>[a-z0-9_]+)','GET',[__CLASS__,'report']);
            self::route($base.'/reports/(?P<report>[a-z0-9_]+)/export','GET',['Agah\\Mobile\\Exports','report']);
            self::route($base.'/(?P<entity>invoices|vouchers|treasury|expenses)/(?P<id>[1-9][0-9]*)/export','GET',['Agah\\Mobile\\Exports','document']);
            self::route($base.'/attachments/(?P<target>invoice|voucher|party|expense)/(?P<id>[1-9][0-9]*)','GET',['Agah\\Mobile\\Files','listing']);
            self::route($base.'/attachments/(?P<target>invoice|voucher|party|expense)/(?P<id>[1-9][0-9]*)','POST',['Agah\\Mobile\\Files','upload']);
            self::route($base.'/files/(?P<id>[1-9][0-9]*)','GET',['Agah\\Mobile\\Files','download']);
            self::route($base.'/expense-images/(?P<id>[1-9][0-9]*)','GET',['Agah\\Mobile\\Files','expenseImage']);
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
                } catch (AppException $e) { self::denial($e->status); return new \WP_Error('agah_mobile_auth',$e->getMessage(),['status'=>$e->status]); }
            },
            'callback'=>function ($r) use ($handler) {
                $requestId=bin2hex(random_bytes(8));
                try {
                    $data=call_user_func($handler,$r);
                    if ($data instanceof \WP_REST_Response) $response=$data;
                    else $response=new \WP_REST_Response(['data'=>self::wire($data),'api_version'=>'1','request_id'=>$requestId]);
                } catch (AppException $e) {
                    self::denial($e->status);
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
    /** Bounded security audit; never include credentials, request bodies or untrusted usernames. */
    private static function denial($status) {
        if(!in_array((int)$status,[401,403],true)) return;
        try {
            if(\Agah\Erp\Security\RateLimiter::consume('mobile-denial-audit|'.\Agah\Erp\Core\Request::ip(),3,60))
                \Agah\Erp\Core\Audit::log('mobile.access.denied','mobile_api',0,null,['status'=>(int)$status],0,get_current_user_id());
        } catch(\Throwable $e) { error_log('AGAH mobile security audit unavailable '.get_class($e)); }
    }
    public static function body($r) {
        if (strlen($r->get_body())>524288) throw new AppException('درخواست بیش از اندازه بزرگ است.',413);
        $in=$r->get_json_params();
        if (!is_array($in)) throw new ValidationException(['body'=>'بدنهٔ JSON معتبر لازم است.']);
        Operations::canonical($in);
        return $in;
    }
    /** URL captures are authoritative; query/JSON parameters cannot replace the routed resource. */
    public static function pathValue($r,$name,$default='') {
        $url=$r->get_url_params();
        return isset($url[$name])?(string)$url[$name]:$default;
    }
    public static function wire($value) {
        if (is_int($value)) return (string)$value;
        if (is_float($value)) throw new \LogicException('Floating point API output');
        if (is_array($value)) foreach ($value as &$v) $v=self::wire($v);
        return $value;
    }
    public static function scope($r,$yearRequired=false) {
        $url=$r->get_url_params(); $cid=Business::id($url['company']??'0'); Ctx::boot(get_current_user_id(),$cid); Policy::need($cid);
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
    public static function text($r,$key,$default='',$max=150) {
        $value=$r->get_param($key)??$default;
        if (!is_string($value)||strlen($value)>$max) throw new ValidationException([$key=>'پارامتر متنی معتبر لازم است.']);
        return $value;
    }
    public static function listing($r) {
        $entity=self::pathValue($r,'entity'); [$c,$year]=self::scope($r,!in_array($entity,['parties','items'],true));
        $p=self::page($r); $f=self::filters($r,$year); $q=$f['q']??'';
        switch ($entity) {
            case 'parties': return PartyService::page($c,$p,$q);
            case 'items': return CatalogService::page($c,'item',$p,$q);
            case 'invoices': return InvoiceService::page($c,self::text($r,'kind','sale',30),$f,$p);
            case 'treasury': return TreasuryService::documents($c,self::text($r,'kind','receipt',30),$p,(int)$year['id'],$f);
            case 'expenses': return ExpenseService::page($c,$f,$p);
            case 'vouchers': return ReportService::vouchers($c,$f,$p);
            case 'stock': return StockService::inventory($c,$p,$q)+['scope'=>'current_all_periods'];
        }
        throw new NotFoundException();
    }
    private static function record($entity,$c,$id) {
        switch ($entity) {
            case 'parties': return PartyService::get($c,$id);
            case 'items': return CatalogService::get($c,'item',$id);
            case 'invoices':
                $doc=InvoiceService::get($c,$id);
                if($doc['status']==='issued')$doc['outstanding']=InvoiceService::outstanding($c,$id);
                return $doc;
            case 'treasury': return TreasuryService::get($c,$id);
            case 'expenses': return ExpenseService::get($c,$id);
            case 'vouchers': return VoucherService::get($c,$id);
        }
        throw new NotFoundException();
    }
    public static function detail($r) {
        [$c,$year]=self::scope($r);$id=Business::id(self::pathValue($r,'id'));$entity=self::pathValue($r,'entity');
        return Db::tx(function () use ($c,$year,$id,$entity) {
            $row=self::record($entity,$c,$id);
            if ($year&&isset($row['fiscal_year_id'])&&(int)$row['fiscal_year_id']!==(int)$year['id']) throw new NotFoundException();
            return ['record'=>$row,'etag'=>Operations::etag(self::versionRecord($entity,$c,$id,$row))];
        });
    }
    private static function versionRecord($entity,$c,$id,$row) {
        // A masked identifier can change without changing its visible hint (or second-resolution timestamp).
        // Bind the opaque ETag to the private version too; never add private fields to the DTO.
        if($entity==='parties') $row['_private_version']=Operations::etag(Business::row('parties',$c,$id));
        return $row;
    }
    public static function lookups($r) {
        [$c]=self::scope($r); $name=self::pathValue($r,'lookup'); $p=self::page($r); $q=self::text($r,'q');
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
            case 'sale_invoices': case 'purchase_invoices':
                $kind=$name==='sale_invoices'?'sale':'purchase'; Ctx::need(InvoiceService::resource($kind).'.view');
                $party=Business::id($r->get_param('party_id')??'0');
                global $wpdb; $like='%'.$wpdb->esc_like($q).'%';
                $where="i.company_id=%d AND i.kind=%s AND i.status='issued' AND (CAST(i.number AS CHAR) LIKE %s OR p.name LIKE %s)";
                $args=[$c,$kind,$like,$like];
                if($party) { $where.=' AND i.party_id=%d'; $args[]=$party; }
                $join=' FROM '.Db::t('invoices').' i JOIN '.Db::t('parties').' p ON p.company_id=i.company_id AND p.id=i.party_id WHERE '.$where;
                $count=(int)Db::val('SELECT COUNT(*)'.$join,...$args);
                $rows=Db::rows('SELECT i.id,i.number,i.invoice_date,p.name party_name'.$join.' ORDER BY i.invoice_date DESC,i.id DESC LIMIT 50 OFFSET %d',...array_merge($args,[($p-1)*50]));
                foreach($rows as &$row) {
                    $row['outstanding']=InvoiceService::outstanding($c,(int)$row['id']);
                    $row['name']='فاکتور '.$row['number'].' — '.$row['party_name'].' — مانده '.Money::fmt($row['outstanding'],Ctx::unit()).' '.Money::unitLabel(Ctx::unit());
                } unset($row);
                return ['rows'=>$rows,'page'=>$p,'pages'=>(int)ceil($count/50),'count'=>$count];
        }
        throw new NotFoundException();
    }
    public static function lookupLabel($r) {
        [$c]=self::scope($r); $name=self::pathValue($r,'lookup'); $id=Business::id(self::pathValue($r,'id'));
        switch ($name) {
            case 'parties': $row=PartyService::get($c,$id); break;
            case 'items': $row=CatalogService::get($c,'item',$id); break;
            case 'units': $row=CatalogService::get($c,'unit',$id); break;
            case 'groups': $row=CatalogService::get($c,'group',$id); break;
            case 'taxes': $row=CatalogService::get($c,'tax',$id); break;
            case 'accounts': $row=AccountService::get($c,$id); break;
            case 'treasury_accounts': $row=TreasuryService::account($c,$id); break;
            case 'warehouses': Ctx::need('warehouse.view'); $row=Business::row('warehouses',$c,$id); break;
            case 'expense_categories': Ctx::need('expense.view'); $row=Business::row('expense_categories',$c,$id); break;
            case 'details': Ctx::need('detail.view'); $row=Business::row('details',$c,$id); break;
            case 'sale_invoices': case 'purchase_invoices':
                $invoice=InvoiceService::get($c,$id);
                if($invoice['kind']!==($name==='sale_invoices'?'sale':'purchase')) throw new NotFoundException();
                $row=['id'=>$invoice['id'],'code'=>$invoice['number'],'name'=>'فاکتور '.$invoice['number']]; break;
            default: throw new NotFoundException();
        }
        return array_intersect_key($row,array_flip(['id','code','name']));
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
        $entity=self::pathValue($r,'entity'); [$c,$year]=self::scope($r,!in_array($entity,['parties','items'],true));
        $id=Business::id(self::pathValue($r,'id','0')); $body=Payload::validate($entity,self::body($r)); $in=self::numeric($body);
        if (!in_array($entity,['parties','items'],true)) self::documentYear($in,$year,$entity);
        $kind=$in['kind']??'';
        $caps=['parties'=>'party','items'=>'item','invoices'=>InvoiceService::resource($entity==='invoices'?$kind:'sale'),'treasury'=>in_array($kind,['receipt','payment','transfer'],true)?$kind:'receipt','expenses'=>'expense','vouchers'=>'voucher','stock'=>'stock'];
        if ($id&&in_array($entity,['treasury','expenses','stock'],true)) throw new AppException('این برگه با اصلاح مستقیم تغییر نمی‌کند؛ از ابطال مجاز استفاده کنید.',409);
        $cap=$caps[$entity].'.'.($id?'edit':'create');
        return Operations::execute($c,$cap,$r->get_header('idempotency-key'),$entity.'.save',[$body,$r->get_header('if-match'),$id,$year['id']??0],function () use ($r,$entity,$c,$id,$in,$year) {
            if ($id) {
                $current=self::record($entity,$c,$id); Operations::match($r->get_header('if-match'),self::versionRecord($entity,$c,$id,$current));
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
        [$c,$year]=self::scope($r,true); $entity=self::pathValue($r,'entity'); $id=Business::id(self::pathValue($r,'id')); $action=self::pathValue($r,'action'); $body=Payload::action(self::body($r));
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
    public static function dashboard($r) {
        [$c,$y]=self::scope($r,true);
        $date=Business::date(self::text($r,'as_of',max($y['start_date'],min(\Agah\Erp\Core\Jalali::today(),$y['end_date'])),10));
        if($date<$y['start_date']||$date>$y['end_date']) throw new ValidationException(['as_of'=>'تاریخ باید در سال مالی انتخاب‌شده باشد.']);
        $from=Business::date(self::text($r,'from',$y['start_date'],10));
        if($from<$y['start_date']||$from>$date)throw new ValidationException(['from'=>'شروع بازه باید در سال مالی و پیش از پایان باشد.']);
        return Db::tx(function () use ($c,$date,$from) {
            $summary=DashboardService::summary($c,$date);$period=DashboardService::period($c,$from,$date);
            $summary['cards']=$period['cards'];
            return $summary+['from'=>$from,'to'=>$date,'notifications'=>DashboardService::notifications($c,$date),'trend_scope'=>'six_calendar_months'];
        });
    }
    public static function report($r) {
        [$c,$year]=self::scope($r,true); $f=self::filters($r,$year); $p=self::page($r);
        switch (self::pathValue($r,'report')) {
            case 'ledger': return ReportService::ledger($c,$f,$p);
            case 'parties': return FinancialService::parties($c,$f['from'],$f['to'],$p);
            case 'statements': return FinancialService::statements($c,$f['from'],$f['to']);
            case 'ratios':
                $data=\Agah\Erp\Modules\Accounting\RatioService::compute($c,$f['from'],$f['to']);$rows=[];
                foreach($data['ratios'] as $code=>$ratio) {
                    $unit=$ratio['unit']==='money'?Money::unitLabel(Ctx::unit()):\Agah\Erp\Modules\Accounting\RatioService::UNITS[$ratio['unit']];
                    $value=$ratio['value']===null?'غیرقابل محاسبه':($ratio['unit']==='money'?Money::toInputValue(Money::integer($ratio['value']),Ctx::unit()):$ratio['value']);
                    $rows[]=['code'=>$code,'name'=>$ratio['name'],'description'=>\Agah\Erp\Modules\Accounting\RatioService::DESCRIPTIONS[$code],'formula'=>$ratio['formula'],'result_text'=>$value,'unit_label'=>$unit,'reason'=>$ratio['reason']];
                }
                return ['rows'=>$rows,'from'=>$f['from'],'to'=>$f['to'],'page'=>1,'pages'=>1];
            case 'inventory': return StockService::inventory($c,$p,$f['q']??'');
            case 'kardex': return StockService::kardex($c,Business::id($r->get_param('warehouse_id')??'0'),Business::id($r->get_param('item_id')??'0'),$p)+['scope'=>'all_periods'];
            case 'party_statement': return PartyService::statement($c,Business::id($r->get_param('party_id')??'0'),$f['from'],$f['to'],$p);
            case 'sales': case 'purchases':
                $data=InvoiceService::page($c,self::pathValue($r,'report')==='sales'?'sale':'purchase',array_merge($f,['status'=>'issued']),$p);
                foreach($data['rows'] as &$row)$row['outstanding']=InvoiceService::outstanding($c,(int)$row['id']);unset($row);
                return $data+['settlement_scope'=>'current'];
            case 'expenses': return ExpenseService::page($c,array_merge($f,['status'=>'issued']),$p);
            case 'receipts': case 'payments':
                $kind=self::pathValue($r,'report')==='receipts'?'receipt':'payment'; Ctx::need($kind.'.view');
                $where='d.company_id=%d AND d.kind=%s AND d.status=\'issued\' AND d.fiscal_year_id=%d AND d.doc_date BETWEEN %s AND %s';
                $args=[$c,$kind,$year['id'],$f['from'],$f['to']];
                $count=(int)Db::val('SELECT COUNT(*) FROM '.Db::t('treasury_docs').' d WHERE '.$where,...$args);
                $rows=Db::rows('SELECT d.id,d.number,d.doc_date,d.description,d.amount,p.name party_name FROM '.Db::t('treasury_docs').' d LEFT JOIN '.Db::t('parties').' p ON p.company_id=d.company_id AND p.id=d.party_id WHERE '.$where.' ORDER BY d.doc_date DESC,d.id DESC LIMIT 50 OFFSET %d',...array_merge($args,[($p-1)*50]));
                return ['rows'=>$rows,'page'=>$p,'pages'=>(int)ceil($count/50),'count'=>$count];
        }
        throw new NotFoundException();
    }
}
