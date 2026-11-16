<?php
namespace Agah\Mobile;
use Agah\Erp\Core\{Ctx,Money,Jalali,AppException,Audit};
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
        $money=['opening','debit','credit','balance','balance_debit','balance_credit','opening_debit','opening_credit','prior_debit','prior_credit','year_debit','year_credit','amount','value','cost','balance_value','sales','cogs','gross','opex','operating','other_income','other_expense','finance_cost','tax_expense','net'];
        if (in_array($key,$money,true)) return Money::toInputValue(Money::integer($raw),Ctx::unit());
        if (in_array($key,['qty','qty_scaled','balance_qty'],true)) return Money::qtyToDb(Money::integer($raw));
        if (substr($key,-5)==='_date'&&$raw) return Jalali::format((string)$raw,false);
        if ($key==='status') return ['draft'=>'پیش‌نویس','issued'=>'صادرشده','void'=>'باطل‌شده','approved'=>'تأییدشده','final'=>'قطعی'][$raw]??(string)$raw;
        return (string)$raw;
    }
    public static function report($r) {
        [$c]=Api::scope($r,true); Ctx::need('report.export');
        if (!\Agah\Erp\Security\RateLimiter::consume('mobile-export|'.get_current_user_id().'|'.$c,10,60)) throw new AppException('تعداد خروجی زیاد است؛ کمی بعد تلاش کنید.',429);
        $data=Api::report($r);
        $rows=$data['rows']??[];
        if (!$rows && isset($data['profit'])) $rows=[$data['profit']];
        $columns=self::columns($r['report'],(string)$r->get_param('mode'));
        $formatted=[];
        foreach ($rows as $row) { $line=[]; foreach ($columns as $key=>$title) $line[$key]=self::value($key,$row[$key]??''); $formatted[]=$line; }
        $format=$r->get_param('format')??'pdf';
        $name='agah-'.$r['report'].'-page-'.max(1,(int)$r->get_param('page'));
        if ($format==='csv') {
            $stream=fopen('php://temp','w+'); fwrite($stream,"\xEF\xBB\xBF");
            if ($formatted) {
                fputcsv($stream,array_values($columns));
                foreach ($formatted as $row) fputcsv($stream,array_map(function ($v) { $s=(string)$v; return !preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D',$s)&&preg_match('/^[=+@\-\t\r]/',$s)?"'".$s:$s; },array_values($row)));
            }
            rewind($stream); $bytes=stream_get_contents($stream); fclose($stream); $mime='text/csv';
        } elseif ($format==='pdf') {
            $loader=dirname(__DIR__).'/vendor/autoload.php';
            if (!is_file($loader)) throw new AppException('کتابخانهٔ خروجی PDF روی سرور نصب نیست.',503);
            require_once $loader;
            $temp=get_temp_dir().'agah-mobile-pdf';
            if (!is_dir($temp)) wp_mkdir_p($temp);
            if (!is_dir($temp)||!is_writable($temp)) throw new AppException('پوشهٔ موقت امن برای PDF قابل نوشتن نیست.',503);
            @chmod($temp,0700);
            $pdf=new \Mpdf\Mpdf(['mode'=>'utf-8','format'=>'A4-L','tempDir'=>$temp,'default_font'=>'dejavusans','autoScriptToLang'=>true,'autoLangToFont'=>true]);
            $pdf->SetDirectionality('rtl');
            $html='<h2>'.esc_html(Ctx::company()['name']).'</h2><p>گزارش — صفحه '.max(1,(int)$r->get_param('page')).'</p><p>واحد مبالغ: '.Money::unitLabel(Ctx::unit()).'. خروجی همین صفحهٔ فیلترشده است.</p><table border="1" cellpadding="5"><thead><tr>';
            foreach ($columns as $title) $html.='<th>'.esc_html($title).'</th>';
            $html.='</tr></thead><tbody>';
            foreach ($formatted as $row) { $html.='<tr>'; foreach ($row as $v) $html.='<td>'.esc_html($v).'</td>'; $html.='</tr>'; }
            $pdf->WriteHTML($html.'</tbody></table>'); $bytes=$pdf->Output('','S'); $mime='application/pdf';
        } else throw new AppException('قالب خروجی معتبر نیست.',422);
        Audit::log('mobile.report.export','report',0,null,['report'=>$r['report'],'format'=>$format],$c);
        return ['name'=>$name.'.'.$format,'mime'=>$mime,'sha256'=>hash('sha256',$bytes),'content_base64'=>base64_encode($bytes)];
    }
}
