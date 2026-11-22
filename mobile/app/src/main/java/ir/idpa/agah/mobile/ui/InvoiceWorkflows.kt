package ir.idpa.agah.mobile.ui

import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Modifier
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.input.key.*
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.unit.dp
import com.journeyapps.barcodescanner.ScanContract
import com.journeyapps.barcodescanner.ScanOptions
import org.json.JSONArray

@Composable internal fun InvoiceQuickEntry(vm: AgahViewModel,s: UiState,draft: AgahViewModel.FormDraft,online: Boolean) {
    var code by rememberSaveable {mutableStateOf("")}
    var picked by rememberSaveable {mutableStateOf("")}
    val focus=remember {FocusRequester()}
    val warehouse=draft.header["_quick_warehouse_id"].orEmpty().ifBlank {"0"}
    fun scan(text: String) {if(text.isNotBlank()) {vm.scanInvoice(text,warehouse);code=""}}
    val camera=rememberLauncherForActivityResult(ScanContract()) {result -> result.contents?.let {scan(it)}}
    OutlinedCard(Modifier.fillMaxWidth()) { Column(Modifier.padding(16.dp),verticalArrangement=Arrangement.spacedBy(12.dp)) {
        SectionTitle("ورود سریع کالا","اسکن و Enter؛ قیمت از کاتالوگ می‌آید. صدور قطعی در گام بعد است.")
        FormField(Field("_quick_warehouse_id","انبار ورود سریع",InputKind.Lookup,"warehouses"),warehouse,{draft.header["_quick_warehouse_id"]=it},vm)
        OutlinedTextField(code,{code=it},label={Text("بارکد کالا")},singleLine=true,keyboardOptions=KeyboardOptions(imeAction=ImeAction.Done),keyboardActions=KeyboardActions(onDone={scan(code)}),enabled=online&&!s.busy,modifier=Modifier.fillMaxWidth().focusRequester(focus).onPreviewKeyEvent {if(it.key==Key.Enter&&it.type==KeyEventType.KeyDown){scan(code);true}else false})
        AdaptiveActions {
            Button(onClick={scan(code);focus.requestFocus()},enabled=online&&!s.busy&&code.isNotBlank()) {Text("افزودن با بارکد")}
            OutlinedButton(onClick={camera.launch(ScanOptions().setPrompt("اسکن کالا برای فاکتور").setBeepEnabled(false).setOrientationLocked(false))},enabled=online&&!s.busy) {Text("اسکن با دوربین")}
        }
        FormField(Field("quick_item","نام یا کد کالا",InputKind.Lookup,"items"),picked,{picked=it},vm)
        OutlinedButton(onClick={vm.scanInvoice(picked,warehouse,true);picked=""},enabled=online&&!s.busy&&picked.isNotBlank()&&picked!="0",modifier=Modifier.fillMaxWidth()) {Text("افزودن کالای انتخاب‌شده")}
        if(draft.scanPending.intValue>0) InfoBanner("در حال خواندن ${draft.scanPending.intValue} کالا؛ پیش از ذخیره منتظر بمانید.")
        if(draft.scanErrors.isNotEmpty()) {
            draft.scanErrors.forEach {InfoBanner(it,true)}
            TextButton(onClick={draft.scanErrors.clear()},enabled=draft.scanPending.intValue==0) {Text("خطاهای ورود کالا را بررسی کردم")}
        }
    } }
}

@Composable internal fun InvoiceIssueDialog(vm: AgahViewModel,s: UiState,online: Boolean,onDismiss:()->Unit) {
    val draft=vm.invoiceIssueDraft()
    val rows=draft.rows
    var editing by draft.editing
    val entry=draft.entry
    var error by draft.error
    val paymentCap=if(s.module?.kind=="purchase") "payment.create" else "receipt.create"
    val canPay=vm.canWrite(paymentCap)&&s.context?.optJSONObject("features")?.optBoolean("mixed_settlement")==true
    AlertDialog(onDismissRequest={vm.clearIssueDraft();onDismiss()},title={Text("صدور و تسویهٔ فاکتور")},text={LazyColumn(verticalArrangement=Arrangement.spacedBy(12.dp)) {
        item {InfoBanner("بدون روش تسویه، فاکتور نسیه صادر می‌شود. می‌توانید چند روش برای تسویهٔ ترکیبی ثبت کنید.")}
        items(rows.indices.toList()) {i -> OutlinedCard { Column(Modifier.padding(12.dp)) {
            Text("روش ${i+1}: "+(mapOf("cash" to "نقد","card" to "پوز / کارت","transfer" to "حواله","cheque" to "چک")[rows[i]["method"]] ?: "روش تسویه")+" • "+rows[i]["amount"].orEmpty()+" "+vm.unit)
            AdaptiveActions {TextButton(onClick={entry.clear();entry.putAll(rows[i]);editing=i}) {Text("ویرایش")};TextButton(onClick={rows.removeAt(i)}) {Text("حذف روش")}}
        } } }
        if(canPay) item {OutlinedButton(onClick={entry.clear();entry["method"]="cash";entry["due_date"]=s.record!!.getString("invoice_date");editing=rows.size}) {Text("افزودن روش تسویه")}}
        if(!canPay) item {Text("ثبت تسویه به مجوز مستقل دریافت یا پرداخت نیاز دارد؛ صدور نسیه مجاز است.")}
        if(error!=null) item {InfoBanner(error!!,true)}
    }},confirmButton={TextButton(onClick={runCatching {if(rows.isNotEmpty()) require(canPay) {"مجوز ثبت تسویه را ندارید؛ روش‌های پرداخت حفظ شدند."};Forms.issuePayments(rows,vm.scale)}.onSuccess {vm.transition("issue","",it.takeIf {a->a.length()>0});onDismiss()}.onFailure {error=it.message}},enabled=online&&!s.busy&&s.pending==null) {Text(if(rows.isEmpty()) "صدور نسیه" else "صدور و ثبت تسویه")}},dismissButton={TextButton(onClick={vm.clearIssueDraft();onDismiss()}) {Text("انصراف")}})
    if(editing!=null) {
        val fields=Forms.lines(modules.first {it.entity=="treasury"}).filterNot {entry["method"]!="cheque"&&it.key in setOf("cheque_no","bank_name","due_date")}
        AlertDialog(onDismissRequest={editing=null},title={Text("روش تسویه")},text={LazyColumn(verticalArrangement=Arrangement.spacedBy(10.dp)) {if(error!=null) item {InfoBanner(error!!,true)};items(fields) {f -> FormField(f,entry[f.key].orEmpty(),{entry[f.key]=it},vm)}}},confirmButton={TextButton(onClick={runCatching {Forms.issuePayments(listOf(entry),vm.scale)}.onSuccess {val i=editing!!;if(i<rows.size)rows[i]=entry.toMutableMap() else rows.add(entry.toMutableMap());editing=null;error=null}.onFailure {error=it.message}},enabled=canPay) {Text("ثبت روش")}},dismissButton={TextButton(onClick={editing=null}) {Text("انصراف")}})
    }
}
