package ir.idpa.agah.mobile

import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.core.app.ApplicationProvider
import androidx.test.platform.app.InstrumentationRegistry
import androidx.compose.ui.graphics.asAndroidBitmap
import android.graphics.Bitmap
import ir.idpa.agah.mobile.data.SessionVault
import org.junit.rules.ExternalResource
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith

/** Requires the isolated local backend fixture; never points at a production host. */
@RunWith(AndroidJUnit4::class)
class OnlineJourneyTest {
    @get:Rule(order=0) val resetSession=object: ExternalResource() {
        override fun before() { SessionVault(ApplicationProvider.getApplicationContext()).apply { clear(); pending=null; server="https://idpa.ir" } }
    }
    @get:Rule(order=1) val compose=createAndroidComposeRule<MainActivity>()
    private fun await(text: String) {
        try { compose.waitUntil(120_000) {
            val ready=compose.onAllNodesWithText(text).fetchSemanticsNodes().isNotEmpty()&&compose.onAllNodesWithTag("request-progress").fetchSemanticsNodes().isEmpty()
            if(!ready&&text!="متوجه شدم"&&compose.onAllNodesWithText("پیام سامانه").fetchSemanticsNodes().isNotEmpty()) throw AssertionError("Unexpected application dialog while awaiting $text")
            ready
        } } catch(error: Throwable) {
            compose.onAllNodes(isRoot()).fetchSemanticsNodes().forEachIndexed { index,_ ->
                val root=compose.onAllNodes(isRoot())[index]
                runCatching { root.printToLog("AgahUiTest") }
                runCatching { java.io.File(compose.activity.cacheDir,"agah-ui-failure-$index.png").outputStream().use { root.captureToImage().asAndroidBitmap().compress(Bitmap.CompressFormat.PNG,100,it) } }
            }
            throw error
        }
    }
    @Test fun realLoginCompanySelectionAndRotatedForm() {
        // Screenshot diagnostics are confined to this test, on disposable fixture data.
        compose.runOnIdle { compose.activity.window.clearFlags(android.view.WindowManager.LayoutParams.FLAG_SECURE) }
        await("ورود امن")
        if(InstrumentationRegistry.getArguments().getString("dark")=="true") compose.onNodeWithContentDescription("تغییر روشنایی").performClick()
        compose.onNodeWithText("نشانی سرور").performTextReplacement("http://10.0.2.2:18085")
        compose.onNodeWithText("نام کاربری یا ایمیل").performTextInput("mobiletest")
        compose.onNodeWithText("گذرواژه").performTextInput("LocalIntegration-2026-Only")
        compose.onNodeWithTag("login-form").performScrollToNode(hasText("ورود امن"))
        compose.onNodeWithText("ورود امن").performClick()
        await("شرکت را انتخاب کنید")
        compose.onNodeWithText("تست مستقل اپ").performClick()
        await("1405")
        compose.onNodeWithText("1405").performClick()
        await("نمای کلی تست مستقل اپ")
        compose.onRoot().captureToImage().asAndroidBitmap().let { bitmap ->
            java.io.File(compose.activity.cacheDir,"agah-dashboard-test.png").outputStream().use { bitmap.compress(Bitmap.CompressFormat.PNG,100,it) }
        }
        compose.onNodeWithTag("dashboard-grid").performScrollToNode(hasText("گزارش‌های مالی"))
        compose.onNodeWithText("گزارش‌های مالی").performClick()
        await("بازهٔ تاریخ و نوع دفتر / تراز")
        compose.onNodeWithTag("report-selector").performClick()
        compose.onNodeWithText("نسبت‌های مالی").performClick()
        await("نسبت‌های مالی")
        compose.onNodeWithTag("report-list").performScrollToNode(hasText("نسبت جاری"))
        compose.onNodeWithText("نسبت جاری").assertExists()
        compose.onNodeWithContentDescription("بازگشت").performClick()
        await("نمای کلی تست مستقل اپ")
        compose.onNodeWithTag("dashboard-grid").performScrollToNode(hasText("اشخاص"))
        compose.onNodeWithText("اشخاص").assertIsEnabled().performClick()
        await("جست‌وجو")
        compose.onNodeWithContentDescription("ثبت جدید").performClick()
        await("نام شخص *")
        val name="آزمون واقعی اندروید " + System.currentTimeMillis()
        compose.onNodeWithText("نام شخص *").performTextInput(name)
        compose.activityRule.scenario.recreate()
        await(name)
        compose.onNodeWithTag("entity-form").performScrollToNode(hasText("ثبت در سرور"))
        compose.onNodeWithText("ثبت در سرور").performClick()
        await("متوجه شدم")
        compose.onNodeWithText("متوجه شدم").performClick()
        await(name)
        compose.onNodeWithTag("record-detail").assertExists()
        compose.onNodeWithContentDescription("خروج امن").performClick()
        await("ورود امن")
    }
}
