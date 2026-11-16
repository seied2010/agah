<?php
/** صفحه‌های «اتصال فروشگاه‌ها» و «سفارش‌های فروشگاه» در زنجیرهٔ کنترلر مشترک (PRG، nonce و توکن). */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{Ctx,Money,Request,ValidationException};
use Agah\Erp\Modules\Connect\ConnectService;
if(!defined('ABSPATH'))exit;
final class ConnectController {
    public static function nav(){return ['connect_clients'=>['اتصال فروشگاه‌ها','connect.view'],'connect_orders'=>['سفارش‌های فروشگاه','connect.view']]+Phase2Controller::nav();}
    public static function cap($p){return self::nav()[$p][1]??Phase2Controller::cap($p);}
    public static function actions(){return ['module_connect_create'=>'connect.edit','module_connect_update'=>'connect.edit','module_connect_rotate'=>'connect.edit','module_connect_revoke'=>'connect.edit','module_connect_delete'=>'connect.edit','module_connect_dispatch'=>'connect.edit','module_connect_close'=>'connect.edit','module_connect_receive'=>'connect.edit','module_connect_resolve'=>'connect.edit']+Phase2Controller::actions();}
    public static function view($p){return ['connect_clients'=>'connect-clients','connect_orders'=>'connect-orders'][$p]??Phase2Controller::view($p);}
    private static function input(){
        $in=[];foreach(['warehouse_id','treasury_account_id','shipping_item_id','reservation_ttl'] as $k)$in[$k]=Request::int('post',$k);
        $in['name']=Request::str('post','name',100);$in['auto_dispatch']=Request::flag('post','auto_dispatch');
        $raw=Request::str('post','party_threshold',30);$in['party_threshold']=$raw===''?0:Money::fromInput($raw,Ctx::unit());
        return $in;
    }
    /** راز تازه فقط یک بار و فقط برای همین کاربر نمایش داده می‌شود. */
    private static function remember(array $r){set_transient('agah_connect_secret_'.get_current_user_id(),\Agah\Erp\Core\Crypto::encrypt(wp_json_encode($r)),300);}
    public static function takeSecret(){$k='agah_connect_secret_'.get_current_user_id();$v=get_transient($k);delete_transient($k);if(!is_string($v))return null;$r=json_decode((string)\Agah\Erp\Core\Crypto::decrypt($v),true);return is_array($r)?$r:null;}
    public static function dispatch($act,$cid,$url){
        if(isset(Phase2Controller::actions()[$act]))return Phase2Controller::dispatch($act,$cid,$url);
        $id=Request::int('post','id');
        switch($act){
            case 'module_connect_create':self::remember(ConnectService::createClient($cid,self::input()));return $url;
            case 'module_connect_update':ConnectService::updateClient($cid,$id,self::input());return $url;
            case 'module_connect_rotate':self::remember(ConnectService::rotate($cid,$id));return $url;
            case 'module_connect_revoke':ConnectService::revoke($cid,$id);return $url;
            case 'module_connect_delete':ConnectService::deleteClient($cid,$id);return $url;
            case 'module_connect_dispatch':ConnectService::dispatch($cid,$id);return $url;
            case 'module_connect_close':ConnectService::closeOrder($cid,$id,Request::str('post','note',300));return $url;
            case 'module_connect_receive':ConnectService::receiveReturn($cid,$id,\Agah\Erp\Core\Jalali::parse(Request::str('post','doc_date',10)));return $url;
            case 'module_connect_resolve':ConnectService::resolveRefund($cid,$id,Request::str('post','note',300));return $url;
        }
        throw new ValidationException(['form'=>'عمل معتبر نیست.']);
    }
}
