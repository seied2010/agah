package ir.idpa.agah.mobile.ui

import android.content.Intent
import android.icu.util.Calendar
import android.icu.util.ULocale
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items as gridItems
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.font.Font
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import ir.idpa.agah.mobile.data.ExactValues
import ir.idpa.agah.mobile.R
import com.journeyapps.barcodescanner.ScanContract
import com.journeyapps.barcodescanner.ScanOptions
import kotlinx.coroutines.launch
import org.json.JSONObject
import java.time.LocalDate

private val Green=Color(0xFF246B52)
private val AgahFont=FontFamily(Font(R.font.vazirmatn))
private val AgahTypography=Typography().let { t -> Typography(
    displayLarge=t.displayLarge.copy(fontFamily=AgahFont),displayMedium=t.displayMedium.copy(fontFamily=AgahFont),displaySmall=t.displaySmall.copy(fontFamily=AgahFont),
    headlineLarge=t.headlineLarge.copy(fontFamily=AgahFont),headlineMedium=t.headlineMedium.copy(fontFamily=AgahFont),headlineSmall=t.headlineSmall.copy(fontFamily=AgahFont),
    titleLarge=t.titleLarge.copy(fontFamily=AgahFont),titleMedium=t.titleMedium.copy(fontFamily=AgahFont),titleSmall=t.titleSmall.copy(fontFamily=AgahFont),
    bodyLarge=t.bodyLarge.copy(fontFamily=AgahFont),bodyMedium=t.bodyMedium.copy(fontFamily=AgahFont),bodySmall=t.bodySmall.copy(fontFamily=AgahFont),
    labelLarge=t.labelLarge.copy(fontFamily=AgahFont),labelMedium=t.labelMedium.copy(fontFamily=AgahFont),labelSmall=t.labelSmall.copy(fontFamily=AgahFont)) }
private val labels=mapOf("name" to "نام","code" to "کد","number" to "شماره","status" to "وضعیت","description" to "شرح","amount" to "مبلغ","total_debit" to "جمع بدهکار","total_credit" to "جمع بستانکار","debit" to "بدهکار","credit" to "بستانکار","balance" to "مانده","outstanding" to "تسویه‌نشده","party_name" to "شخص","invoice_date" to "تاریخ فاکتور","doc_date" to "تاریخ","voucher_date" to "تاریخ سند","due_date" to "سررسید","phone" to "تلفن","email" to "ایمیل","address" to "نشانی","barcode" to "بارکد","sale_price" to "قیمت فروش","purchase_price" to "قیمت خرید","qty_scaled" to "مقدار","qty" to "موجودی","warehouse_name" to "انبار","item_name" to "کالا","account_name" to "حساب","detail_name" to "تفصیلی","net" to "سود خالص")
private val monetary=setOf("amount","debit","credit","total_debit","total_credit","balance","outstanding","sale_price","purchase_price","trade_price","unit_price","net","opening","tax_amount","discount_amount")
fun persianDate(iso: String): String = runCatching {
    val date=LocalDate.parse(iso.take(10)); val c=Calendar.getInstance(ULocale("fa_IR@calendar=persian"))
    c.timeInMillis=date.atTime(12,0).atZone(java.time.ZoneId.of("Asia/Tehran")).toInstant().toEpochMilli()
    "${c.get(Calendar.YEAR)}/${(c.get(Calendar.MONTH)+1).toString().padStart(2,'0')}/${c.get(Calendar.DAY_OF_MONTH).toString().padStart(2,'0')}"
}.getOrDefault(iso)
private fun shown(key: String,value: String,scale: Long,unit: String): String = when {
    key.endsWith("_date") -> persianDate(value)
    key in monetary -> ExactValues.display(value,scale)+" "+unit
    key in setOf("qty_scaled","qty") -> ExactValues.display(value,10000)
    key=="status" -> mapOf("draft" to "پیش‌نویس","issued" to "صادرشده","approved" to "تأییدشده","final" to "قطعی","void" to "باطل‌شده")[value] ?: value
    else -> value
}

@Composable fun AgahApp(vm: AgahViewModel) {
    val s by vm.state.collectAsStateWithLifecycle()
    var dark by rememberSaveable { mutableStateOf(false) }
    val context=LocalContext.current
    var online by remember { mutableStateOf(true) }
    DisposableEffect(Unit) {
        val manager=context.getSystemService(ConnectivityManager::class.java)
        fun update() { online=manager.getNetworkCapabilities(manager.activeNetwork)?.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)==true }
        val callback=object: ConnectivityManager.NetworkCallback() {
            override fun onAvailable(network: android.net.Network) { update() }
            override fun onLost(network: android.net.Network) { update() }
            override fun onCapabilitiesChanged(network: android.net.Network,caps: NetworkCapabilities) { update() }
        }
        manager.registerDefaultNetworkCallback(callback); update()
        onDispose { manager.unregisterNetworkCallback(callback) }
    }
    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Rtl) {
        MaterialTheme(typography=AgahTypography,colorScheme=if(dark) darkColorScheme(primary=Color(0xFF9BD4B8)) else lightColorScheme(primary=Green,background=Color(0xFFF4F7F5))) {
            Surface(Modifier.fillMaxSize()) {
                Column(Modifier.fillMaxSize().safeDrawingPadding()) {
                    Row(Modifier.fillMaxWidth().padding(horizontal=16.dp,vertical=8.dp),verticalAlignment=Alignment.CenterVertically) {
                        Text("آگاه",style=MaterialTheme.typography.headlineMedium,fontWeight=FontWeight.Bold,modifier=Modifier.weight(1f))
                        IconButton(onClick={dark=!dark}) { Icon(if(dark) Icons.Default.LightMode else Icons.Default.DarkMode,"تغییر روشنایی") }
                        if(s.signedIn) IconButton(onClick=vm::logout,enabled=!s.busy&&online) { Icon(Icons.Default.Logout,"خروج امن") }
                    }
                    if(s.busy) LinearProgressIndicator(Modifier.fillMaxWidth())
                    if(!online) Text("ارتباط اینترنت قطع است؛ ثبت اطلاعات غیرفعال است.",color=MaterialTheme.colorScheme.error,modifier=Modifier.padding(12.dp))
                    if(s.pending!=null&&s.signedIn) OutlinedButton(onClick=vm::checkPending,enabled=!s.busy&&online,modifier=Modifier.padding(8.dp).fillMaxWidth()) { Text("نتیجهٔ ثبت قبلی نامشخص است؛ استعلام") }
                    if(s.pending!=null&&s.signedIn) TextButton(onClick=vm::cancelPending,enabled=!s.busy&&online,modifier=Modifier.fillMaxWidth()) { Text("تعیین تکلیف و لغو درخواستِ ثبت‌نشده") }
                    when {
                        !s.signedIn -> Login(vm,s.busy)
                        s.company==null -> Selection("شرکت را انتخاب کنید",s.companies,"name",vm::selectCompany)
                        s.year==null -> Selection("سال مالی ${s.company!!.optString("name")}",s.context?.rows("years") ?: emptyList(),"jy",vm::selectYear)
                        else -> {
                            Row(Modifier.fillMaxWidth().padding(horizontal=16.dp),verticalAlignment=Alignment.CenterVertically) {
                                TextButton(onClick=vm::changeCompany,enabled=!s.busy) { Text(s.company!!.optString("name")) }
                                Text(" • ${s.year!!.optString("jy")} • ${vm.unit}",style=MaterialTheme.typography.labelMedium)
                                Spacer(Modifier.weight(1f))
                                if(s.screen!="dashboard") IconButton(onClick=vm::back,enabled=!s.busy) { Icon(Icons.AutoMirrored.Filled.ArrowBack,"بازگشت") }
                            }
                            if(s.context?.optString("mode")!="write") Text("حالت فقط مشاهده",modifier=Modifier.padding(horizontal=24.dp),color=MaterialTheme.colorScheme.primary)
                            Box(Modifier.weight(1f)) {
                                when(s.screen) {
                                    "dashboard" -> Dashboard(vm,s)
                                    "list" -> Listing(vm,s,online)
                                    "detail" -> Detail(vm,s,online)
                                    "form" -> FormScreen(vm,s,online)
                                    "report" -> ReportScreen(vm,s)
                                    "devices" -> DeviceScreen(vm,s)
                                }
                            }
                        }
                    }
                }
            }
            if(s.error!=null) AlertDialog(onDismissRequest=vm::dismissError,title={Text("پیام سامانه")},text={Text(s.error!!)},confirmButton={TextButton(onClick=vm::dismissError) { Text("متوجه شدم") }})
            BackHandler(s.signedIn&&s.company!=null&&s.screen!="dashboard"&&!s.busy) { vm.back() }
        }
    }
}
@Composable private fun Login(vm: AgahViewModel,busy: Boolean) {
    var server by rememberSaveable { mutableStateOf(vm.repository.vault.server) }
    var user by rememberSaveable { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var factor by remember { mutableStateOf("") }
    val context=LocalContext.current
    LazyColumn(Modifier.fillMaxWidth().widthIn(max=520.dp).padding(24.dp),verticalArrangement=Arrangement.spacedBy(14.dp)) {
        item { Text("حسابداری همیشه همراه شما",style=MaterialTheme.typography.headlineSmall); Text("ورود به حساب آنلاین آگاه؛ اطلاعات مالی فقط روی سرور ثبت می‌شوند.") }
        item { OutlinedTextField(server,{server=it},label={Text("نشانی سرور")},singleLine=true,modifier=Modifier.fillMaxWidth()) }
        item { OutlinedTextField(user,{user=it},label={Text("نام کاربری یا ایمیل")},singleLine=true,modifier=Modifier.fillMaxWidth()) }
        item { OutlinedTextField(password,{password=it},label={Text("گذرواژه")},visualTransformation=PasswordVisualTransformation(),singleLine=true,modifier=Modifier.fillMaxWidth()) }
        item { OutlinedTextField(factor,{factor=it},label={Text("کد دومرحله‌ای (اگر فعال است)")},singleLine=true,modifier=Modifier.fillMaxWidth()) }
        item { Button(onClick={vm.login(server,user,password,factor); password=""; factor=""},enabled=!busy&&user.isNotBlank()&&password.isNotEmpty(),modifier=Modifier.fillMaxWidth()) { Text("ورود امن") } }
        item { TextButton(onClick={ runCatching { val uri=Uri.parse(server); if(uri.scheme=="https") context.startActivity(Intent(Intent.ACTION_VIEW,Uri.parse(server.trimEnd('/')+"/wp-login.php?action=lostpassword"))) } }) { Text("بازیابی گذرواژه در سامانه") } }
    }
}
@Composable private fun Selection(title: String,rows: List<JSONObject>,label: String,onPick:(JSONObject)->Unit) {
    LazyColumn(Modifier.fillMaxSize().padding(20.dp),verticalArrangement=Arrangement.spacedBy(12.dp)) {
        item { Text(title,style=MaterialTheme.typography.titleLarge) }
        if(rows.isEmpty()) item { Text("مورد مجازی برای این حساب یافت نشد.") }
        items(rows) { row -> Card(onClick={onPick(row)},modifier=Modifier.fillMaxWidth()) { Text(row.optString(label),Modifier.padding(24.dp),style=MaterialTheme.typography.titleMedium) } }
    }
}
@Composable private fun Dashboard(vm: AgahViewModel,s: UiState) {
    val cards=s.data.optJSONObject("cards") ?: JSONObject()
    val visible=modules.filter { vm.can(it.capability+".view") }
    LazyVerticalGrid(GridCells.Adaptive(280.dp),Modifier.fillMaxSize().padding(16.dp),horizontalArrangement=Arrangement.spacedBy(12.dp),verticalArrangement=Arrangement.spacedBy(12.dp)) {
        item { Column { Text("نمای کلی ${s.company!!.optString("name")}",style=MaterialTheme.typography.titleLarge); Text("آخرین دادهٔ دریافت‌شده • ${persianDate(s.data.optString("date"))}"); TextButton(onClick=vm::dashboard,enabled=!s.busy) { Text("تازه‌سازی اطلاعات") } } }
        gridItems(cards.keys().asSequence().toList()) { key -> Card(Modifier.fillMaxWidth()) { Column(Modifier.padding(20.dp)) { Text(key); Text(ExactValues.display(cards.optString(key),vm.scale)+" "+vm.unit,style=MaterialTheme.typography.titleLarge,color=MaterialTheme.colorScheme.primary) } } }
        gridItems(visible) { m -> ElevatedCard(onClick={vm.openModule(m)},modifier=Modifier.fillMaxWidth(),enabled=!s.busy) { Row(Modifier.padding(22.dp),verticalAlignment=Alignment.CenterVertically) { Icon(Icons.Default.FolderOpen,null); Spacer(Modifier.width(12.dp)); Text(m.title,style=MaterialTheme.typography.titleMedium) } } }
        if(vm.can("report.view")) item { OutlinedButton(onClick={vm.reports()},modifier=Modifier.fillMaxWidth()) { Text("گزارش‌های مالی") } }
        item { OutlinedButton(onClick=vm::devices,modifier=Modifier.fillMaxWidth()) { Text("دستگاه‌های فعال") } }
    }
}
@Composable private fun Listing(vm: AgahViewModel,s: UiState,online: Boolean) {
    val module=s.module!!; var q by remember(module) { mutableStateOf(s.query) }
    Column(Modifier.fillMaxSize().padding(16.dp),verticalArrangement=Arrangement.spacedBy(8.dp)) {
        Text(module.title,style=MaterialTheme.typography.titleLarge)
        Row(verticalAlignment=Alignment.CenterVertically) {
            OutlinedTextField(q,{q=it},label={Text("جست‌وجو")},modifier=Modifier.weight(1f),singleLine=true)
            IconButton(onClick={vm.openModule(module,1,q)},enabled=!s.busy) { Icon(Icons.Default.Search,"جست‌وجو") }
            if(vm.canWrite(module.capability+".create")) IconButton(onClick={vm.edit(true)},enabled=online&&!s.busy&&s.pending==null) { Icon(Icons.Default.Add,"ثبت جدید") }
        }
        val rows=s.data.rows()
        if(rows.isEmpty()) Text("اطلاعاتی در این فهرست وجود ندارد.",Modifier.padding(20.dp))
        LazyColumn(Modifier.weight(1f),verticalArrangement=Arrangement.spacedBy(10.dp)) {
            items(rows) { record -> Card(onClick={if(module.entity!="stock") vm.openRecord(record)},enabled=!s.busy,modifier=Modifier.fillMaxWidth()) {
                Column(Modifier.padding(16.dp),verticalArrangement=Arrangement.spacedBy(6.dp)) {
                    val title = record.optString("name").ifEmpty { record.optString("party_name") }
                        .ifEmpty { record.optString("item_name") }.ifEmpty { "شماره ${record.optString("number",record.optString("id"))}" }
                    Text(title,fontWeight=FontWeight.Bold)
                    RecordFields(record,vm.scale,vm.unit,limit=5)
                }
            } }
        }
        Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.SpaceBetween,verticalAlignment=Alignment.CenterVertically) {
            TextButton(onClick={vm.openModule(module,s.page-1,s.query)},enabled=s.page>1&&!s.busy) { Text("قبلی") }
            Text("صفحه ${s.page} از ${s.data.optString("pages","1")}")
            TextButton(onClick={vm.openModule(module,s.page+1,s.query)},enabled=s.page<s.data.optInt("pages",1)&&!s.busy) { Text("بعدی") }
        }
    }
}
@Composable private fun RecordFields(record: JSONObject,scale: Long,unit: String,limit: Int=100) {
    record.keys().asSequence().filter { it in labels && !record.isNull(it) && record.opt(it) !is JSONObject && record.opt(it) !is org.json.JSONArray }.take(limit).forEach { key ->
        Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.spacedBy(12.dp)) {
            Text(labels[key]!!,modifier=Modifier.weight(.35f),color=MaterialTheme.colorScheme.onSurfaceVariant)
            Text(shown(key,record.optString(key),scale,unit),modifier=Modifier.weight(.65f))
        }
    }
}
@Composable private fun Detail(vm: AgahViewModel,s: UiState,online: Boolean) {
    val m=s.module!!; val r=s.record!!
    var action by remember { mutableStateOf<String?>(null) }; var reason by remember { mutableStateOf("") }
    LazyColumn(Modifier.fillMaxSize().padding(20.dp),verticalArrangement=Arrangement.spacedBy(14.dp)) {
        item { Text(m.title,style=MaterialTheme.typography.titleLarge); RecordFields(r,vm.scale,vm.unit) }
        items(r.rows("lines")+r.rows("items")) { line -> OutlinedCard { Column(Modifier.padding(14.dp)) { RecordFields(line,vm.scale,vm.unit) } } }
        if(m.entity in setOf("parties","items","invoices","vouchers")&&vm.canWrite(m.capability+".edit")) item { Button(onClick={vm.edit()},enabled=online&&!s.busy&&s.pending==null) { Text("ویرایش") } }
        if(m.entity=="invoices"&&r.optString("status")=="draft"&&vm.canWrite(m.capability+".create")) item { Button(onClick={action="issue"},enabled=online&&!s.busy&&s.pending==null) { Text("صدور قطعی فاکتور") } }
        if(m.entity=="vouchers") items(listOf("approve" to "تأیید سند","finalize" to "قطعی‌کردن","reopen" to "بازگشایی","void" to "ابطال")) { (a,title) ->
            if(vm.canWrite("voucher.$a")) OutlinedButton(onClick={action=a},enabled=online&&!s.busy&&s.pending==null) { Text(title) }
        }
        if(m.entity in setOf("invoices","treasury","expenses")&&vm.canWrite(m.capability+".delete")) item { OutlinedButton(onClick={action="void"},enabled=online&&!s.busy&&s.pending==null) { Text("ابطال با ثبت دلیل") } }
    }
    if(action!=null) AlertDialog(onDismissRequest={action=null},title={Text("تأیید عملیات")},text={ Column { Text("عملیات روی سرور انجام می‌شود. نتیجه را پیش از ارسال مجدد بررسی کنید."); OutlinedTextField(reason,{reason=it},label={Text("دلیل / توضیح")}) } },confirmButton={TextButton(onClick={vm.transition(action!!,reason); action=null}) { Text("تأیید") }},dismissButton={TextButton(onClick={action=null}) { Text("انصراف") }})
}
@Composable private fun FormScreen(vm: AgahViewModel,s: UiState,online: Boolean) {
    val m=s.module!!; val record=s.record
    val fields=remember(m) { Forms.header(m) }; val lineFields=remember(m) { Forms.lines(m) }
    val values=remember(m,record) { mutableStateMapOf<String,String>().apply {
        putAll(Forms.defaults(m,maxOf(LocalDate.now().toString(),s.year!!.getString("start_date"))))
        if(record!=null) putAll(Forms.values(fields,record,vm.scale))
    } }
    val lines=remember(m,record) { mutableStateListOf<MutableMap<String,String>>().apply { if(record!=null) addAll((record.rows("lines")+record.rows("items")).map { Forms.values(lineFields,it,vm.scale).toMutableMap() }) } }
    var editing by remember { mutableStateOf<Int?>(null) }; var formError by remember { mutableStateOf<String?>(null) }
    LazyColumn(Modifier.fillMaxSize().imePadding().padding(20.dp),verticalArrangement=Arrangement.spacedBy(12.dp)) {
        item { Text((if(record==null) "ثبت " else "ویرایش ")+m.title,style=MaterialTheme.typography.titleLarge); Text("واحد مبالغ: ${vm.unit} • محاسبه و تأیید نهایی در سرور") }
        items(fields) { f -> FormField(f,values[f.key].orEmpty(),{values[f.key]=it},vm) }
        items(lines.indices.toList()) { i -> OutlinedCard(Modifier.fillMaxWidth()) { Column(Modifier.padding(12.dp)) { Text("ردیف ${i+1}"); Text(lines[i].entries.joinToString(" • ") { (k,v) -> (labels[k] ?: lineFields.find { it.key==k }?.title ?: k)+": "+v }); Row { TextButton(onClick={editing=i}) { Text("ویرایش") }; TextButton(onClick={lines.removeAt(i)}) { Text("حذف ردیف") } } } } }
        if(lineFields.isNotEmpty()) item { OutlinedButton(onClick={editing=lines.size}) { Text("افزودن ردیف") } }
        if(formError!=null) item { Text(formError!!,color=MaterialTheme.colorScheme.error) }
        item { Button(onClick={runCatching { Forms.build(m,values,lines,vm.scale) }.onSuccess(vm::submit).onFailure { formError=it.message }},enabled=online&&!s.busy&&s.pending==null,modifier=Modifier.fillMaxWidth()) { Text(if(m.entity in setOf("invoices","vouchers")) "ذخیرهٔ پیش‌نویس آنلاین" else "ثبت در سرور") } }
    }
    if(editing!=null) {
        val index=editing!!
        val row=remember(index) { mutableStateMapOf<String,String>().apply { putAll(if(index<lines.size) lines[index] else mapOf("method" to "cash","qty_scaled" to "1","due_date" to LocalDate.now().toString())) } }
        AlertDialog(onDismissRequest={editing=null},title={Text("قلم ${index+1}")},text={LazyColumn(verticalArrangement=Arrangement.spacedBy(10.dp)) { items(lineFields) { f -> FormField(f,row[f.key].orEmpty(),{row[f.key]=it},vm) } }},confirmButton={TextButton(onClick={ if(index<lines.size) lines[index]=row.toMutableMap() else lines.add(row.toMutableMap()); editing=null }) { Text("ثبت ردیف") }},dismissButton={TextButton(onClick={editing=null}) { Text("انصراف") }})
    }
}
@Composable private fun FormField(f: Field,value: String,onValue:(String)->Unit,vm: AgahViewModel) {
    var choose by remember { mutableStateOf(false) }; var caption by remember(value) { mutableStateOf("") }
    val scanner=rememberLauncherForActivityResult(ScanContract()) { result -> result.contents?.let(onValue) }
    when(f.type) {
        InputKind.Flag -> Row(verticalAlignment=Alignment.CenterVertically) { Checkbox(value=="1",{onValue(if(it) "1" else "0")}); Text(f.title) }
        InputKind.Lookup -> OutlinedButton(onClick={choose=true},modifier=Modifier.fillMaxWidth()) { Text(f.title+": "+caption.ifEmpty { if(value.isBlank()||value=="0") "انتخاب کنید" else "کد $value" }) }
        InputKind.Choice -> Column { Text(f.title); f.choices.forEach { (key,title) -> Row(Modifier.fillMaxWidth().clickable { onValue(key) },verticalAlignment=Alignment.CenterVertically) { RadioButton(value==key,{onValue(key)}); Text(title) } } }
        else -> OutlinedTextField(value,onValue,label={Text(f.title+if(f.required) " *" else "")},supportingText={ if(f.type==InputKind.Date) Text("${persianDate(value)} • ورود تاریخ استاندارد YYYY-MM-DD") },keyboardOptions=KeyboardOptions(keyboardType=if(f.type in setOf(InputKind.Money,InputKind.Quantity)) KeyboardType.Decimal else KeyboardType.Text),modifier=Modifier.fillMaxWidth(),singleLine=f.key!="description"&&f.key!="address")
    }
    if(f.key=="barcode") TextButton(onClick={scanner.launch(ScanOptions().setPrompt("بارکد را داخل کادر قرار دهید").setBeepEnabled(false).setOrientationLocked(false))}) { Icon(Icons.Default.QrCodeScanner,null); Text("اسکن با دوربین") }
    if(choose) LookupDialog(vm,f.lookup,{ record -> onValue(record.optString("id")); caption=record.optString("name",record.optString("code")); choose=false },{choose=false})
}
@Composable private fun LookupDialog(vm: AgahViewModel,source: String,onPick:(JSONObject)->Unit,onDismiss:()->Unit) {
    var q by remember { mutableStateOf("") }; var rows by remember { mutableStateOf<List<JSONObject>>(emptyList()) }; var error by remember { mutableStateOf<String?>(null) }
    var page by remember { mutableIntStateOf(1) }; var pages by remember { mutableIntStateOf(1) }; var busy by remember { mutableStateOf(false) }
    val scope=rememberCoroutineScope()
    val scanner=rememberLauncherForActivityResult(ScanContract()) { result ->
        result.contents?.let { code -> scope.launch {
            busy=true; error=null
            try { onPick(vm.barcode(code)) } catch(e: Exception) { error=e.message } finally { busy=false }
        } }
    }
    fun load(p: Int) { scope.launch { busy=true; error=null; try { val result=vm.lookup(source,q,p); rows=result.rows(); page=p; pages=result.optInt("pages",1) } catch(e: Exception) { error=e.message } finally { busy=false } } }
    LaunchedEffect(Unit) { load(1) }
    AlertDialog(onDismissRequest=onDismiss,title={Text("انتخاب از اطلاعات شرکت")},text={ Column(Modifier.heightIn(max=480.dp)) {
        Row { OutlinedTextField(q,{q=it},label={Text("جست‌وجو")},modifier=Modifier.weight(1f)); IconButton(onClick={load(1)},enabled=!busy) { Icon(Icons.Default.Search,"جست‌وجو") } }
        if(busy) LinearProgressIndicator(Modifier.fillMaxWidth())
        if(error!=null) Text(error!!,color=MaterialTheme.colorScheme.error)
        if(source=="items") TextButton(onClick={scanner.launch(ScanOptions().setPrompt("اسکن بارکد کالا").setBeepEnabled(false).setOrientationLocked(false))},enabled=!busy) { Text("انتخاب کالا با بارکد") }
        LazyColumn(Modifier.weight(1f,false)) { items(rows) { r -> TextButton(onClick={onPick(r)},modifier=Modifier.fillMaxWidth()) { Text(r.optString("code")+" — "+r.optString("name")) } } }
        Row { TextButton(onClick={load(page-1)},enabled=page>1&&!busy) { Text("قبلی") }; TextButton(onClick={load(page+1)},enabled=page<pages&&!busy) { Text("بعدی") } }
    } },confirmButton={TextButton(onClick=onDismiss) { Text("بستن") }})
}
@Composable private fun ReportScreen(vm: AgahViewModel,s: UiState) {
    LazyColumn(Modifier.fillMaxSize().padding(16.dp),verticalArrangement=Arrangement.spacedBy(12.dp)) {
        item { Text("گزارش‌های مالی",style=MaterialTheme.typography.titleLarge) }
        items(listOf("ledger" to "دفتر روزنامه","parties" to "مانده اشخاص","statements" to "صورت‌های مالی","sales" to "فروش","purchases" to "خرید","expenses" to "هزینه","inventory" to "موجودی")) { (key,title) -> TextButton(onClick={vm.reports(key)},enabled=!s.busy) { Text(title) } }
        items(s.data.rows()) { r -> OutlinedCard(Modifier.fillMaxWidth()) { Column(Modifier.padding(14.dp)) { RecordFields(r,vm.scale,vm.unit) } } }
        item { s.data.optJSONObject("profit")?.let { RecordFields(it,vm.scale,vm.unit) } }
        if(s.data.rows().isEmpty()&&!s.data.has("profit")) item { Text("داده‌ای برای نمایش وجود ندارد.") }
    }
}
@Composable private fun DeviceScreen(vm: AgahViewModel,s: UiState) {
    LazyColumn(Modifier.fillMaxSize().padding(20.dp),verticalArrangement=Arrangement.spacedBy(12.dp)) {
        item { Text("نشست‌های فعال موبایل",style=MaterialTheme.typography.titleLarge) }
        items(s.data.rows()) { r -> Card(Modifier.fillMaxWidth()) { Column(Modifier.padding(16.dp)) { Text(r.optString("device_name")); Text(if(r.optBoolean("current")) "این دستگاه" else "دستگاه دیگر"); if(!r.optBoolean("current")) TextButton(onClick={vm.revoke(r.getString("id"))},enabled=!s.busy) { Text("لغو نشست") } } } }
    }
}
