<?php

/**
 * پشتیبان قابل‌دریافت شرکت‌ها.
 *
 * فایل خروجی با گذرواژهٔ انتخابی کاربر و secretbox رمز می‌شود، مسیر عمومی وب ندارد
 * و رکوردهای باینری مانند پیوست‌ها را نیز عیناً نگه می‌دارد.
 * بازیابی شرکت با کنترل ساختار، مالکیت ردیف‌ها و رابطه‌های پایگاه داده انجام می‌شود.
 */
namespace Agah\Erp\Modules\Maintenance;

use Agah\Erp\Core\{Audit,Crypto,Ctx,Db,Schema,Settings,ValidationException,ConflictException,NotFoundException};

if ( ! defined( 'ABSPATH' ) ) exit;

final class PortableBackupService {
    const MAGIC = "AGAH-PORTABLE-1\n";
    const FORMAT = 1;
    const EXT = 'agahbackup';
    private const EXCLUDED = array( 'auth_states', 'rate_limits', 'login_otp', 'idempotency' );

    private static function limit() {
        return defined( 'AGAH_PORTABLE_BACKUP_MAX_BYTES' )
            ? max( 1048576, min( 268435456, (int) AGAH_PORTABLE_BACKUP_MAX_BYTES ) )
            : 67108864;
    }

    private static function memoryBudget() {
        $v = trim( (string) ini_get( 'memory_limit' ) );
        if ( '' === $v || '-1' === $v ) return 0;
        $n = (int) $v;
        $suffix = strtolower( substr( $v, -1 ) );
        if ( 'g' === $suffix ) $n *= 1073741824;
        elseif ( 'm' === $suffix ) $n *= 1048576;
        elseif ( 'k' === $suffix ) $n *= 1024;
        return $n;
    }

    private static function password( $password ) {
        if ( ! is_string( $password ) || strlen( $password ) < 12 || strlen( $password ) > 200 ) {
            throw new ValidationException( array( 'backup_password' => 'گذرواژهٔ فایل باید بین ۱۲ تا ۲۰۰ نویسه باشد.' ) );
        }
        return $password;
    }

    private static function key( $password, $salt ) {
        self::password( $password );
        try {
            return sodium_crypto_pwhash(
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
                $password,
                $salt,
                SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
                SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
                SODIUM_CRYPTO_PWHASH_ALG_DEFAULT
            );
        } catch ( \Throwable $e ) {
            throw new ValidationException( array( 'backup' => 'حافظهٔ لازم برای رمزگذاری امن فایل در دسترس نیست.' ) );
        }
    }

    /** اطلاعات ستون و کلید اصلی فقط برای جدول‌های همین نسخه. */
    private static function metadata() {
        global $wpdb;
        $actual = Db::col( 'SHOW TABLES LIKE %s', $wpdb->esc_like( Db::t( '' ) ) . '%' );
        $expected = array_map( array( Db::class, 't' ), Schema::tables() );
        sort( $actual );
        sort( $expected );
        if ( $actual !== $expected ) {
            throw new ValidationException( array( 'backup' => 'ساختار پایگاه داده با نسخهٔ افزونه تطبیق ندارد؛ ابتدا سلامت سامانه را بررسی کنید.' ) );
        }
        $out = array();
        foreach ( Schema::tables() as $table ) {
            $columns = Db::rows( 'SHOW COLUMNS FROM ' . Db::t( $table ) );
            $out[ $table ] = array(
                'columns' => array_column( $columns, 'Field' ),
                'primary' => array(),
            );
            foreach ( Db::rows( 'SHOW INDEX FROM ' . Db::t( $table ) . " WHERE Key_name='PRIMARY'" ) as $row ) {
                $out[ $table ]['primary'][] = $row['Column_name'];
            }
            if ( ! $out[ $table ]['primary'] ) {
                throw new ValidationException( array( 'backup' => 'جدول بدون کلید اصلی قابل پشتیبان‌گیری امن نیست.' ) );
            }
        }
        return $out;
    }

    private static function selected( $table, array $meta, $scope ) {
        if ( in_array( $table, self::EXCLUDED, true ) ) return false;
        if ( 'all' === $scope ) return true;
        return 'companies' === $table || 'role_caps' === $table || in_array( 'company_id', $meta['columns'], true );
    }

    private static function query( $table, array $meta, $scope, $cid, $limit, $offset ) {
        $order = implode( ',', array_map( function( $column ) { return '`' . $column . '`'; }, $meta['primary'] ) );
        $physical = Db::t( $table );
        if ( 'all' === $scope ) {
            return Db::rows( 'SELECT * FROM ' . $physical . ' ORDER BY ' . $order . ' LIMIT %d OFFSET %d', $limit, $offset );
        }
        if ( 'companies' === $table ) {
            return Db::rows( 'SELECT * FROM ' . $physical . ' WHERE id=%d ORDER BY ' . $order . ' LIMIT %d OFFSET %d', $cid, $limit, $offset );
        }
        if ( 'role_caps' === $table ) {
            return Db::rows( 'SELECT rc.* FROM ' . $physical . ' rc JOIN ' . Db::t( 'roles' ) . ' r ON r.id=rc.role_id WHERE r.company_id=%d ORDER BY rc.role_id,rc.cap LIMIT %d OFFSET %d', $cid, $limit, $offset );
        }
        if ( in_array( 'company_id', $meta['columns'], true ) ) {
            return Db::rows( 'SELECT * FROM ' . $physical . ' WHERE company_id=%d ORDER BY ' . $order . ' LIMIT %d OFFSET %d', $cid, $limit, $offset );
        }
        return array();
    }

    private static function capture( $scope, $cid ) {
        return Db::tx( function() use ( $scope, $cid ) {
            if ( 'company' === $scope ) {
                $company = Db::row( 'SELECT id,uuid,name,status FROM ' . Db::t( 'companies' ) . ' WHERE id=%d FOR UPDATE', $cid );
                if ( ! $company ) throw new NotFoundException();
                $companies = array( $company );
            } else {
                $companies = Db::rows( 'SELECT id,uuid,name,status FROM ' . Db::t( 'companies' ) . ' ORDER BY id FOR UPDATE' );
                if ( ! $companies ) throw new ConflictException( 'شرکتی برای پشتیبان‌گیری وجود ندارد.' );
            }

            $metadata = self::metadata();
            $tables = array();
            $counts = array();
            $size = 0;
            foreach ( $metadata as $table => $meta ) {
                if ( ! self::selected( $table, $meta, $scope ) ) continue;
                $rows = array();
                $step = in_array( $table, array( 'files', 'expense_images', 'company_archive_chunks' ), true ) ? 1 : 100;
                $offset = 0;
                do {
                    $batch = self::query( $table, $meta, $scope, $cid, $step, $offset );
                    foreach ( $batch as $row ) {
                        $encoded = array();
                        foreach ( $meta['columns'] as $column ) {
                            $value = $row[ $column ];
                            $encoded[] = null === $value ? null : base64_encode( (string) $value );
                            $size += null === $value ? 4 : strlen( (string) $value ) * 2 + 8;
                        }
                        $budget = self::memoryBudget();
                        if ( $size > self::limit() || ( $budget > 0 && memory_get_usage( true ) + $size * 4 > $budget - 16777216 ) ) {
                            throw new ValidationException( array( 'backup' => 'حجم اطلاعات از سقف پشتیبان قابل‌دریافت بیشتر است؛ پشتیبان سرور لازم است.' ) );
                        }
                        $rows[] = $encoded;
                    }
                    $offset += $step;
                } while ( count( $batch ) === $step );
                $tables[ $table ] = array( 'columns' => $meta['columns'], 'rows' => $rows );
                $counts[ $table ] = count( $rows );
            }

            $users = array();
            global $wpdb;
            $userSql = 'SELECT ID,user_login FROM ' . $wpdb->users;
            if($scope==='company')$userSql.=' WHERE ID IN (SELECT user_id FROM '.Db::t('company_members').' WHERE company_id='.(int)$cid.')';
            foreach ( Db::rows( $userSql . ' ORDER BY ID' ) as $user ) {
                $users[ (string) $user['ID'] ] = hash( 'sha256', $user['user_login'] );
            }
            return array(
                'format' => self::FORMAT,
                'schema' => Schema::VERSION,
                'plugin' => defined( 'AGAH_ERP_VERSION' ) ? AGAH_ERP_VERSION : '',
                'scope' => $scope,
                'created_at' => gmdate( 'c' ),
                'source' => hash( 'sha256', untrailingslashit( home_url() ) ),
                'crypto_probe' => Crypto::encrypt( 'agah-portable-backup-key-check' ),
                'companies' => $companies,
                'users' => $users,
                'counts' => $counts,
                'tables' => $tables,
            );
        } );
    }

    private static function seal( array $data, $password ) {
        $json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $json ) || strlen( $json ) > self::limit() * 2 ) {
            throw new ValidationException( array( 'backup' => 'ساخت فایل پشتیبان کامل نشد.' ) );
        }
        $compressed = gzcompress( $json, 6 );
        if ( false === $compressed ) throw new ConflictException( 'فشرده‌سازی فایل پشتیبان در این سرور در دسترس نیست.' );
        $salt = random_bytes( SODIUM_CRYPTO_PWHASH_SALTBYTES );
        $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $key = self::key( $password, $salt );
        $cipher = sodium_crypto_secretbox( $compressed, $nonce, $key );
        sodium_memzero( $key );
        return self::MAGIC . $salt . $nonce . $cipher;
    }

    /** @return array{name:string,bytes:string,sha256:string,counts:array,scope:string,companies:array} */
    public static function create( $scope, $cid, $password ) {
        if ( ! in_array( $scope, array( 'company', 'all' ), true ) ) throw new ValidationException( array( 'scope' => 'دامنهٔ پشتیبان معتبر نیست.' ) );
        if ( 'company' === $scope && (int) $cid < 1 ) throw new ValidationException( array( 'scope' => 'شرکت مشخص نشده است.' ) );
        if ( BackupService::blocked() ) throw new ConflictException( 'بازیابی سامانه در حال اجراست.' );
        self::password( $password );
        $data = self::capture( $scope, (int) $cid );
        $bytes = self::seal( $data, $password );
        if ( strlen( $bytes ) > self::limit() ) throw new ValidationException( array( 'backup' => 'فایل نهایی از سقف مجاز بزرگ‌تر است.' ) );
        $sha = hash( 'sha256', $bytes );
        $rows = array_sum( $data['counts'] );
        $auditCid = 'company' === $scope ? (int) $cid : 0;
        Audit::log( 'backup.export', 'backup', 0, null, array( 'scope' => $scope, 'sha256' => $sha, 'tables' => count( $data['tables'] ), 'rows' => $rows ), $auditCid );
        $stamp = gmdate( 'Ymd-His' );
        $name = 'company' === $scope ? 'agah-company-' . (int) $cid . '-' . $stamp : 'agah-all-companies-' . $stamp;
        return array( 'name' => $name . '.' . self::EXT, 'bytes' => $bytes, 'sha256' => $sha, 'counts' => $data['counts'], 'scope' => $scope, 'companies' => $data['companies'] );
    }

    /** رمزگشایی و کنترل کامل ساختار؛ خروجی این متد فقط در حافظه است. */
    private static function decodeBytes( $bytes, $password ) {
        if ( ! is_string( $bytes ) || strlen( $bytes ) > self::limit() || strlen( $bytes ) < strlen( self::MAGIC ) + SODIUM_CRYPTO_PWHASH_SALTBYTES + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
            throw new ValidationException( array( 'backup' => 'اندازه یا قالب فایل پشتیبان معتبر نیست.' ) );
        }
        $head = strlen( self::MAGIC );
        if ( substr( $bytes, 0, $head ) !== self::MAGIC ) throw new ValidationException( array( 'backup' => 'این فایل پشتیبان آگاه نیست.' ) );
        $salt = substr( $bytes, $head, SODIUM_CRYPTO_PWHASH_SALTBYTES );
        $nonceAt = $head + SODIUM_CRYPTO_PWHASH_SALTBYTES;
        $nonce = substr( $bytes, $nonceAt, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $cipher = substr( $bytes, $nonceAt + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $key = self::key( $password, $salt );
        $compressed = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
        sodium_memzero( $key );
        if ( false === $compressed ) throw new ValidationException( array( 'backup_password' => 'گذرواژه نادرست است یا فایل دستکاری شده است.' ) );
        $json = @gzuncompress( $compressed, self::limit() * 2 );
        if ( false === $json ) throw new ValidationException( array( 'backup' => 'محتوای فشردهٔ فایل معتبر نیست.' ) );
        $data = json_decode( $json, true, 512, JSON_BIGINT_AS_STRING );
        if ( ! is_array( $data ) || ( $data['format'] ?? null ) !== self::FORMAT || ! in_array( $data['scope'] ?? '', array( 'company', 'all' ), true ) || ! is_array( $data['tables'] ?? null ) || ! is_array( $data['counts'] ?? null ) || ! is_array( $data['companies'] ?? null ) ) {
            throw new ValidationException( array( 'backup' => 'ساختار داخلی فایل معتبر نیست.' ) );
        }
        foreach ( $data['tables'] as $table => $definition ) {
            if ( ! in_array( $table, Schema::tables(), true ) || ! is_array( $definition['columns'] ?? null ) || ! is_array( $definition['rows'] ?? null ) || (int) ( $data['counts'][ $table ] ?? -1 ) !== count( $definition['rows'] ) ) {
                throw new ValidationException( array( 'backup' => 'فهرست جدول‌ها یا تعداد ردیف‌ها معتبر نیست.' ) );
            }
            foreach ( $definition['rows'] as $row ) {
                if ( ! is_array( $row ) || count( $row ) !== count( $definition['columns'] ) ) throw new ValidationException( array( 'backup' => 'یک ردیف ناقص در فایل وجود دارد.' ) );
                foreach ( $row as $value ) if ( null !== $value && ( ! is_string( $value ) || false === base64_decode( $value, true ) ) ) throw new ValidationException( array( 'backup' => 'یک مقدار نامعتبر در فایل وجود دارد.' ) );
            }
        }
        return $data;
    }

    /** رمزگشایی و کنترل اصالت، بدون تغییر هیچ داده‌ای. */
    public static function inspectBytes( $bytes, $password ) {
        $data = self::decodeBytes( $bytes, $password );
        return array(
            'format' => $data['format'],
            'schema' => (string) ( $data['schema'] ?? '' ),
            'plugin' => (string) ( $data['plugin'] ?? '' ),
            'scope' => $data['scope'],
            'created_at' => (string) ( $data['created_at'] ?? '' ),
            'companies' => $data['companies'],
            'counts' => $data['counts'],
            'same_installation_key' => Crypto::decrypt( (string) ( $data['crypto_probe'] ?? '' ) ) === 'agah-portable-backup-key-check',
            'sha256' => hash( 'sha256', $bytes ),
        );
    }

    private static function decodedRow( array $columns, array $row ) {
        $out = array();
        foreach ( $columns as $i => $column ) $out[ $column ] = null === $row[ $i ] ? null : base64_decode( $row[ $i ], true );
        return $out;
    }

    /** فایل قابل‌خواندن برای حسابدار؛ داده‌های رمز، توکن و محتوای باینری عمداً صادر نمی‌شوند. */
    public static function readableRows( $cid ) {
        $cid = (int) $cid;
        $meta = self::metadata();
        $labels = array( 'companies'=>'شرکت','company_members'=>'اعضای شرکت','roles'=>'نقش‌ها','accounts'=>'کدینگ حساب‌ها','details'=>'تفصیلی‌ها','fiscal_years'=>'سال‌های مالی','fiscal_periods'=>'دوره‌های مالی','parties'=>'اشخاص','items'=>'کالا و خدمات','tax_rates'=>'نرخ مالیات','vouchers'=>'اسناد حسابداری','voucher_lines'=>'ردیف‌های اسناد','invoices'=>'فاکتورها','invoice_lines'=>'ردیف‌های فاکتور','treasury_docs'=>'برگه‌های خزانه','treasury_doc_items'=>'اقلام خزانه','cheques'=>'چک‌ها','warehouses'=>'انبارها','stock_moves'=>'گردش انبار','employees'=>'کارکنان','assets'=>'دارایی‌ها','audit_log'=>'گزارش تغییرات' );
        $rows = array();
        foreach ( $meta as $table => $definition ) {
            if ( ! self::selected( $table, $definition, 'company' ) ) continue;
            $offset = 0; $step = in_array( $table, array( 'files', 'expense_images', 'company_archive_chunks' ), true ) ? 1 : 200;
            do {
                $batch = self::query( $table, $definition, 'company', $cid, $step, $offset );
                foreach ( $batch as $record ) {
                    $clean = array();
                    foreach ( $record as $column => $value ) {
                        if ( preg_match( '/(?:password|secret|token|recovery|_enc$|_idx$|file_data$|image_data$|chunk_data$)/i', $column ) ) continue;
                        if ( null !== $value && ! preg_match( '//u', (string) $value ) ) $value = '[دادهٔ باینری ـ ' . strlen( (string) $value ) . ' بایت]';
                        elseif ( is_string( $value ) && strlen( $value ) > 2000 ) $value = mb_substr( $value, 0, 2000 ) . '…';
                        $clean[ $column ] = $value;
                    }
                    $pk = array(); foreach ( $definition['primary'] as $column ) $pk[] = (string) ( $record[ $column ] ?? '' );
                    $rows[] = array( $labels[ $table ] ?? $table, $table, implode( '/', $pk ), wp_json_encode( $clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
                    if ( count( $rows ) > 10000 ) throw new ValidationException( array( 'export' => 'خروجی جامع بیش از ۱۰هزار رکورد است؛ از خروجی‌های تخصصی هر بخش استفاده کنید.' ) );
                }
                $offset += $step;
            } while ( count( $batch ) === $step );
        }
        return $rows;
    }

    /** بازیابی وب فقط برای همان شرکت، همان نصب، همان ساختار و با عضویت فعال کاربر جاری مجاز است. */
    public static function restoreCompanyBytes( $bytes, $password, $cid, $uid ) {
        $cid = (int) $cid; $uid = (int) $uid;
        if ( BackupService::blocked() ) throw new ConflictException( 'بازیابی دیگری در حال اجراست.' );
        $data = self::decodeBytes( $bytes, $password );
        if ( $data['scope'] !== 'company' || count( $data['companies'] ) !== 1 || (int) ( $data['companies'][0]['id'] ?? 0 ) !== $cid ) throw new ValidationException( array( 'backup' => 'فقط پشتیبان همین شرکت در این بخش بازیابی می‌شود.' ) );
        if ( (string) ( $data['schema'] ?? '' ) !== Schema::VERSION ) throw new ValidationException( array( 'backup' => 'نسخهٔ ساختار فایل با نسخهٔ نصب‌شده یکسان نیست.' ) );
        if ( ! hash_equals( (string) ( $data['source'] ?? '' ), hash( 'sha256', untrailingslashit( home_url() ) ) ) || Crypto::decrypt( (string) ( $data['crypto_probe'] ?? '' ) ) !== 'agah-portable-backup-key-check' ) throw new ValidationException( array( 'backup' => 'این فایل متعلق به همین نصب و کلید رمزنگاری نیست.' ) );
        global $wpdb;
        $meta = self::metadata();
        foreach ( $meta as $table => $definition ) if ( self::selected( $table, $definition, 'company' ) && ( ! isset( $data['tables'][ $table ] ) || $data['tables'][ $table ]['columns'] !== $definition['columns'] ) ) throw new ValidationException( array( 'backup' => 'جدول‌ها یا ستون‌های فایل با نسخهٔ فعلی تطبیق ندارند.' ) );
        $roleIds = array();
        foreach ( $data['tables']['roles']['rows'] as $row ) {
            $role = self::decodedRow( $data['tables']['roles']['columns'], $row );
            $roleIds[(string)$role['id']] = true;
        }
        foreach ( $data['tables'] as $table => $definition ) {
            if ( !isset($meta[$table]) || !self::selected($table,$meta[$table],'company') || $definition['columns'] !== $meta[$table]['columns'] ) throw new ValidationException(['backup'=>'جدول خارج از دامنهٔ پشتیبان شرکت است.']);
            foreach ( $definition['rows'] as $row ) {
                $record = self::decodedRow($definition['columns'],$row);
                if ( ($table==='companies' && (string)$record['id']!==(string)$cid) || (isset($record['company_id']) && (string)$record['company_id']!==(string)$cid) || ($table==='role_caps' && !isset($roleIds[(string)$record['role_id']])) ) throw new ValidationException(['backup'=>'ردیفی خارج از شرکت انتخاب‌شده در فایل وجود دارد.']);
            }
        }
        if(count($data['tables']['companies']['rows'])!==1)throw new ValidationException(['backup'=>'رکورد شرکت در فایل معتبر نیست.']);
        $members = $data['tables']['company_members']; $memberOk = false;
        // Old files contain every WordPress user; unrelated accounts must not block this company's restore.
        foreach($members['rows'] as $row){
            $member=self::decodedRow($members['columns'],$row);$id=(int)$member['user_id'];
            $hash=$data['users'][$id]??null;
            $login=$wpdb->get_var($wpdb->prepare("SELECT user_login FROM {$wpdb->users} WHERE ID=%d",$id));
            if(null===$login||!is_string($hash)||!hash_equals($hash,hash('sha256',$login)))throw new ValidationException(['backup'=>'کاربر عضو شرکت در فایل با این نصب تطبیق ندارد.']);
        }
        foreach ( $members['rows'] as $row ) { $m = self::decodedRow( $members['columns'], $row ); if ( (int) $m['company_id'] === $cid && (int) $m['user_id'] === $uid && $m['status'] === 'active' ) { $memberOk = true; break; } }
        if ( ! $memberOk ) throw new ValidationException( array( 'backup' => 'در فایل، عضویت فعال کاربر جاری وجود ندارد؛ بازیابی وب مجاز نیست.' ) );
        $sha = hash( 'sha256', $bytes );
        $lock = 'agah_portable_restore_' . $cid;
        if ( (int) Db::val( 'SELECT GET_LOCK(%s,0)', $lock ) !== 1 ) throw new ConflictException( 'بازیابی دیگری در حال اجراست.' );
        try {
            update_option( 'agah_erp_restore_until', time() + 1800, false );
            Db::raw( 'SET FOREIGN_KEY_CHECKS=0' );
            Db::tx( function() use ( $data, $meta, $cid, $uid, $sha ) {
                if ( ! Db::row( 'SELECT id FROM ' . Db::t( 'companies' ) . ' WHERE id=%d FOR UPDATE', $cid ) ) throw new NotFoundException();
                $roles = Db::col( 'SELECT id FROM ' . Db::t( 'roles' ) . ' WHERE company_id=%d', $cid );
                if ( $roles ) Db::raw( 'DELETE FROM ' . Db::t( 'role_caps' ) . ' WHERE role_id IN (' . Db::in( $roles ) . ')' );
                foreach ( array_reverse( array_keys( $data['tables'] ) ) as $table ) {
                    if ( $table === 'role_caps' ) continue;
                    if ( $table === 'companies' ) Db::delete( 'companies', array( 'id' => $cid ) );
                    elseif ( in_array( 'company_id', $meta[ $table ]['columns'], true ) ) Db::delete( $table, array( 'company_id' => $cid ) );
                }
                foreach ( $data['tables'] as $table => $definition ) foreach ( $definition['rows'] as $row ) Db::insert( $table, self::decodedRow( $definition['columns'], $row ) );
                // Re-enabling FOREIGN_KEY_CHECKS does not validate rows inserted while disabled.
                foreach ( Schema::foreignKeyDefinitions() as $fk ) {
                    list($table,$name,$columns,$parent,$parentColumns)=$fk;
                    if(!isset($data['tables'][$table]))continue;
                    $joins=[];$present=[];
                    foreach($columns as $i=>$column){$joins[]='c.`'.$column.'`=p.`'.$parentColumns[$i].'`';$present[]='c.`'.$column.'` IS NOT NULL';}
                    $scope=$table==='role_caps'?'c.role_id IN (SELECT id FROM '.Db::t('roles').' WHERE company_id=%d)':($table==='companies'?'c.id=%d':'c.company_id=%d');
                    if(Db::val('SELECT 1 FROM '.Db::t($table).' c LEFT JOIN '.Db::t($parent).' p ON '.implode(' AND ',$joins).' WHERE '.$scope.' AND '.implode(' AND ',$present).' AND p.`'.$parentColumns[0].'` IS NULL LIMIT 1',$cid))throw new ValidationException(['backup'=>'رابطهٔ اطلاعات در فایل پشتیبان معتبر نیست؛ بازیابی لغو شد.']);
                }
                foreach ( $data['tables'] as $table => $definition ) {
                    $expected = count( $definition['rows'] );
                    if ( $table === 'companies' ) $actual = (int) Db::val( 'SELECT COUNT(*) FROM ' . Db::t( 'companies' ) . ' WHERE id=%d', $cid );
                    elseif ( $table === 'role_caps' ) $actual = (int) Db::val( 'SELECT COUNT(*) FROM ' . Db::t( 'role_caps' ) . ' rc JOIN ' . Db::t( 'roles' ) . ' r ON r.id=rc.role_id WHERE r.company_id=%d', $cid );
                    else $actual = (int) Db::val( 'SELECT COUNT(*) FROM ' . Db::t( $table ) . ' WHERE company_id=%d', $cid );
                    if ( $actual !== $expected ) throw new ValidationException( array( 'backup' => 'تعداد ردیف‌های بازیابی‌شده تطبیق ندارد.' ) );
                }
                if ( Audit::verify( $cid ) !== null ) throw new ValidationException( array( 'backup' => 'زنجیرهٔ ممیزی فایل سالم نیست.' ) );
                $bad = Db::val( 'SELECT v.id FROM ' . Db::t( 'vouchers' ) . ' v LEFT JOIN ' . Db::t( 'voucher_lines' ) . " l ON l.company_id=v.company_id AND l.voucher_id=v.id WHERE v.company_id=%d AND v.status IN ('approved','final') GROUP BY v.id,v.total_debit,v.total_credit HAVING COALESCE(SUM(l.debit),0)<>v.total_debit OR COALESCE(SUM(l.credit),0)<>v.total_credit OR v.total_debit<>v.total_credit OR v.total_debit=0 LIMIT 1", $cid );
                if ( $bad ) throw new ValidationException( array( 'backup' => 'یک سند بازیابی‌شده نامتوازن است.' ) );
                Ctx::boot( $uid, $cid, true );
                Audit::log( 'backup.restore', 'company', $cid, null, array( 'sha256' => $sha ), $cid, $uid );
            } );
            Settings::flush(); wp_cache_flush();
            return array( 'status' => 'restored', 'company_id' => $cid );
        } finally {
            $wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );
            delete_option( 'agah_erp_restore_until' );
            Db::val( 'SELECT RELEASE_LOCK(%s)', $lock );
        }
    }

    public static function inspectFile( $path, $password ) {
        if ( ! is_string( $path ) || ! is_file( $path ) || is_link( $path ) || filesize( $path ) > self::limit() ) throw new ValidationException( array( 'backup' => 'فایل پشتیبان معتبر نیست.' ) );
        $bytes = file_get_contents( $path );
        if ( false === $bytes ) throw new ValidationException( array( 'backup' => 'خواندن فایل پشتیبان ناموفق بود.' ) );
        return self::inspectBytes( $bytes, $password );
    }

    /** اعتبارسنجی پشتیبان بدون قراردادن گذرواژه در تاریخچهٔ فرمان. */
    public static function register() {
        if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) return;
        \WP_CLI::add_command( 'agah portable-backup', function( $args, $assoc ) {
            try {
                $action = $args[0] ?? 'verify';
                if ( 'verify' !== $action || empty( $args[1] ) ) \WP_CLI::error( 'Usage: wp agah portable-backup verify FILE --password-env=ENV_NAME' );
                $envName = isset( $assoc['password-env'] ) ? (string) $assoc['password-env'] : '';
                if ( ! preg_match( '/^[A-Z][A-Z0-9_]{2,60}$/D', $envName ) ) \WP_CLI::error( 'A safe --password-env name is required.' );
                $password = getenv( $envName );
                if ( ! is_string( $password ) || '' === $password ) \WP_CLI::error( 'Password environment variable is empty.' );
                $result = self::inspectFile( (string) $args[1], $password );
                unset( $password );
                \WP_CLI::line( wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
                \WP_CLI::success( 'Portable backup is authentic; no data was changed.' );
            } catch ( \Throwable $e ) {
                error_log( '[agah-portable-backup-cli] ' . get_class( $e ) );
                \WP_CLI::error( $e instanceof ValidationException ? implode( ' ', $e->errors ) : 'Portable backup verification failed.' );
            }
        } );
    }

    public static function maxBytes() { return self::limit(); }
}
