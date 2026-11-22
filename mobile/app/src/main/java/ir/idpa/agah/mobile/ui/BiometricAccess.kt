package ir.idpa.agah.mobile.ui

import android.content.Intent
import androidx.activity.compose.LocalActivity
import android.os.Build
import android.provider.Settings
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity
import androidx.lifecycle.Lifecycle
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Fingerprint
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.saveable.rememberSaveableStateHolder
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import ir.idpa.agah.mobile.data.BiometricAction
import ir.idpa.agah.mobile.data.BiometricRequest

data class BiometricAvailability(val available: Boolean,val message: String)
data class BiometricActions(val state: LocalSecurityState,val availability: BiometricAvailability,val busy: Boolean,val run: (BiometricAction)->Unit,val deviceSettings: ()->Unit)

/** The success callback alone cannot open the vault: the authenticated CryptoObject must decrypt its key. */
class BiometricHost(private val activity: FragmentActivity,private val vm: AgahViewModel) {
    private var prompt: BiometricPrompt?=null
    private var request: BiometricRequest?=null
    fun availability(): BiometricAvailability {
        val code=BiometricManager.from(activity).canAuthenticate(BiometricManager.Authenticators.BIOMETRIC_STRONG)
        return BiometricAvailability(code==BiometricManager.BIOMETRIC_SUCCESS,when(code) {
            BiometricManager.BIOMETRIC_SUCCESS -> "اثر انگشت یا زیست‌سنجی قویِ ثبت‌شدهٔ دستگاه قابل استفاده است."
            BiometricManager.BIOMETRIC_ERROR_NONE_ENROLLED -> "ابتدا اثر انگشت را در تنظیمات امنیتی گوشی ثبت کنید."
            BiometricManager.BIOMETRIC_ERROR_NO_HARDWARE -> "این دستگاه حسگر زیست‌سنجی سازگار ندارد؛ ورود با گذرواژه در دسترس است."
            else -> "حسگر فعلاً آماده نیست؛ دوباره تلاش کنید یا با گذرواژه وارد شوید."
        })
    }
    fun authenticate(action: BiometricAction) {
        if(!availability().available)return
        val pending=vm.prepareBiometric(action) ?: return
        request=pending
        val current=BiometricPrompt(activity,ContextCompat.getMainExecutor(activity),object: BiometricPrompt.AuthenticationCallback() {
            override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult) {
                if(request!==pending)return
                request=null
                val cipher=result.cryptoObject?.cipher
                if(cipher==null||!activity.lifecycle.currentState.isAtLeast(Lifecycle.State.STARTED))vm.cancelBiometric(pending)
                else vm.completeBiometric(pending,cipher)
            }
            override fun onAuthenticationError(code: Int,description: CharSequence) {
                if(request!==pending)return
                request=null
                vm.cancelBiometric(pending,when(code) {
                    BiometricPrompt.ERROR_CANCELED,BiometricPrompt.ERROR_USER_CANCELED,BiometricPrompt.ERROR_NEGATIVE_BUTTON -> "ورود با اثر انگشت لغو شد؛ می‌توانید دوباره تلاش کنید."
                    BiometricPrompt.ERROR_LOCKOUT,BiometricPrompt.ERROR_LOCKOUT_PERMANENT -> "حسگر موقتاً قفل شده است؛ با گذرواژهٔ حساب وارد شوید یا پس از بازکردن قفل دستگاه دوباره تلاش کنید."
                    else -> "اثر انگشت تأیید نشد؛ دوباره تلاش کنید یا با گذرواژهٔ حساب وارد شوید."
                })
            }
        })
        prompt=current
        try {
            current.authenticate(BiometricPrompt.PromptInfo.Builder().setTitle(when(action) {
                BiometricAction.ENABLE -> "فعال‌سازی ورود با اثر انگشت"
                BiometricAction.UNLOCK -> "ورود امن به آگاه"
                BiometricAction.DISABLE -> "تأیید غیرفعال‌سازی اثر انگشت"
            }).setSubtitle("از زیست‌سنجی ثبت‌شدهٔ همین دستگاه استفاده کنید.")
                .setAllowedAuthenticators(BiometricManager.Authenticators.BIOMETRIC_STRONG)
                .setNegativeButtonText("انصراف").setConfirmationRequired(true).build(),BiometricPrompt.CryptoObject(pending.cipher))
        } catch(e: Exception) {request=null;vm.cancelBiometric(pending,"حسگر آماده نیست؛ دوباره تلاش کنید یا با گذرواژه وارد شوید.")}
    }
    fun cancel() {val active=request;request=null;if(active!=null)vm.cancelBiometric(active);prompt?.cancelAuthentication();prompt=null}
    fun deviceSettings() {
        val intent=if(Build.VERSION.SDK_INT>=30)Intent(Settings.ACTION_BIOMETRIC_ENROLL).putExtra(Settings.EXTRA_BIOMETRIC_AUTHENTICATORS_ALLOWED,BiometricManager.Authenticators.BIOMETRIC_STRONG)
        else Intent(Settings.ACTION_SECURITY_SETTINGS)
        runCatching {activity.startActivity(intent)}
    }
}

@Composable fun SecureAgahApp(vm: AgahViewModel,host: BiometricHost) {
    val security by vm.security.collectAsStateWithLifecycle()
    val state by vm.state.collectAsStateWithLifecycle()
    var available by remember {mutableStateOf(host.availability())}
    val activity=checkNotNull(LocalActivity.current as? FragmentActivity)
    DisposableEffect(activity,host) {
        val observer=LifecycleEventObserver {_,event->if(event==Lifecycle.Event.ON_RESUME)available=host.availability()}
        activity.lifecycle.addObserver(observer)
        onDispose {activity.lifecycle.removeObserver(observer)}
    }
    key(vm.repository.vault.server,vm.repository.vault.owner) {
        val saved=rememberSaveableStateHolder()
        var confirmPassword by rememberSaveable {mutableStateOf(false)}
        if(security.locked) {
            val context=LocalContext.current
            val dark=context.getSharedPreferences("agah-appearance",android.content.Context.MODE_PRIVATE).getBoolean("dark",false)
            CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Rtl) {
                MaterialTheme(colorScheme=if(dark)AgahDark else AgahLight,typography=AgahType,shapes=AgahShapes) {
                    BiometricLockScreen(security,available,state.busy,{host.authenticate(BiometricAction.UNLOCK)},{if(state.signedIn)confirmPassword=true else vm.usePasswordInstead()},host::deviceSettings)
                    if(confirmPassword)AlertDialog(onDismissRequest={confirmPassword=false},title={Text("ورود دوباره با گذرواژه")},text={Text("فضای کار فعلی و اطلاعات ثبت‌نشده بسته می‌شود. عملیات ثبت‌شده و رسیدهای نامشخص حفظ می‌شوند. ادامه می‌دهید؟")},confirmButton={TextButton(onClick={confirmPassword=false;vm.usePasswordInstead()},enabled=!state.busy) {Text("ادامه و ورود دوباره")}},dismissButton={TextButton(onClick={confirmPassword=false}) {Text("بازگشت")}})
                }
            }
        } else saved.SaveableStateProvider("work") {AgahApp(vm,BiometricActions(security,available,state.busy,host::authenticate,host::deviceSettings))}
    }
}

@Composable fun BiometricLockScreen(state: LocalSecurityState,available: BiometricAvailability,busy: Boolean,onUnlock: ()->Unit,onPassword: ()->Unit,onDeviceSettings: ()->Unit) {
    Surface(Modifier.fillMaxSize(),color=MaterialTheme.colorScheme.background) {
        Box(Modifier.fillMaxSize().safeDrawingPadding(),contentAlignment=Alignment.Center) {
            LazyColumn(Modifier.widthIn(max=520.dp).fillMaxWidth().padding(24.dp).testTag("biometric-lock"),verticalArrangement=Arrangement.spacedBy(18.dp),horizontalAlignment=Alignment.CenterHorizontally) {
                item {BrandMark()}
                item {SectionTitle("ورود امن به آگاه","برای بازکردن فضای کار، هویت خود را تأیید کنید.")}
                item {Surface(shape=MaterialTheme.shapes.extraLarge,color=MaterialTheme.colorScheme.primaryContainer) {Icon(Icons.Default.Fingerprint,null,Modifier.padding(24.dp).size(64.dp),tint=MaterialTheme.colorScheme.onPrimaryContainer)}}
                item {Text(available.message,style=MaterialTheme.typography.bodyMedium)}
                if(state.message!=null)item {InfoBanner(state.message,true)}
                if(busy)item {LinearProgressIndicator(Modifier.fillMaxWidth());Text("بررسی نشست و دسترسی‌ها…")}
                item {Button(onClick=onUnlock,enabled=available.available&&!busy&&!state.prompting,modifier=Modifier.fillMaxWidth().heightIn(min=56.dp)) {Icon(Icons.Default.Fingerprint,null);Spacer(Modifier.width(8.dp));Text("ورود با اثر انگشت")}}
                item {OutlinedButton(onClick=onPassword,enabled=!busy&&!state.prompting,modifier=Modifier.fillMaxWidth().heightIn(min=48.dp)) {Text("ورود با گذرواژهٔ حساب")}}
                if(!available.available)item {TextButton(onClick=onDeviceSettings,enabled=!busy&&!state.prompting) {Text("تنظیمات امنیتی گوشی")}}
                item {Text("اثر انگشت در اپ ذخیره یا به سرور ارسال نمی‌شود.",style=MaterialTheme.typography.bodySmall,color=MaterialTheme.colorScheme.onSurfaceVariant)}
            }
        }
    }
}

@Composable fun BiometricSettingsDialog(actions: BiometricActions,onDismiss: ()->Unit) {
    AlertDialog(onDismissRequest=onDismiss,icon={Icon(Icons.Default.Lock,null)},title={Text("ورود با اثر انگشت")},text={Column(Modifier.heightIn(max=440.dp).verticalScroll(rememberScrollState()),verticalArrangement=Arrangement.spacedBy(12.dp)) {
        Text(if(actions.state.enabled)"ورود با اثر انگشت روی این گوشی فعال است. پس از رفتن اپ به پس‌زمینه، فضای کار قفل می‌شود." else "برای ورودهای بعدی، می‌توانید از اثر انگشت یا زیست‌سنجی قوی همین گوشی استفاده کنید. ورود اولیه و بررسی دسترسی‌ها همچنان با حساب سامانه است.")
        Text(actions.availability.message)
        if(!actions.availability.available)TextButton(onClick=actions.deviceSettings,enabled=!actions.busy&&!actions.state.prompting) {Text("تنظیمات امنیتی گوشی")}
        Text("با تغییر اثر انگشت‌های ثبت‌شده ممکن است ورود دوباره با گذرواژه لازم شود. گذرواژه در اپ ذخیره نمی‌شود.",style=MaterialTheme.typography.bodySmall)
        actions.state.message?.let {InfoBanner(it,!it.endsWith("شد."))}
    }},confirmButton={TextButton(onClick={actions.run(if(actions.state.enabled)BiometricAction.DISABLE else BiometricAction.ENABLE)},enabled=actions.availability.available&&!actions.busy&&!actions.state.prompting) {Text(if(actions.state.enabled)"غیرفعال‌سازی با تأیید اثر انگشت" else "فعال‌سازی با اثر انگشت")}},dismissButton={TextButton(onClick=onDismiss) {Text("بستن")}})
}
