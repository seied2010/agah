<?php
namespace Agah\Mobile;
use Agah\Erp\Core\{Db,Audit};
if (!defined('ABSPATH')) exit;
final class Integration {
    public static function register() {
        add_action('agah_erp_company_restored',[__CLASS__,'company'],10,2);
        add_action('agah_erp_all_restored',[__CLASS__,'all']);
        add_action('agah_erp_daily',[__CLASS__,'cleanup']);
    }
    public static function cleanup() {
        // Receipt tombstones are intentionally durable; only obsolete session credentials are removed.
        Db::exec('DELETE FROM '.Schema::table('sessions').' WHERE expires_at<%d',time()-604800);
    }
    /** Existing operation hashes become durable tombstones; delayed requests cannot replay them. */
    public static function company($cid,$uid) {
        if (!Db::inTx()) throw new \LogicException('Restore integration requires the restore transaction');
        Db::exec('UPDATE '.Schema::table('sessions').' SET revoked=1 WHERE user_id IN (SELECT user_id FROM '.Db::t('company_members').' WHERE company_id=%d) OR user_id IN (SELECT user_id FROM '.Schema::table('operations').' WHERE company_id=%d)',$cid,$cid);
        Db::exec('UPDATE '.Schema::table('operations').' SET action=%s WHERE company_id=%d','restored',$cid);
        Db::exec('UPDATE '.Schema::table('policies')." SET mode=IF(mode='off','off','read') WHERE company_id=%d",$cid);
        Audit::log('mobile.restore.invalidated','company',$cid,null,['policy'=>'writes_disabled','sessions'=>'revoked','receipts'=>'restored'],$cid,$uid);
    }
    public static function all() {
        if (!Db::inTx()) throw new \LogicException('Restore integration requires the restore transaction');
        Db::exec('UPDATE '.Schema::table('sessions').' SET revoked=1');
        Db::exec('UPDATE '.Schema::table('operations').' SET action=%s','restored');
        Db::exec('UPDATE '.Schema::table('policies')." SET mode=IF(mode='off','off','read')");
    }
}
