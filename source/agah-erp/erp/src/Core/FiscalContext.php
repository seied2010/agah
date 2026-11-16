<?php
namespace Agah\Erp\Core;
use Agah\Erp\Modules\Accounting\FiscalService;
if(!defined('ABSPATH'))exit;
final class FiscalContext {
    public static function catalog(){
        $raw=get_option('agah_fiscal_catalog',range(1400,1420));
        $years=array_values(array_unique(array_filter(array_map('intval',(array)$raw),function($y){return $y>=1300&&$y<=1600;})));sort($years);return $years;
    }
    public static function selected($cid){
        $id=(int)get_user_meta(get_current_user_id(),'agah_fy_'.(int)$cid,true);
        if($id){$r=Db::row('SELECT * FROM '.Db::t('fiscal_years').' WHERE company_id=%d AND id=%d',$cid,$id);if($r)return $r;}
        return FiscalService::forDate($cid,Jalali::today())?:Db::row('SELECT * FROM '.Db::t('fiscal_years').' WHERE company_id=%d ORDER BY start_date DESC LIMIT 1',$cid);
    }
    public static function select($cid,$jy){
        Access::need($cid,'fiscal_year.view');$jy=(int)$jy;
        $r=Db::row('SELECT * FROM '.Db::t('fiscal_years').' WHERE company_id=%d AND jy=%d',$cid,$jy);
        if(!$r){
            if(!in_array($jy,self::catalog(),true))throw new ValidationException(['jy'=>'سال در فهرست مجاز مدیریت نیست.']);
            $id=Access::write($cid,'fiscal_year.create',function()use($cid,$jy){$id=Db::val('SELECT id FROM '.Db::t('fiscal_years').' WHERE company_id=%d AND jy=%d',$cid,$jy);return $id?:FiscalService::createYear($cid,$jy);});
        }else $id=$r['id'];
        update_user_meta(get_current_user_id(),'agah_fy_'.(int)$cid,(int)$id);
    }
    public static function range($cid,array $f){
        $year=self::selected($cid);
        if($year){$f['fiscal_year_id']=(int)$year['id'];if(empty($f['from']))$f['from']=$year['start_date'];if(empty($f['to']))$f['to']=$year['end_date'];}
        return $f;
    }
    public static function today($cid){$fy=self::selected($cid);$today=Jalali::today();return $fy?max($fy['start_date'],min($today,$fy['end_date'])):$today;}
    public static function render($cid){
        if(!Ctx::can('fiscal_year.view'))return;
        $selected=self::selected($cid);$years=[];foreach(self::catalog() as $y)if(Ctx::can('fiscal_year.create'))$years[$y]=$y;
        foreach(FiscalService::all($cid) as $r)$years[(int)$r['jy']]=$r['jy'];ksort($years);
        echo '<form method="post" class="erp-filter erp-no-print erp-year-switch">';\Agah\Erp\Http\ModuleController::form('module_year_select',$cid,'dashboard');
        \Agah\Erp\Http\ModuleController::select('jy','سال مالی',$years,$selected['jy']??Jalali::currentYear());
        echo '<button type="submit" class="btn btn-outline">انتخاب سال مالی</button><small>شرکت یک بار تعریف می‌شود؛ اطلاعات هر سال مستقل است.</small></form>';
    }
}
