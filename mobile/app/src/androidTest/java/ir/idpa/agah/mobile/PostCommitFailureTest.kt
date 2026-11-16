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
}
