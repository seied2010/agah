<?php
/** نمای یک دستهٔ منو (UX-02): کلیک بر عنوان دسته این صفحه را باز می‌کند. */
namespace Agah\Erp\Http;
use Agah\Erp\Core\Request;
if(!defined('ABSPATH'))exit;
Navigation::overview(ModuleController::nav(),$cid,Request::str('get','g',12));
