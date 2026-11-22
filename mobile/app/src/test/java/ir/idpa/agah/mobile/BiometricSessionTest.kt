package ir.idpa.agah.mobile

import android.app.Application
import androidx.test.core.app.ApplicationProvider
import ir.idpa.agah.mobile.data.*
import ir.idpa.agah.mobile.ui.AgahViewModel
import kotlinx.coroutines.*
import okhttp3.mockwebserver.Dispatcher
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import okhttp3.mockwebserver.RecordedRequest
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Before
import org.junit.After
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.Shadows.shadowOf
import org.robolectric.annotation.Config
import org.robolectric.annotation.LooperMode
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit

@RunWith(RobolectricTestRunner::class)
@Config(sdk=[28])
@LooperMode(LooperMode.Mode.PAUSED)
class BiometricSessionTest {
    private lateinit var app: Application
    private lateinit var server: MockWebServer
    private lateinit var vault: SessionVault
    private lateinit var repository: AgahRepository
    @Before fun setup(){app=ApplicationProvider.getApplicationContext();app.getSharedPreferences("agah_session",0).edit().clear().commit();server=MockWebServer();server.start();vault=SessionVault(app,TestVaultKeys());vault.server=server.url("/").toString().trimEnd('/');vault.saveRefresh("7.original","7");repository=AgahRepository(vault)}
    @After fun teardown(){server.shutdown()}
    private fun success(data: String)=MockResponse().setHeader("Content-Type","application/json").setBody("{\"api_version\":\"1\",\"data\":$data}")
    private fun tokens(refresh: String="7.rotated",user: String="7")=success("{\"access_token\":\"$user.access\",\"refresh_token\":\"$refresh\",\"user\":{\"id\":\"$user\"}}")
    private fun unauthorized()=MockResponse().setResponseCode(401).setBody("{\"code\":\"agah_mobile_auth\",\"data\":{\"status\":401}}")
    private fun enable(){val r=vault.prepareBiometric(BiometricAction.ENABLE);vault.completeBiometric(r,r.cipher)}
    private fun unlock(){val r=vault.prepareBiometric(BiometricAction.UNLOCK);vault.completeBiometric(r,r.cipher);repository.resumeLocal()}
    private fun waitUntil(condition: ()->Boolean){val limit=System.nanoTime()+TimeUnit.SECONDS.toNanos(8);while(!condition()&&System.nanoTime()<limit){shadowOf(android.os.Looper.getMainLooper()).idle();Thread.sleep(10)};assertTrue("condition not reached",condition())}
    @Test fun protectedColdStartMakesNoNetworkRequestBeforeAuthentication(){enable();vault.lock();val vm=AgahViewModel(app,repository);assertTrue(vm.security.value.locked);assertFalse(vm.state.value.signedIn);assertFalse(vm.state.value.busy);assertEquals(0,server.requestCount);assertThrows(LocalSessionLocked::class.java){runBlocking{repository.request("companies")}};assertEquals(0,server.requestCount)}
    @Test fun canceledAndLateCallbacksDoNotOpenSession(){enable();vault.lock();val vm=AgahViewModel(app,repository);val r=vm.prepareBiometric(BiometricAction.UNLOCK)!!;vm.cancelBiometric(r);vm.completeBiometric(r,r.cipher);assertTrue(vm.security.value.locked);assertFalse(vault.locallyUnlocked);assertEquals(0,server.requestCount)}
    @Test fun unlockWaitsForServerAndRechecksMembership(){enable();vault.lock();val vm=AgahViewModel(app,repository);server.enqueue(tokens());server.enqueue(success("[]"));val r=vm.prepareBiometric(BiometricAction.UNLOCK)!!;vm.completeBiometric(r,r.cipher);assertTrue(vm.security.value.locked);waitUntil{!vm.state.value.busy};assertFalse(vm.security.value.locked);assertTrue(vm.state.value.signedIn);assertEquals(2,server.requestCount);assertTrue(server.takeRequest().path!!.endsWith("auth/refresh"));assertTrue(server.takeRequest().path!!.endsWith("companies"))}
    @Test fun networkFailureDuringUnlockKeepsAppLockedAndCredentialProtected(){enable();vault.lock();val vm=AgahViewModel(app,repository);server.enqueue(MockResponse().setResponseCode(503).setBody("temporary proxy"));val r=vm.prepareBiometric(BiometricAction.UNLOCK)!!;vm.completeBiometric(r,r.cipher);waitUntil{!vm.state.value.busy};assertTrue(vm.security.value.locked);assertTrue(vm.security.value.enabled);assertFalse(vault.locallyUnlocked);assertNotNull(vm.security.value.message)}
    @Test fun passwordFallbackCannotResurrectLateBiometricResult(){enable();vault.lock();val vm=AgahViewModel(app,repository);val r=vm.prepareBiometric(BiometricAction.UNLOCK)!!;vm.cancelBiometric(r);vm.usePasswordInstead();vm.completeBiometric(r,r.cipher);assertFalse(vault.biometricEnabled);assertNull(vault.refresh());assertFalse(vm.state.value.signedIn);assertEquals(0,server.requestCount)}
    @Test fun backgroundWhileUnlockingNeverUncoversWorkspace(){enable();vault.lock();val vm=AgahViewModel(app,repository);val started=CountDownLatch(1);val release=CountDownLatch(1);server.dispatcher=object: Dispatcher(){override fun dispatch(request: RecordedRequest): MockResponse {if(request.path!!.endsWith("auth/refresh")){started.countDown();check(release.await(5,TimeUnit.SECONDS));return tokens()};return success("[]")}};val r=vm.prepareBiometric(BiometricAction.UNLOCK)!!;vm.completeBiometric(r,r.cipher);waitUntil{started.count==0L};vm.lockForBackground();release.countDown();waitUntil{!vm.state.value.busy};assertTrue(vm.security.value.locked);assertFalse(vault.locallyUnlocked)}
    @Test fun refreshFinishingAfterLocalLockPersistsRotationButDoesNotRetryFinancialWrite()=runBlocking {
        enable();val started=CountDownLatch(1);val release=CountDownLatch(1)
        server.dispatcher=object: Dispatcher(){override fun dispatch(request: RecordedRequest): MockResponse {if(request.path!!.endsWith("auth/refresh")){started.countDown();check(release.await(5,TimeUnit.SECONDS));return tokens()};return unauthorized()}}
        val call=async(Dispatchers.Default){runCatching{repository.request("companies/1/invoices","POST",JSONObject().put("test",true))}}
        assertTrue(started.await(5,TimeUnit.SECONDS));repository.lockLocal();release.countDown();assertTrue(call.await().exceptionOrNull() is LocalSessionLocked);assertFalse(vault.locallyUnlocked);assertEquals(2,server.requestCount);unlock();assertEquals("7.rotated",vault.refresh())
    }
    @Test fun logoutDuringRefreshCannotRecreateForgottenCredentials()=runBlocking {
        enable();val started=CountDownLatch(1);val release=CountDownLatch(1)
        server.dispatcher=object: Dispatcher(){override fun dispatch(request: RecordedRequest): MockResponse {if(request.path!!.endsWith("auth/refresh")){started.countDown();check(release.await(5,TimeUnit.SECONDS));return tokens()};return unauthorized()}}
        val call=async(Dispatchers.Default){runCatching{repository.request("companies")}}
        assertTrue(started.await(5,TimeUnit.SECONDS));repository.forgetLocal();release.countDown();assertTrue(call.await().isFailure);assertNull(vault.owner);assertNull(vault.refresh());assertFalse(vault.biometricEnabled)
    }
    @Test fun alreadySentWriteMayFinishAfterLockWithoutResubmission()=runBlocking {
        enable();val started=CountDownLatch(1);val release=CountDownLatch(1)
        server.dispatcher=object: Dispatcher(){override fun dispatch(request: RecordedRequest): MockResponse {started.countDown();check(release.await(5,TimeUnit.SECONDS));return success("{\"id\":\"19\",\"state\":\"committed\"}")}}
        val call=async(Dispatchers.Default){repository.request("companies/1/invoices","POST",JSONObject().put("test",true))};assertTrue(started.await(5,TimeUnit.SECONDS));repository.lockLocal();release.countDown();assertEquals("19",call.await().getString("id"));assertEquals(1,server.requestCount);assertFalse(vault.locallyUnlocked)
    }
    @Test fun unrecognizedProxy401DoesNotEraseProtectedRefresh()=runBlocking {enable();server.enqueue(MockResponse().setResponseCode(401).setBody("proxy unauthorized"));val failure=runCatching{repository.restore()}.exceptionOrNull();assertTrue(failure is ApiFailure);assertFalse((failure as ApiFailure).definitive);assertTrue(vault.biometricEnabled);assertEquals("7.original",vault.refresh())}
    @Test fun authoritativeExpiryReturnsToPasswordLogin()=runBlocking {enable();server.enqueue(unauthorized());assertTrue(runCatching{repository.restore()}.isFailure);assertFalse(vault.biometricEnabled);assertNull(vault.owner);assertNull(vault.refresh())}
}
