<?php
namespace Agah\Mobile;
if (!defined('ABSPATH')) exit;
final class Schema {
    public static function table($name) {
        if (!in_array($name,['sessions','operations','policies'],true)) throw new \LogicException('Unknown mobile table');
        global $wpdb;
        return $wpdb->prefix.'agmobile_'.$name;
    }
    public static function install() {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $collate=$wpdb->get_charset_collate();
        dbDelta('CREATE TABLE '.self::table('sessions')." (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            device_id char(64) NOT NULL,
            device_name varchar(100) NOT NULL,
            access_hash char(64) NOT NULL,
            refresh_hash char(64) NOT NULL,
            password_version char(64) NOT NULL,
            mfa_version bigint NOT NULL DEFAULT -1,
            mfa_verified tinyint NOT NULL DEFAULT 0,
            expires_at bigint unsigned NOT NULL,
            created_at bigint unsigned NOT NULL,
            last_seen bigint unsigned NOT NULL,
            revoked tinyint NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY access_hash (access_hash),
            UNIQUE KEY refresh_hash (refresh_hash),
            KEY user_device (user_id,device_id)
        ) ENGINE=InnoDB $collate;");
        dbDelta('CREATE TABLE '.self::table('policies')." (
            company_id bigint unsigned NOT NULL,
            user_id bigint unsigned NOT NULL DEFAULT 0,
            mode varchar(8) NOT NULL DEFAULT 'read',
            PRIMARY KEY  (company_id,user_id)
        ) ENGINE=InnoDB $collate;");
        dbDelta('CREATE TABLE '.self::table('operations')." (
            company_id bigint unsigned NOT NULL,
            user_id bigint unsigned NOT NULL,
            operation_hash char(64) NOT NULL,
            fingerprint char(64) NOT NULL,
            action varchar(80) NOT NULL,
            result_json longtext NOT NULL,
            created_at bigint unsigned NOT NULL,
            PRIMARY KEY  (company_id,user_id,operation_hash)
        ) ENGINE=InnoDB $collate;");
    }
}
