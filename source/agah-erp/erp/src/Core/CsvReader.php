<?php
/**
 * خواندن CSV نرم‌افزارهای دیگر (IMP-01): تشخیص رمزگذاری و جداکننده، سپس ردیف‌ها با شمارهٔ ردیف فایل.
 * رمزگذاری‌ها: UTF-8 (با/بی BOM)، UTF-16 با BOM، و Windows-1256 (خروجی رایج نرم‌افزارهای فارسی قدیمی).
 * فایل باینری، رمزگذاری‌شده یا قالب اختصاصی خوانده نمی‌شود (IMP-04)؛ فقط متن قابل خواندن.
 */
namespace Agah\Erp\Core;
if(!defined('ABSPATH'))exit;
final class CsvReader {
    const MAX_FILE=5242880;
    const MAX_ROWS=5000;
    const MAX_COLS=60;

    /** @return array{encoding:string,text:string} */
    public static function decode($bytes){
        $bytes=(string)$bytes;
        if(strncmp($bytes,"\xEF\xBB\xBF",3)===0)return ['encoding'=>'utf-8-bom','text'=>substr($bytes,3)];
        foreach(["\xFF\xFE"=>'UTF-16LE',"\xFE\xFF"=>'UTF-16BE'] as $bom=>$enc)if(strncmp($bytes,$bom,2)===0){$t=function_exists('mb_convert_encoding')?mb_convert_encoding(substr($bytes,2),'UTF-8',$enc):false;if(!is_string($t)||!mb_check_encoding($t,'UTF-8'))throw new ValidationException(['file'=>'فایل UTF-16 قابل خواندن نیست.']);return ['encoding'=>strtolower($enc),'text'=>$t];}
        if(strpos($bytes,"\0")!==false)throw new ValidationException(['file'=>'فایل متنی قابل خواندن نیست (باینری یا قالب اختصاصی). از نرم‌افزار مبدأ خروجی CSV یا Excel بگیرید.']);
        if(mb_check_encoding($bytes,'UTF-8'))return ['encoding'=>'utf-8','text'=>$bytes];
        $t=function_exists('iconv')?@iconv('CP1256','UTF-8//IGNORE',$bytes):false;
        if(!is_string($t)||$t==='')throw new ValidationException(['file'=>'رمزگذاری فایل شناخته نشد؛ فایل را با رمزگذاری UTF-8 ذخیره کنید.']);
        return ['encoding'=>'windows-1256','text'=>$t];
    }
    /** جداکننده‌ای که در سطر نخست بیشترین ستون یکسان را می‌دهد. */
    public static function delimiter($text){
        $first=strtok(str_replace("\r\n","\n",$text),"\n");$best=',';$score=0;
        foreach([',',';',"\t",'|'] as $d){$n=count(str_getcsv((string)$first,$d,'"','\\'));if($n>$score){$score=$n;$best=$d;}}
        return $best;
    }
    /** @return array{rows:array<int,array<int,string>>,encoding:string,delimiter:string} */
    public static function read($bytes){
        if(strlen((string)$bytes)>self::MAX_FILE)throw new ValidationException(['file'=>'حجم فایل بیش از ۵ مگابایت است.']);
        $d=self::decode($bytes);$text=str_replace(["\r\n","\r"],"\n",$d['text']);$delim=self::delimiter($text);
        $h=fopen('php://temp','w+');fwrite($h,$text);rewind($h);$rows=[];$no=0;
        try{
            while(($cells=fgetcsv($h,0,$delim,'"','\\'))!==false){
                $no++;if($cells===[null])continue;
                if(count($cells)>self::MAX_COLS)throw new ValidationException(['file'=>'ردیف '.$no.' بیش از '.self::MAX_COLS.' ستون دارد.']);
                $cells=array_map(fn($c)=>trim(str_replace("\u{FEFF}",'',(string)$c)),$cells);
                if(implode('',$cells)==='')continue;
                $rows[$no]=$cells;if(count($rows)>self::MAX_ROWS+1)throw new ValidationException(['file'=>'فایل بیش از '.self::MAX_ROWS.' ردیف دارد؛ آن را چند بخش کنید.']);
            }
        }finally{fclose($h);}
        return ['rows'=>$rows,'encoding'=>$d['encoding'],'delimiter'=>$delim];
    }
}
