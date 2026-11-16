package ir.idpa.agah.mobile.ui

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import ir.idpa.agah.mobile.data.*
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import org.json.JSONObject
import java.net.URLEncoder
import java.util.UUID

data class UiState(
    val signedIn: Boolean=false,val busy: Boolean=false,val error: String?=null,
    val companies: List<JSONObject> = emptyList(),val company: JSONObject?=null,val context: JSONObject?=null,
    val year: JSONObject?=null,val screen: String="dashboard",val module: Module?=null,
    val data: JSONObject=JSONObject(),val record: JSONObject?=null,val etag: String?=null,
    val page: Int=1,val query: String="",val pending: String?=null
)
fun JSONObject.rows(key: String="rows"): List<JSONObject> = optJSONArray(key)?.let { a -> (0 until a.length()).mapNotNull { a.optJSONObject(it) } } ?: emptyList()
fun JSONObject.strings(key: String): List<String> = optJSONArray(key)?.let { a -> (0 until a.length()).map { a.optString(it) } } ?: emptyList()

class AgahViewModel(app: Application): AndroidViewModel(app) {
    val repository=AgahRepository(SessionVault(app))
    private val mutable=MutableStateFlow(UiState(pending=repository.vault.pending))
    val state=mutable.asStateFlow()
    private fun update(fn: (UiState)->UiState) { mutable.value=fn(mutable.value) }
    private fun work(block: suspend ()->Unit) {
        if(mutable.value.busy) return
        update { it.copy(busy=true,error=null) }
        viewModelScope.launch {
            try { block() }
            catch(e: Exception) {
                if(e is kotlinx.coroutines.CancellationException) throw e
                update { it.copy(error=if(e is ApiFailure || e is IllegalArgumentException) e.message else "ارتباط کامل نشد؛ در صورت ثبت، وضعیت عملیات را استعلام کنید.") }
            } finally { update { it.copy(busy=false) } }
        }
    }
    init { work { if(repository.restore()) loadCompanies() } }
    fun login(server: String,user: String,password: String,factor: String) = work {
        repository.setServer(server); repository.login(user,password,factor); loadCompanies()
    }
    private suspend fun loadCompanies() {
        val companies=repository.request("companies").rows()
        update { UiState(signedIn=true,busy=true,companies=companies,pending=repository.vault.pending) }
    }
    fun selectCompany(company: JSONObject) = work {
        val context=repository.request("companies/${company.getString("id")}/context")
        update { it.copy(company=company,context=context,year=null,data=JSONObject(),record=null,module=null,screen="dashboard") }
    }
    fun selectYear(year: JSONObject) { update { it.copy(year=year,data=JSONObject(),record=null,screen="dashboard",module=null) }; dashboard() }
    fun changeCompany() { update { it.copy(company=null,context=null,year=null,data=JSONObject(),record=null,module=null) } }
    val scale: Long get() = if(mutable.value.company?.optString("display_unit")=="toman") 1000 else 100
    val unit: String get() = if(scale==1000L) "تومان" else "ریال"
    fun can(cap: String): Boolean = mutable.value.context?.strings("capabilities")?.contains(cap)==true
    fun canWrite(cap: String): Boolean = mutable.value.context?.optString("mode")=="write" && can(cap)
    fun path(suffix: String, extra: String=""): String {
        val s=mutable.value
        return "companies/${s.company!!.getString("id")}/$suffix?year_id=${s.year?.getString("id") ?: "0"}"+extra
    }
    fun dashboard() = work {
        val data=repository.request(path("dashboard"))
        update { it.copy(screen="dashboard",module=null,record=null,data=data) }
    }
    fun openModule(module: Module,page: Int=1,q: String="") = work { loadModule(module,page,q) }
    private suspend fun loadModule(module: Module,page: Int=1,q: String="") {
        val data=repository.request(path(module.entity,"&kind=${module.kind}&page=$page&q=${URLEncoder.encode(q,"UTF-8")}"))
        update { it.copy(screen="list",module=module,record=null,data=data,page=page,query=q) }
    }
    fun openRecord(record: JSONObject) = work {
        val module=mutable.value.module ?: return@work
        val data=repository.request(path("${module.entity}/${record.getString("id")}"))
        update { it.copy(screen="detail",record=data.getJSONObject("record"),etag=data.getString("etag")) }
    }
    fun edit(new: Boolean=false) {
        update { it.copy(screen="form",record=if(new) null else it.record,etag=if(new) null else it.etag) }
    }
    fun back() {
        val s=mutable.value
        when(s.screen) { "form" -> update { it.copy(screen=if(it.record==null) "list" else "detail") }; "detail" -> s.module?.let { openModule(it,s.page,s.query) }; else -> dashboard() }
    }
    fun dismissError() { update { it.copy(error=null) } }
    fun submit(payload: JSONObject) = work {
        check(repository.vault.pending==null) { "ابتدا نتیجهٔ عملیات قبلی را بررسی کنید." }
        val s=mutable.value; val m=s.module!!; val id=s.record?.optString("id"); val key=UUID.randomUUID().toString()
        val receipt=JSONObject().put("key",key).put("company",s.company!!.getString("id")).put("year",s.year!!.getString("id")).put("entity",m.entity)
        repository.vault.pending=receipt.toString(); update { it.copy(pending=receipt.toString()) }
        try {
            repository.request(path(m.entity+(id?.let { "/$it" } ?: "")),if(id==null) "POST" else "PUT",payload,
                buildMap { put("Idempotency-Key",key); s.etag?.let { put("If-Match",it) } })
            repository.vault.pending=null; update { it.copy(pending=null) }; loadModule(m)
        } catch(e: ApiFailure) {
            // A structured server rejection with 4xx rolls back; unknown/server/network outcomes remain pending.
            if(e.status in listOf(400,401,403,404,409,422,429)) { repository.vault.pending=null; update { it.copy(pending=null) } }
            throw e
        }
    }
    fun transition(action: String,reason: String) = work {
        check(repository.vault.pending==null)
        val s=mutable.value; val m=s.module!!; val key=UUID.randomUUID().toString()
        val receipt=JSONObject().put("key",key).put("company",s.company!!.getString("id")).put("year",s.year!!.getString("id")).put("entity",m.entity)
        repository.vault.pending=receipt.toString(); update { it.copy(pending=receipt.toString()) }
        try {
            repository.request(path("${m.entity}/${s.record!!.getString("id")}/$action"),"POST",JSONObject().put("reason",reason),mapOf("Idempotency-Key" to key,"If-Match" to s.etag!!))
            repository.vault.pending=null; update { it.copy(pending=null) }; loadModule(m)
        } catch(e: ApiFailure) {
            if(e.status in listOf(400,401,403,404,409,422,429)) { repository.vault.pending=null; update { it.copy(pending=null) } }
            throw e
        }
    }
    fun checkPending() = work {
        val receipt=JSONObject(repository.vault.pending ?: return@work)
        val result=repository.request("companies/${receipt.getString("company")}/operations/${receipt.getString("key")}")
        if(result.optString("state")=="committed") {
            repository.vault.pending=null; update { it.copy(pending=null,error="عملیات در سرور ثبت قطعی شده است؛ فهرست را تازه کنید.") }
        } else if(result.optString("state")=="cancelled") {
            repository.vault.pending=null; update { it.copy(pending=null,error="درخواست لغو شده و ثبت نخواهد شد.") }
        } else update { it.copy(error="هنوز رسید ثبت پیدا نشد. ممکن است درخواست در حال اجرا باشد؛ دوباره استعلام یا درخواست را لغو کنید. شناسه: ${receipt.getString("key")}") }
    }
    fun cancelPending() = work {
        val receipt=JSONObject(repository.vault.pending ?: return@work)
        val result=repository.request("companies/${receipt.getString("company")}/operations/${receipt.getString("key")}","DELETE")
        if(result.optString("state") in listOf("cancelled","committed")) {
            repository.vault.pending=null
            update { it.copy(pending=null,error=if(result.optString("state")=="committed") "عملیات قبلاً ثبت شده است؛ فهرست را تازه کنید." else "درخواست لغو شد؛ هیچ ثبت دیرهنگامی با این شناسه انجام نمی‌شود.") }
        }
    }
    suspend fun lookup(source: String,q: String,page: Int=1): JSONObject = repository.request(path("lookups/$source","&page=$page&q=${URLEncoder.encode(q,"UTF-8")}"))
    suspend fun barcode(code: String): JSONObject = repository.request(path("barcode","&code=${URLEncoder.encode(code,"UTF-8")}"))
    fun reports(name: String="ledger") = work { val data=repository.request(path("reports/$name")); update { it.copy(screen="report",data=data,module=null,record=null) } }
    fun devices() = work { val data=repository.request("auth/devices"); update { it.copy(screen="devices",data=data,module=null,record=null) } }
    fun revoke(id: String) = work { repository.request("auth/devices/$id","DELETE"); val data=repository.request("auth/devices"); update { it.copy(data=data) } }
    fun logout() = work { repository.logout(); update { UiState() } }
}
