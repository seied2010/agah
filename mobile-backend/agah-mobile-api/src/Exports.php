<?php
namespace Agah\Mobile;
use Agah\Erp\Core\{Ctx,Money,AppException,Audit};
if (!defined('ABSPATH')) exit;
final class Exports {
    public static function report($r) {
        [$c]=Api::scope($r,true); Ctx::need('report.export');
        $data=Api::report($r);
        $rows=$data['rows']??[];
        if (!$rows && isset($data['profit'])) $rows=[$data['profit']];
        $format=$r->get_param('format')??'pdf';
        $name='agah-'.$r['report'].'-page-'.max(1,(int)$r->get_param('page'));
        if ($format==='csv') {
            $stream=fopen('php://temp','w+'); fwrite($stream,"\xEF\xBB\xBF");
            if ($rows) {
                fputcsv($stream,array_keys($rows[0]));
                foreach ($rows as $row) fputcsv($stream,array_map(function ($v) { $s=is_scalar($v)?(string)$v:''; return preg_match('/^[=+@\-\t\r]/',$s)?"'".$s:$s; },array_values($row)));
            }
            rewind($stream); $bytes=stream_get_contents($stream); fclose($stream); $mime='text/csv';
        } elseif ($format==='pdf') {
            $loader=dirname(__DIR__).'/vendor/autoload.php';
            if (!is_file($loader)) throw new AppException('کتابخانهٔ خروجی PDF روی سرور نصب نیست.',503);
            require_once $loader;
            $temp=get_temp_dir().'agah-mobile-pdf';
            if (!is_dir($temp)) wp_mkdir_p($temp);
            $pdf=new \Mpdf\Mpdf(['mode'=>'utf-8','format'=>'A4-L','tempDir'=>$temp,'default_font'=>'dejavusans','autoScriptToLang'=>true,'autoLangToFont'=>true]);
            $pdf->SetDirectionality('rtl');
            $html='<h2>'.esc_html(Ctx::company()['name']).'</h2><p>گزارش '.$r['report'].' — صفحه '.max(1,(int)$r->get_param('page')).'</p><p>واحد ذخیرهٔ مبالغ: یک‌صدم ریال. خروجی همین صفحهٔ فیلترشده است.</p><table border="1" cellpadding="5"><thead><tr>';
            foreach (array_keys($rows[0]??[]) as $key) $html.='<th>'.esc_html($key).'</th>';
            $html.='</tr></thead><tbody>';
            foreach ($rows as $row) { $html.='<tr>'; foreach ($row as $v) $html.='<td>'.esc_html(is_scalar($v)?(string)$v:'').'</td>'; $html.='</tr>'; }
            $pdf->WriteHTML($html.'</tbody></table>'); $bytes=$pdf->Output('','S'); $mime='application/pdf';
        } else throw new AppException('قالب خروجی معتبر نیست.',422);
        Audit::log('mobile.report.export','report',0,null,['report'=>$r['report'],'format'=>$format],$c);
        return ['name'=>$name.'.'.$format,'mime'=>$mime,'sha256'=>hash('sha256',$bytes),'content_base64'=>base64_encode($bytes)];
    }
}
