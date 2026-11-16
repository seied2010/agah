<?php
/** CSV + genuine OOXML .xlsx. Local ZIP writer, no external library or formula evaluation. */
namespace Agah\Erp\Core;
if(!defined('ABSPATH'))exit;
final class DecimalCell {public $number;public function __construct($number){$this->number=$number;}}
final class Tabular {
    /** واحد مبلغ خروجی؛ null یعنی واحد نمایش شرکت. گزارش‌های قانونی ریال را صریح تعیین می‌کنند. */
    public static $unit=null;
    public static function unit(){return self::$unit??(Ctx::cidOrZero()?Ctx::unit():'rial');}
    const MONEY=['credit_limit','sale_price','purchase_price','amount','outstanding','value','cost','balance_value','net','tax','extra','gross','taxable','insurance_employee','insurance_employer','accum','book_value','receivable','payable','opening','debit','credit','balance','balance_debit','balance_credit','closing','profit','خالص','مالیات','هزینه_جانبی'];
    public static function cell($key,$v){return in_array($key,self::MONEY,true)&&$v!==''&&$v!==null&&preg_match('/^-?\d+$/D',(string)$v)?new DecimalCell(Money::toInputValue((int)$v,self::unit())):$v;}
    private static function xml($v){$v=preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u','',(string)$v);return htmlspecialchars($v,ENT_XML1|ENT_QUOTES,'UTF-8');}
    private static function zip(array $files){$body='';$central='';$count=0;foreach($files as $name=>$data){$offset=strlen($body);$size=strlen($data);$crc=crc32($data);$len=strlen($name);$body.=pack('VvvvvvVVVvv',0x04034b50,20,0,0,0,33,$crc,$size,$size,$len,0).$name.$data;$central.=pack('VvvvvvvVVVvvvvvVV',0x02014b50,20,20,0,0,0,33,$crc,$size,$size,$len,0,0,0,0,0,$offset).$name;$count++;}return $body.$central.pack('VvvvvVVv',0x06054b50,0,0,$count,$count,strlen($central),strlen($body),0);}
    public static function xlsx(array $headers,array $rows,$sheet='گزارش'){
        if(count($rows)>10000)throw new ValidationException(['export'=>'خروجی تا ۱۰هزار ردیف است؛ بازه را کوتاه‌تر کنید.']);
        $xml='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0" rightToLeft="1"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>';
        foreach(array_merge([$headers],$rows) as $i=>$row){$xml.='<row r="'.($i+1).'">';foreach(array_values($row) as $v){$number=$v instanceof DecimalCell?$v->number:(is_int($v)?(string)$v:null);
            // Excel only guarantees 15 significant decimal digits. Keep larger exact values as text.
            if($number!==null&&preg_match('/^-?\d+(?:\.\d+)?$/D',$number)&&strlen(ltrim(str_replace(['-','.'],'',$number),'0'))<=15)$xml.='<c s="'.($v instanceof DecimalCell?'1':'0').'" t="n"><v>'.$number.'</v></c>';
            else $xml.='<c t="inlineStr"><is><t xml:space="preserve">'.self::xml($number??$v).'</t></is></c>';
        }$xml.='</row>';}$xml.='</sheetData></worksheet>';
        return self::zip([
            '[Content_Types].xml'=>'<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels'=>'<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml'=>'<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.self::xml($sheet).'" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels'=>'<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml'=>'<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="1"><font><sz val="11"/><name val="Arial"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf xfId="0"/><xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
            'xl/worksheets/sheet1.xml'=>$xml
        ]);
    }
    public static function download($name,array $headers,array $rows,$format=null,$sheet='گزارش'){
        $format=$format??Request::str('get','format',10,'csv');if($format==='excel')$format='xlsx';
        if(!in_array($format,['csv','xlsx'],true))throw new ValidationException(['format'=>'قالب خروجی معتبر نیست.']);
        if(count($rows)>10000)throw new ValidationException(['export'=>'خروجی تا ۱۰هزار ردیف است؛ بازه را کوتاه‌تر کنید.']);
        $bytes=$format==='xlsx'?self::xlsx($headers,$rows,$sheet):null;
        header('Content-Type: '.($format==='xlsx'?'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':'text/csv; charset=UTF-8'));header('Content-Disposition: attachment; filename="'.sanitize_file_name($name).'.'.$format.'"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
        if($bytes!==null){header('Content-Length: '.strlen($bytes));echo $bytes;return;}
        $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");foreach(array_merge([$headers],$rows) as $row){foreach($row as &$v){if($v instanceof DecimalCell){$v=$v->number;continue;}if(is_string($v)&&preg_match('/^[\s]*[=+\-@]/u',$v))$v="'".$v;}unset($v);fputcsv($out,array_values($row),',','"','');}fclose($out);
    }
}
