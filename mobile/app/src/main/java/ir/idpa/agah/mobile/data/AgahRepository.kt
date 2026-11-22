package ir.idpa.agah.mobile.data

import ir.idpa.agah.mobile.BuildConfig
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import okhttp3.HttpUrl.Companion.toHttpUrl
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import java.io.IOException
import java.util.concurrent.TimeUnit

class AgahRepository(val vault: SessionVault) {
    private val client = OkHttpClient.Builder().connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(45, TimeUnit.SECONDS).writeTimeout(45, TimeUnit.SECONDS)
        .callTimeout(65, TimeUnit.SECONDS).retryOnConnectionFailure(false)
        .followRedirects(false).followSslRedirects(false).build()
    // A stale pooled socket may be retried for reads. Financial writes must keep an uncertain outcome.
    private val readClient=client.newBuilder().retryOnConnectionFailure(true).build()
    private val rotation = Mutex()
    private val sessionBoundary=Any()
    @Volatile private var sessionEpoch=0L
    private var rotating=false
    @Volatile private var lockPending=false
    private fun checkSession(expected: Long) {check(expected==sessionEpoch) {"نشست تغییر کرده است؛ دوباره تلاش کنید."}}
    @Volatile private var access: String? = null
    @Volatile var userId: String? = null
        private set

    private fun checkedServer(raw: String): okhttp3.HttpUrl {
        val url = raw.trim().toHttpUrl()
        require(url.username.isEmpty() && url.password.isEmpty() && url.query == null && url.fragment == null) { "نشانی ریشهٔ سامانه را بدون اطلاعات ورود، پارامتر یا # وارد کنید." }
        require(url.isHttps || (BuildConfig.DEBUG && url.host in setOf("10.0.2.2", "127.0.0.1", "localhost"))) { "نشانی HTTPS معتبر لازم است." }
        return url
    }
    fun setServer(raw: String) {
        val url=checkedServer(raw)
        synchronized(sessionBoundary) {sessionEpoch++;lockPending=false;access=null;userId=null;vault.server=url.toString().trimEnd('/')}
    }
    private suspend fun raw(path: String, method: String, body: JSONObject?, token: String?, headers: Map<String,String>,expectedEpoch: Long=sessionEpoch): JSONObject = withContext(Dispatchers.IO) {
        val root = synchronized(sessionBoundary) {checkSession(expectedEpoch);checkedServer(vault.server)}.toString().trimEnd('/') + "/wp-json/agah-mobile/v1/"
        val builder = Request.Builder().url(root + path.trimStart('/')).header("Accept", "application/json")
        if (token != null) builder.header("Authorization", "Bearer $token")
        headers.forEach { (k,v) -> builder.header(k,v) }
        val payload = if (method in setOf("GET", "HEAD")) null else (body ?: JSONObject()).toString().toRequestBody("application/json; charset=utf-8".toMediaType())
        builder.method(method, payload)
        // Revoking the same session is idempotent; it can recover a stale socket like a read.
        (if(method in setOf("GET","HEAD")||path=="auth/logout") readClient else client).newCall(builder.build()).execute().use { response ->
            val bytes = response.body?.byteStream()?.use { stream ->
                val out = java.io.ByteArrayOutputStream()
                val buffer = ByteArray(8192)
                while (out.size() <= 4_194_304) {
                    val n = stream.read(buffer, 0, minOf(buffer.size, 4_194_305 - out.size()))
                    if (n < 0) break
                    out.write(buffer, 0, n)
                }
                out.toByteArray()
            } ?: byteArrayOf()
            if (bytes.size > 4_194_304) throw ApiFailure(502,"پاسخ سرور بیش از حد بزرگ است.")
            ApiEnvelope.parse(response.code,bytes)
        }
    }
    suspend fun login(username: String, password: String, factor: String) {
        val expected=sessionEpoch
        val data = raw("auth/login", "POST", JSONObject().put("username", username).put("password", password)
            .put("factor", factor).put("device_id", vault.device).put("device_name", android.os.Build.MODEL), null, emptyMap(),expected)
        accept(data,expected)
    }
    private suspend fun accept(data: JSONObject,expected: Long) {
        val a=data.optString("access_token");val r=data.optString("refresh_token");val id=data.optJSONObject("user")?.optString("id").orEmpty()
        if(!Regex("[1-9][0-9]{0,18}").matches(id)||a.length !in 3..256||r.length !in 3..256||!a.startsWith("$id.")||!r.startsWith("$id.")) throw ApiFailure(502,"پاسخ ورود کامل و معتبر نیست.")
        withContext(Dispatchers.IO) { synchronized(sessionBoundary) {checkSession(expected);vault.saveRefresh(r,id);access=a;userId=id} }
    }
    private suspend fun refresh(old: String?,expected: Long=sessionEpoch) = rotation.withLock {
        synchronized(sessionBoundary) {checkSession(expected);if(lockPending)throw LocalSessionLocked()}
        if (access != null && access != old) return@withLock
        try {
            val refresh=withContext(Dispatchers.IO) {synchronized(sessionBoundary) {checkSession(expected);if(lockPending)throw LocalSessionLocked();rotating=true;vault.refresh()}} ?: throw ApiFailure(401,"دوباره وارد شوید.")
            accept(raw("auth/refresh","POST",JSONObject().put("refresh_token",refresh),null,emptyMap(),expected),expected)
        } catch(e: ApiFailure) {
            if(e.status==401&&e.definitive)withContext(Dispatchers.IO) {synchronized(sessionBoundary) {if(expected==sessionEpoch) {access=null;userId=null;vault.clear()}}};throw e
        } finally {synchronized(sessionBoundary) {rotating=false;if(lockPending) {access=null;vault.lock()}}}
    }
    suspend fun restore(): Boolean {
        if (withContext(Dispatchers.IO) { vault.refresh() } == null) return false
        refresh(null); return true
    }
    suspend fun request(path: String, method: String = "GET", body: JSONObject? = null, headers: Map<String,String> = emptyMap()): JSONObject {
        val expected=sessionEpoch
        if(lockPending||vault.biometricEnabled&&!vault.locallyUnlocked)throw LocalSessionLocked()
        val token = access
        return try { raw(path, method, body, token, headers,expected) }
        catch (e: ApiFailure) {
            // A proxy's unrecognized 401 is not proof that an earlier financial write was rejected.
            if (e.status != 401 || !e.definitive) throw e
            refresh(token,expected)
            if(lockPending)throw LocalSessionLocked()
            raw(path,method,body,access,headers,expected)
        }
    }
    fun lockLocal() {synchronized(sessionBoundary) {lockPending=true;access=null;if(!rotating)vault.lock()}}
    fun resumeLocal() {synchronized(sessionBoundary) {check(vault.locallyUnlocked);lockPending=false}}
    fun forgetLocal() {synchronized(sessionBoundary) {sessionEpoch++;lockPending=false;access=null;userId=null;vault.clear()}}
    suspend fun logout(): Boolean {
        var revoked=false
        try { request("auth/logout","POST"); revoked=true }
        catch(e: IOException) { revoked=e is ApiFailure&&e.status==401&&e.definitive }
        finally { withContext(Dispatchers.IO) { forgetLocal() } }
        return revoked
    }
}
