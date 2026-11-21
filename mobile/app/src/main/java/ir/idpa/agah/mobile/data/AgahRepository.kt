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
        access = null; userId = null
        vault.server = url.toString().trimEnd('/')
    }
    private suspend fun raw(path: String, method: String, body: JSONObject?, token: String?, headers: Map<String,String>): JSONObject = withContext(Dispatchers.IO) {
        val root = checkedServer(vault.server).toString().trimEnd('/') + "/wp-json/agah-mobile/v1/"
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
        val data = raw("auth/login", "POST", JSONObject().put("username", username).put("password", password)
            .put("factor", factor).put("device_id", vault.device).put("device_name", android.os.Build.MODEL), null, emptyMap())
        accept(data)
    }
    private suspend fun accept(data: JSONObject) {
        val a=data.optString("access_token");val r=data.optString("refresh_token");val id=data.optJSONObject("user")?.optString("id").orEmpty()
        if(!Regex("[1-9][0-9]{0,18}").matches(id)||a.length !in 3..256||r.length !in 3..256||!a.startsWith("$id.")||!r.startsWith("$id.")) throw ApiFailure(502,"پاسخ ورود کامل و معتبر نیست.")
        withContext(Dispatchers.IO) { vault.saveRefresh(r,id) }; access=a; userId=id
    }
    private suspend fun refresh(old: String?) = rotation.withLock {
        if (access != null && access != old) return@withLock
        val refresh = withContext(Dispatchers.IO) { vault.refresh() } ?: throw ApiFailure(401,"دوباره وارد شوید.")
        try { accept(raw("auth/refresh","POST",JSONObject().put("refresh_token",refresh),null,emptyMap())) }
        catch (e: ApiFailure) { if (e.status == 401) { access = null; userId=null; withContext(Dispatchers.IO) { vault.clear() } }; throw e }
    }
    suspend fun restore(): Boolean {
        if (withContext(Dispatchers.IO) { vault.refresh() } == null) return false
        refresh(null); return true
    }
    suspend fun request(path: String, method: String = "GET", body: JSONObject? = null, headers: Map<String,String> = emptyMap()): JSONObject {
        val token = access
        return try { raw(path, method, body, token, headers) }
        catch (e: ApiFailure) {
            // A proxy's unrecognized 401 is not proof that an earlier financial write was rejected.
            if (e.status != 401 || !e.definitive) throw e
            refresh(token)
            raw(path,method,body,access,headers)
        }
    }
    suspend fun logout(): Boolean {
        var revoked=false
        try { request("auth/logout","POST"); revoked=true }
        catch(e: IOException) { revoked=e is ApiFailure&&e.status==401&&e.definitive }
        finally { access=null;userId=null;withContext(Dispatchers.IO) { vault.clear() } }
        return revoked
    }
}
