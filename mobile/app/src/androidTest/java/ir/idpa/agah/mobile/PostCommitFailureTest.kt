package ir.idpa.agah.mobile

import android.app.Application
import androidx.test.core.app.ApplicationProvider
import androidx.test.ext.junit.runners.AndroidJUnit4
import ir.idpa.agah.mobile.data.SessionVault
import ir.idpa.agah.mobile.ui.AgahViewModel
import ir.idpa.agah.mobile.ui.modules
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.runBlocking
import kotlinx.coroutines.withTimeout
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith

/** Synthetic network failures, not a substitute for the separate real-server journey. */
@RunWith(AndroidJUnit4::class)
class PostCommitFailureTest {
    private fun response(data: String)=MockResponse().setHeader("Content-Type","application/json")
        .setBody("{\"api_version\":\"1\",\"data\":$data,\"request_id\":\"0123456789abcdef\"}")
    private suspend fun idle(vm: AgahViewModel) { withTimeout(120_000) { vm.state.first { !it.busy } } }
    private suspend fun prepare(server: MockWebServer): AgahViewModel {
        val app=ApplicationProvider.getApplicationContext<Application>()
        SessionVault(app).apply { clear(); pending=null }
        val vm=AgahViewModel(app); idle(vm)
        server.enqueue(response("""{"access_token":"1.test-access","refresh_token":"1.test-refresh","user":{"id":"1","name":"test"}}"""))
        server.enqueue(response("""{"rows":[{"id":"1","name":"test","display_unit":"rial"}]}"""))
        vm.login(server.url("/").toString(),"fixture","fixture","");idle(vm)
        server.enqueue(response("""{"company":{"id":"1"},"years":[{"id":"1","jy":"1405","start_date":"2026-03-21","end_date":"2027-03-20"}],"mode":"write","capabilities":["party.view","party.create","dashboard.view","fiscal_year.view"]}"""))
        vm.selectCompany(vm.state.value.companies.first());idle(vm)
        server.enqueue(response("""{"cards":{},"months":[],"date":"2026-11-16"}"""))
        vm.selectYear(vm.state.value.context!!.getJSONArray("years").getJSONObject(0));idle(vm)
        server.enqueue(response("""{"rows":[],"page":"1","pages":"0"}"""))
        vm.openModule(modules.first());idle(vm);vm.edit(true)
        return vm
    }
    private fun clean(vm: AgahViewModel) { vm.repository.vault.apply { pending=null; clear(); server="https://idpa.ir" } }
    @Test fun failedRefreshCannotReopenCommittedForm()=runBlocking {
        MockWebServer().use { server -> server.start(); val vm=prepare(server)
            try {
                server.enqueue(response("""{"id":"52","entity":"parties","state":"committed"}"""))
                server.enqueue(MockResponse().setResponseCode(500).setBody("upstream unavailable"))
                vm.submit(JSONObject("""{"name":"test","kind":"real","is_customer":true,"is_active":true,"credit_limit":"0"}"""));idle(vm)
                assertEquals("list",vm.state.value.screen);assertNull(vm.state.value.pending)
                assertTrue(vm.state.value.error!!.contains("52"))
                val requests=(0 until server.requestCount).map { server.takeRequest() }
                assertEquals(1,requests.count { it.method=="POST"&&it.path!!.contains("/companies/1/parties") })
            } finally { clean(vm) }
        }
    }
    @Test fun incompleteReceiptRemainsUncertainUntilServerConfirms()=runBlocking {
        MockWebServer().use { server -> server.start(); val vm=prepare(server)
            try {
                server.enqueue(response("{}"))
                vm.submit(JSONObject("""{"name":"test","kind":"real","is_customer":true,"is_active":true,"credit_limit":"0"}"""));idle(vm)
                assertEquals("form",vm.state.value.screen);assertNotNull(vm.state.value.pending)
                server.enqueue(response("""{"state":"committed","result":{"id":"52","state":"committed"}}"""))
                vm.checkPending();idle(vm)
                assertNull(vm.state.value.pending);assertNotEquals("form",vm.state.value.screen)
            } finally { clean(vm) }
        }
    }
    @Test fun expiredRefreshReturnsToLoginAndRetainsOwnerReceipt()=runBlocking {
        MockWebServer().use { server -> server.start();val vm=prepare(server)
            try {
                vm.repository.vault.pending="owner-only-receipt"
                val denied=MockResponse().setResponseCode(401).setBody("""{"code":"agah_mobile_auth","message":"expired","data":{"status":401}}""")
                server.enqueue(denied);server.enqueue(denied.clone())
                vm.dashboard();idle(vm)
                assertFalse(vm.state.value.signedIn);assertNull(vm.state.value.company);assertEquals(0,vm.state.value.data.length())
                server.enqueue(response("""{"access_token":"1.test-access","refresh_token":"1.test-refresh","user":{"id":"1","name":"test"}}"""))
                server.enqueue(response("""{"rows":[]}"""))
                vm.login(server.url("/").toString(),"fixture","fixture","");idle(vm)
                assertEquals("owner-only-receipt",vm.state.value.pending)
            } finally { clean(vm) }
        }
    }
    @Test fun failedRemoteLogoutStillErasesLocalCredentials()=runBlocking {
        MockWebServer().use { server -> server.start();val vm=prepare(server)
            try {
                server.enqueue(MockResponse().setResponseCode(503).setBody("unavailable"))
                vm.logout();idle(vm)
                assertFalse(vm.state.value.signedIn);assertNull(vm.repository.vault.refresh())
                assertTrue(vm.state.value.error!!.contains("ابطال نشست سرور تأیید نشد"))
            } finally { clean(vm) }
        }
    }
    @Test fun droppedReadConnectionCanRecoverWithoutLeavingDashboard()=runBlocking {
        MockWebServer().use { server -> server.start();val vm=prepare(server)
            try {
                server.enqueue(MockResponse().setSocketPolicy(okhttp3.mockwebserver.SocketPolicy.DISCONNECT_AFTER_REQUEST))
                server.enqueue(response("""{"cards":{},"months":[],"date":"2026-11-16"}"""))
                vm.dashboard();idle(vm)
                assertEquals("dashboard",vm.state.value.screen);assertNull(vm.state.value.error)
            } finally { clean(vm) }
        }
    }
    @Test fun droppedWriteConnectionIsNeverAutomaticallyReplayed()=runBlocking {
        MockWebServer().use { server -> server.start();val vm=prepare(server)
            try {
                val before=server.requestCount
                server.enqueue(MockResponse().setSocketPolicy(okhttp3.mockwebserver.SocketPolicy.DISCONNECT_AFTER_REQUEST))
                server.enqueue(response("""{"id":"52","entity":"parties","state":"committed"}"""))
                vm.submit(JSONObject("""{"name":"test","kind":"real","is_customer":true,"is_active":true,"credit_limit":"0"}"""));idle(vm)
                assertEquals(before+1,server.requestCount);assertNotNull(vm.state.value.pending)
                assertEquals("form",vm.state.value.screen)
            } finally { clean(vm) }
        }
    }
    @Test fun unrecognizedProxy401DoesNotReplayFinancialWrite()=runBlocking {
        MockWebServer().use { server -> server.start();val vm=prepare(server)
            try {
                val before=server.requestCount
                server.enqueue(MockResponse().setResponseCode(401).setBody("proxy authentication failure"))
                vm.submit(JSONObject("""{"name":"test","kind":"real","is_customer":true,"is_active":true,"credit_limit":"0"}"""));idle(vm)
                assertEquals(before+1,server.requestCount);assertNotNull(vm.repository.vault.pending)
                assertNotNull(vm.repository.vault.refresh())
            } finally { clean(vm) }
        }
    }
    @Test fun companyAccessRemovalClearsItsCachedScreenOnResume()=runBlocking {
        MockWebServer().use { server -> server.start();val vm=prepare(server)
            try {
                server.enqueue(MockResponse().setResponseCode(403).setBody("""{"error":{"message":"access removed","fields":{}},"request_id":"0123456789abcdef"}"""))
                server.enqueue(response("""{"rows":[]}"""))
                vm.revalidate();idle(vm)
                assertTrue(vm.state.value.signedIn);assertNull(vm.state.value.company)
                assertNull(vm.state.value.module);assertEquals(0,vm.state.value.data.length())
                assertTrue(vm.state.value.error!!.contains("دسترسی"))
            } finally { clean(vm) }
        }
    }
    @Test fun malformedStatusAndCancelReceiptsCannotEraseUncertainWrite()=runBlocking {
        MockWebServer().use { server -> server.start();val vm=prepare(server)
            try {
                server.enqueue(response("{}"))
                vm.submit(JSONObject("""{"name":"test","kind":"real","is_customer":true,"is_active":true,"credit_limit":"0"}"""));idle(vm)
                assertNotNull(vm.state.value.pending)
                server.enqueue(response("""{"state":"committed","result":{}}"""))
                vm.checkPending();idle(vm);assertNotNull(vm.state.value.pending)
                server.enqueue(response("""{"state":"committed"}"""))
                vm.cancelPending();idle(vm);assertNotNull(vm.state.value.pending)
                assertNotNull(vm.repository.vault.pending)
            } finally { clean(vm) }
        }
    }
    @Test fun yearSelectionCannotRaceAndDoesNotReuseOldReportDates()=runBlocking {
        MockWebServer().use { server -> server.start();val vm=prepare(server)
            try {
                server.enqueue(response("""{"rows":[]}"""))
                vm.reports();idle(vm);assertTrue(vm.state.value.reportFrom.isNotEmpty())
                val year=vm.state.value.year!!
                val other=JSONObject(year.toString()).put("id","2")
                server.enqueue(response("""{"cards":{},"months":[],"date":"2026-11-16"}""").setBodyDelay(300,java.util.concurrent.TimeUnit.MILLISECONDS))
                vm.selectYear(year);vm.selectYear(other);idle(vm)
                assertEquals(year.getString("id"),vm.state.value.year!!.getString("id"))
                assertEquals("",vm.state.value.reportFrom);assertEquals("",vm.state.value.reportTo)
            } finally { clean(vm) }
        }
    }
    @Test fun reportRangeOutsideSelectedYearIsNotSilentlyDisplayedAsApplied()=runBlocking {
        MockWebServer().use { server -> server.start();val vm=prepare(server)
            try {
                val before=server.requestCount
                vm.reports("ledger",1,"2000-01-01","2026-04-01");idle(vm)
                assertEquals(before,server.requestCount)
                assertTrue(vm.state.value.error!!.contains("سال مالی"))
            } finally { clean(vm) }
        }
    }
}
