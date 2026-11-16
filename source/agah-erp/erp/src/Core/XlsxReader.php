<?php
/**
 * خوانندهٔ حداقلی XLSX بدون کتابخانهٔ بیرونی و بدون افزونهٔ zip: فقط اولین برگه، فقط مقدار ذخیره‌شدهٔ سلول (فرمول اجرا نمی‌شود).
 * ایمنی: سقف حجم، تعداد فایل داخل بسته، حجم بازشده و نسبت فشرده‌سازی (ضد بمب zip)، رد فایل رمزدار،
 * رد DOCTYPE/ENTITY در XML (ضد XXE) و بارگذاری بدون شبکه.
 */
namespace Agah\Erp\Core;
if(!defined('ABSPATH'))exit;
final class XlsxReader {
    const MAX_FILE=5242880;      // ۵ مگابایت فایل بارگذاری‌شده
    const MAX_PART=20971520;     // ۲۰ مگابایت برای هر جزء بازشده
    const MAX_TOTAL=41943040;    // ۴۰ مگابایت مجموع بازشده
    const MAX_ENTRIES=500;
    const MAX_ROWS=5000;
    const MAX_COLS=40;
    private static function fail($m='فایل Excel (XLSX) معتبر نیست.'){throw new ValidationException(['file'=>$m]);}

    /** فهرست اجزای بسته: نام => [روش، حجم فشرده، حجم بازشده، آفست سرآیند محلی] */
    private static function entries($bin){
        $len=strlen($bin);if($len<22||$len>self::MAX_FILE)self::fail($len>self::MAX_FILE?'حجم فایل بیش از ۵ مگابایت است.':null);
        if(substr($bin,0,4)!=="PK\x03\x04")self::fail();
        $pos=strrpos(substr($bin,max(0,$len-65557)),"PK\x05\x06");if($pos===false)self::fail();$pos+=max(0,$len-65557);
        $eocd=unpack('vdisk/vcd_disk/ventries_disk/ventries/Vsize/Voffset',substr($bin,$pos+4,16));
        if($eocd['entries']>self::MAX_ENTRIES||$eocd['offset']+$eocd['size']>$len)self::fail();
        $out=[];$p=$eocd['offset'];
        for($i=0;$i<$eocd['entries'];$i++){
            if(substr($bin,$p,4)!=="PK\x01\x02")self::fail();
            $h=unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/viattr/Veattr/Voffset',substr($bin,$p+4,42));
            $name=substr($bin,$p+46,$h['nlen']);
            if($h['flags']&1)self::fail('فایل رمزدار پذیرفته نمی‌شود.');
            if(!in_array($h['method'],[0,8],true))self::fail();
            $out[$name]=$h;$p+=46+$h['nlen']+$h['elen']+$h['clen'];
        }
        return $out;
    }
    private static function part($bin,array $entries,$name,&$budget,$required=true){
        if(!isset($entries[$name])){if($required)self::fail();return null;}
        $h=$entries[$name];if($h['usize']>self::MAX_PART||$h['csize']>self::MAX_FILE)self::fail('محتوای فایل بیش از حد بزرگ است.');
        $p=$h['offset'];if(substr($bin,$p,4)!=="PK\x03\x04")self::fail();
        $l=unpack('vnlen/velen',substr($bin,$p+26,4));$data=substr($bin,$p+30+$l['nlen']+$l['elen'],$h['csize']);
        if($h['method']===8){$data=@gzinflate($data,self::MAX_PART);if($data===false)self::fail();}
        if(strlen($data)!==$h['usize']||crc32($data)!==$h['crc'])self::fail();
        $budget-=strlen($data);if($budget<0)self::fail('محتوای فایل بیش از حد بزرگ است.');
        return $data;
    }
    private static function dom($xml){
        if(!class_exists('DOMDocument'))self::fail('افزونهٔ DOM روی سرور لازم است.');
        if(preg_match('/<!DOCTYPE|<!ENTITY/i',$xml))self::fail();
        $d=new \DOMDocument();$prev=libxml_use_internal_errors(true);$ok=$d->loadXML($xml,LIBXML_NONET|LIBXML_COMPACT);libxml_clear_errors();libxml_use_internal_errors($prev);
        if(!$ok)self::fail();return $d;
    }
    private static function text(\DOMElement $e){$s='';foreach($e->getElementsByTagNameNS('*','t') as $t){if($t->parentNode&&$t->parentNode->localName==='rPh')continue;$s.=$t->textContent;}return $s;}
    private static function col($ref){if(!preg_match('/^([A-Z]{1,3})/',$ref,$m))return -1;$n=0;foreach(str_split($m[1]) as $c)$n=$n*26+ord($c)-64;return $n-1;}

    /** @return array{sheet:string,rows:array<int,array<int,string>>} کلید = شمارهٔ ردیف در Excel؛ مقدار همهٔ سلول‌ها متن خام */
    public static function read($bin){
        $entries=self::entries($bin);$budget=self::MAX_TOTAL;
        $types=self::part($bin,$entries,'[Content_Types].xml',$budget);if(strpos($types,'spreadsheetml')===false)self::fail();
        $wb=self::dom(self::part($bin,$entries,'xl/workbook.xml',$budget));$sheet=$wb->getElementsByTagNameNS('*','sheet')->item(0);if(!$sheet)self::fail();
        $rid=$sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships','id');$target='xl/worksheets/sheet1.xml';
        $rels=self::part($bin,$entries,'xl/_rels/workbook.xml.rels',$budget,false);
        if($rels!==null)foreach(self::dom($rels)->getElementsByTagNameNS('*','Relationship') as $r)if($r->getAttribute('Id')===$rid){$t=ltrim($r->getAttribute('Target'),'/');$target=strpos($t,'xl/')===0?$t:'xl/'.$t;}
        if(strpos($target,'..')!==false)self::fail();
        $shared=[];$ss=self::part($bin,$entries,'xl/sharedStrings.xml',$budget,false);
        if($ss!==null)foreach(self::dom($ss)->getElementsByTagNameNS('*','si') as $si){$shared[]=self::text($si);if(count($shared)>200000)self::fail();}
        $rows=[];
        foreach(self::dom(self::part($bin,$entries,$target,$budget))->getElementsByTagNameNS('*','row') as $row){
            $r=(int)$row->getAttribute('r');if($r<=0)$r=($rows?max(array_keys($rows)):0)+1;if(count($rows)>self::MAX_ROWS)self::fail('حداکثر '.self::MAX_ROWS.' ردیف در هر فایل پذیرفته می‌شود.');
            $cells=[];$next=0;
            foreach($row->childNodes as $c){
                if(!$c instanceof \DOMElement||$c->localName!=='c')continue;
                $i=$c->hasAttribute('r')?self::col($c->getAttribute('r')):$next;$next=$i+1;if($i<0||$i>=self::MAX_COLS)continue;
                $type=$c->getAttribute('t');$v=null;foreach($c->childNodes as $x)if($x instanceof \DOMElement&&$x->localName==='v')$v=$x->textContent;
                if($type==='s')$val=$shared[(int)$v]??'';elseif($type==='inlineStr'){$val='';foreach($c->childNodes as $x)if($x instanceof \DOMElement&&$x->localName==='is')$val=self::text($x);}
                elseif($type==='b')$val=$v==='1'?'1':'0';else $val=(string)$v;
                $cells[$i]=trim(str_replace("\xC2\xA0",' ',$val));
            }
            if(!$cells)continue;$line=[];for($i=0;$i<=max(array_keys($cells));$i++)$line[$i]=$cells[$i]??'';
            if(implode('',$line)==='')continue;$rows[$r]=$line;
        }
        ksort($rows);
        return ['sheet'=>$sheet->getAttribute('name'),'rows'=>$rows];
    }

    /** تاریخ سلول: شمسی متنی (۱۴۰۵/۰۲/۱۱)، میلادی ISO یا شمارهٔ روز Excel. خروجی Y-m-d میلادی یا null. */
    public static function date($v){
        $v=trim((string)$v);if($v==='')return null;
        $v=Money::normDigits(preg_split('/[\sT]+/u',$v)[0]);
        if(preg_match('/^\d{1,6}(\.\d+)?$/D',$v)){$n=(int)$v;if($n<20000||$n>80000)return null;return (new \DateTimeImmutable('1899-12-30'))->modify('+'.$n.' days')->format('Y-m-d');}
        if(preg_match('/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/D',$v,$m)){
            if((int)$m[1]>=1700){$d=\DateTimeImmutable::createFromFormat('!Y-n-j',(int)$m[1].'-'.(int)$m[2].'-'.(int)$m[3]);return $d&&$d->format('Y-n-j')===(int)$m[1].'-'.(int)$m[2].'-'.(int)$m[3]?$d->format('Y-m-d'):null;}
            try{return Jalali::parse($m[1].'/'.$m[2].'/'.$m[3]);}catch(\Throwable $e){return null;}
        }
        return null;
    }
    /** مبلغ سلول به واحد داخلی (۱/۱۰۰ ریال) با واحد فایل؛ جداکنندهٔ هزارگان پذیرفته و نماد علمی رد می‌شود. null یعنی نامعتبر. */
    public static function amount($v,$unit){
        $v=str_replace([',','٬','،',' ',"\xE2\x80\x8C"],'',trim(Money::normDigits((string)$v)));if($v===''||$v==='-')return 0;
        $neg=false;if(preg_match('/^\((.*)\)$/D',$v,$m)){$v=$m[1];$neg=true;}if($v!==''&&$v[0]==='-'){$v=substr($v,1);$neg=!$neg;}
        if(!preg_match('/^\d+(\.\d+)?$/D',$v))return null;
        try{$n=Money::fromInput($v,$unit);}catch(\Throwable $e){return null;}
        return $neg?-$n:$n;
    }
}
