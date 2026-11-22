package ir.idpa.agah.mobile.data

import android.os.Build
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import java.security.KeyStore
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey

/** Platform keys are never exported; the software implementation exists only in test sources. */
interface VaultKeys {
    fun regular(): SecretKey
    fun biometric(create: Boolean = false): SecretKey
    fun deleteRegular()
    fun deleteBiometric()
}

class AndroidVaultKeys : VaultKeys {
    private fun store()=KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
    private fun key(alias: String, authenticated: Boolean, create: Boolean): SecretKey {
        (store().getKey(alias,null) as? SecretKey)?.let { return it }
        check(create) { "کلید ورود با اثر انگشت در دسترس نیست؛ با گذرواژه وارد شوید." }
        return KeyGenerator.getInstance("AES","AndroidKeyStore").run {init(specification(alias,authenticated));generateKey()}
    }
    internal fun specification(alias: String,authenticated: Boolean): KeyGenParameterSpec {
        val spec=KeyGenParameterSpec.Builder(alias,KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
            .setKeySize(256).setBlockModes(KeyProperties.BLOCK_MODE_GCM).setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
        if(authenticated) {
            spec.setUserAuthenticationRequired(true).setInvalidatedByBiometricEnrollment(true)
            if(Build.VERSION.SDK_INT>=30)spec.setUserAuthenticationParameters(0,KeyProperties.AUTH_BIOMETRIC_STRONG)
            else @Suppress("DEPRECATION") spec.setUserAuthenticationValidityDurationSeconds(-1)
        }
        return spec.build()
    }
    override fun regular()=key("agah.refresh.v1",false,true)
    override fun biometric(create: Boolean)=key("agah.biometric.wrap.v1",true,create)
    override fun deleteRegular() {store().deleteEntry("agah.refresh.v1")}
    override fun deleteBiometric() {store().deleteEntry("agah.biometric.wrap.v1")}
}

class LocalSessionLocked : IllegalStateException("برای ادامه، قفل اپ را با اثر انگشت باز کنید.")
enum class BiometricAction { ENABLE, UNLOCK, DISABLE }
class BiometricRequest internal constructor(val action: BiometricAction,val cipher: javax.crypto.Cipher,internal val binding: String)
