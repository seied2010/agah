package ir.idpa.agah.mobile.data

import android.content.Context
import android.util.Base64
import java.util.UUID
import java.security.MessageDigest
import org.json.JSONObject
import javax.crypto.Cipher
import javax.crypto.spec.GCMParameterSpec

/** Only refresh credentials and identifiers persist; financial payloads never do. */
class SessionVault(context: Context,private val keys: VaultKeys = AndroidVaultKeys()) {
    private val preferences = context.getSharedPreferences("agah_session", Context.MODE_PRIVATE)
    private var dataKey: ByteArray? = null
    val owner: String? get()=preferences.getString("owner",null)
    val biometricEnabled: Boolean get()=preferences.getBoolean("biometric",false)||preferences.contains("biometric_wrap")||preferences.contains("protected_refresh")
    val locallyUnlocked: Boolean get()=!biometricEnabled||dataKey!=null
    var server: String
        get() = preferences.getString("server", "https://idpa.ir")!!
        set(value) { clear(); preferences.edit().putString("server", value).commit() }
    val device: String
        get() = preferences.getString("device", null) ?: UUID.randomUUID().toString().also {
            preferences.edit().putString("device", it).commit()
        }
    private fun binding()="agah.biometric.v1|$server|${owner ?: error("ابتدا با حساب کاربری وارد شوید.")}"
    private fun decode(value: String): ByteArray=Base64.decode(value,Base64.NO_WRAP).also {require(it.size>28)}
    private fun encrypt(value: ByteArray,key: javax.crypto.SecretKey,aad: String): String {
        val cipher=Cipher.getInstance("AES/GCM/NoPadding");cipher.init(Cipher.ENCRYPT_MODE,key);cipher.updateAAD(aad.toByteArray())
        return Base64.encodeToString(cipher.iv+cipher.doFinal(value),Base64.NO_WRAP)
    }
    private fun decrypt(encoded: String,key: javax.crypto.SecretKey,aad: String): ByteArray {
        val bytes=decode(encoded);val cipher=Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.DECRYPT_MODE,key,GCMParameterSpec(128,bytes.copyOfRange(0,12)));cipher.updateAAD(aad.toByteArray())
        return cipher.doFinal(bytes.copyOfRange(12,bytes.size))
    }
    @Synchronized fun refresh(): String? {
        if(biometricEnabled) {
            val secret=dataKey ?: throw LocalSessionLocked()
            return String(decrypt(preferences.getString("protected_refresh",null) ?: error("نشست محفوظ در دسترس نیست."),javax.crypto.spec.SecretKeySpec(secret,"AES"),binding()+"|refresh"),Charsets.UTF_8)
        }
        return runCatching {
            val encoded=preferences.getString("refresh",null) ?: return null
            String(decrypt(encoded,keys.regular(),server),Charsets.UTF_8)
        }.getOrElse {clear();null}
    }
    @Synchronized fun saveRefresh(value: String,owner: String) {
        if(biometricEnabled) {
            check(owner==this.owner) {"حساب ورود با اثر انگشت تغییر کرده است."}
            val secret=dataKey ?: throw LocalSessionLocked()
            check(preferences.edit().putString("protected_refresh",encrypt(value.toByteArray(),javax.crypto.spec.SecretKeySpec(secret,"AES"),binding()+"|refresh")).commit())
        } else check(preferences.edit().putString("refresh",encrypt(value.toByteArray(),keys.regular(),server)).putString("owner",owner).commit())
    }
    @Synchronized fun prepareBiometric(action: BiometricAction): BiometricRequest {
        val aad=binding();val cipher=Cipher.getInstance("AES/GCM/NoPadding")
        if(action==BiometricAction.ENABLE) {
            check(!biometricEnabled && refresh()!=null)
            keys.deleteBiometric()
            cipher.init(Cipher.ENCRYPT_MODE,keys.biometric(true))
        } else {
            check(biometricEnabled)
            val wrapped=decode(preferences.getString("biometric_wrap",null) ?: error("کلید محفوظ در دسترس نیست."))
            require(wrapped.size==60)
            cipher.init(Cipher.DECRYPT_MODE,keys.biometric(),GCMParameterSpec(128,wrapped.copyOfRange(0,12)))
        }
        cipher.updateAAD(aad.toByteArray())
        return BiometricRequest(action,cipher,aad)
    }
    @Synchronized fun completeBiometric(request: BiometricRequest,authenticatedCipher: Cipher) {
        require(request.cipher===authenticatedCipher && request.binding==binding()) {"درخواست ورود تغییر کرده است؛ دوباره تلاش کنید."}
        when(request.action) {
            BiometricAction.ENABLE -> {
                check(!biometricEnabled)
                val refresh=refresh() ?: error("نشست پایان یافته است؛ با گذرواژه وارد شوید.")
                val secret=ByteArray(32).also {java.security.SecureRandom().nextBytes(it)}
                try {
                    val protected=encrypt(refresh.toByteArray(),javax.crypto.spec.SecretKeySpec(secret,"AES"),binding()+"|refresh")
                    val wrapped=Base64.encodeToString(authenticatedCipher.iv+authenticatedCipher.doFinal(secret),Base64.NO_WRAP)
                    check(preferences.edit().putBoolean("biometric",true).putString("biometric_wrap",wrapped).putString("protected_refresh",protected).remove("refresh").commit())
                    dataKey=secret.copyOf();keys.deleteRegular()
                } finally {secret.fill(0)}
            }
            BiometricAction.UNLOCK, BiometricAction.DISABLE -> {
                check(biometricEnabled)
                val wrapped=decode(preferences.getString("biometric_wrap",null)!!)
                val secret=authenticatedCipher.doFinal(wrapped.copyOfRange(12,wrapped.size))
                try {
                    require(secret.size==32)
                    val value=decrypt(preferences.getString("protected_refresh",null)!!,javax.crypto.spec.SecretKeySpec(secret,"AES"),binding()+"|refresh")
                    if(request.action==BiometricAction.DISABLE) {
                        check(preferences.edit().putString("refresh",encrypt(value,keys.regular(),server)).remove("biometric").remove("biometric_wrap").remove("protected_refresh").commit())
                        lock();keys.deleteBiometric()
                    } else {lock();dataKey=secret.copyOf()}
                    value.fill(0)
                } finally {secret.fill(0)}
            }
        }
    }
    @Synchronized fun lock() {dataKey?.fill(0);dataKey=null}
    @Synchronized fun clear() {
        lock();check(preferences.edit().remove("refresh").remove("owner").remove("biometric").remove("biometric_wrap").remove("protected_refresh").commit())
        runCatching {keys.deleteRegular()};runCatching {keys.deleteBiometric()}
    }
    private fun receiptKey(): String? {
        val owner=preferences.getString("owner",null) ?: return null
        return MessageDigest.getInstance("SHA-256").digest((server+"|"+owner).toByteArray()).joinToString("") { "%02x".format(it.toInt() and 255) }
    }
    var pending: String?
        get() {
            val key=receiptKey() ?: return null
            val map=JSONObject(preferences.getString("receipts","{}")!!)
            if(map.has(key)) return map.getString(key)
            // Migrate only an explicitly owner-bound receipt from the development builds.
            val legacy=preferences.getString("pending",null) ?: return null
            val item=runCatching { JSONObject(legacy) }.getOrNull() ?: return null
            if(item.optString("server")==server&&item.optString("user")==preferences.getString("owner",null)) {
                map.put(key,legacy)
                check(preferences.edit().putString("receipts",map.toString()).remove("pending").commit())
                return legacy
            }
            return null
        }
        set(value) {
            val key=receiptKey() ?: run { require(value==null); return }
            val map=JSONObject(preferences.getString("receipts","{}")!!)
            if(value==null) map.remove(key) else map.put(key,value)
            check(preferences.edit().putString("receipts",map.toString()).commit())
        }
}
