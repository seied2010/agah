package ir.idpa.agah.mobile.data

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyStore
import java.util.UUID
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/** Only refresh credentials and identifiers persist; financial payloads never do. */
class SessionVault(context: Context) {
    private val preferences = context.getSharedPreferences("agah_session", Context.MODE_PRIVATE)
    private val alias = "agah.refresh.v1"
    var server: String
        get() = preferences.getString("server", "https://idpa.ir")!!
        set(value) { clear(); preferences.edit().putString("server", value).commit() }
    val device: String
        get() = preferences.getString("device", null) ?: UUID.randomUUID().toString().also {
            preferences.edit().putString("device", it).commit()
        }
    private fun key(): SecretKey {
        val store = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        return (store.getKey(alias, null) as? SecretKey) ?: KeyGenerator.getInstance("AES", "AndroidKeyStore").run {
            init(KeyGenParameterSpec.Builder(alias, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM).setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE).build())
            generateKey()
        }
    }
    fun refresh(): String? = runCatching {
        val encoded = preferences.getString("refresh", null) ?: return null
        val bytes = Base64.decode(encoded, Base64.NO_WRAP)
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.DECRYPT_MODE, key(), GCMParameterSpec(128, bytes.copyOfRange(0, 12)))
        cipher.updateAAD(server.toByteArray())
        String(cipher.doFinal(bytes.copyOfRange(12, bytes.size)), Charsets.UTF_8)
    }.getOrElse { clear(); null }
    fun saveRefresh(value: String) {
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.ENCRYPT_MODE, key()); cipher.updateAAD(server.toByteArray())
        val bytes = cipher.iv + cipher.doFinal(value.toByteArray(Charsets.UTF_8))
        check(preferences.edit().putString("refresh", Base64.encodeToString(bytes, Base64.NO_WRAP)).commit())
    }
    fun clear() { preferences.edit().remove("refresh").commit() }
    var pending: String?
        get() = preferences.getString("pending", null)
        set(value) { check(preferences.edit().putString("pending", value).commit()) }
}
