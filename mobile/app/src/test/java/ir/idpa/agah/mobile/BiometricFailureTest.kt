package ir.idpa.agah.mobile

import android.security.keystore.KeyPermanentlyInvalidatedException
import android.security.keystore.UserNotAuthenticatedException
import ir.idpa.agah.mobile.data.BiometricAction
import ir.idpa.agah.mobile.ui.biometricFailureMessage
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import javax.crypto.IllegalBlockSizeException

@RunWith(RobolectricTestRunner::class)
@Config(sdk=[28])
class BiometricFailureTest {
    @Test fun wrappedAuthorizationFailureIsRecognized() {
        val error=IllegalBlockSizeException("private provider detail").apply {initCause(UserNotAuthenticatedException())}
        val text=biometricFailureMessage(BiometricAction.ENABLE,error)
        assertTrue(text.contains("BIO-E-AUTH"));assertFalse(text.contains("کلید اثر انگشت گوشی تغییر"));assertFalse(text.contains("private provider detail"))
    }
    @Test fun onlyInvalidatedKeyAsksForReactivation() {
        val text=biometricFailureMessage(BiometricAction.UNLOCK,KeyPermanentlyInvalidatedException())
        assertTrue(text.contains("BIO-U-KEY"));assertTrue(text.contains("دوباره فعال کنید"))
    }
    @Test fun unexpectedFailureDoesNotExposeProviderOrAccountDetails() {
        for(action in BiometricAction.entries) {
            val text=biometricFailureMessage(action,IllegalStateException("SECRET-token-and-account@example.invalid"))
            assertTrue(text.contains("LOCAL"));assertFalse(text.contains("SECRET"));assertFalse(text.contains("example.invalid"))
        }
    }
}
