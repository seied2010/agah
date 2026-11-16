<?php
/**
 * ساختار پایگاه داده (DDL) سامانهٔ حسابداری — نسخه‌دار و قابل ارتقا.
 *
 * اصول طراحی:
 *  ۱) همهٔ جدول‌ها InnoDB و utf8mb4 هستند (تراکنش و کلید خارجی لازم است). اگر تبدیل ممکن نباشد هشدار مدیریتی نمایش داده می‌شود.
 *  ۲) هر جدول مالی ستون company_id دارد و روابط بین‌جدولی با «کلید خارجی ترکیبی (company_id, id)» تعریف می‌شود؛
 *     یعنی حتی با خطای کد، پایگاه داده اجازه نمی‌دهد ردیف یک شرکت به ردیف شرکت دیگر وصل شود.
 *  ۳) مبالغ: BIGINT به ریال. تاریخ: DATE میلادی (نمایش شمسی در لایهٔ رابط).
 *  ۴) voucher_lines.debit/credit نامنفی هستند و توازن هنگام تأیید/قطعی‌کردن در سرویس بررسی می‌شود.
 *  ۵) dbDelta فقط ستون/ایندکس اضافه می‌کند؛ کلیدهای خارجی بعد از آن با addForeignKeys() و به‌صورت idempotent اضافه می‌شوند.
 *
 * نسخهٔ ۱ (alpha): هسته — شرکت/نقش/عضویت/تنظیمات/سال مالی/کدینگ/تفصیلی/سند/شمارنده/ممیزی.
 * جدول‌های اشخاص، کالا، خزانه، فاکتور و ... در نسخه‌های بعدی با افزایش VERSION اضافه می‌شوند (docs/DB-SCHEMA.md).
 */
namespace Agah\Erp\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Schema {

	const VERSION    = '21';
	const OPTION_VER = 'agah_erp_db_ver';

	public static function tables() {
		return array_merge(Phase2Schema::tables(),ConnectSchema::tables(),ManufacturingSchema::tables(),OperationsSchema::tables(),FinanceSchema::tables(),TradeSchema::tables(), BusinessSchema::tables(), array(
			'companies', 'roles', 'role_caps', 'company_members', 'settings', 'fiscal_years', 'fiscal_periods',
			'accounts', 'details', 'vouchers', 'voucher_lines', 'sequences', 'audit_log', 'idempotency', 'auth_states', 'rate_limits', 'login_otp', 'audit_heads', 'schema_migrations',
		));
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$p = $wpdb->prefix . 'agah_';
		$c = 'ENGINE=InnoDB ' . $wpdb->get_charset_collate();
		foreach ( self::ddl( $p, $c ) as $sql ) {
			dbDelta( $sql );
			if ( $wpdb->last_error ) { throw new \RuntimeException( 'Schema migration failed' ); }
		}
		self::ensureInnoDb();
		self::addForeignKeys();
		if(self::missingForeignKeys() || get_option('agah_erp_engine_error')) throw new \RuntimeException('Required database constraints unavailable');
		BusinessSchema::seed();
		TradeSchema::seed();
        OperationsSchema::seed();
        ReleaseSetup::seed();
        ManufacturingSchema::seed();
        ConnectSchema::seed();
        Phase2Schema::seed();
		PrecisionMigration::run();
		self::recordMigration();
		update_option( self::OPTION_VER, self::VERSION, false );
	}

	/** در init صدا زده می‌شود؛ فقط وقتی نسخه عوض شده باشد کار می‌کند. */
	/** قفل نام‌دار مانع اجرای هم‌زمان DDL می‌شود؛ نسخه فقط پس از موفقیت ثبت می‌شود. */
	public static function maybeUpgrade() {
		if ( get_option( self::OPTION_VER ) === self::VERSION ) return;
		$lock = 'agah_schema_' . substr( hash( 'sha256', Db::t('settings') ), 0, 32 );
		if ( 1 !== (int) Db::val( 'SELECT GET_LOCK(%s, 10)', $lock ) ) {
			throw new \RuntimeException('Schema upgrade unavailable');
		}
		try {
			wp_cache_delete( self::OPTION_VER, 'options' );
			if ( get_option( self::OPTION_VER ) !== self::VERSION ) self::install();
		} finally {
			Db::val( 'SELECT RELEASE_LOCK(%s)', $lock );
		}
	}

	/** مهاجرت این نسخه فقط افزایشی است؛ هیچ دادهٔ نسخهٔ ۱ حذف یا بازنویسی نمی‌شود. */
	private static function recordMigration() {
		Db::exec( 'INSERT IGNORE INTO ' . Db::t('schema_migrations') . ' (version, applied_at) VALUES (%s,%s)', self::VERSION, gmdate('Y-m-d H:i:s') );
	}

	private static function ensureInnoDb() {
		global $wpdb;
		$bad = array();
		foreach ( self::tables() as $t ) {
			$name   = Db::t( $t );
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $name ) );
			if ( $engine && 'InnoDB' !== $engine ) {
				$wpdb->query( "ALTER TABLE `$name` ENGINE=InnoDB" ); // phpcs:ignore
				$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $name ) );
				if ( 'InnoDB' !== $engine ) {
					$bad[] = $name;
					error_log( '[agah-erp] table is not InnoDB: ' . $name );
				}
			}
		}
		update_option( 'agah_erp_engine_error', $bad, false );
	}

	/** @return string[] */
	private static function ddl( $p, $c ) {
		$s = array();

		$s[] = "CREATE TABLE {$p}companies (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(36) NOT NULL,
  name varchar(190) NOT NULL,
  legal_name varchar(190) NOT NULL DEFAULT '',
  kind varchar(10) NOT NULL DEFAULT 'legal',
  national_id_enc varchar(400) NOT NULL DEFAULT '',
  economic_code varchar(30) NOT NULL DEFAULT '',
  registration_no varchar(30) NOT NULL DEFAULT '',
  phone varchar(40) NOT NULL DEFAULT '',
  address varchar(500) NOT NULL DEFAULT '',
  postal_code varchar(20) NOT NULL DEFAULT '',
  display_unit varchar(8) NOT NULL DEFAULT 'toman',
  status varchar(10) NOT NULL DEFAULT 'active',
  owner_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uuid (uuid),
  KEY owner_user_id (owner_user_id)
) $c;";

		$s[] = "CREATE TABLE {$p}roles (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  company_id bigint(20) unsigned NOT NULL,
  role_key varchar(40) NOT NULL,
  name varchar(100) NOT NULL,
  is_system tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY company_role (company_id,role_key),
  UNIQUE KEY company_id_id (company_id,id)
) $c;";

		$s[] = "CREATE TABLE {$p}role_caps (
  role_id bigint(20) unsigned NOT NULL,
  cap varchar(80) NOT NULL,
  PRIMARY KEY  (role_id,cap)
) $c;";

		$s[] = "CREATE TABLE {$p}company_members (
  company_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  role_id bigint(20) unsigned NOT NULL,
  status varchar(10) NOT NULL DEFAULT 'active',
  created_at datetime NOT NULL,
  PRIMARY KEY  (company_id,user_id),
  KEY user_id (user_id),
  KEY role_id (role_id)
) $c;";

		$s[] = "CREATE TABLE {$p}settings (
  company_id bigint(20) unsigned NOT NULL,
  skey varchar(80) NOT NULL,
  svalue longtext NOT NULL,
  PRIMARY KEY  (company_id,skey)
) $c;";

		$s[] = "CREATE TABLE {$p}fiscal_years (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  company_id bigint(20) unsigned NOT NULL,
  title varchar(100) NOT NULL,
  jy smallint(5) unsigned NOT NULL DEFAULT 0,
  start_date date NOT NULL,
  end_date date NOT NULL,
  status varchar(10) NOT NULL DEFAULT 'open',
  closing_voucher_id bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY company_start (company_id,start_date),
  UNIQUE KEY company_id_id (company_id,id)
) $c;";

		$s[] = "CREATE TABLE {$p}fiscal_periods (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  company_id bigint(20) unsigned NOT NULL,
  fiscal_year_id bigint(20) unsigned NOT NULL,
  period_no tinyint(3) unsigned NOT NULL,
  name varchar(40) NOT NULL,
  start_date date NOT NULL,
  end_date date NOT NULL,
  status varchar(10) NOT NULL DEFAULT 'open',
  PRIMARY KEY  (id),
  UNIQUE KEY fy_no (fiscal_year_id,period_no),
  KEY company_dates (company_id,start_date,end_date)
) $c;";

		$s[] = "CREATE TABLE {$p}accounts (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  company_id bigint(20) unsigned NOT NULL,
  parent_id bigint(20) unsigned DEFAULT NULL,
  level tinyint(3) unsigned NOT NULL,
  code varchar(20) NOT NULL,
  name varchar(190) NOT NULL,
  nature varchar(8) NOT NULL DEFAULT 'debit',
  stmt_class varchar(24) NOT NULL DEFAULT '',
  sys_key varchar(40) DEFAULT NULL,
  detail_rule varchar(10) NOT NULL DEFAULT 'none',
  detail_kind varchar(20) NOT NULL DEFAULT '',
  is_active tinyint(1) NOT NULL DEFAULT 1,
  is_system tinyint(1) NOT NULL DEFAULT 0,
  ratio_tag varchar(30) DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY company_code (company_id,code),
  UNIQUE KEY company_syskey (company_id,sys_key),
  UNIQUE KEY company_id_id (company_id,id),
  KEY parent_id (parent_id)
) $c;";

		$s[] = "CREATE TABLE {$p}details (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  company_id bigint(20) unsigned NOT NULL,
  code varchar(20) NOT NULL,
  name varchar(190) NOT NULL,
  kind varchar(20) NOT NULL DEFAULT 'other',
  ref_type varchar(20) NOT NULL DEFAULT '',
  ref_id bigint(20) unsigned NOT NULL DEFAULT 0,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY company_code (company_id,code),
  UNIQUE KEY company_id_id (company_id,id),
  KEY company_kind (company_id,kind)
) $c;";

		$s[] = "CREATE TABLE {$p}vouchers (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(36) NOT NULL,
  company_id bigint(20) unsigned NOT NULL,
  fiscal_year_id bigint(20) unsigned NOT NULL,
  number int(10) unsigned DEFAULT NULL,
  voucher_date date NOT NULL,
  type varchar(12) NOT NULL DEFAULT 'normal',
  description varchar(500) NOT NULL DEFAULT '',
  status varchar(10) NOT NULL DEFAULT 'draft',
  source_type varchar(24) NOT NULL DEFAULT '',
  source_id bigint(20) unsigned NOT NULL DEFAULT 0,
  total_debit bigint(20) unsigned NOT NULL DEFAULT 0,
  total_credit bigint(20) unsigned NOT NULL DEFAULT 0,
  revision bigint(20) unsigned NOT NULL DEFAULT 1,
  status_reason varchar(300) NOT NULL DEFAULT '',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
  approved_at datetime DEFAULT NULL,
  finalized_by bigint(20) unsigned NOT NULL DEFAULT 0,
  finalized_at datetime DEFAULT NULL,
  voided_by bigint(20) unsigned NOT NULL DEFAULT 0,
  voided_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uuid (uuid),
  UNIQUE KEY fy_number (company_id,fiscal_year_id,number),
  UNIQUE KEY company_id_id (company_id,id),
  KEY company_date (company_id,voucher_date),
  KEY company_source (company_id,source_type,source_id),
  KEY company_status (company_id,status),
  KEY fiscal_year_id (fiscal_year_id)
) $c;";

		$s[] = "CREATE TABLE {$p}voucher_lines (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  company_id bigint(20) unsigned NOT NULL,
  voucher_id bigint(20) unsigned NOT NULL,
  line_no smallint(5) unsigned NOT NULL,
  account_id bigint(20) unsigned NOT NULL,
  detail_id bigint(20) unsigned DEFAULT NULL,
  description varchar(500) NOT NULL DEFAULT '',
  debit bigint(20) unsigned NOT NULL DEFAULT 0,
  credit bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY voucher_id (voucher_id),
  KEY company_account (company_id,account_id,detail_id),
  KEY company_detail (company_id,detail_id),
  UNIQUE KEY company_id_id (company_id,id)
) $c;";

		$s[] = "CREATE TABLE {$p}sequences (
  company_id bigint(20) unsigned NOT NULL,
  fiscal_year_id bigint(20) unsigned NOT NULL DEFAULT 0,
  kind varchar(24) NOT NULL,
  last_no int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (company_id,fiscal_year_id,kind)
) $c;";

		$s[] = "CREATE TABLE {$p}audit_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  company_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  event varchar(60) NOT NULL,
  entity_type varchar(30) NOT NULL DEFAULT '',
  entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
  old_json longtext,
  new_json longtext,
  ip varchar(45) NOT NULL DEFAULT '',
  ua varchar(200) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  prev_hash char(64) NOT NULL DEFAULT '',
  row_hash char(64) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY company_time (company_id,created_at),
  KEY company_entity (company_id,entity_type,entity_id),
  KEY company_event (company_id,event),
  UNIQUE KEY company_id_id (company_id,id)
) $c;";

		$s[] = "CREATE TABLE {$p}idempotency (
  token char(64) NOT NULL,
  company_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL,
  action varchar(40) NOT NULL,
  request_hash char(64) NOT NULL,
  state varchar(10) NOT NULL DEFAULT 'pending',
  result_url text,
  expires_at bigint(20) NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (token),
  KEY company_user (company_id,user_id),
  KEY expires_at (expires_at)
) $c;";

		$s[] = "CREATE TABLE {$p}auth_states (
  user_id bigint(20) unsigned NOT NULL,
  secret_enc text,
  pending_enc text,
  pending_session char(64) NOT NULL DEFAULT '',
  pending_until bigint(20) NOT NULL DEFAULT 0,
  last_step bigint(20) NOT NULL DEFAULT -1,
  backup_hashes text,
  version bigint(20) unsigned NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (user_id)
) $c;";

		$s[] = "CREATE TABLE {$p}rate_limits (
  bucket char(64) NOT NULL,
  hits int(10) unsigned NOT NULL DEFAULT 0,
  expires_at bigint(20) NOT NULL,
  PRIMARY KEY  (bucket),
  KEY expires_at (expires_at)
) $c;";

		$s[] = "CREATE TABLE {$p}login_otp (
  user_id bigint(20) unsigned NOT NULL,
  session_hash char(64) NOT NULL,
  code_hash char(64) NOT NULL,
  expires_at bigint(20) NOT NULL,
  attempts int(10) unsigned NOT NULL DEFAULT 0,
  consumed tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (user_id,session_hash),
  KEY expires_at (expires_at)
) $c;";

		$s[] = "CREATE TABLE {$p}audit_heads (
  company_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (company_id)
) $c;";

		$s[] = "CREATE TABLE {$p}schema_migrations (
  version varchar(20) NOT NULL,
  applied_at datetime NOT NULL,
  PRIMARY KEY  (version)
) $c;";

		return array_merge($s,BusinessSchema::ddl($p,$c),TradeSchema::ddl($p,$c),FinanceSchema::ddl($p,$c),OperationsSchema::ddl($p,$c),ManufacturingSchema::ddl($p,$c),ConnectSchema::ddl($p,$c),Phase2Schema::ddl($p,$c));
	}

	/** [جدول فرزند, نام, ستون‌ها, جدول والد, ستون‌های والد, رفتار حذف]. کلیدهای ترکیبی تضمین می‌کنند فرزند و والد هم‌شرکت‌اند. */
	public static function foreignKeyDefinitions() { return self::fks(); }

	private static function fks() {
		$R = 'RESTRICT';
		$C = 'CASCADE';
		return array_merge(Phase2Schema::fks(),ConnectSchema::fks(),ManufacturingSchema::fks(),OperationsSchema::fks(),FinanceSchema::fks(),TradeSchema::fks(),BusinessSchema::fks(), array(
			array( 'roles', 'fk_roles_company', array( 'company_id' ), 'companies', array( 'id' ), $R ),
			array( 'role_caps', 'fk_rc_role', array( 'role_id' ), 'roles', array( 'id' ), $C ),
			array( 'company_members', 'fk_cm_company', array( 'company_id' ), 'companies', array( 'id' ), $R ),
			array( 'company_members', 'fk_cm_role', array( 'company_id', 'role_id' ), 'roles', array( 'company_id', 'id' ), $R ),
			array( 'fiscal_years', 'fk_fy_company', array( 'company_id' ), 'companies', array( 'id' ), $R ),
			array( 'fiscal_periods', 'fk_fp_fy', array( 'company_id', 'fiscal_year_id' ), 'fiscal_years', array( 'company_id', 'id' ), $R ),
			array( 'accounts', 'fk_acc_company', array( 'company_id' ), 'companies', array( 'id' ), $R ),
			array( 'accounts', 'fk_acc_parent', array( 'company_id', 'parent_id' ), 'accounts', array( 'company_id', 'id' ), $R ),
			array( 'details', 'fk_det_company', array( 'company_id' ), 'companies', array( 'id' ), $R ),
			array( 'vouchers', 'fk_v_fy', array( 'company_id', 'fiscal_year_id' ), 'fiscal_years', array( 'company_id', 'id' ), $R ),
			array( 'voucher_lines', 'fk_vl_voucher', array( 'company_id', 'voucher_id' ), 'vouchers', array( 'company_id', 'id' ), $C ),
			array( 'voucher_lines', 'fk_vl_account', array( 'company_id', 'account_id' ), 'accounts', array( 'company_id', 'id' ), $R ),
			array( 'voucher_lines', 'fk_vl_detail', array( 'company_id', 'detail_id' ), 'details', array( 'company_id', 'id' ), $R ),
		));
	}

	private static function addForeignKeys() {
		global $wpdb;
		$missing = array();
		foreach ( self::fks() as $fk ) {
			list( $table, $name, $cols, $ref, $refCols, $onDelete ) = $fk;
			$exists = $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_NAME = %s AND CONSTRAINT_TYPE = %s',
				Db::t( $table ), $name, 'FOREIGN KEY'
			) );
			if ( (int) $exists ) {
				continue;
			}
			$sql = sprintf(
				'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`) ON DELETE %s ON UPDATE RESTRICT',
				Db::t( $table ), $name, implode( '`,`', $cols ), Db::t( $ref ), implode( '`,`', $refCols ), $onDelete
			);
			$wpdb->last_error = '';
			$wpdb->query( $sql ); // phpcs:ignore
			if ( '' !== $wpdb->last_error ) {
				$missing[] = $name;
				error_log( '[agah-erp] FK failed ' . $name . ': ' . $wpdb->last_error );
				$wpdb->last_error = '';
			}
		}
		update_option( 'agah_erp_fk_missing', $missing, false );
	}

	public static function missingForeignKeys() {
		return (array) get_option( 'agah_erp_fk_missing', array() );
	}
}
