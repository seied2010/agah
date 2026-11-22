package ir.idpa.agah.mobile.ui

import android.content.Intent
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.clickable
import androidx.compose.foundation.selection.toggleable
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.selection.selectableGroup
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.grid.GridItemSpan
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
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.platform.LocalSoftwareKeyboardController
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.font.Font
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.semantics.*
import androidx.compose.ui.text.style.TextDirection
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import ir.idpa.agah.mobile.data.ExactValues
import ir.idpa.agah.mobile.data.PersianDates
import ir.idpa.agah.mobile.R
import com.journeyapps.barcodescanner.ScanContract
import com.journeyapps.barcodescanner.ScanOptions
import kotlinx.coroutines.launch
import org.json.JSONObject
import java.time.LocalDate

private val labels=mapOf("invoice_id" to "شناسه فاکتور","paid" to "تسویه‌شده","unit_price" to "قیمت واحد","tax_amount" to "مالیات","discount_amount" to "تخفیف","net_amount" to "خالص ردیف","extra_cost" to "هزینهٔ جانبی","unit_name" to "واحد","item_code" to "کد کالا","method" to "روش","reference" to "شماره پیگیری","cheque_no" to "شماره چک","bank_name" to "بانک","review_count" to "اسناد نیازمند بررسی","noncash_documents" to "اسناد غیرنقدی","name" to "نام","code" to "کد","number" to "شماره","status" to "وضعیت","description" to "شرح","amount" to "مبلغ","total_debit" to "جمع بدهکار","total_credit" to "جمع بستانکار","debit" to "بدهکار","credit" to "بستانکار","balance" to "مانده","outstanding" to "تسویه‌نشده","party_name" to "شخص","invoice_date" to "تاریخ فاکتور","doc_date" to "تاریخ","voucher_date" to "تاریخ سند","due_date" to "سررسید","phone" to "تلفن","email" to "ایمیل","address" to "نشانی","barcode" to "بارکد","sale_price" to "قیمت فروش","purchase_price" to "قیمت خرید","qty_scaled" to "مقدار","qty" to "موجودی","warehouse_name" to "انبار","item_name" to "کالا","account_name" to "حساب","detail_name" to "تفصیلی","net" to "سود خالص")
private val monetary=setOf("paid","net_amount","extra_cost","amount","debit","credit","total_debit","total_credit","balance","outstanding","sale_price","purchase_price","trade_price","unit_price","net","opening","tax_amount","discount_amount")
private val financialLabels=mapOf("opening" to "مانده ابتدای بازه","closing" to "مانده پایان بازه","profit" to "سود مبنای جریان نقد","noncash_capital_adjustment" to "تعدیل سرمایه‌ای غیرنقدی","balance_debit" to "مانده بدهکار","balance_credit" to "مانده بستانکار","opening_debit" to "مانده اول بدهکار","opening_credit" to "مانده اول بستانکار","year_debit" to "افتتاحیه بدهکار","year_credit" to "افتتاحیه بستانکار","prior_debit" to "گردش قبل بدهکار","prior_credit" to "گردش قبل بستانکار","sales" to "فروش خالص","cogs" to "بهای تمام‌شده","gross" to "سود ناخالص","opex" to "هزینه عملیاتی","operating" to "سود عملیاتی","other_income" to "سایر درآمدها","other_expense" to "سایر هزینه‌ها","finance_cost" to "هزینه مالی","tax_expense" to "مالیات","assets" to "دارایی‌ها","liabilities" to "بدهی‌ها","equity" to "حقوق مالکانه","current_profit" to "سود دوره","difference" to "اختلاف تطبیق","start" to "وجه نقد ابتدای بازه","end" to "وجه نقد انتهای بازه","change" to "تغییر وجه نقد","noncash_contra" to "اقلام غیرنقدی","working_capital" to "سرمایه در گردش","investing" to "فعالیت سرمایه‌گذاری","financing" to "فعالیت تأمین مالی","value" to "ارزش انبار","cost" to "بها","balance_value" to "مانده ارزش")
fun persianDate(iso: String): String = runCatching { PersianDates.fromIso(iso) }.getOrDefault(iso)
private fun shown(key: String,value: String,scale: Long,unit: String): String = when {
    key.endsWith("_date") -> persianDate(value)
    key in monetary || key in financialLabels -> ExactValues.pretty(value,scale)+" "+unit
    key in setOf("qty_scaled","qty","balance_qty") -> ExactValues.pretty(value,10000)
    key=="method" -> mapOf("cash" to "نقد","card" to "کارت","transfer" to "حواله","cheque" to "چک")[value] ?: value
    key=="status" -> mapOf("active" to "فعال","pending" to "در انتظار","cleared" to "وصول‌شده","bounced" to "برگشت‌خورده","endorsed" to "واگذارشده","draft" to "پیش‌نویس","issued" to "صادرشده","approved" to "تأییدشده","final" to "قطعی","void" to "باطل‌شده")[value] ?: value
    else -> value
}

@Composable fun AgahApp(vm: AgahViewModel,biometrics: BiometricActions?=null) {
    var showBiometrics by remember {mutableStateOf(false)}
    var biometricOffered by rememberSaveable {mutableStateOf(false)}
    val s by vm.state.collectAsStateWithLifecycle()
    LaunchedEffect(s.signedIn,s.busy,biometrics?.state?.enabled) {
        if(s.signedIn&&!s.busy&&biometrics!=null&&!biometrics.state.enabled&&biometrics.availability.available&&!biometricOffered) {biometricOffered=true;showBiometrics=true}
    }
    val context=LocalContext.current
    val appearance=remember { context.getSharedPreferences("agah-appearance",android.content.Context.MODE_PRIVATE) }
    var dark by rememberSaveable { mutableStateOf(appearance.getBoolean("dark",false)) }
    val drawer=rememberDrawerState(DrawerValue.Closed)
    val scope=rememberCoroutineScope()
    var pendingExit by remember { mutableStateOf<(() -> Unit)?>(null) }
    val navigate: (() -> Unit)->Unit={ action -> if(s.screen=="form") pendingExit=action else action() }
    SideEffect {
        (context as? android.app.Activity)?.window?.let { window ->
            androidx.core.view.WindowCompat.getInsetsController(window,window.decorView).apply {
                isAppearanceLightStatusBars=!dark
                isAppearanceLightNavigationBars=!dark
            }
        }
    }
    val lifecycle=LocalLifecycleOwner.current.lifecycle
    DisposableEffect(lifecycle,vm) {
        val observer=LifecycleEventObserver { _,event -> if(event==Lifecycle.Event.ON_RESUME) vm.revalidate() }
        lifecycle.addObserver(observer)
        onDispose { lifecycle.removeObserver(observer) }
    }
    val keyboard=LocalSoftwareKeyboardController.current
    val focus=LocalFocusManager.current
    LaunchedEffect(s.signedIn,s.screen,s.company?.optString("id"),s.year?.optString("id")) {
        focus.clearFocus(); keyboard?.hide()
    }
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
        MaterialTheme(typography=AgahType,shapes=AgahShapes,colorScheme=if(dark) AgahDark else AgahLight) {
            val navigable=s.signedIn&&s.company!=null&&s.year!=null
            ModalNavigationDrawer(drawerState=drawer,gesturesEnabled=navigable&&!s.busy,drawerContent={
                if(navigable) ModalDrawerSheet(Modifier.widthIn(max=320.dp)) {
                    NavigationMenu(vm,s,perform=navigate) { scope.launch { drawer.close() } }
                }
            }) {
                Surface(Modifier.fillMaxSize(),color=MaterialTheme.colorScheme.background) {
                    Column(Modifier.fillMaxSize().safeDrawingPadding()) {
                        Surface(color=MaterialTheme.colorScheme.surface,shadowElevation=1.dp) {
                            Row(Modifier.fillMaxWidth().padding(horizontal=16.dp,vertical=10.dp),verticalAlignment=Alignment.CenterVertically,horizontalArrangement=Arrangement.spacedBy(8.dp)) {
                                if(navigable) IconButton(onClick={scope.launch { drawer.open() }},enabled=!s.busy) { Icon(Icons.Default.Menu,"فهرست بخش‌ها") }
                                BrandMark()
                                Column(Modifier.weight(1f)) {
                                    Text("آگاه",style=MaterialTheme.typography.titleLarge)
                                    Text("حسابداری همراه",style=MaterialTheme.typography.labelMedium,color=MaterialTheme.colorScheme.onSurfaceVariant)
                                }
                                IconButton(onClick={dark=!dark;appearance.edit().putBoolean("dark",dark).apply()},modifier=Modifier.semantics { stateDescription=if(dark) "حالت تیره" else "حالت روشن" }) { Icon(if(dark) Icons.Default.LightMode else Icons.Default.DarkMode,"تغییر روشنایی") }
                                if(s.signedIn&&biometrics!=null)IconButton(onClick={showBiometrics=true},enabled=!s.busy) {Icon(Icons.Default.Fingerprint,"امنیت ورود با اثر انگشت")}
                                if(s.signedIn) IconButton(onClick={navigate(vm::logout)},enabled=!s.busy) { Icon(Icons.Default.Logout,"خروج امن") }
                            }
                        }
                        if(s.busy) LinearProgressIndicator(Modifier.fillMaxWidth().testTag("request-progress").semantics { contentDescription="در حال دریافت یا ثبت اطلاعات" })
                        if(!online) Box(Modifier.padding(12.dp)) { InfoBanner("ارتباط اینترنت قطع است؛ ثبت اطلاعات غیرفعال است.",true) }
                        if(s.pending!=null&&s.signedIn) Column(Modifier.padding(12.dp)) {
                            InfoBanner("نتیجهٔ ثبت قبلی نامشخص است؛ پیش از ثبت دوباره، نتیجه را استعلام کنید.",true)
                            OutlinedButton(onClick=vm::checkPending,enabled=!s.busy&&online,modifier=Modifier.fillMaxWidth()) { Text("نتیجهٔ ثبت قبلی نامشخص است؛ استعلام") }
                            TextButton(onClick=vm::cancelPending,enabled=!s.busy&&online,modifier=Modifier.fillMaxWidth()) { Text("تعیین تکلیف و لغو درخواستِ ثبت‌نشده") }
                        }
                        when {
                            !s.signedIn -> Box(Modifier.weight(1f).fillMaxWidth(),contentAlignment=Alignment.TopCenter) { Login(vm,s.busy) }
                            s.company==null -> Selection("شرکت را انتخاب کنید",s.companies,"name",vm::selectCompany)
                            s.year==null -> Selection("سال مالی ${s.company!!.optString("name")}",s.context?.rows("years") ?: emptyList(),"jy",vm::selectYear)
                            else -> {
                                Surface(color=MaterialTheme.colorScheme.surface) {
                                    Row(Modifier.fillMaxWidth().padding(horizontal=16.dp,vertical=4.dp),verticalAlignment=Alignment.CenterVertically) {
                                        Column(Modifier.weight(1f)) {
                                            TextButton(onClick={navigate(vm::changeCompany)},enabled=!s.busy) { Text(s.company!!.optString("name")) }
                                            Text("سال ${s.year!!.optString("jy")} • ${vm.unit} • "+if(s.context?.optString("mode")=="write") "ثبت و مشاهده" else "فقط مشاهده",style=MaterialTheme.typography.labelMedium,modifier=Modifier.padding(start=12.dp,bottom=8.dp),color=MaterialTheme.colorScheme.onSurfaceVariant)
                                        }
                                        if(s.screen!="dashboard") IconButton(onClick={navigate(vm::back)},enabled=!s.busy) { Icon(Icons.AutoMirrored.Filled.ArrowBack,"بازگشت") }
                                    }
                                }
                                BoxWithConstraints(Modifier.weight(1f).fillMaxWidth()) {
                                    val wide=maxWidth>=1100.dp
                                    Row(Modifier.fillMaxSize()) {
                                        if(wide) Surface(Modifier.width(236.dp).fillMaxHeight(),color=MaterialTheme.colorScheme.surface) { NavigationMenu(vm,s,perform=navigate) {} }
                                        Box(Modifier.weight(1f).fillMaxHeight(),contentAlignment=Alignment.TopCenter) {
                                            Box(Modifier.widthIn(max=if(s.screen=="form"||s.screen=="detail") 760.dp else 1200.dp).fillMaxSize()) {
                                                when(s.screen) {
                                                    "dashboard" -> Dashboard(vm,s)
                                                    "list" -> Listing(vm,s,online)
                                                    "detail" -> Detail(vm,s,online)
                                                    "form" -> FormScreen(vm,s,online)
                                                    "report" -> ReportScreen(vm,s)
                                                    "devices" -> DeviceScreen(vm,s)
                                                    "attachments" -> AttachmentsScreen(vm,s,online)
                                                    "history" -> HistoryScreen(vm,s)
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            if(showBiometrics&&biometrics!=null)BiometricSettingsDialog(biometrics) {showBiometrics=false}
            if(s.error!=null) AlertDialog(onDismissRequest=vm::dismissError,icon={Icon(Icons.Default.Info,null)},title={Text("پیام سامانه")},text={Text(s.error!!,Modifier.heightIn(max=320.dp).verticalScroll(rememberScrollState()))},confirmButton={TextButton(onClick=vm::dismissError) { Text("متوجه شدم") }})
            if(pendingExit!=null) AlertDialog(onDismissRequest={pendingExit=null},icon={Icon(Icons.Default.EditNote,null)},title={Text("از فرم خارج می‌شوید؟")},text={Text("اطلاعات این فرم هنوز ثبت نشده است. برای ثبت، به ویرایش ادامه دهید.")},confirmButton={TextButton(onClick={val action=pendingExit;pendingExit=null;action?.invoke()}) {Text("خروج از فرم")}},dismissButton={TextButton(onClick={pendingExit=null}) {Text("ادامهٔ ویرایش")}})
            BackHandler(s.signedIn&&s.company!=null&&s.screen!="dashboard"&&!s.busy&&!drawer.isOpen) { navigate(vm::back) }
        }
    }
}
@Composable private fun NavigationMenu(vm: AgahViewModel,s: UiState,perform: ((()->Unit)->Unit)={it()},onNavigate:()->Unit) {
    Column(Modifier.fillMaxHeight().verticalScroll(rememberScrollState()).padding(16.dp),verticalArrangement=Arrangement.spacedBy(6.dp)) {
        SectionTitle("فضای کار","دسترسی متناسب با نقش شما")
        Spacer(Modifier.height(12.dp))
        NavigationDrawerItem(label={Text("داشبورد")},selected=s.screen=="dashboard",icon={Icon(Icons.Default.SpaceDashboard,null)},onClick={if(!s.busy) {perform {vm.dashboard();onNavigate()}} },modifier=Modifier.semantics { if(s.busy) disabled() })
        modules.filter { vm.can(it.capability+".view") }.forEach { m ->
            NavigationDrawerItem(label={Text(m.title)},selected=s.screen in setOf("list","detail","form","attachments","history")&&s.module==m,icon={Icon(moduleIcon(m),null)},onClick={if(!s.busy) {perform {vm.openModule(m);onNavigate()}} })
        }
        HorizontalDivider(Modifier.padding(vertical=12.dp))
        if(vm.can("report.view")) NavigationDrawerItem(label={Text("گزارش‌های مالی")},selected=s.screen=="report",icon={Icon(Icons.Default.Assessment,null)},onClick={if(!s.busy) {perform {vm.reports();onNavigate()}} })
        NavigationDrawerItem(label={Text("دستگاه‌های فعال")},selected=s.screen=="devices",icon={Icon(Icons.Default.Devices,null)},onClick={if(!s.busy) {perform {vm.devices();onNavigate()}} })
    }
}
@Composable private fun Login(vm: AgahViewModel,busy: Boolean) {
    var server by rememberSaveable { mutableStateOf(vm.repository.vault.server) }
    var user by rememberSaveable { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var factor by remember { mutableStateOf("") }
    var reveal by remember { mutableStateOf(false) }
    val context=LocalContext.current
    fun signIn() { if(!busy&&user.isNotBlank()&&password.isNotEmpty()) { vm.login(server,user,password,factor);password="";factor="" } }
    LazyColumn(Modifier.widthIn(max=560.dp).fillMaxWidth().imePadding().padding(24.dp).testTag("login-form"),verticalArrangement=Arrangement.spacedBy(16.dp)) {
        item { OverviewHero("به آگاه خوش آمدید","وارد فضای کار مالی شرکت شوید.") }
        item { SectionTitle("ورود به حساب کاربری") }
        item { OutlinedTextField(server,{server=it},label={Text("نشانی سرور")},leadingIcon={Icon(Icons.Default.Language,null)},keyboardOptions=KeyboardOptions(keyboardType=KeyboardType.Uri,imeAction=ImeAction.Next),textStyle=MaterialTheme.typography.bodyLarge.copy(textDirection=TextDirection.Ltr),singleLine=true,modifier=Modifier.fillMaxWidth(),enabled=!busy,shape=MaterialTheme.shapes.medium) }
        item { OutlinedTextField(user,{user=it},label={Text("نام کاربری یا ایمیل")},leadingIcon={Icon(Icons.Default.PersonOutline,null)},keyboardOptions=KeyboardOptions(imeAction=ImeAction.Next),singleLine=true,modifier=Modifier.fillMaxWidth(),enabled=!busy,shape=MaterialTheme.shapes.medium) }
        item { OutlinedTextField(password,{password=it},label={Text("گذرواژه")},leadingIcon={Icon(Icons.Default.Lock,null)},trailingIcon={IconButton(onClick={reveal=!reveal}) {Icon(if(reveal) Icons.Default.VisibilityOff else Icons.Default.Visibility,if(reveal) "پنهان کردن گذرواژه" else "نمایش گذرواژه")}},visualTransformation=if(reveal) androidx.compose.ui.text.input.VisualTransformation.None else PasswordVisualTransformation(),keyboardOptions=KeyboardOptions(keyboardType=KeyboardType.Password,imeAction=ImeAction.Next),singleLine=true,modifier=Modifier.fillMaxWidth(),enabled=!busy,shape=MaterialTheme.shapes.medium) }
        item { OutlinedTextField(factor,{factor=it},label={Text("کد دومرحله‌ای (اگر فعال است)")},leadingIcon={Icon(Icons.Default.VerifiedUser,null)},supportingText={Text("اگر ورود دومرحله‌ای فعال نیست، خالی بگذارید.")},keyboardOptions=KeyboardOptions(imeAction=ImeAction.Done),keyboardActions=KeyboardActions(onDone={signIn()}),singleLine=true,modifier=Modifier.fillMaxWidth(),enabled=!busy,shape=MaterialTheme.shapes.medium) }
        item { Button(onClick={signIn()},enabled=!busy&&user.isNotBlank()&&password.isNotEmpty(),modifier=Modifier.fillMaxWidth().heightIn(min=56.dp),shape=MaterialTheme.shapes.medium) { Text("ورود امن") } }
        item { TextButton(onClick={ runCatching { val uri=Uri.parse(server); if(uri.scheme=="https") context.startActivity(Intent(Intent.ACTION_VIEW,Uri.parse(server.trimEnd('/')+"/wp-login.php?action=lostpassword"))) } },modifier=Modifier.fillMaxWidth()) { Text("بازیابی گذرواژه در سامانه") } }
        item { InfoBanner("اطلاعات مالی روی سرور شرکت نگهداری می‌شود؛ گذرواژه در اپ ذخیره نمی‌شود.") }
    }
}
@Composable private fun Selection(title: String,rows: List<JSONObject>,label: String,onPick:(JSONObject)->Unit) {
    Box(Modifier.fillMaxSize(),contentAlignment=Alignment.TopCenter) {
        LazyColumn(Modifier.widthIn(max=760.dp).fillMaxSize().padding(20.dp),verticalArrangement=Arrangement.spacedBy(12.dp)) {
            item { OverviewHero(title,"فضای کاری خود را برای ادامه انتخاب کنید.") }
            if(rows.isEmpty()) item { EmptyState("دسترسی فعالی یافت نشد","برای بررسی شرکت‌ها و مجوزهای این حساب با مدیر سامانه تماس بگیرید.") }
            items(rows) { row -> OutlinedCard(onClick={onPick(row)},modifier=Modifier.fillMaxWidth()) {
                Row(Modifier.padding(20.dp),verticalAlignment=Alignment.CenterVertically,horizontalArrangement=Arrangement.spacedBy(16.dp)) {
                    Icon(if(label=="jy") Icons.Default.CalendarMonth else Icons.Default.Business,null,tint=MaterialTheme.colorScheme.primary)
                    Text(row.optString(label),Modifier.weight(1f),style=MaterialTheme.typography.titleMedium)
                    Icon(Icons.AutoMirrored.Filled.ArrowBack,null,tint=MaterialTheme.colorScheme.onSurfaceVariant)
                }
            } }
        }
    }
}
@Composable private fun Dashboard(vm: AgahViewModel,s: UiState) {
    val cards=s.data.optJSONObject("cards") ?: JSONObject()
    val visible=modules.filter { vm.can(it.capability+".view") }
    val preferences=LocalContext.current.getSharedPreferences("dashboard-layout",android.content.Context.MODE_PRIVATE)
    var showTrend by remember { mutableStateOf(preferences.getBoolean("trend",true)) }
    var showAlerts by remember { mutableStateOf(preferences.getBoolean("alerts",true)) }
    var customize by remember { mutableStateOf(false) }
    var datePicker by remember { mutableStateOf(false) }
    LazyVerticalGrid(GridCells.Adaptive(260.dp),Modifier.fillMaxSize().padding(horizontal=16.dp).testTag("dashboard-grid"),contentPadding=PaddingValues(top=16.dp,bottom=28.dp),horizontalArrangement=Arrangement.spacedBy(12.dp),verticalArrangement=Arrangement.spacedBy(12.dp)) {
        item(span={GridItemSpan(maxLineSpan)}) { OverviewHero("نمای کلی ${s.company!!.optString("name")}","بازه ${persianDate(s.data.optString("from"))} تا ${persianDate(s.data.optString("date"))}") {
            TextButton(onClick={if(s.data.optString("from").isNotBlank()) vm.dashboardRange(s.data.getString("from"),s.data.getString("date")) else vm.dashboard()},enabled=!s.busy) { Icon(Icons.Default.Refresh,null);Spacer(Modifier.width(8.dp));Text("تازه‌سازی اطلاعات") }
        } }
        gridItems(cards.keys().asSequence().toList()) { key -> MetricCard(key,cards.optString(key),vm.scale,vm.unit) }
        item(span={GridItemSpan(maxLineSpan)}) { AdaptiveActions {
            OutlinedButton(onClick={datePicker=true},enabled=!s.busy) { Icon(Icons.Default.DateRange,null);Spacer(Modifier.width(8.dp));Text("بازهٔ داشبورد") }
            TextButton(onClick={customize=true}) { Icon(Icons.Default.Tune,null);Spacer(Modifier.width(8.dp));Text("شخصی‌سازی داشبورد") }
        } }
        item(span={GridItemSpan(maxLineSpan)}) { SectionTitle("فضای کار","دسترسی سریع به عملیات روزانه") }
        gridItems(visible) { m -> WorkspaceTile(m,!s.busy) {vm.openModule(m)} }
        if(vm.can("report.view")) item { OutlinedButton(onClick={vm.reports()},modifier=Modifier.fillMaxWidth().heightIn(min=56.dp)) { Icon(Icons.Default.Assessment,null);Spacer(Modifier.width(8.dp));Text("گزارش‌های مالی") } }
        if(vm.can("report.view")) item { OutlinedButton(onClick={vm.reports("ratios")},modifier=Modifier.fillMaxWidth().heightIn(min=56.dp)) { Icon(Icons.Default.Insights,null);Spacer(Modifier.width(8.dp));Text("نسبت‌ها و شاخص‌های مالی") } }
        if(showTrend) item(span={GridItemSpan(maxLineSpan)}) { DashboardTrend(s.data,vm.scale,vm.unit) }
        if(showAlerts) item(span={GridItemSpan(maxLineSpan)}) { OutlinedCard(Modifier.fillMaxWidth()) { Column(Modifier.padding(20.dp),verticalArrangement=Arrangement.spacedBy(12.dp)) {
            SectionTitle("هشدارهای مدیریتی","موارد نیازمند توجه در تاریخ مبنا")
            val alerts=s.data.rows("notifications")
            if(alerts.isEmpty()) InfoBanner("هشداری برای دسترسی شما و تاریخ مبنا وجود ندارد.")
            alerts.forEach { alert -> Row(horizontalArrangement=Arrangement.spacedBy(12.dp)) {
                Icon(Icons.Default.NotificationsNone,null,tint=MaterialTheme.colorScheme.secondary)
                Column(Modifier.weight(1f)) {
                    Text(alert.optString("title"),style=MaterialTheme.typography.titleSmall)
                    if(alert.optString("date").isNotBlank()) Text(persianDate(alert.optString("date")),style=MaterialTheme.typography.bodySmall,color=MaterialTheme.colorScheme.onSurfaceVariant)
                    if(alert.has("amount")) Text(ExactValues.pretty(alert.optString("amount"),vm.scale)+" "+vm.unit,style=MaterialTheme.typography.bodyMedium)
                }
            };HorizontalDivider(color=MaterialTheme.colorScheme.outlineVariant) }
        } } }
        item(span={GridItemSpan(maxLineSpan)}) { TextButton(onClick=vm::devices,modifier=Modifier.fillMaxWidth()) { Icon(Icons.Default.Devices,null);Spacer(Modifier.width(8.dp));Text("دستگاه‌های فعال") } }
    }
    if(datePicker) {
        var from by remember { mutableStateOf(s.data.optString("from",s.year!!.getString("start_date"))) }
        var to by remember { mutableStateOf(s.data.optString("date")) }
        AlertDialog(onDismissRequest={datePicker=false},title={Text("بازهٔ داشبورد")},text={Column(Modifier.verticalScroll(rememberScrollState()),verticalArrangement=Arrangement.spacedBy(12.dp)) {
            FormField(Field("from","از تاریخ",InputKind.Date),from,{from=it},vm)
            FormField(Field("to","تا تاریخ",InputKind.Date),to,{to=it},vm)
        }},confirmButton={TextButton(onClick={vm.dashboardRange(from,to);datePicker=false}) { Text("اعمال") }},dismissButton={TextButton(onClick={datePicker=false}) { Text("انصراف") }})
    }
    if(customize) AlertDialog(onDismissRequest={customize=false},title={Text("اجزای داشبورد")},text={Column {
        Row(Modifier.fillMaxWidth().heightIn(min=48.dp).toggleable(value=showTrend,role=Role.Checkbox,onValueChange={showTrend=it;preferences.edit().putBoolean("trend",it).apply()}),verticalAlignment=Alignment.CenterVertically) { Checkbox(showTrend,null);Text("روند شش‌ماهه",Modifier.padding(start=8.dp)) }
        Row(Modifier.fillMaxWidth().heightIn(min=48.dp).toggleable(value=showAlerts,role=Role.Checkbox,onValueChange={showAlerts=it;preferences.edit().putBoolean("alerts",it).apply()}),verticalAlignment=Alignment.CenterVertically) { Checkbox(showAlerts,null);Text("هشدارهای مدیریتی",Modifier.padding(start=8.dp)) }
    }},confirmButton={TextButton(onClick={customize=false}) { Text("بستن") }})
}
@Composable private fun Listing(vm: AgahViewModel,s: UiState,online: Boolean) {
    val module=s.module!!; var q by remember(module) { mutableStateOf(s.query) }
    Column(Modifier.fillMaxSize().padding(16.dp),verticalArrangement=Arrangement.spacedBy(8.dp)) {
        SectionTitle(module.title,moduleHint(module))
        if(module.entity=="stock") Text("موجودی فعلی در تمام دوره‌ها؛ برای دیدن گردش، کالا را انتخاب کنید.",style=MaterialTheme.typography.bodySmall)
        Row(verticalAlignment=Alignment.CenterVertically) {
            OutlinedTextField(q,{q=it},label={Text("جست‌وجو")},leadingIcon={Icon(Icons.Default.Search,null)},shape=MaterialTheme.shapes.medium,keyboardOptions=KeyboardOptions(imeAction=ImeAction.Search),keyboardActions=KeyboardActions(onSearch={vm.openModule(module,1,q)}),modifier=Modifier.weight(1f),singleLine=true)
            IconButton(onClick={vm.openModule(module,1,q)},enabled=!s.busy) { Icon(Icons.Default.Search,"جست‌وجو") }
            if(vm.canWrite(module.capability+".create")) IconButton(onClick={vm.edit(true)},enabled=online&&!s.busy&&s.pending==null) { Icon(Icons.Default.Add,"ثبت جدید") }
        }
        val rows=s.data.rows()
        if(rows.isEmpty()&&!s.busy) EmptyState(if(s.query.isBlank()) "فهرست هنوز خالی است" else "نتیجه‌ای پیدا نشد",if(s.query.isBlank()) "پس از ثبت اطلاعات، موارد این بخش اینجا نمایش داده می‌شوند." else "عبارت جست‌وجو را تغییر دهید و دوباره جست‌وجو کنید.")
        LazyColumn(Modifier.weight(1f),verticalArrangement=Arrangement.spacedBy(10.dp)) {
            items(rows) { record -> Card(onClick={if(module.entity=="stock") vm.stockHistory(record) else vm.openRecord(record)},enabled=!s.busy,modifier=Modifier.fillMaxWidth()) {
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
@Composable internal fun RecordFields(record: JSONObject,scale: Long,unit: String,limit: Int=100) {
    val keys=record.keys().asSequence().filter { (it in labels || it in financialLabels) && !record.isNull(it) && record.opt(it) !is JSONObject && record.opt(it) !is org.json.JSONArray }.take(limit).toList()
    Column(verticalArrangement=Arrangement.spacedBy(10.dp)) {
        keys.forEach { key ->
            val label=labels[key] ?: financialLabels.getValue(key)
            val value=shown(key,record.optString(key),scale,unit)
            BoxWithConstraints(Modifier.fillMaxWidth()) {
                if(maxWidth<400.dp) Column(Modifier.fillMaxWidth().semantics(mergeDescendants=true) {},verticalArrangement=Arrangement.spacedBy(2.dp)) {
                    Text(label,style=MaterialTheme.typography.labelMedium,color=MaterialTheme.colorScheme.onSurfaceVariant)
                    FieldValue(key,value)
                } else Row(Modifier.fillMaxWidth().semantics(mergeDescendants=true) {},horizontalArrangement=Arrangement.spacedBy(16.dp)) {
                    Text(label,modifier=Modifier.weight(.4f),style=MaterialTheme.typography.bodyMedium,color=MaterialTheme.colorScheme.onSurfaceVariant)
                    Box(Modifier.weight(.6f)) { FieldValue(key,value) }
                }
            }
        }
    }
}
@Composable private fun FieldValue(key: String,value: String) {
    if(key=="status") Surface(color=MaterialTheme.colorScheme.secondaryContainer,shape=MaterialTheme.shapes.small) { Text(value,Modifier.padding(horizontal=10.dp,vertical=4.dp),style=MaterialTheme.typography.labelLarge,color=MaterialTheme.colorScheme.onSecondaryContainer) }
    else Text(value,style=if(key in monetary||key in financialLabels) MaterialTheme.typography.titleMedium else MaterialTheme.typography.bodyLarge)
}
@Composable private fun Detail(vm: AgahViewModel,s: UiState,online: Boolean) {
    val m=s.module!!; val r=s.record!!
    var action by remember { mutableStateOf<String?>(null) }; var reason by remember { mutableStateOf("") }
    LazyColumn(Modifier.fillMaxSize().padding(20.dp).testTag("record-detail"),verticalArrangement=Arrangement.spacedBy(14.dp)) {
        item { SectionTitle(m.title,"جزئیات و وضعیت سند") }
        item { OutlinedCard(Modifier.fillMaxWidth()) { Column(Modifier.padding(20.dp)) { RecordFields(r,vm.scale,vm.unit) } } }
        if(m.entity=="parties") item { OutlinedButton(onClick={vm.partyHistory()},enabled=!s.busy&&online) { Text("گردش حساب و سوابق مالی") } }
        if(m.entity in setOf("invoices","vouchers","treasury","expenses")&&vm.can(m.capability+".export")) item { OutlinedButton(onClick={vm.exportDocument()},enabled=!s.busy&&online) { Text("دریافت / اشتراک‌گذاری PDF سند") } }
        if(m.entity=="invoices"&&vm.can(m.capability+".export")) item { AdaptiveActions {
            listOf("store" to "نسخهٔ فروشگاه","customer" to "نسخهٔ مشتری","workshop" to "کارگاه بدون قیمت","receipt" to "رسید ۸۰ میلی‌متری").forEach { (layout,label) -> OutlinedButton(onClick={vm.exportDocument(layout)},enabled=!s.busy&&online) {Text(label)} }
        } }
        items(r.rows("lines")+r.rows("items")) { line -> OutlinedCard { Column(Modifier.padding(14.dp)) { RecordFields(line,vm.scale,vm.unit) } } }
        if(m.entity=="treasury") {
            if(r.rows("cheques").isNotEmpty()) item { Text("مشخصات چک‌ها",style=MaterialTheme.typography.titleMedium) }
            items(r.rows("cheques")) { cheque -> OutlinedCard(Modifier.fillMaxWidth()) { Column(Modifier.padding(14.dp)) { RecordFields(cheque,vm.scale,vm.unit) } } }
            if(r.rows("settlements").isNotEmpty()) item { Text("تخصیص‌های فاکتور",style=MaterialTheme.typography.titleMedium) }
            items(r.rows("settlements")) { settlement -> OutlinedCard(Modifier.fillMaxWidth()) { Column(Modifier.padding(14.dp)) { RecordFields(settlement,vm.scale,vm.unit) } } }
        }
        if(m.entity in setOf("parties","items","invoices","vouchers")&&vm.canWrite(m.capability+".edit")) item { Button(onClick={vm.edit()},enabled=online&&!s.busy&&s.pending==null) { Text("ویرایش") } }
        if(m.entity in setOf("parties","invoices","vouchers","expenses")&&vm.can("file.view")) item { OutlinedButton(onClick=vm::attachments,enabled=!s.busy&&online) { Text("پیوست‌های خصوصی سند") } }
        if(m.entity=="invoices"&&r.optString("status")=="draft"&&vm.canWrite(m.capability+".create")) item { Button(onClick={action="issue"},enabled=online&&!s.busy&&s.pending==null) { Text("صدور قطعی فاکتور") } }
        if(m.entity=="vouchers") items(listOf("approve" to "تأیید سند","finalize" to "قطعی‌کردن","reopen" to "بازگشایی","void" to "ابطال")) { (a,title) ->
            if(vm.canWrite("voucher.$a")) OutlinedButton(onClick={action=a},enabled=online&&!s.busy&&s.pending==null) { Text(title) }
        }
        if(m.entity in setOf("invoices","treasury","expenses")&&vm.canWrite(m.capability+".delete")) item { OutlinedButton(onClick={action="void"},enabled=online&&!s.busy&&s.pending==null) { Text("ابطال با ثبت دلیل") } }
    }
    if(action=="issue") InvoiceIssueDialog(vm,s,online) {action=null}
    if(action!=null&&action!="issue") AlertDialog(onDismissRequest={action=null},title={Text("تأیید عملیات")},text={ Column { Text("عملیات روی سرور انجام می‌شود. نتیجه را پیش از ارسال مجدد بررسی کنید."); OutlinedTextField(reason,{reason=it},label={Text("دلیل / توضیح")}) } },confirmButton={TextButton(onClick={vm.transition(action!!,reason); action=null}) { Text("تأیید") }},dismissButton={TextButton(onClick={action=null}) { Text("انصراف") }})
}
@Composable private fun FormScreen(vm: AgahViewModel,s: UiState,online: Boolean) {
    val m=s.module!!; val record=s.record
    val fields=remember(m) { Forms.header(m) }; val lineFields=remember(m) { Forms.lines(m) }
    val draft=vm.formDraft(m,record)
    val values=draft.header
    val lines=draft.lines
    val canPayExpense=vm.canWrite(if(values["_category_kind"]=="income") "receipt.create" else "payment.create")
    var editing by draft.editingLine
    var allocationEditing by draft.editingAllocation
    var formError by remember { mutableStateOf<String?>(null) }
    val fieldErrors=remember(m,record) { mutableStateMapOf<String,String>() }
    val formList=rememberLazyListState()
    val formScope=rememberCoroutineScope()
    fun beginLine(index: Int) {
        draft.lineEntry.clear(); draft.lineEntry.putAll(if(index<lines.size) lines[index] else mapOf("method" to "cash","qty_scaled" to "1","due_date" to values["doc_date"].orEmpty().ifEmpty { LocalDate.now().toString() }))
        editing=index
    }
    fun beginAllocation(index: Int) {
        draft.allocationEntry.clear(); if(index<draft.allocations.size) draft.allocationEntry.putAll(draft.allocations[index])
        allocationEditing=index
    }
    LazyColumn(Modifier.fillMaxSize().imePadding().padding(20.dp).testTag("entity-form"),state=formList,verticalArrangement=Arrangement.spacedBy(12.dp)) {
        item { SectionTitle((if(record==null) "ثبت " else "ویرایش ")+m.title,"اطلاعات اصلی را تکمیل کنید؛ فیلدهای ستاره‌دار الزامی‌اند.") }
        item { InfoBanner("واحد مبالغ: ${vm.unit} • محاسبه و تأیید نهایی در سرور") }
        items(fields) { f -> FormField(f,values[f.key].orEmpty(),{values[f.key]=it;fieldErrors.remove(f.key)},vm,error=fieldErrors[f.key],onPicked={ picked -> if(f.key=="category_id") values["_category_kind"]=picked.optString("kind") }) }
        if(m.entity=="expenses"&&(canPayExpense||lines.isNotEmpty())) item { Text("دریافت / پرداخت هم‌زمان (اختیاری)",style=MaterialTheme.typography.titleMedium) }
        if(m.entity=="invoices") item { InvoiceQuickEntry(vm,s,draft,online) }
        if(lineFields.isNotEmpty()&&(m.entity!="expenses"||canPayExpense)) item { OutlinedButton(onClick={beginLine(lines.size)},enabled=!s.busy&&draft.scanPending.intValue==0) { Text("افزودن ردیف") } }
        items(lines.indices.toList()) { i -> OutlinedCard(Modifier.fillMaxWidth()) { Column(Modifier.padding(12.dp)) { Text("ردیف ${i+1}"); if(lines[i]["_stock_hint"].orEmpty().isNotBlank()) Text(lines[i]["_stock_hint"].orEmpty(),style=MaterialTheme.typography.bodySmall); Text(lineFields.mapNotNull { f -> lines[i][f.key]?.takeIf { it.isNotBlank()&&it!="0" }?.let { v -> f.title+": "+(lines[i]["_title_"+f.key] ?: v) } }.joinToString(" • ")); Row { TextButton(onClick={beginLine(i)},enabled=!s.busy&&draft.scanPending.intValue==0) { Text("ویرایش") }; TextButton(onClick={lines.removeAt(i)},enabled=!s.busy&&draft.scanPending.intValue==0) { Text("حذف ردیف") } } } } }

        if(m.entity=="treasury") {
            item { Text("تخصیص به فاکتور (اختیاری)",style=MaterialTheme.typography.titleMedium); Text("علی‌الحساب با تخصیص فاکتور ترکیب نمی‌شود.") }
            items(draft.allocations.indices.toList()) { i -> OutlinedCard(Modifier.fillMaxWidth()) { Column(Modifier.padding(12.dp)) { Text((draft.allocations[i]["_title_invoice_id"] ?: "فاکتور ${draft.allocations[i]["invoice_id"]}")+" • "+draft.allocations[i]["amount"].orEmpty()+" "+vm.unit); Row { TextButton(onClick={beginAllocation(i)}) { Text("ویرایش") }; TextButton(onClick={draft.allocations.removeAt(i)}) { Text("حذف") } } } } }
            item { OutlinedButton(onClick={beginAllocation(draft.allocations.size)}) { Text("افزودن تخصیص فاکتور") } }
        }
        if(formError!=null) item { InfoBanner(formError!!,true) }
        if(m.entity=="expenses"&&lines.isNotEmpty()&&!canPayExpense) item { Text("مجوز ثبت دریافت یا پرداخت این سرفصل را ندارید؛ اقلام پرداخت را حذف کنید یا از مدیر مجوز بگیرید.",color=MaterialTheme.colorScheme.error) }
        item { Button(onClick={
            fieldErrors.clear()
            fields.forEach { f ->
                val value=values[f.key].orEmpty()
                if(f.required&&value.isBlank()) fieldErrors[f.key]="${f.title} را وارد کنید."
                else if(f.type in setOf(InputKind.Money,InputKind.Quantity)&&value.isNotBlank()) runCatching { ExactValues.scaled(value,if(f.type==InputKind.Quantity) 10000 else vm.scale) }.onFailure { fieldErrors[f.key]=it.message ?: "مقدار معتبر وارد کنید." }
            }
            if(fieldErrors.isNotEmpty()) {
                formError="${fieldErrors.size} فیلد نیاز به اصلاح دارد؛ پیام زیر فیلدها را بررسی کنید."
                formScope.launch { formList.animateScrollToItem(2+fields.indexOfFirst {fieldErrors.containsKey(it.key)}.coerceAtLeast(0)) }
            }
            else runCatching { Forms.build(m,values,lines,vm.scale,draft.allocations) }.onSuccess { formError=null;vm.submit(it) }.onFailure { formError=it.message }
        },enabled=online&&!s.busy&&s.pending==null&&draft.scanPending.intValue==0&&draft.scanErrors.isEmpty()&&vm.canWrite(m.capability+if(record==null) ".create" else ".edit")&&(m.entity!="expenses"||lines.isEmpty()||canPayExpense),modifier=Modifier.fillMaxWidth().heightIn(min=56.dp)) { Text(if(m.entity in setOf("invoices","vouchers")) "ذخیرهٔ پیش‌نویس آنلاین" else "ثبت در سرور") } }
    }
    if(editing!=null) {
        val index=editing!!
        val row=draft.lineEntry
        val shownFields=lineFields.filterNot { f -> (m.entity in setOf("treasury","expenses")&&row["method"]!="cheque"&&f.key in setOf("cheque_no","bank_name","due_date"))||(m.entity=="stock"&&values["kind"]!="transfer"&&f.key=="target_warehouse_id")||(m.entity=="stock"&&values["kind"] in setOf("issue","transfer")&&f.key=="cost") }
        AlertDialog(onDismissRequest={editing=null},title={Text("قلم ${index+1}")},text={LazyColumn(verticalArrangement=Arrangement.spacedBy(10.dp)) { items(shownFields) { f -> FormField(f,row[f.key].orEmpty(),{row[f.key]=it},vm) { picked ->
            row["_title_"+f.key]=picked.optString("name")
            if(m.entity=="invoices"&&f.key=="item_id") row["unit_price"]=ExactValues.display(picked.optString(if(m.kind=="purchase") "purchase_price" else "sale_price","0"),vm.scale)
        } } }},confirmButton={TextButton(onClick={ if(index<lines.size) lines[index]=row.toMutableMap() else lines.add(row.toMutableMap()); editing=null }) { Text("ثبت ردیف") }},dismissButton={TextButton(onClick={editing=null}) { Text("انصراف") }})
    }
    if(allocationEditing!=null) {
        val index=allocationEditing!!;val row=draft.allocationEntry
        AlertDialog(onDismissRequest={allocationEditing=null},title={Text("تخصیص به فاکتور")},text={LazyColumn(verticalArrangement=Arrangement.spacedBy(12.dp)) { items(Forms.allocations(m)) { f -> FormField(f,row[f.key].orEmpty(),{row[f.key]=it},vm) { picked -> row["_title_"+f.key]=picked.optString("name") } } }},confirmButton={TextButton(onClick={if(index<draft.allocations.size) draft.allocations[index]=row.toMutableMap() else draft.allocations.add(row.toMutableMap()); allocationEditing=null}) { Text("ثبت تخصیص") }},dismissButton={TextButton(onClick={allocationEditing=null}) { Text("انصراف") }})
    }
}
@Composable internal fun FormField(f: Field,value: String,onValue:(String)->Unit,vm: AgahViewModel,error: String?=null,onPicked:(JSONObject)->Unit = {}) {
    var choose by remember { mutableStateOf(false) }; var caption by remember(f.key,f.lookup) { mutableStateOf("") }
    LaunchedEffect(value,f.lookup) {
        if(f.type==InputKind.Lookup) {
            if(value.isBlank()||value=="0") caption=""
            else try { caption=vm.lookupLabel(f.lookup,value).optString("name") }
            catch(e: Exception) { if(e is kotlinx.coroutines.CancellationException) throw e }
        }
    }
    val supporting:(@Composable ()->Unit)?=when {
        error!=null -> {{Text(error)}}
        f.type==InputKind.Money -> {{Text("واحد: ${vm.unit} • اعشار با «.» یا «٫»")}}
        f.type==InputKind.Quantity -> {{Text("اعشار با «.» یا «٫»؛ هزارگان سه‌رقمی")}}
        else -> null
    }
    val scanner=rememberLauncherForActivityResult(ScanContract()) { result -> result.contents?.let(onValue) }
    when(f.type) {
        InputKind.Flag -> Row(Modifier.fillMaxWidth().heightIn(min=48.dp).toggleable(value=value=="1",role=Role.Checkbox,onValueChange={onValue(if(it) "1" else "0")}),verticalAlignment=Alignment.CenterVertically) { Checkbox(value=="1",null); Text(f.title,Modifier.padding(start=12.dp)) }
        InputKind.Lookup -> Column(verticalArrangement=Arrangement.spacedBy(4.dp)) {
            OutlinedCard(onClick={choose=true},modifier=Modifier.fillMaxWidth().heightIn(min=64.dp),border=androidx.compose.foundation.BorderStroke(1.dp,if(error!=null) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.outline)) {
                Row(Modifier.padding(14.dp),horizontalArrangement=Arrangement.spacedBy(12.dp),verticalAlignment=Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(f.title+if(f.required) " *" else "",style=MaterialTheme.typography.labelMedium,color=MaterialTheme.colorScheme.onSurfaceVariant)
                        Text(caption.ifEmpty { if(value.isBlank()||value=="0") "انتخاب کنید" else "شناسه $value" },style=MaterialTheme.typography.bodyLarge)
                    }
                    Icon(Icons.Default.ExpandMore,null)
                }
            }
            if(error!=null) Text(error,color=MaterialTheme.colorScheme.error,style=MaterialTheme.typography.bodySmall,modifier=Modifier.semantics { liveRegion=LiveRegionMode.Polite })
        }
        InputKind.Choice -> Column(Modifier.selectableGroup(),verticalArrangement=Arrangement.spacedBy(6.dp)) {
            Text(f.title,style=MaterialTheme.typography.labelLarge)
            f.choices.forEach { (key,title) -> Surface(color=if(value==key) MaterialTheme.colorScheme.primaryContainer else MaterialTheme.colorScheme.surface,shape=MaterialTheme.shapes.medium) {
                Row(Modifier.fillMaxWidth().heightIn(min=52.dp).selectable(selected=value==key,role=Role.RadioButton,onClick={onValue(key)}).padding(horizontal=12.dp),verticalAlignment=Alignment.CenterVertically) { RadioButton(value==key,null); Text(title,Modifier.padding(start=12.dp)) }
            } }
        }
        InputKind.Date -> OutlinedButton(onClick={choose=true},modifier=Modifier.fillMaxWidth().heightIn(min=56.dp),shape=MaterialTheme.shapes.medium) { Icon(Icons.Default.CalendarMonth,null); Spacer(Modifier.width(8.dp)); Text(f.title+": "+if(value.isNotBlank()) persianDate(value) else "انتخاب تاریخ شمسی") }
        else -> OutlinedTextField(value,onValue,label={Text(f.title+if(f.required) " *" else "")},supportingText=supporting,isError=error!=null,shape=MaterialTheme.shapes.medium,textStyle=MaterialTheme.typography.bodyLarge.copy(textDirection=if(f.type in setOf(InputKind.Money,InputKind.Quantity)||f.key in setOf("phone","email","barcode","postal_code","national_id")) TextDirection.Ltr else TextDirection.Content),keyboardOptions=KeyboardOptions(keyboardType=when {f.type in setOf(InputKind.Money,InputKind.Quantity)->KeyboardType.Decimal;f.key=="phone"->KeyboardType.Phone;f.key=="email"->KeyboardType.Email;else->KeyboardType.Text}),modifier=Modifier.fillMaxWidth(),singleLine=f.key!="description"&&f.key!="address")
    }
    if(f.key=="barcode") TextButton(onClick={scanner.launch(ScanOptions().setPrompt("بارکد را داخل کادر قرار دهید").setBeepEnabled(false).setOrientationLocked(false))}) { Icon(Icons.Default.QrCodeScanner,null); Text("اسکن با دوربین") }
    if(choose&&f.type==InputKind.Lookup) LookupDialog(vm,f.lookup,{ record -> onValue(record.optString("id")); caption=record.optString("name",record.optString("code")); onPicked(record); choose=false },{choose=false})
    if(f.type==InputKind.Lookup&&!f.required&&value.isNotBlank()&&value!="0") TextButton(onClick={onValue("0");caption=""}) { Text("پاک‌کردن ${f.title}") }
    if(choose&&f.type==InputKind.Date) PersianDateDialog(value,{onValue(it); choose=false},{choose=false})
}
@Composable private fun PersianDateDialog(value: String,onPick:(String)->Unit,onDismiss:()->Unit) {
    val initial=remember { persianDate(value.ifBlank { LocalDate.now().toString() }).split('/') }
    var year by remember { mutableStateOf(initial.getOrElse(0){"1405"}) }; var month by remember { mutableStateOf(initial.getOrElse(1){"1"}) }; var day by remember { mutableStateOf(initial.getOrElse(2){"1"}) }
    var error by remember { mutableStateOf<String?>(null) }
    AlertDialog(onDismissRequest=onDismiss,title={Text("انتخاب تاریخ شمسی")},text={Column(Modifier.heightIn(max=360.dp).verticalScroll(rememberScrollState()),verticalArrangement=Arrangement.spacedBy(12.dp)) {
        OutlinedTextField(year,{year=it},label={Text("سال")},keyboardOptions=KeyboardOptions(keyboardType=KeyboardType.Number),singleLine=true)
        OutlinedTextField(month,{month=it},label={Text("ماه")},keyboardOptions=KeyboardOptions(keyboardType=KeyboardType.Number),singleLine=true)
        OutlinedTextField(day,{day=it},label={Text("روز")},keyboardOptions=KeyboardOptions(keyboardType=KeyboardType.Number),singleLine=true)
        if(error!=null) Text(error!!,color=MaterialTheme.colorScheme.error)
    }},confirmButton={TextButton(onClick={ runCatching {
        PersianDates.toIso(year,month,day)
    }.onSuccess(onPick).onFailure { error="تاریخ شمسی معتبر وارد کنید." } }) { Text("انتخاب") }},dismissButton={TextButton(onClick=onDismiss) { Text("انصراف") }})
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
        if(error!=null) InfoBanner(error!!,true)
        if(!busy&&error==null&&rows.isEmpty()) Text("نتیجه‌ای پیدا نشد؛ عبارت جست‌وجو را تغییر دهید.",style=MaterialTheme.typography.bodyMedium)
        if(source=="items") TextButton(onClick={scanner.launch(ScanOptions().setPrompt("اسکن بارکد کالا").setBeepEnabled(false).setOrientationLocked(false))},enabled=!busy) { Text("انتخاب کالا با بارکد") }
        LazyColumn(Modifier.weight(1f,false),verticalArrangement=Arrangement.spacedBy(8.dp),contentPadding=PaddingValues(vertical=8.dp)) { items(rows) { r -> OutlinedCard(onClick={onPick(r)},modifier=Modifier.fillMaxWidth(),enabled=!busy) { Column(Modifier.padding(14.dp)) { Text(r.optString("name"),style=MaterialTheme.typography.bodyLarge); if(r.optString("code").isNotBlank()) Text("کد: "+r.optString("code"),style=MaterialTheme.typography.bodySmall,color=MaterialTheme.colorScheme.onSurfaceVariant) } } } }
        Row { TextButton(onClick={load(page-1)},enabled=page>1&&!busy) { Text("قبلی") }; TextButton(onClick={load(page+1)},enabled=page<pages&&!busy) { Text("بعدی") } }
    } },confirmButton={TextButton(onClick=onDismiss) { Text("بستن") }})
}
@Composable private fun ReportScreen(vm: AgahViewModel,s: UiState) {
    var filters by remember { mutableStateOf(false) }
    var menu by remember { mutableStateOf(false) }
    val choices=listOf("ledger" to "دفاتر و تراز","parties" to "مانده اشخاص","statements" to "صورت‌های مالی","ratios" to "نسبت‌های مالی","sales" to "فروش","purchases" to "خرید","receipts" to "دریافت","payments" to "پرداخت","expenses" to "هزینه","inventory" to "موجودی فعلی انبار")
    LazyColumn(Modifier.fillMaxSize().padding(16.dp).testTag("report-list"),verticalArrangement=Arrangement.spacedBy(12.dp)) {
        item { SectionTitle("گزارش‌های مالی","تحلیل اطلاعات با همان مبنای نسخهٔ وب") }
        item { Box {
            OutlinedButton(onClick={menu=true},enabled=!s.busy,modifier=Modifier.fillMaxWidth().testTag("report-selector")) { Text(choices.firstOrNull { it.first==s.report }?.second ?: "انتخاب گزارش");Icon(Icons.Default.ArrowDropDown,null) }
            DropdownMenu(expanded=menu,onDismissRequest={menu=false}) { choices.forEach { (key,title) -> DropdownMenuItem(text={Text(title)},onClick={menu=false;vm.reports(key)},enabled=!s.busy) } }
        } }
        item { OutlinedButton(onClick={filters=true},enabled=!s.busy) { Text("بازهٔ تاریخ و نوع دفتر / تراز") } }
        item { Text("از ${persianDate(s.reportFrom.ifEmpty { s.year!!.getString("start_date") })} تا ${persianDate(s.reportTo.ifEmpty { s.year!!.getString("end_date") })}",style=MaterialTheme.typography.bodySmall) }
        if(s.report=="inventory") item { Text("موجودی فعلی در تمام دوره‌هاست؛ بازهٔ تاریخ آن را به موجودی تاریخی تبدیل نمی‌کند.") }
        if(s.report in setOf("sales","purchases")) item { Text("مبلغ تسویه‌نشده، وضعیت فعلی تسویه است؛ بازهٔ تاریخ روی تاریخ صدور فاکتور اعمال می‌شود.") }
        if(vm.can("report.export")) item { AdaptiveActions { OutlinedButton(onClick={vm.export("pdf")},enabled=!s.busy) { Icon(Icons.Default.PictureAsPdf,null);Spacer(Modifier.width(8.dp));Text("دریافت PDF این صفحه") }; OutlinedButton(onClick={vm.export("csv")},enabled=!s.busy) { Icon(Icons.Default.TableChart,null);Spacer(Modifier.width(8.dp));Text("دریافت CSV") } } }
        items(s.data.rows()) { r -> OutlinedCard(Modifier.fillMaxWidth()) { Column(Modifier.padding(14.dp),verticalArrangement=Arrangement.spacedBy(8.dp)) {
            if(s.report=="ratios") {
                Text(r.optString("name"),style=MaterialTheme.typography.titleMedium)
                Text(r.optString("description"))
                Text(r.optString("result_text")+if(r.optString("result_text")=="غیرقابل محاسبه") "" else " "+r.optString("unit_label"),color=MaterialTheme.colorScheme.primary)
                Text(r.optString("formula"),style=MaterialTheme.typography.bodySmall)
                if(r.optString("reason").isNotBlank()) Text(r.optString("reason"),style=MaterialTheme.typography.bodySmall)
            } else RecordFields(r,vm.scale,vm.unit)
        } } }
        item { s.data.optJSONObject("profit")?.let { SectionTitle("سود و زیان"); RecordFields(it,vm.scale,vm.unit) } }
        item { s.data.optJSONObject("balance")?.let { SectionTitle("ترازنامه"); RecordFields(it,vm.scale,vm.unit) } }
        items(s.data.optJSONObject("balance")?.rows() ?: emptyList()) { r -> OutlinedCard(Modifier.fillMaxWidth()) { Column(Modifier.padding(14.dp)) { RecordFields(r,vm.scale,vm.unit) } } }
        item { s.data.optJSONObject("cashflow")?.let { SectionTitle("جریان وجوه نقد"); RecordFields(it,vm.scale,vm.unit) } }
        if(s.data.rows().isEmpty()&&!s.data.has("profit")&&!s.busy) item { EmptyState("گزارش بدون داده است","بازه و فیلترهای گزارش را بررسی کنید.",Icons.Default.Assessment) }
        item { Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.SpaceBetween) {
            TextButton(onClick={vm.reports(s.report,s.reportPage-1)},enabled=s.reportPage>1&&!s.busy) { Text("قبلی") }
            Text("صفحه ${s.reportPage}")
            TextButton(onClick={vm.reports(s.report,s.reportPage+1)},enabled=s.reportPage<s.data.optInt("pages",1)&&!s.busy) { Text("بعدی") }
        } }
    }
    if(filters) {
        var from by remember { mutableStateOf(s.reportFrom.ifEmpty { s.year!!.getString("start_date") }) }
        var to by remember { mutableStateOf(s.reportTo.ifEmpty { s.year!!.getString("end_date") }) }
        var mode by remember { mutableStateOf(s.reportMode) }
        AlertDialog(onDismissRequest={filters=false},title={Text("فیلتر گزارش")},text={LazyColumn(verticalArrangement=Arrangement.spacedBy(12.dp)) {
            item { FormField(Field("from","از تاریخ",InputKind.Date),from,{from=it},vm) }
            item { FormField(Field("to","تا تاریخ",InputKind.Date),to,{to=it},vm) }
            if(s.report=="ledger") item { FormField(Field("mode","نوع گزارش",InputKind.Choice,choices=listOf("journal" to "روزنامه","general" to "دفتر کل","subsidiary" to "دفتر معین","trial2" to "تراز دو ستونی","trial4" to "تراز چهار ستونی","trial6" to "تراز شش ستونی","trial8" to "تراز هشت ستونی")),mode,{mode=it},vm) }
        }},confirmButton={TextButton(onClick={vm.reports(s.report,1,from,to,mode); filters=false}) { Text("اعمال") }},dismissButton={TextButton(onClick={filters=false}) { Text("انصراف") }})
    }
}
@Composable private fun AttachmentsScreen(vm: AgahViewModel,s: UiState,online: Boolean) {
    val picker=rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { uri -> uri?.let(vm::uploadAttachment) }
    LazyColumn(Modifier.fillMaxSize().padding(20.dp),verticalArrangement=Arrangement.spacedBy(12.dp)) {
        item { SectionTitle("پیوست‌های خصوصی","تصویر یا PDF تا دو مگابایت؛ دسترسی مطابق مجوز سند") }
        if(vm.canWrite("file.create")&&vm.canWrite(s.module!!.capability+".edit")) item { Button(onClick={picker.launch(arrayOf("image/jpeg","image/png","image/webp","application/pdf"))},enabled=!s.busy&&online&&s.pending==null) { Text("افزودن پیوست") } }
        if(s.attachments.isEmpty()&&!s.busy) item { EmptyState("هنوز پیوستی وجود ندارد","تصویر یا فایل مرتبط با این سند در این بخش نمایش داده می‌شود.",Icons.Default.AttachFile) }
        items(s.attachments) { file -> OutlinedCard(Modifier.fillMaxWidth()) { Column(Modifier.padding(16.dp)) { Text(file.optString("original_name")); Text(file.optString("byte_size")+" بایت"); TextButton(onClick={vm.downloadAttachment(file.getString("id"),file.optString("source")=="expense_image")},enabled=!s.busy&&online) { Text("دریافت / اشتراک‌گذاری") } } } }
    }
}
@Composable private fun HistoryScreen(vm: AgahViewModel,s: UiState) {
    val stock=s.module?.entity=="stock"
    LazyColumn(Modifier.fillMaxSize().padding(20.dp),verticalArrangement=Arrangement.spacedBy(12.dp)) {
        item { Text(s.historyTitle,style=MaterialTheme.typography.titleLarge); Text(if(stock) "گردش انبار در تمام دوره‌ها" else "گردش حساب در سال مالی انتخاب‌شده") }
        item {
            if(stock) {
                s.data.optJSONObject("opening")?.let { Text("ماندهٔ قبل از این صفحه: ${ExactValues.pretty(it.optString("qty","0"),10000)}") }
                s.data.optJSONObject("balance")?.let { Text("موجودی فعلی: ${ExactValues.pretty(it.optString("qty","0"),10000)}") }
            } else { RecordFields(s.data,vm.scale,vm.unit) }
        }
        items(s.data.rows()) { row -> OutlinedCard(Modifier.fillMaxWidth()) { Column(Modifier.padding(14.dp),verticalArrangement=Arrangement.spacedBy(6.dp)) {
            if(stock) { Text(persianDate(row.optString("move_date"))); Text("مانده مقدار: ${ExactValues.pretty(row.optString("balance_qty","0"),10000)}") }
            RecordFields(row,vm.scale,vm.unit)
        } } }
        if(s.data.rows().isEmpty()&&!s.busy) item { EmptyState("گردشی ثبت نشده است","برای این انتخاب، گردش قابل نمایشی وجود ندارد.") }
        item { Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.SpaceBetween) {
            TextButton(onClick={if(stock) vm.stockHistory(s.historySubject!!,s.historyPage-1) else vm.partyHistory(s.historyPage-1)},enabled=s.historyPage>1&&!s.busy) { Text("قبلی") }
            Text("صفحه ${s.historyPage}")
            TextButton(onClick={if(stock) vm.stockHistory(s.historySubject!!,s.historyPage+1) else vm.partyHistory(s.historyPage+1)},enabled=s.historyPage<s.data.optInt("pages",1)&&!s.busy) { Text("بعدی") }
        } }
        if(!stock) {
            item { Text("آخرین سوابق فاکتور و دریافت/پرداخت (تمام دوره‌ها)",style=MaterialTheme.typography.titleMedium) }
            items(s.data.rows("invoices")+s.data.rows("payments")) { row -> OutlinedCard(Modifier.fillMaxWidth()) { Column(Modifier.padding(14.dp)) { RecordFields(row,vm.scale,vm.unit) } } }
        }
    }
}
@Composable private fun DeviceScreen(vm: AgahViewModel,s: UiState) {
    LazyColumn(Modifier.fillMaxSize().padding(20.dp),verticalArrangement=Arrangement.spacedBy(12.dp)) {
        item { SectionTitle("نشست‌های فعال موبایل","دسترسی دستگاه‌ها به حساب خود را مدیریت کنید.") }
        items(s.data.rows()) { r -> Card(Modifier.fillMaxWidth()) { Column(Modifier.padding(16.dp)) { Text(r.optString("device_name")); Text(if(r.optBoolean("current")) "این دستگاه" else "دستگاه دیگر"); if(!r.optBoolean("current")) TextButton(onClick={vm.revoke(r.getString("id"))},enabled=!s.busy) { Text("لغو نشست") } } } }
    }
}
