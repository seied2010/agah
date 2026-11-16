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

class ApiFailure(val status: Int, override val message: String, val fields: JSONObject = JSONObject()) : IOException(message)

class AgahRepository(val vault: SessionVault) {
    private val client = OkHttpClient.Builder().connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(45, TimeUnit.SECONDS).writeTimeout(45, TimeUnit.SECONDS)
        .callTimeout(65, TimeUnit.SECONDS).retryOnConnectionFailure(false)
        .followRedirects(false).followSslRedirects(false).build()
    private val rotation = Mutex()
    @Volatile private var access: String? = null

    fun setServer(raw: String) {
        val url = raw.trim().toHttpUrl()
        require(url.username.isEmpty() && url.password.isEmpty() && url.query == null && url.fragment == null)
        require(url.isHttps || (BuildConfig.DEBUG && url.host in setOf("10.0.2.2", "127.0.0.1", "localhost"))) { "نشانی HTTPS معتبر لازم است." }
        access = null
        vault.server = url.toString().trimEnd('/')
    }
    private suspend fun raw(path: String, method: String, body: JSONObject?, token: String?, headers: Map<String,String>): JSONObject = withContext(Dispatchers.IO) {
        val root = vault.server.trimEnd('/') + "/wp-json/agah-mobile/v1/"
        val builder = Request.Builder().url(root + path.trimStart('/')).header("Accept", "application/json")
        if (token != null) builder.header("Authorization", "Bearer $token")
        headers.forEach { (k,v) -> builder.header(k,v) }
        val payload = if (method in setOf("GET", "HEAD")) null else (body ?: JSONObject()).toString().toRequestBody("application/json; charset=utf-8".toMediaType())
        builder.method(method, payload)
        client.newCall(builder.build()).execute().use { response ->
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
            val json = runCatching { JSONObject(String(bytes, Charsets.UTF_8)) }.getOrElse { throw ApiFailure(response.code,"پاسخ سرور معتبر نیست؛ نتیجهٔ عملیات را بررسی کنید.") }
            if (!response.isSuccessful) {
                val error = json.optJSONObject("error")
                throw ApiFailure(response.code,error?.optString("message") ?: json.optString("message","خطای ارتباط با سرور"),error?.optJSONObject("fields") ?: JSONObject())
            }
            if (json.optString("api_version") != "1") throw ApiFailure(502,"نسخهٔ API سرور سازگار نیست.")
            json.opt("data")?.let { data ->
                if (data is JSONObject) data else JSONObject().put("rows", data)
            } ?: throw ApiFailure(502,"پاسخ سرور ناقص است.")
        }
    }
    suspend fun login(username: String, password: String, factor: String) {
        val data = raw("auth/login", "POST", JSONObject().put("username", username).put("password", password)
            .put("factor", factor).put("device_id", vault.device).put("device_name", android.os.Build.MODEL), null, emptyMap())
        accept(data)
    }
    private fun accept(data: JSONObject) {
        val a = data.getString("access_token"); val r = data.getString("refresh_token")
        vault.saveRefresh(r); access = a
    }
    private suspend fun refresh(old: String?) = rotation.withLock {
        if (access != null && access != old) return@withLock
        val refresh = vault.refresh() ?: throw ApiFailure(401,"دوباره وارد شوید.")
        try { accept(raw("auth/refresh","POST",JSONObject().put("refresh_token",refresh),null,emptyMap())) }
        catch (e: ApiFailure) { if (e.status == 401) { access = null; vault.clear() }; throw e }
    }
    suspend fun restore(): Boolean {
        if (vault.refresh() == null) return false
        refresh(null); return true
    }
    suspend fun request(path: String, method: String = "GET", body: JSONObject? = null, headers: Map<String,String> = emptyMap()): JSONObject {
        val token = access
        return try { raw(path, method, body, token, headers) }
        catch (e: ApiFailure) {
            if (e.status != 401) throw e
            refresh(token)
            raw(path,method,body,access,headers)
        }
    }
    suspend fun logout() {
        request("auth/logout","POST")
        access = null; vault.clear()
    }
}
