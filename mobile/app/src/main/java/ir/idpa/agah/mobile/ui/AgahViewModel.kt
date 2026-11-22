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
    val reportPage: Int=1,val reportFrom: String="",val reportTo: String="",val reportMode: String="journal",
    val historySubject: JSONObject?=null,val historyPage: Int=1,val historyTitle: String=""
)
fun JSONObject.rows(key: String="rows"): List<JSONObject> = optJSONArray(key)?.let { a -> (0 until a.length()).mapNotNull { a.optJSONObject(it) } } ?: emptyList()
fun JSONObject.strings(key: String): List<String> = optJSONArray(key)?.let { a -> (0 until a.length()).map { a.optString(it) } } ?: emptyList()

data class LocalSecurityState(val enabled: Boolean=false,val locked: Boolean=false,val prompting: Boolean=false,val message: String?=null)

class AgahViewModel @JvmOverloads constructor(app: Application,val repository: AgahRepository=AgahRepository(SessionVault(app))): AndroidViewModel(app) {
    data class FormDraft(val key: String,val header: androidx.compose.runtime.snapshots.SnapshotStateMap<String,String>,val lines: androidx.compose.runtime.snapshots.SnapshotStateList<MutableMap<String,String>>) {
        val scanPending=androidx.compose.runtime.mutableIntStateOf(0)
        val scanErrors=mutableStateListOf<String>()
        val scanMutex=kotlinx.coroutines.sync.Mutex()
        val allocations=mutableStateListOf<MutableMap<String,String>>()
        val editingLine=androidx.compose.runtime.mutableStateOf<Int?>(null)
        val lineEntry=mutableStateMapOf<String,String>()
        val editingAllocation=androidx.compose.runtime.mutableStateOf<Int?>(null)
        val allocationEntry=mutableStateMapOf<String,String>()
    }
    data class InvoiceIssueDraft(val key: String) {
        val rows=mutableStateListOf<MutableMap<String,String>>()
        val entry=mutableStateMapOf<String,String>()
        val editing=androidx.compose.runtime.mutableStateOf<Int?>(null)
        val error=androidx.compose.runtime.mutableStateOf<String?>(null)
    }
    private var issueDraft: InvoiceIssueDraft?=null
    fun invoiceIssueDraft(): InvoiceIssueDraft {
        val s=mutable.value
        val key="${repository.userId}:${s.company?.optString("id")}:${s.year?.optString("id")}:${s.record?.optString("id")}:${s.etag}"
        if(issueDraft?.key!=key)issueDraft=InvoiceIssueDraft(key)
        return issueDraft!!
    }
    fun clearIssueDraft() {issueDraft=null}
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
    private val localSecurity=MutableStateFlow(LocalSecurityState(repository.vault.biometricEnabled,repository.vault.biometricEnabled))
    val security=localSecurity.asStateFlow()
    private var biometricRequest: BiometricRequest?=null
    private var securityEpoch=0L
    fun prepareBiometric(action: BiometricAction): BiometricRequest? {
        if(mutable.value.busy||localSecurity.value.prompting)return null
        return try {
            check(action==BiometricAction.UNLOCK || mutable.value.signedIn)
            repository.vault.prepareBiometric(action).also {biometricRequest=it;localSecurity.value=localSecurity.value.copy(prompting=true,message=null)}
        } catch(e: Exception) {localSecurity.value=localSecurity.value.copy(message=biometricFailureMessage(action,e));null}
    }
    fun cancelBiometric(request: BiometricRequest?,message: String?=null) {
        if(request!=null&&biometricRequest!==request)return
        biometricRequest=null;localSecurity.value=localSecurity.value.copy(prompting=false,message=message)
    }
    fun completeBiometric(request: BiometricRequest,cipher: javax.crypto.Cipher) {
        if(biometricRequest!==request)return
        biometricRequest=null
        try {
            repository.vault.completeBiometric(request,cipher)
            localSecurity.value=localSecurity.value.copy(enabled=repository.vault.biometricEnabled,prompting=false,message=null)
            if(request.action==BiometricAction.UNLOCK) {
                repository.resumeLocal()
                val epoch=securityEpoch
                work(allowLocked=true) {
                    check(repository.restore()) {"نشست پایان یافته است؛ با گذرواژه وارد شوید."}
                    if(mutable.value.signedIn) revalidateContext() else loadCompanies()
                    if(epoch==securityEpoch)localSecurity.value=localSecurity.value.copy(locked=false,message=null)
                }
            } else localSecurity.value=localSecurity.value.copy(locked=false,message=if(repository.vault.biometricEnabled) "ورود با اثر انگشت فعال شد." else "ورود با اثر انگشت غیرفعال شد.")
        } catch(e: Exception) {
            if(localSecurity.value.locked)repository.lockLocal()
            localSecurity.value=localSecurity.value.copy(enabled=repository.vault.biometricEnabled,prompting=false,message=biometricFailureMessage(request.action,e))
        }
    }
    fun lockForBackground() {
        securityEpoch++;biometricRequest=null
        if(repository.vault.biometricEnabled) {
            localSecurity.value=localSecurity.value.copy(enabled=true,locked=true,prompting=false,message=null)
            // An already-authorized write/rotation may finish; never replay or discard it on local lock.
            if(!mutable.value.busy)repository.lockLocal()
        } else localSecurity.value=localSecurity.value.copy(prompting=false)
    }
    override fun onCleared() {repository.lockLocal();super.onCleared()}
    fun usePasswordInstead() {
        if(mutable.value.busy)return
        securityEpoch++;biometricRequest=null;repository.forgetLocal();draft=null;issueDraft=null
        localSecurity.value=LocalSecurityState()
        update {UiState(error="برای ادامه با گذرواژهٔ حساب وارد شوید. رسیدهای نامشخص برای همان حساب نگه داشته می‌شوند.")}
    }
    private val mutable=MutableStateFlow(UiState(pending=repository.vault.pending))
    val state=mutable.asStateFlow()
    private fun update(fn: (UiState)->UiState) { mutable.value=fn(mutable.value) }
    private fun newReceipt(key: String): JSONObject = JSONObject().put("key",key).put("company",mutable.value.company!!.getString("id")).put("year",mutable.value.year!!.getString("id")).put("server",repository.vault.server).put("user",repository.userId)
    private fun pendingReceipt(): JSONObject {
        val value=JSONObject(repository.vault.pending ?: error("رسیدی برای استعلام نیست."))
        require(value.optString("server",repository.vault.server)==repository.vault.server&&value.optString("user",repository.userId.orEmpty())==repository.userId) { "رسید به حساب یا سرور دیگری تعلق دارد؛ با همان حساب و سرور وارد شوید." }
        return value
    }
    private fun work(allowLocked: Boolean=false,block: suspend ()->Unit) {
        if(mutable.value.busy||localSecurity.value.prompting||localSecurity.value.locked&&!allowLocked) return
        update { it.copy(busy=true,error=null) }
        viewModelScope.launch {
            try { block() }
            catch(e: Exception) {
                if(e is kotlinx.coroutines.CancellationException) throw e
                if(ir.idpa.agah.mobile.BuildConfig.DEBUG) android.util.Log.d("AgahDebug",e.javaClass.name+"\n"+e.stackTrace.take(12).joinToString("\n"))
                val message=if(e is ApiFailure) e.message+(if(e.fields.length()>0) "\n"+e.fields.keys().asSequence().map { key -> e.fields.optString(key) }.joinToString("\n") else "") else if(e is IllegalArgumentException) e.message else "ارتباط کامل نشد؛ در صورت ثبت، وضعیت عملیات را استعلام کنید."
                if(e is ApiFailure&&e.status==401&&e.definitive&&mutable.value.signedIn) {
                    draft=null; issueDraft=null
                    kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.IO) { getApplication<Application>().cacheDir.resolve("exports").deleteRecursively() }
                    update { UiState(busy=true,error="نشست پایان یافته است؛ دوباره وارد شوید. رسیدهای نامشخص برای همان حساب نگه داشته می‌شوند.") }
                } else update { it.copy(error=message) }
                if(localSecurity.value.locked)localSecurity.value=localSecurity.value.copy(message=message)
            } finally {
                if(!repository.vault.biometricEnabled)localSecurity.value=LocalSecurityState()
                else if(localSecurity.value.locked)repository.lockLocal()
                update { it.copy(busy=false) }
            }
        }
    }
    init { if(!repository.vault.biometricEnabled)work { if(repository.restore()) loadCompanies() } }
    fun login(server: String,user: String,password: String,factor: String) = work {
        repository.setServer(server); repository.login(user,password,factor); loadCompanies()
    }
    fun revalidate() {
        if(!mutable.value.signedIn) return
        work { revalidateContext() }
    }
    private suspend fun revalidateContext() {
            val company=mutable.value.company
            if(company==null) { loadCompanies();return }
            try {
                val context=repository.request("companies/${company.getString("id")}/context")
                val selected=mutable.value.year
                val year=selected?.let { chosen -> context.rows("years").firstOrNull { it.optString("id")==chosen.optString("id") } }
                if(selected!=null&&year==null) {
                    draft=null; issueDraft=null
                    update { it.copy(context=context,year=null,module=null,record=null,data=JSONObject(),screen="dashboard") }
                } else update { it.copy(context=context,year=year) }
            } catch(e: ApiFailure) {
                if(e.definitive&&e.status in setOf(403,404)) {
                    draft=null; issueDraft=null;loadCompanies()
                    update { it.copy(error="دسترسی این شرکت تغییر کرده است؛ دوباره از شرکت‌های مجاز انتخاب کنید.") }
                } else throw e
            }
    }
    private suspend fun loadCompanies() {
        val companies=repository.request("companies").rows()
        update { UiState(signedIn=true,busy=true,companies=companies,pending=repository.vault.pending) }
    }
    fun selectCompany(company: JSONObject) = work {
        val context=repository.request("companies/${company.getString("id")}/context")
        update { it.copy(company=company,context=context,year=null,data=JSONObject(),record=null,module=null,screen="dashboard") }
    }
    fun selectYear(year: JSONObject) {
        if(mutable.value.busy) return
        draft=null; issueDraft=null
        update { it.copy(year=year,data=JSONObject(),record=null,screen="dashboard",module=null,reportFrom="",reportTo="",reportPage=1) }
        dashboard()
    }
    fun changeCompany() { draft=null; issueDraft=null; update { it.copy(company=null,context=null,year=null,data=JSONObject(),record=null,module=null,attachments=emptyList(),historySubject=null) } }
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
    fun dashboardAt(date: String) = work {
        val data=repository.request(path("dashboard","&as_of=${URLEncoder.encode(date,"UTF-8")}"))
        update { it.copy(screen="dashboard",module=null,record=null,data=data) }
    }
    fun dashboardRange(from: String,to: String) = work {
        val data=repository.request(path("dashboard","&from=${URLEncoder.encode(from,"UTF-8")}&as_of=${URLEncoder.encode(to,"UTF-8")}"))
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
        draft=null; issueDraft=null
        update { it.copy(screen="form",record=if(new) null else it.record,etag=if(new) null else it.etag) }
    }
    fun back() {
        val s=mutable.value
        when(s.screen) { "history" -> if(s.module?.entity=="parties") update { it.copy(screen="detail") } else s.module?.let { openModule(it,s.page,s.query) }; "attachments" -> update { it.copy(screen="detail") }; "form" -> update { it.copy(screen=if(it.record==null) "list" else "detail") }; "detail" -> s.module?.let { openModule(it,s.page,s.query) }; else -> dashboard() }
    }
    fun dismissError() { update { it.copy(error=null) } }
    private suspend fun confirmed(module: Module,result: JSONObject) {
        ApiEnvelope.committed(result)
        repository.vault.pending=null; draft=null; issueDraft=null
        val message="ثبت با شناسهٔ ${result.getString("id")} در سرور انجام شد."
        // Exit the write form BEFORE the optional refresh; a failed GET cannot re-enable a completed form.
        update { it.copy(pending=null,record=null,screen="list",data=JSONObject(),error=message) }
        try {
            if(module.entity=="stock") loadModule(module)
            else {
                val detail=repository.request(path("${module.entity}/${result.getString("id")}"))
                val record=detail.getJSONObject("record");val etag=detail.getString("etag")
                update { it.copy(screen="detail",module=module,record=record,etag=etag) }
            }
        }
        catch(e: Exception) {
            if(e is kotlinx.coroutines.CancellationException) throw e
            update { it.copy(error=message+" دریافت اطلاعات تازه ناموفق بود؛ فهرست را تازه کنید.") }
        }
    }
    fun submit(payload: JSONObject) = work {
        check(repository.vault.pending==null) { "ابتدا نتیجهٔ عملیات قبلی را بررسی کنید." }
        val s=mutable.value; val m=s.module!!;
        if(m.entity=="invoices") {val d=formDraft(m,s.record);check(d.scanPending.intValue==0&&d.scanErrors.isEmpty()) {"ابتدا خواندن بارکدها و بررسی خطاها را تمام کنید."}}
        val id=s.record?.optString("id"); val key=UUID.randomUUID().toString()
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
    fun transition(action: String,reason: String,payments: org.json.JSONArray?=null) = work {
        if(payments!=null&&payments.length()>0) require(mutable.value.context?.optJSONObject("features")?.optBoolean("mixed_settlement")==true) {"افزونهٔ API موبایل را به‌روزرسانی کنید."}
        check(repository.vault.pending==null)
        val s=mutable.value; val m=s.module!!; val key=UUID.randomUUID().toString()
        val receipt=newReceipt(key).put("entity",m.entity)
        repository.vault.pending=receipt.toString(); update { it.copy(pending=receipt.toString()) }
        try {
            val result=repository.request(path("${m.entity}/${s.record!!.getString("id")}/$action"),"POST",JSONObject().put("reason",reason).apply {if(payments!=null) put("payment_items",payments)},mapOf("Idempotency-Key" to key,"If-Match" to s.etag!!))
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
            val confirmed=ApiEnvelope.committed(result.optJSONObject("result") ?: JSONObject())
            repository.vault.pending=null; draft=null; issueDraft=null
            update { it.copy(pending=null,record=null,module=null,screen="dashboard",data=JSONObject(),error="عملیات با شناسهٔ ${confirmed.getString("id")} در سرور ثبت قطعی شده است؛ اطلاعات را تازه کنید.") }
        } else if(result.optString("state")=="restored") {
            repository.vault.pending=null; draft=null; issueDraft=null
            update { it.copy(pending=null,record=null,module=null,screen="dashboard",data=JSONObject(),error="داده‌های شرکت از پشتیبان بازیابی شده‌اند. این رسید قدیمی قابل اتکا نیست؛ پیش از ثبت تازه وضعیت سند را در وب بررسی کنید.") }
        } else if(result.optString("state")=="cancelled") {
            repository.vault.pending=null; update { it.copy(pending=null,error="درخواست لغو شده و ثبت نخواهد شد.") }
        } else update { it.copy(error="هنوز رسید ثبت پیدا نشد. ممکن است درخواست در حال اجرا باشد؛ دوباره استعلام یا درخواست را لغو کنید. شناسه: ${receipt.getString("key")}") }
    }
    fun cancelPending() = work {
        val receipt=pendingReceipt()
        val result=repository.request("companies/${receipt.getString("company")}/operations/${receipt.getString("key")}","DELETE")
        if(result.optString("state") in listOf("cancelled","committed","restored")) {
            if(result.optString("state")=="committed") ApiEnvelope.committed(result.optJSONObject("result") ?: JSONObject())
            repository.vault.pending=null
            if(result.optString("state")=="committed"||result.optString("state")=="restored") {
                draft=null; issueDraft=null
                update { it.copy(pending=null,record=null,module=null,screen="dashboard",data=JSONObject(),error=if(result.optString("state")=="restored") "داده‌ها بازیابی شده‌اند؛ وضعیت سند قدیمی را پیش از ثبت تازه در وب بررسی کنید." else "عملیات قبلاً ثبت شده است؛ اطلاعات را تازه کنید.") }
            } else update { it.copy(pending=null,error="درخواست لغو شد؛ هیچ ثبت دیرهنگامی با این شناسه انجام نمی‌شود.") }
        }
    }
    suspend fun lookup(source: String,q: String,page: Int=1): JSONObject {
        val extra=if(source in setOf("sale_invoices","purchase_invoices")) {
            val party=draft?.header?.get("party_id").orEmpty()
            require(party.isNotBlank()&&party!="0") { "ابتدا شخص دریافت یا پرداخت را انتخاب کنید." }
            "&party_id=$party"
        } else ""
        return repository.request(path("lookups/$source","&page=$page&q=${URLEncoder.encode(q,"UTF-8")}"+extra))
    }
    suspend fun lookupLabel(source: String,id: String): JSONObject = repository.request(path("lookups/$source/$id"))
    suspend fun barcode(code: String): JSONObject = repository.request(path("barcode","&code=${URLEncoder.encode(code,"UTF-8")}"))
    suspend fun entryItem(id: String,warehouse: String): JSONObject {
        require(mutable.value.context?.optJSONObject("features")?.optBoolean("invoice_entry")==true) {"افزونهٔ API موبایل را به 0.4.0-rc1 به‌روزرسانی کنید."}
        return repository.request(path("invoice-entry-item","&kind=${mutable.value.module!!.kind}&item_id=$id&warehouse_id=$warehouse"))
    }
    fun scanInvoice(code: String,warehouse: String,byId: Boolean=false) {
        val s=mutable.value;val module=s.module ?: return
        if(module.entity!="invoices"||s.screen!="form"||s.pending!=null) return
        val draft=formDraft(module,s.record)
        draft.scanPending.intValue++
        viewModelScope.launch {
            draft.scanMutex.lock()
            try {
                if(this@AgahViewModel.draft!==draft)return@launch
                val item=if(byId) JSONObject().put("id",code) else barcode(ExactValues.normalize(code).trim())
                if(this@AgahViewModel.draft!==draft||mutable.value.company?.optString("id")!=s.company?.optString("id")||mutable.value.year?.optString("id")!=s.year?.optString("id"))return@launch
                val data=entryItem(item.getString("id"),warehouse)
                if(this@AgahViewModel.draft!==draft)return@launch
                val updated=InvoiceEntry.add(draft.lines,data,warehouse,scale)
                draft.lines.clear();draft.lines.addAll(updated)
            } catch(e: Exception) {
                if(e is kotlinx.coroutines.CancellationException)throw e
                draft.scanErrors.add("بارکد $code: "+(e.message ?: "دریافت کالا ناموفق بود."))
            } finally {draft.scanPending.intValue--;draft.scanMutex.unlock()}
        }
    }
    fun stockHistory(subject: JSONObject,page: Int=1) = work {
        val data=repository.request(path("reports/kardex","&warehouse_id=${subject.getString("warehouse_id")}&item_id=${subject.getString("item_id")}&page=$page"))
        update { it.copy(screen="history",data=data,historySubject=subject,historyPage=page,historyTitle="گردش ${subject.optString("item_name")} — ${subject.optString("warehouse_name")}") }
    }
    fun partyHistory(page: Int=1) = work {
        val subject=mutable.value.record!!
        val data=repository.request(path("reports/party_statement","&party_id=${subject.getString("id")}&page=$page"))
        update { it.copy(screen="history",data=data,historySubject=subject,historyPage=page,historyTitle="گردش حساب ${subject.optString("name")}") }
    }
    private fun reportQuery(page: Int,from: String,to: String,mode: String): String = "&page=$page&from=${URLEncoder.encode(from.ifEmpty { mutable.value.year!!.getString("start_date") },"UTF-8")}&to=${URLEncoder.encode(to.ifEmpty { mutable.value.year!!.getString("end_date") },"UTF-8")}&mode=$mode"
    fun reports(name: String="ledger",page: Int=1,from: String=mutable.value.reportFrom,to: String=mutable.value.reportTo,mode: String=mutable.value.reportMode) = work {
        val year=mutable.value.year!!
        val start=from.ifEmpty { year.getString("start_date") };val end=to.ifEmpty { year.getString("end_date") }
        require(start>=year.getString("start_date")&&end<=year.getString("end_date")&&start<=end) { "بازهٔ گزارش باید داخل سال مالی انتخاب‌شده و با ترتیب درست باشد." }
        val data=repository.request(path("reports/$name",reportQuery(page,start,end,mode)))
        update { it.copy(screen="report",data=data,module=null,record=null,report=name,reportPage=page,reportFrom=start,reportTo=end,reportMode=mode) }
    }
    fun export(format: String) = work {
        val s=mutable.value
        val data=repository.request(path("reports/${s.report}/export",reportQuery(s.reportPage,s.reportFrom,s.reportTo,s.reportMode)+"&format=$format"))
        PrivateFiles.share(getApplication(),data)
    }
    fun exportDocument(layout: String="standard") = work {
        val s=mutable.value
        if(layout!="standard") require(s.context?.optJSONObject("features")?.optBoolean("invoice_layouts")==true) {"برای قالب چاپ جدید، افزونهٔ API موبایل را به‌روزرسانی کنید."}
        val data=repository.request(path("${s.module!!.entity}/${s.record!!.getString("id")}/export","&layout=${URLEncoder.encode(layout,"UTF-8")}"))
        PrivateFiles.share(getApplication(),data)
    }
    private fun target(): String = when(mutable.value.module?.entity) { "parties" -> "party"; "invoices" -> "invoice"; "vouchers" -> "voucher"; "expenses" -> "expense"; else -> error("نوع پیوست مجاز نیست.") }
    private suspend fun fetchAttachments() {
        val s=mutable.value;val target=target()
        val data=repository.request(path("attachments/$target/${s.record!!.getString("id")}",if(target=="expense") "&include_expense_images=1" else ""))
        update { it.copy(screen="attachments",attachments=data.rows()) }
    }
    fun attachments() = work { fetchAttachments() }
    fun downloadAttachment(id: String,expenseImage: Boolean=false) = work { PrivateFiles.share(getApplication(),repository.request(path("${if(expenseImage) "expense-images" else "files"}/$id"))) }
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
    fun logout() = work {
        val revoked=repository.logout();draft=null; issueDraft=null
        kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.IO) { getApplication<Application>().cacheDir.resolve("exports").deleteRecursively() }
        update { UiState(busy=true,error=if(revoked) null else "خروج روی این دستگاه انجام شد؛ ابطال نشست سرور تأیید نشد. پس از اتصال، نشست‌های فعال را بررسی کنید.") }
    }
}
