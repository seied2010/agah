<?php
/**
 * REST خصوصی اتصال فروشگاه‌ها: /wp-json/agah-connect/v1/...
 * هر درخواست امضای HMAC دارد؛ درخواست‌های نوشتنی سرآیند Idempotency-Key می‌خواهند.
 * پاسخ همیشه {ok,data} یا {ok:false,error:{code,message,details}} است و هرگز متن SQL یا راز در آن نیست.
 */
namespace Agah\Erp\Http;
use Agah\Erp\Core\{AppException,Db,ValidationException};
use Agah\Erp\Modules\Connect\{ApiError,ConnectService};
use Agah\Erp\Security\Auth;
if(!defined('ABSPATH'))exit;
final class ConnectApi {
    const NS='agah-connect/v1';
    public static function register(){add_action('rest_api_init',[__CLASS__,'routes']);}
    public static function routes(){
        $routes=[
            ['GET','/health','health',false],['GET','/catalog','catalog',false],['GET','/stock','stock',false],
            ['POST','/reservations','reserve',true],['GET','/reservations/(?P<id>[0-9a-f-]{36})','reservation',false],['POST','/reservations/(?P<id>[0-9a-f-]{36})/release','release',true],
            ['POST','/orders/paid','paid',true],['POST','/orders/cancel','cancel',true],['POST','/orders/refund','refund',true],['GET','/orders','orders',false],['GET','/orders/status','order',false],
        ];
        foreach($routes as [$method,$route,$fn,$write])register_rest_route(self::NS,$route,['methods'=>$method,'permission_callback'=>'__return_true','callback'=>function(\WP_REST_Request $r)use($fn,$write){return self::handle($r,$fn,$write);}]);
    }
    private static function respond($status,array $body){$r=new \WP_REST_Response($body,$status);$r->header('Cache-Control','no-store');$r->header('X-Content-Type-Options','nosniff');return $r;}
    private static function error($status,$code,$message,array $details=[]){return self::respond($status,['ok'=>false,'error'=>['code'=>$code,'message'=>$message,'details'=>$details?:new \stdClass()]]);}
    public static function handle(\WP_REST_Request $r,$fn,$write){
        try{
            if(!is_ssl()&&!in_array(wp_get_environment_type(),['local','development'],true))return self::error(403,'https_required','اتصال فقط با HTTPS پذیرفته می‌شود.');
            $body=(string)$r->get_body();if(strlen($body)>262144)return self::error(413,'too_large','حجم درخواست بیش از حد مجاز است.');
            $client=ConnectService::authenticate($r->get_header('x_agah_key'),$r->get_header('x_agah_timestamp'),$r->get_header('x_agah_nonce'),$r->get_header('x_agah_signature'),$r->get_method(),$r->get_route(),$r->get_query_params(),$body);
            ConnectService::boot($client);
            if($write){
                $in=json_decode($body,true);if(!is_array($in))return self::error(400,'bad_json','بدنهٔ درخواست باید JSON معتبر باشد.');
                $data=ConnectService::idempotent($client,$r->get_header('idempotency_key'),$r->get_route(),$body,function()use($fn,$client,$in,$r){return $fn==='release'?ConnectService::release($client,$in,$r->get_url_params()):ConnectService::$fn($client,$in);});
            }else $data=$fn==='health'?ConnectService::health($client):($fn==='reservation'?ConnectService::reservation($client,$r->get_query_params(),$r->get_url_params()):ConnectService::$fn($client,$r->get_query_params()));
            return self::respond(200,['ok'=>true,'data'=>$data]);
        }catch(ApiError $e){return self::error($e->status,$e->code_name,$e->getMessage(),$e->details);}
        catch(ValidationException $e){return self::error(422,'validation',$e->getMessage(),$e->errors);}
        catch(AppException $e){return self::error($e->status,['403'=>'forbidden','404'=>'not_found','409'=>'conflict'][(string)$e->status]??'rejected',$e->getMessage());}
        catch(\Throwable $e){$ref=substr(hash('sha256',uniqid('',true)),0,12);error_log('[agah-connect '.$ref.'] '.get_class($e).': '.$e->getMessage());return self::error(500,'internal','خطای داخلی؛ کد پیگیری: '.$ref);}
        finally{Auth::$machine=0;}
    }
}
