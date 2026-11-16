<?php
/**
 * خطاهای برنامه.
 * قاعده: AppException و زیرکلاس‌هایش «پیام امن برای کاربر» دارند و عیناً نمایش داده می‌شوند.
 * هر استثنای دیگر (خطای SQL، باگ) فقط در لاگ سرور ثبت می‌شود و کاربر پیام عمومی می‌بیند (جلوگیری از افشای ساختار پایگاه داده).
 */
namespace Agah\Erp\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

class AppException extends \Exception {
	/** @var int کد وضعیت HTTP */
	public $status = 400;
	public function __construct( $message = '', $status = 400 ) {
		parent::__construct( $message );
		$this->status = $status;
	}
}

/** خطای اعتبارسنجی فرم: آرایهٔ [نام فیلد => پیام]. */
class ValidationException extends AppException {
	/** @var array<string,string> */
	public $errors = array();
	public function __construct( $errors, $message = 'اطلاعات واردشده درست نیست؛ موارد مشخص‌شده را اصلاح کنید.' ) {
		parent::__construct( $message, 422 );
		$this->errors = is_array( $errors ) ? $errors : array( '_' => (string) $errors );
	}
}

class ForbiddenException extends AppException {
	public function __construct( $message = 'اجازهٔ انجام این کار را ندارید.' ) {
		parent::__construct( $message, 403 );
	}
}

/** موجود نیست یا متعلق به شرکت دیگری است (عمداً بین این دو تفاوتی نمی‌گذاریم تا وجود داده‌های دیگران لو نرود). */
class NotFoundException extends AppException {
	public function __construct( $message = 'مورد درخواستی پیدا نشد.' ) {
		parent::__construct( $message, 404 );
	}
}

/** تعارض با وضعیت فعلی داده (دورهٔ بسته، سند قطعی، تکراری...). */
class ConflictException extends AppException {
	public function __construct( $message = 'این عملیات با وضعیت فعلی داده سازگار نیست.' ) {
		parent::__construct( $message, 409 );
	}
}
