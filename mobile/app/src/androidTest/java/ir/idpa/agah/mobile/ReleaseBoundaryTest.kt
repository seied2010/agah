package ir.idpa.agah.mobile

import android.content.pm.ApplicationInfo
import android.view.WindowManager
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.test.ext.junit.runners.AndroidJUnit4
import org.junit.Assert.*
import org.junit.Assume.assumeFalse
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import okhttp3.mockwebserver.MockWebServer

/** Runs explicitly against the non-debuggable, minified acceptance variant. */
@RunWith(AndroidJUnit4::class)
class ReleaseBoundaryTest {
    @get:Rule val compose=createAndroidComposeRule<MainActivity>()
    @Test fun packagedAppIsPrivateAndRejectsCleartextBeforeSendingCredentials() {
        assumeFalse("Install the acceptance target APK first",compose.activity.applicationInfo.flags and ApplicationInfo.FLAG_DEBUGGABLE != 0)
        assertEquals(0,compose.activity.applicationInfo.flags and ApplicationInfo.FLAG_DEBUGGABLE)
        assertEquals(0,compose.activity.applicationInfo.flags and ApplicationInfo.FLAG_ALLOW_BACKUP)
        assertNotEquals(0,compose.activity.window.attributes.flags and WindowManager.LayoutParams.FLAG_SECURE)
        MockWebServer().use { server ->
            server.start()
            compose.waitUntil(120_000) { compose.onAllNodesWithText("ورود امن").fetchSemanticsNodes().isNotEmpty()&&compose.onAllNodesWithTag("request-progress").fetchSemanticsNodes().isEmpty() }
            compose.onNodeWithText("نشانی سرور").performTextReplacement(server.url("/").toString())
            compose.onNodeWithText("نام کاربری یا ایمیل").performTextInput("fixture-only")
            compose.onNodeWithText("گذرواژه").performTextInput("not-a-production-password")
            compose.onNodeWithTag("login-form").performScrollToNode(hasText("ورود امن"))
            compose.onNodeWithText("ورود امن").performClick()
            compose.waitUntil(120_000) { compose.onAllNodesWithText("نشانی HTTPS معتبر لازم است.").fetchSemanticsNodes().isNotEmpty() }
            assertEquals(0,server.requestCount)
        }
    }
}
