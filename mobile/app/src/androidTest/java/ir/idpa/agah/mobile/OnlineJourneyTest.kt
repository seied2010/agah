package ir.idpa.agah.mobile

import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.core.app.ApplicationProvider
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
    private fun await(text: String) { compose.waitUntil(120_000) {
        compose.onAllNodesWithText(text).fetchSemanticsNodes().isNotEmpty()&&compose.onAllNodesWithTag("request-progress").fetchSemanticsNodes().isEmpty()
    } }
    @Test fun realLoginCompanySelectionAndRotatedForm() {
        await("ورود امن")
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
        await(name)
        await("متوجه شدم")
        compose.onNodeWithText("متوجه شدم").performClick()
        compose.onNodeWithContentDescription("خروج امن").performClick()
        await("ورود امن")
    }
}
