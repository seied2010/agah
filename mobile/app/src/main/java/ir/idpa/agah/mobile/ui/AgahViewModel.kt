package ir.idpa.agah.mobile.ui

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateListOf
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
    val page: Int=1,val query: String="",val pending: String?=null,
    val attachments: List<JSONObject> = emptyList(),val report: String="ledger",
    val reportPage: Int=1,val reportFrom: String="",val reportTo: String="",val reportMode: String="journal"
)
fun JSONObject.rows(key: String="rows"): List<JSONObject> = optJSONArray(key)?.let { a -> (0 until a.length()).mapNotNull { a.optJSONObject(it) } } ?: emptyList()
fun JSONObject.strings(key: String): List<String> = optJSONArray(key)?.let { a -> (0 until a.length()).map { a.optString(it) } } ?: emptyList()

class AgahViewModel(app: Application): AndroidViewModel(app) {
    data class FormDraft(val key: String,val header: androidx.compose.runtime.snapshots.SnapshotStateMap<String,String>,val lines: androidx.compose.runtime.snapshots.SnapshotStateList<MutableMap<String,String>>)
    private var draft: FormDraft?=null
    fun formDraft(module: Module,record: JSONObject?): FormDraft {
        val s=mutable.value
        val key="${s.company?.optString("id")}:${s.year?.optString("id")}:${module.entity}:${module.kind}:${record?.optString("id")}:${s.etag}"
        if(draft?.key!=key) {
            val header=mutableStateMapOf<String,String>().apply {
                putAll(Forms.defaults(module,java.time.LocalDate.now().toString().coerceIn(s.year!!.getString("start_date"),s.year!!.getString("end_date"))))
                if(record!=null) putAll(Forms.values(Forms.header(module),record,scale))
            }
            val lines=mutableStateListOf<MutableMap<String,String>>().apply {
                if(record!=null) addAll((record.rows("lines")+record.rows("items")).map { Forms.values(Forms.lines(module),it,scale).toMutableMap() })
            }
            draft=FormDraft(key,header,lines)
        }
        return draft!!
    }
    val repository=AgahRepository(SessionVault(app))
    private val mutable=MutableStateFlow(UiState(pending=repository.vault.pending))
    val state=mutable.asStateFlow()
    private fun update(fn: (UiState)->UiState) { mutable.value=fn(mutable.value) }
    private fun newReceipt(key: String): JSONObject = JSONObject().put("key",key).put("company",mutable.value.company!!.getString("id")).put("year",mutable.value.year!!.getString("id")).put("server",repository.vault.server).put("user",repository.userId)
    private fun pendingReceipt(): JSONObject {
        val value=JSONObject(repository.vault.pending ?: error("رسیدی برای استعلام نیست."))
        require(value.optString("server",repository.vault.server)==repository.vault.server&&value.optString("user",repository.userId.orEmpty())==repository.userId) { "رسید به حساب یا سرور دیگری تعلق دارد؛ با همان حساب و سرور وارد شوید." }
        return value
    }
    private fun work(block: suspend ()->Unit) {
        if(mutable.value.busy) return
        update { it.copy(busy=true,error=null) }
        viewModelScope.launch {
            try { block() }
            catch(e: Exception) {
                if(e is kotlinx.coroutines.CancellationException) throw e
                update { it.copy(error=if(e is ApiFailure) e.message+(if(e.fields.length()>0) "\n"+e.fields.keys().asSequence().map { key -> e.fields.optString(key) }.joinToString("\n") else "") else if(e is IllegalArgumentException) e.message else "ارتباط کامل نشد؛ در صورت ثبت، وضعیت عملیات را استعلام کنید.") }
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
        draft=null
        update { it.copy(screen="form",record=if(new) null else it.record,etag=if(new) null else it.etag) }
    }
    fun back() {
        val s=mutable.value
        when(s.screen) { "attachments" -> update { it.copy(screen="detail") }; "form" -> update { it.copy(screen=if(it.record==null) "list" else "detail") }; "detail" -> s.module?.let { openModule(it,s.page,s.query) }; else -> dashboard() }
    }
    fun dismissError() { update { it.copy(error=null) } }
    private suspend fun confirmed(module: Module,result: JSONObject) {
        ApiEnvelope.committed(result)
        repository.vault.pending=null; draft=null
        val message="ثبت با شناسهٔ ${result.getString("id")} در سرور انجام شد."
        // Exit the write form BEFORE the optional refresh; a failed GET cannot re-enable a completed form.
        update { it.copy(pending=null,record=null,screen="list",data=JSONObject(),error=message) }
        try { loadModule(module) }
        catch(e: Exception) {
            if(e is kotlinx.coroutines.CancellationException) throw e
            update { it.copy(error=message+" دریافت فهرست تازه ناموفق بود؛ فهرست را تازه کنید.") }
        }
    }
    fun submit(payload: JSONObject) = work {
        check(repository.vault.pending==null) { "ابتدا نتیجهٔ عملیات قبلی را بررسی کنید." }
        val s=mutable.value; val m=s.module!!; val id=s.record?.optString("id"); val key=UUID.randomUUID().toString()
        val receipt=newReceipt(key).put("entity",m.entity)
        repository.vault.pending=receipt.toString(); update { it.copy(pending=receipt.toString()) }
        try {
            val result=repository.request(path(m.entity+(id?.let { "/$it" } ?: "")),if(id==null) "POST" else "PUT",payload,
                buildMap { put("Idempotency-Key",key); s.etag?.let { put("If-Match",it) } })
            confirmed(m,result)
        } catch(e: ApiFailure) {
            // A structured server rejection with 4xx rolls back; unknown/server/network outcomes remain pending.
            if(e.definitive&&e.status in listOf(400,401,403,404,409,422,429)) { repository.vault.pending=null; update { it.copy(pending=null) } }
            throw e
        }
    }
    fun transition(action: String,reason: String) = work {
        check(repository.vault.pending==null)
        val s=mutable.value; val m=s.module!!; val key=UUID.randomUUID().toString()
        val receipt=newReceipt(key).put("entity",m.entity)
        repository.vault.pending=receipt.toString(); update { it.copy(pending=receipt.toString()) }
        try {
            val result=repository.request(path("${m.entity}/${s.record!!.getString("id")}/$action"),"POST",JSONObject().put("reason",reason),mapOf("Idempotency-Key" to key,"If-Match" to s.etag!!))
            confirmed(m,result)
        } catch(e: ApiFailure) {
            if(e.definitive&&e.status in listOf(400,401,403,404,409,422,429)) { repository.vault.pending=null; update { it.copy(pending=null) } }
            throw e
        }
    }
    fun checkPending() = work {
        val receipt=pendingReceipt()
        val result=repository.request("companies/${receipt.getString("company")}/operations/${receipt.getString("key")}")
        if(result.optString("state")=="committed") {
            repository.vault.pending=null; draft=null
            update { it.copy(pending=null,record=null,module=null,screen="dashboard",data=JSONObject(),error="عملیات در سرور ثبت قطعی شده است؛ اطلاعات را تازه کنید.") }
        } else if(result.optString("state")=="restored") {
            repository.vault.pending=null; draft=null
            update { it.copy(pending=null,record=null,module=null,screen="dashboard",data=JSONObject(),error="داده‌های شرکت از پشتیبان بازیابی شده‌اند. این رسید قدیمی قابل اتکا نیست؛ پیش از ثبت تازه وضعیت سند را در وب بررسی کنید.") }
        } else if(result.optString("state")=="cancelled") {
            repository.vault.pending=null; update { it.copy(pending=null,error="درخواست لغو شده و ثبت نخواهد شد.") }
        } else update { it.copy(error="هنوز رسید ثبت پیدا نشد. ممکن است درخواست در حال اجرا باشد؛ دوباره استعلام یا درخواست را لغو کنید. شناسه: ${receipt.getString("key")}") }
    }
    fun cancelPending() = work {
        val receipt=pendingReceipt()
        val result=repository.request("companies/${receipt.getString("company")}/operations/${receipt.getString("key")}","DELETE")
        if(result.optString("state") in listOf("cancelled","committed","restored")) {
            repository.vault.pending=null
            if(result.optString("state")=="committed"||result.optString("state")=="restored") {
                draft=null
                update { it.copy(pending=null,record=null,module=null,screen="dashboard",data=JSONObject(),error=if(result.optString("state")=="restored") "داده‌ها بازیابی شده‌اند؛ وضعیت سند قدیمی را پیش از ثبت تازه در وب بررسی کنید." else "عملیات قبلاً ثبت شده است؛ اطلاعات را تازه کنید.") }
            } else update { it.copy(pending=null,error="درخواست لغو شد؛ هیچ ثبت دیرهنگامی با این شناسه انجام نمی‌شود.") }
        }
    }
    suspend fun lookup(source: String,q: String,page: Int=1): JSONObject = repository.request(path("lookups/$source","&page=$page&q=${URLEncoder.encode(q,"UTF-8")}"))
    suspend fun barcode(code: String): JSONObject = repository.request(path("barcode","&code=${URLEncoder.encode(code,"UTF-8")}"))
    private fun reportQuery(page: Int,from: String,to: String,mode: String): String = "&page=$page&from=${URLEncoder.encode(from.ifEmpty { mutable.value.year!!.getString("start_date") },"UTF-8")}&to=${URLEncoder.encode(to.ifEmpty { mutable.value.year!!.getString("end_date") },"UTF-8")}&mode=$mode"
    fun reports(name: String="ledger",page: Int=1,from: String=mutable.value.reportFrom,to: String=mutable.value.reportTo,mode: String=mutable.value.reportMode) = work {
        val data=repository.request(path("reports/$name",reportQuery(page,from,to,mode)))
        update { it.copy(screen="report",data=data,module=null,record=null,report=name,reportPage=page,reportFrom=from,reportTo=to,reportMode=mode) }
    }
    fun export(format: String) = work {
        val s=mutable.value
        val data=repository.request(path("reports/${s.report}/export",reportQuery(s.reportPage,s.reportFrom,s.reportTo,s.reportMode)+"&format=$format"))
        PrivateFiles.share(getApplication(),data)
    }
    private fun target(): String = when(mutable.value.module?.entity) { "parties" -> "party"; "invoices" -> "invoice"; "vouchers" -> "voucher"; "expenses" -> "expense"; else -> error("نوع پیوست مجاز نیست.") }
    private suspend fun fetchAttachments() {
        val s=mutable.value; val data=repository.request(path("attachments/${target()}/${s.record!!.getString("id")}"))
        update { it.copy(screen="attachments",attachments=data.rows()) }
    }
    fun attachments() = work { fetchAttachments() }
    fun downloadAttachment(id: String) = work { PrivateFiles.share(getApplication(),repository.request(path("files/$id"))) }
    fun uploadAttachment(uri: android.net.Uri) = work {
        check(repository.vault.pending==null)
        val s=mutable.value; val body=PrivateFiles.upload(getApplication(),uri); val key=UUID.randomUUID().toString()
        val receipt=newReceipt(key)
        repository.vault.pending=receipt.toString(); update { it.copy(pending=receipt.toString()) }
        try {
            val result=repository.request(path("attachments/${target()}/${s.record!!.getString("id")}"),"POST",body,mapOf("Idempotency-Key" to key))
            ApiEnvelope.committed(result)
            repository.vault.pending=null; update { it.copy(pending=null,error="پیوست در سرور ثبت شد.") }
            try { fetchAttachments() } catch(e: Exception) { if(e is kotlinx.coroutines.CancellationException) throw e; update { it.copy(error="پیوست ثبت شد؛ دریافت فهرست ناموفق بود. پیوست را دوباره ارسال نکنید.") } }
        } catch(e: ApiFailure) {
            if(e.definitive&&e.status in listOf(400,401,403,404,409,413,422,429)) { repository.vault.pending=null; update { it.copy(pending=null) } }
            throw e
        }
    }
    fun devices() = work { val data=repository.request("auth/devices"); update { it.copy(screen="devices",data=data,module=null,record=null) } }
    fun revoke(id: String) = work { repository.request("auth/devices/$id","DELETE"); val data=repository.request("auth/devices"); update { it.copy(data=data) } }
    fun logout() = work { repository.logout(); update { UiState() } }
}
