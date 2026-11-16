<?php
/** درگاه سرویس‌های عمومی: زمینه و مجوز اجباری؛ قفل شرکت همهٔ تغییرات پیکربندی/مالی را سری می‌کند. */
namespace Agah\Erp\Core;
if(!defined('ABSPATH')) exit;
final class Access {
    public static function need($cid,$cap) {
        if(Ctx::cidOrZero()!==(int)$cid) throw new NotFoundException();
        Ctx::need($cap);
    }
    /** قفل شرکت باید پیش از قفل سال/حساب/سند گرفته شود تا ترتیب قفل‌ها یکسان بماند. */
    public static function write($cid,$cap,callable $fn) {
        if(class_exists('Agah\\Erp\\Modules\\Maintenance\\BackupService')&&\Agah\Erp\Modules\Maintenance\BackupService::blocked())throw new ConflictException('بازیابی سامانه در حال اجراست؛ کمی بعد تلاش کنید.');
        self::need($cid,$cap);
        // SUB-05: پس از پایان اشتراک (فقط وقتی اجبار اشتراک روشن است) ثبت تازه بسته می‌شود؛ خواندن و خروجی از این مسیر نمی‌گذرند.
        if(class_exists('Agah\\Erp\\Modules\\Subscription\\SubscriptionService')&&!\Agah\Erp\Modules\Subscription\SubscriptionService::canWrite((int)$cid,$cap))throw new ConflictException('اشتراک این شرکت فعال نیست؛ ثبت تازه بسته است. دیدن و خروجی داده‌ها آزاد است. برای تمدید به صفحهٔ «اشتراک» بروید.');
        return Db::tx(function()use($cid,$cap,$fn){
            if(!Db::row('SELECT id FROM '.Db::t('companies').' WHERE id=%d FOR UPDATE',$cid)) throw new NotFoundException();
            Ctx::boot(Ctx::uid(),$cid,true);
            Ctx::need($cap);
            return $fn();
        });
    }
}
