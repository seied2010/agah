package ir.idpa.agah.mobile

import android.app.Application
import android.graphics.Bitmap
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.asAndroidBitmap
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.unit.Density
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.test.core.app.ApplicationProvider
import ir.idpa.agah.mobile.ui.*
import org.junit.Assert.*
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode
import org.robolectric.annotation.LooperMode
import java.io.File

/** Real Compose layout/rasterization; no server credentials or financial mutations. */
@RunWith(RobolectricTestRunner::class)
@Config(sdk=[28],qualifiers="w360dp-h800dp-xhdpi")
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@LooperMode(LooperMode.Mode.PAUSED)
class PresentationTest {
    @get:Rule val compose=createComposeRule()
    @Before fun resetAppearance() {
        ApplicationProvider.getApplicationContext<Application>().getSharedPreferences("agah-appearance",0).edit().clear().commit()
    }
    private var rootView: android.view.View?=null
    private fun screenshot(name: String) {
        val directory=File("build/reports/ui-screenshots").apply {mkdirs()}
        compose.runOnIdle {
            val view=checkNotNull(rootView)
            val bitmap=Bitmap.createBitmap(view.width,view.height,Bitmap.Config.ARGB_8888)
            view.draw(android.graphics.Canvas(bitmap))
            File(directory,"$name.png").outputStream().use {bitmap.compress(Bitmap.CompressFormat.PNG,100,it)}
            bitmap.recycle()
        }
    }
    @Test fun realLoginLightAndPasswordVisibility() {
        val vm=AgahViewModel(ApplicationProvider.getApplicationContext<Application>())
        compose.setContent {
            rootView=androidx.compose.ui.platform.LocalView.current.rootView
            AgahApp(vm) }
        compose.waitForIdle()
        compose.onNodeWithText("نشانی سرور").assertExists()
        screenshot("login-light-phone-top")
        compose.onNodeWithTag("login-form").performScrollToNode(hasText("ورود امن"))
        compose.onNodeWithText("ورود امن").assertIsNotEnabled()
        screenshot("login-light-phone")
        compose.onNodeWithTag("login-form").performScrollToNode(hasText("گذرواژه"))
        compose.onNodeWithText("گذرواژه").performTextInput("test-only")
        compose.onNodeWithContentDescription("نمایش گذرواژه").performClick()
        compose.onNodeWithContentDescription("پنهان کردن گذرواژه").assertExists()
        compose.onNodeWithTag("login-form").performScrollToNode(hasText("نام کاربری یا ایمیل"))
        compose.onNodeWithText("نام کاربری یا ایمیل").performTextInput("test-only")
        compose.onNodeWithTag("login-form").performScrollToNode(hasText("ورود امن"))
        compose.onNodeWithText("ورود امن").assertIsEnabled()
        compose.onNodeWithContentDescription("تغییر روشنایی").performClick()
        screenshot("login-dark-phone")
    }
    @Test fun largeFontActionsWrapAndRemainTouchable() {
        compose.setContent {
            rootView=androidx.compose.ui.platform.LocalView.current.rootView
            CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Rtl,LocalDensity provides Density(LocalDensity.current.density,1.5f)) {
                MaterialTheme(colorScheme=AgahLight,typography=AgahType,shapes=AgahShapes) {
                    Surface(Modifier.fillMaxSize()) { Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp),verticalArrangement=Arrangement.spacedBy(16.dp)) {
                        OverviewHero("نمای کلی شرکت آگاه","مدیریت مالی با دید روشن")
                        SectionTitle("گزارش‌های مالی","نمایش با قلم بزرگ")
                        AdaptiveActions {
                            OutlinedButton(onClick={}) {Text("دریافت PDF این صفحه")}
                            OutlinedButton(onClick={}) {Text("دریافت CSV")}
                        }
                        InfoBanner("حالت فقط مشاهده؛ ثبت با اجازهٔ مدیر فعال می‌شود.")
                        EmptyState("گزارش بدون داده است","بازه و فیلترهای گزارش را بررسی کنید.")
                    } }
                }
            }
        }
        compose.onNodeWithText("دریافت PDF این صفحه").assertIsDisplayed()
        compose.onNodeWithText("دریافت CSV").assertIsDisplayed()
        val first=compose.onNodeWithText("دریافت PDF این صفحه").fetchSemanticsNode().boundsInRoot
        val second=compose.onNodeWithText("دریافت CSV").fetchSemanticsNode().boundsInRoot
        assertTrue("Actions must wrap instead of overlapping",second.top>=first.bottom)
        screenshot("large-font-phone")
    }
    @Test @Config(qualifiers="w800dp-h600dp-xhdpi") fun tabletLandscapeUsesReadableCenteredLogin() {
        val vm=AgahViewModel(ApplicationProvider.getApplicationContext<Application>())
        compose.setContent {
            rootView=androidx.compose.ui.platform.LocalView.current.rootView
            AgahApp(vm) }
        compose.waitForIdle()
        val field=compose.onNodeWithText("نشانی سرور").fetchSemanticsNode().boundsInRoot
        val root=compose.onRoot().fetchSemanticsNode().boundsInRoot
        assertTrue("Form should not span tablet edge to edge",field.left>root.left+100)
        compose.onNodeWithTag("login-form").performScrollToNode(hasText("ورود امن"))
        compose.onNodeWithText("ورود امن").assertIsDisplayed()
        screenshot("login-tablet-landscape")
    }
    @Test @Config(qualifiers="w800dp-h1000dp-xhdpi") fun dashboardComponentsTablet() {
        compose.setContent {
            rootView=androidx.compose.ui.platform.LocalView.current.rootView
            CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Rtl) {
                MaterialTheme(colorScheme=AgahLight,typography=AgahType,shapes=AgahShapes) {
                    Surface(Modifier.fillMaxSize(),color=MaterialTheme.colorScheme.background) {
                        Column(Modifier.verticalScroll(rememberScrollState()).padding(24.dp),verticalArrangement=Arrangement.spacedBy(16.dp)) {
                            OverviewHero("نمای کلی شرکت آگاه","نمونهٔ چیدمان؛ مقادیر این آزمون صرفاً ساختگی‌اند.")
                            Row(horizontalArrangement=Arrangement.spacedBy(12.dp)) {
                                Box(Modifier.weight(1f)) {MetricCard("فروش خالص","128450000000",100,"ریال")}
                                Box(Modifier.weight(1f)) {MetricCard("ماندهٔ دریافتنی","24900000000",100,"ریال")}
                            }
                            SectionTitle("فضای کار","دسترسی سریع به عملیات روزانه")
                            modules.take(6).chunked(2).forEach { pair -> Row(horizontalArrangement=Arrangement.spacedBy(12.dp)) {
                                pair.forEach { m -> Box(Modifier.weight(1f)) {WorkspaceTile(m,true) {}} }
                            } }
                        }
                    }
                }
            }
        }
        compose.onNodeWithText("فاکتور فروش").assertIsDisplayed()
        screenshot("dashboard-components-tablet")
    }
    @Test @Config(qualifiers="w320dp-h800dp-xhdpi") fun narrowFormAndErrorUseFullWidthTargets() {
        val vm=AgahViewModel(ApplicationProvider.getApplicationContext<Application>())
        compose.setContent {
            rootView=androidx.compose.ui.platform.LocalView.current.rootView
            CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Rtl,LocalDensity provides Density(LocalDensity.current.density,1.5f)) {
                MaterialTheme(colorScheme=AgahLight,typography=AgahType,shapes=AgahShapes) {
                    Surface(Modifier.fillMaxSize()) { Column(Modifier.verticalScroll(rememberScrollState()).padding(16.dp),verticalArrangement=Arrangement.spacedBy(12.dp)) {
                        SectionTitle("ثبت دریافت","نمایش با قلم بزرگ")
                        FormField(Field("amount","مبلغ",InputKind.Money),"",{},vm,error="مبلغ معتبر وارد کنید.")
                        FormField(Field("advance","علی‌الحساب",InputKind.Flag),"1",{},vm)
                        FormField(Field("method","روش پرداخت",InputKind.Choice,choices=listOf("cash" to "نقد","card" to "کارت")),"cash",{},vm)
                    } }
                }
            }
        }
        compose.onNodeWithText("مبلغ معتبر وارد کنید.").assertIsDisplayed()
        val flag=compose.onNode(isToggleable()).fetchSemanticsNode().boundsInRoot
        assertTrue("Checkbox row touch height >=48dp",flag.height>=48*compose.density.density)
        compose.onNodeWithText("نقد").assertIsDisplayed()
        screenshot("form-narrow-large-font")
    }

}
