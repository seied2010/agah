<?php
namespace Agah\Mobile;
use Agah\Erp\Core\{Ctx,Money,Jalali,AppException,Audit,Db,Business,Settings,NotFoundException};
if (!defined('ABSPATH')) exit;
final class Exports {
    private static function columns($report,$mode) {
        if ($report==='ledger') return \Agah\Erp\Modules\Accounting\ReportService::columns($mode?:'journal');
        $map=[
            'parties'=>['code'=>'کد','name'=>'شخص','phone'=>'تلفن','opening'=>'مانده اول','debit'=>'بدهکار','credit'=>'بستانکار','balance'=>'مانده'],
            'sales'=>['number'=>'شماره','party_name'=>'مشتری','invoice_date'=>'تاریخ','status'=>'وضعیت','amount'=>'مبلغ'],
            'purchases'=>['number'=>'شماره','party_name'=>'تأمین‌کننده','invoice_date'=>'تاریخ','status'=>'وضعیت','amount'=>'مبلغ'],
            'expenses'=>['number'=>'شماره','party_name'=>'شخص','doc_date'=>'تاریخ','description'=>'شرح','amount'=>'مبلغ'],
            'receipts'=>['number'=>'شماره','party_name'=>'شخص','doc_date'=>'تاریخ','description'=>'شرح','amount'=>'مبلغ'],
            'payments'=>['number'=>'شماره','party_name'=>'شخص','doc_date'=>'تاریخ','description'=>'شرح','amount'=>'مبلغ'],
            'inventory'=>['warehouse_name'=>'انبار','code'=>'کد کالا','item_name'=>'کالا','qty'=>'موجودی','value'=>'ارزش'],
            'kardex'=>['move_date'=>'تاریخ','qty_scaled'=>'مقدار','cost'=>'بها','balance_qty'=>'مانده مقدار','balance_value'=>'مانده ارزش'],
            'statements'=>['sales'=>'فروش خالص','cogs'=>'بهای تمام‌شده','gross'=>'سود ناخالص','opex'=>'هزینه عملیاتی','operating'=>'سود عملیاتی','other_income'=>'سایر درآمدها','other_expense'=>'سایر هزینه‌ها','finance_cost'=>'هزینه مالی','tax_expense'=>'هزینه مالیات','net'=>'سود خالص']
        ];
        return $map[$report]??[];
    }
    private static function value($key,$raw) {
        if (!is_scalar($raw)||$raw==='') return '';
        $money=['opening','debit','credit','balance','balance_debit','balance_credit','opening_debit','opening_credit','prior_debit','prior_credit','year_debit','year_credit','amount','value','cost','balance_value','sales','cogs','gross','opex','operating','other_income','other_expense','finance_cost','tax_expense','net','unit_price','discount_amount','net_amount','tax_amount','extra_cost','total_debit','total_credit'];
        if (in_array($key,$money,true)) return Money::toInputValue(Money::integer($raw),Ctx::unit());
        if (in_array($key,['qty','qty_scaled','balance_qty'],true)) return Money::qtyToDb(Money::integer($raw));
        if (substr($key,-5)==='_date'&&$raw) return Jalali::format((string)$raw,false);
        if ($key==='status') return ['draft'=>'پیش‌نویس','issued'=>'صادرشده','void'=>'باطل‌شده','approved'=>'تأییدشده','final'=>'قطعی'][$raw]??(string)$raw;
        return (string)$raw;
    }
    private static function renderPdf($html) {
        $loader=dirname(__DIR__).'/vendor/autoload.php';
        if (!is_file($loader)) throw new AppException('کتابخانهٔ خروجی PDF روی سرور نصب نیست.',503);
        require_once $loader;
        $temp=get_temp_dir().'agah-mobile-pdf';
        if(!is_dir($temp)) wp_mkdir_p($temp);
        if(!is_dir($temp)||!is_writable($temp)) throw new AppException('پوشهٔ موقت امن برای PDF قابل نوشتن نیست.',503);
        @chmod($temp,0700);
        $pdf=new \Mpdf\Mpdf(['mode'=>'utf-8','format'=>'A4-L','tempDir'=>$temp,'default_font'=>'dejavusans','autoScriptToLang'=>true,'autoLangToFont'=>true]);
        $pdf->SetDirectionality('rtl');
        $pdf->SetHTMLFooter('<div style="text-align:center;font-size:8pt">صفحه {PAGENO} از {nbpg}</div>');
        $pdf->WriteHTML($html);
        return $pdf->Output('','S');
    }
    private static function encoded($name,$mime,$bytes) {
        if(strlen($bytes)>2097152) throw new AppException('این خروجی برای دریافت موبایل بزرگ است؛ از نسخهٔ وب دریافت کنید.',413);
        return ['name'=>$name,'mime'=>$mime,'sha256'=>hash('sha256',$bytes),'content_base64'=>base64_encode($bytes)];
    }
    public static function document($r) {
        [$c,$year]=Api::scope($r,true);$id=Business::id($r['id']);$entity=$r['entity'];
        if(!\Agah\Erp\Security\RateLimiter::consume('mobile-document-export|'.get_current_user_id().'|'.$c,10,60)) throw new AppException('تعداد خروجی زیاد است؛ کمی بعد تلاش کنید.',429);
        $snapshot=Db::tx(function () use ($c,$id,$entity,$year) {
            switch($entity) {
                case 'invoices': $doc=\Agah\Erp\Modules\Trade\InvoiceService::get($c,$id);$cap=\Agah\Erp\Modules\Trade\InvoiceService::resource($doc['kind']);$title=\Agah\Erp\Modules\Trade\InvoiceService::KINDS[$doc['kind']]; break;
                case 'vouchers': $doc=\Agah\Erp\Modules\Accounting\VoucherService::get($c,$id);$cap='voucher';$title='سند حسابداری';break;
                case 'treasury': $doc=\Agah\Erp\Modules\Treasury\TreasuryService::get($c,$id);$cap=$doc['kind'];$title=$doc['kind']==='receipt'?'برگه دریافت':'برگه پرداخت';break;
                case 'expenses': $doc=\Agah\Erp\Modules\Expenses\ExpenseService::get($c,$id);$cap='expense';$title='برگه هزینه / درآمد';break;
                default:throw new NotFoundException();
            }
            Ctx::need($cap.'.export');
            if((int)$doc['fiscal_year_id']!==(int)$year['id']) throw new NotFoundException();
            $party=empty($doc['party_id'])?null:Db::row('SELECT name,phone,address FROM '.Db::t('parties').' WHERE company_id=%d AND id=%d',$c,$doc['party_id']);
            return [$doc,$title,$party];
        });
        [$doc,$title,$party]=$snapshot;
        $html='<h2>'.esc_html(Ctx::company()['name']).'</h2><h3>'.esc_html($title).'</h3><p>شماره: '.esc_html($doc['number']??'بدون شماره').' — وضعیت: '.esc_html(self::value('status',$doc['status']??'draft')).'</p>';
        $date=$doc['invoice_date']??$doc['voucher_date']??$doc['doc_date']??'';
        $html.='<p>تاریخ: '.esc_html($date?Jalali::format($date):'').' — واحد مبالغ: '.Money::unitLabel(Ctx::unit()).'</p>';
        if($party) $html.='<p>طرف حساب: '.esc_html($party['name']).' — تلفن: '.esc_html($party['phone']).'</p><p>'.esc_html($party['address']).'</p>';
        if(!empty($doc['description'])) $html.='<p>شرح: '.esc_html($doc['description']).'</p>';
        $columns=$entity==='invoices'?['item_code'=>'کد','item_name'=>'کالا / خدمت','unit_name'=>'واحد','qty_scaled'=>'مقدار','unit_price'=>'قیمت واحد','discount_amount'=>'تخفیف ردیف','net_amount'=>'خالص','tax_amount'=>'مالیات']:
            ($entity==='vouchers'?['account_code'=>'کد حساب','account_name'=>'حساب','detail_name'=>'تفصیلی','description'=>'شرح','debit'=>'بدهکار','credit'=>'بستانکار']:['account_name'=>'حساب خزانه','method'=>'روش','amount'=>'مبلغ','reference'=>'پیگیری']);
        $rows=$doc['lines']??$doc['items']??[];
        if($rows) {
            $html.='<table border="1" cellpadding="5" style="width:100%;font-size:9pt"><thead><tr>';
            foreach($columns as $label) $html.='<th>'.esc_html($label).'</th>';
            $html.='</tr></thead><tbody>';
            foreach($rows as $row) { $html.='<tr>';foreach($columns as $key=>$label) $html.='<td>'.esc_html(self::value($key,$row[$key]??'')).'</td>';$html.='</tr>'; }
            $html.='</tbody></table>';
        }
        foreach(['discount_amount'=>'تخفیف کل','extra_cost'=>'هزینه جانبی','amount'=>'جمع کل','total_debit'=>'جمع بدهکار','total_credit'=>'جمع بستانکار'] as $key=>$label) if(isset($doc[$key])) $html.='<p>'.esc_html($label).': '.esc_html(self::value($key,$doc[$key])).' '.Money::unitLabel(Ctx::unit()).'</p>';
        if($entity==='vouchers') {
            $html.='<table style="width:100%;margin-top:25mm"><tr>';
            foreach(['حسابداری','مدیر مالی','مدیرعامل'] as $index=>$fallback) $html.='<td style="text-align:center">'.esc_html(Settings::get('voucher_sign_'.($index+1),$c)?:$fallback).'<br><br>نام و امضا</td>';
            $html.='</tr></table>';
        }
        $bytes=self::renderPdf($html);
        Audit::log('mobile.document.export',$entity,$id,null,null,$c);
        return self::encoded('agah-'.$entity.'-'.$id.'.pdf','application/pdf',$bytes);
    }
    public static function report($r) {
        [$c]=Api::scope($r,true); Ctx::need('report.export');
        if (!\Agah\Erp\Security\RateLimiter::consume('mobile-export|'.get_current_user_id().'|'.$c,10,60)) throw new AppException('تعداد خروجی زیاد است؛ کمی بعد تلاش کنید.',429);
        $data=Api::report($r);
        $rows=$data['rows']??[];
        $columns=self::columns($r['report'],Api::text($r,'mode','journal',30));
        if ($r['report']==='statements') {
            $columns=['section'=>'صورت مالی','label'=>'عنوان','amount'=>'مبلغ','count'=>'تعداد'];$rows=[];
            $labels=self::columns('statements','')+['assets'=>'دارایی‌ها','liabilities'=>'بدهی‌ها','equity'=>'حقوق مالکانه','current_profit'=>'سود دوره','difference'=>'اختلاف تطبیق','profit'=>'سود دوره','noncash_contra'=>'اقلام غیرنقدی','working_capital'=>'سرمایه در گردش','noncash_capital_adjustment'=>'تعدیل سرمایه‌ای غیرنقدی','investing'=>'فعالیت سرمایه‌گذاری','financing'=>'فعالیت تأمین مالی','start'=>'وجه نقد ابتدای بازه','end'=>'وجه نقد انتهای بازه','change'=>'تغییر وجه نقد','review_count'=>'اسناد نیازمند بررسی','noncash_documents'=>'اسناد غیرنقدی'];
            foreach(['profit'=>'سود و زیان','balance'=>'ترازنامه','cashflow'=>'جریان وجوه نقد'] as $section=>$title) {
                foreach(($data[$section]??[]) as $key=>$value) {
                    if(!isset($labels[$key])||!is_scalar($value)) continue;
                    $count=in_array($key,['review_count','noncash_documents'],true);
                    $rows[]=['section'=>$title,'label'=>$labels[$key],'amount'=>$count?'':$value,'count'=>$count?$value:''];
                }
                if($section==='balance') foreach(($data[$section]['rows']??[]) as $row) $rows[]=['section'=>'ترازنامه — ریز حساب‌ها','label'=>$row['code'].' — '.$row['name'],'amount'=>$row['balance'],'count'=>''];
            }
        }
        $formatted=[];
        foreach ($rows as $row) { $line=[]; foreach ($columns as $key=>$title) $line[$key]=self::value($key,$row[$key]??''); $formatted[]=$line; }
        $format=Api::text($r,'format','pdf',10);
        $name='agah-'.$r['report'].'-page-'.max(1,(int)$r->get_param('page'));
        if ($format==='csv') {
            $stream=fopen('php://temp','w+'); fwrite($stream,"\xEF\xBB\xBF");
            if ($formatted) {
                fputcsv($stream,array_values($columns));
                foreach ($formatted as $row) fputcsv($stream,array_map(function ($v) { $s=(string)$v; return !preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D',$s)&&preg_match('/^[=+@\-\t\r]/',$s)?"'".$s:$s; },array_values($row)));
            }
            rewind($stream); $bytes=stream_get_contents($stream); fclose($stream); $mime='text/csv';
        } elseif ($format==='pdf') {
            $html='<h2>'.esc_html(Ctx::company()['name']).'</h2><p>گزارش — صفحه '.max(1,(int)$r->get_param('page')).'</p><p>واحد مبالغ: '.Money::unitLabel(Ctx::unit()).'. خروجی همین صفحهٔ فیلترشده است.</p><table border="1" cellpadding="5"><thead><tr>';
            foreach ($columns as $title) $html.='<th>'.esc_html($title).'</th>';
            $html.='</tr></thead><tbody>';
            foreach ($formatted as $row) { $html.='<tr>'; foreach ($row as $v) $html.='<td>'.esc_html($v).'</td>'; $html.='</tr>'; }
            $bytes=self::renderPdf($html.'</tbody></table>'); $mime='application/pdf';
        } else throw new AppException('قالب خروجی معتبر نیست.',422);
        Audit::log('mobile.report.export','report',0,null,['report'=>$r['report'],'format'=>$format],$c);
        return self::encoded($name.'.'.$format,$mime,$bytes);
    }
}
