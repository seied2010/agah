package ir.idpa.agah.mobile.ui

import android.security.keystore.KeyPermanentlyInvalidatedException
import android.security.keystore.UserNotAuthenticatedException
import ir.idpa.agah.mobile.data.BiometricAction
import java.security.GeneralSecurityException

/** Fixed diagnostics only: never display provider exception text, account data, or credentials. */
internal fun biometricFailureMessage(action: BiometricAction,error: Throwable): String {
    val causes=generateSequence(error) {it.cause}.take(8).toList()
    val operation=when(action) {BiometricAction.ENABLE->"E";BiometricAction.UNLOCK->"U";BiometricAction.DISABLE->"D"}
    val reason=when {
        causes.any {it is KeyPermanentlyInvalidatedException}->"KEY"
        causes.any {it is UserNotAuthenticatedException}->"AUTH"
        causes.any {it is GeneralSecurityException}->"CRYPTO"
        causes.any {it is IllegalArgumentException}->"REQUEST"
        else->"LOCAL"
    }
    val message=when(reason) {
        "KEY"->"کلید اثر انگشت گوشی تغییر کرده است؛ با گذرواژه وارد شوید و اثر انگشت را دوباره فعال کنید."
        "AUTH"->"مجوز حسگر برای این عملیات تأیید نشد؛ دوباره اثر انگشت را تأیید کنید. ورود با گذرواژه هم در دسترس است."
        else->when(action) {
            BiometricAction.ENABLE->"فعال‌سازی اثر انگشت کامل نشد؛ دوباره تلاش کنید. ورود با گذرواژه همچنان در دسترس است."
            BiometricAction.UNLOCK->"بازکردن قفل با اثر انگشت کامل نشد؛ دوباره تلاش کنید یا با گذرواژه وارد شوید."
            BiometricAction.DISABLE->"غیرفعال‌سازی اثر انگشت کامل نشد؛ دوباره تلاش کنید."
        }
    }
    return "$message (BIO-$operation-$reason)"
}
