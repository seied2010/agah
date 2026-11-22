package ir.idpa.agah.mobile

import android.app.Application
import android.content.Context
import android.security.keystore.KeyProperties
import androidx.test.core.app.ApplicationProvider
import ir.idpa.agah.mobile.data.*
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey

/** Tests encrypted storage and state with software keys; does not emulate a physical biometric sensor. */
internal class TestVaultKeys : VaultKeys {
    private fun generate()=KeyGenerator.getInstance("AES").apply {init(256)}.generateKey()
    var ordinary: SecretKey?=null
    var protected: SecretKey?=null
    var invalidated=false
    override fun regular()=ordinary ?: generate().also {ordinary=it}
    override fun biometric(create: Boolean): SecretKey {
        check(!invalidated) {"Test key invalidated"}
        return protected ?: run {check(create);generate().also {protected=it}}
    }
    override fun deleteRegular(){ordinary=null}
    override fun deleteBiometric(){protected=null;invalidated=false}
}

@RunWith(RobolectricTestRunner::class)
@Config(sdk=[28])
class BiometricVaultTest {
    private lateinit var context: Context
    private lateinit var keys: TestVaultKeys
    private lateinit var vault: SessionVault
    @Before fun setup(){context=ApplicationProvider.getApplicationContext<Application>();context.getSharedPreferences("agah_session",0).edit().clear().commit();keys=TestVaultKeys();vault=SessionVault(context,keys);vault.saveRefresh("7.test-refresh-secret","7")}
    private fun enable(){val r=vault.prepareBiometric(BiometricAction.ENABLE);vault.completeBiometric(r,r.cipher)}
    private fun unlock(){val r=vault.prepareBiometric(BiometricAction.UNLOCK);vault.completeBiometric(r,r.cipher)}
    private fun fails(block: ()->Unit){try{block();fail("operation must be rejected")}catch(e: Exception){ /* expected */ }}
    @Test fun previousEncryptedSessionStillLoads(){assertEquals("7.test-refresh-secret",SessionVault(context,keys).refresh());assertFalse(vault.biometricEnabled)}
    @Test fun enablingRemovesUnprotectedCredentialAndKey(){enable();val prefs=context.getSharedPreferences("agah_session",0);assertTrue(vault.biometricEnabled);assertFalse(prefs.contains("refresh"));assertNull(keys.ordinary);assertFalse(prefs.all.values.toString().contains("test-refresh-secret"));assertNotNull(prefs.getString("biometric_wrap",null))}
    @Test fun lockedSessionCannotBeReadAndSurvivesProcessRestart(){enable();vault.lock();assertThrows(LocalSessionLocked::class.java){vault.refresh()};val restarted=SessionVault(context,keys);assertTrue(restarted.biometricEnabled);assertFalse(restarted.locallyUnlocked);assertThrows(LocalSessionLocked::class.java){restarted.refresh()};val r=restarted.prepareBiometric(BiometricAction.UNLOCK);restarted.completeBiometric(r,r.cipher);assertEquals("7.test-refresh-secret",restarted.refresh())}
    @Test fun rotationUsesUnlockedDataKeyAndRemainsProtectedAfterRestart(){enable();vault.saveRefresh("7.rotated-secret","7");vault.lock();unlock();assertEquals("7.rotated-secret",vault.refresh());assertFalse(context.getSharedPreferences("agah_session",0).contains("refresh"))}
    @Test fun canceledUnlockNeverOpensVault(){enable();vault.lock();vault.prepareBiometric(BiometricAction.UNLOCK);assertFalse(vault.locallyUnlocked);assertThrows(LocalSessionLocked::class.java){vault.refresh()}}
    @Test fun changedEnrollmentDoesNotFallBackToOrdinaryStorage(){enable();vault.lock();keys.invalidated=true;fails{vault.prepareBiometric(BiometricAction.UNLOCK)};assertTrue(vault.biometricEnabled);assertFalse(vault.locallyUnlocked);assertFalse(context.getSharedPreferences("agah_session",0).contains("refresh"))}
    @Test fun wrongCipherCannotUnlock(){enable();vault.lock();val requested=vault.prepareBiometric(BiometricAction.UNLOCK);val different=vault.prepareBiometric(BiometricAction.UNLOCK);fails{vault.completeBiometric(requested,different.cipher)};assertFalse(vault.locallyUnlocked)}
    @Test fun changedAccountBindingRejectsPreviouslyPreparedCipher(){enable();vault.lock();val r=vault.prepareBiometric(BiometricAction.UNLOCK);context.getSharedPreferences("agah_session",0).edit().putString("owner","8").commit();fails{vault.completeBiometric(r,r.cipher)};assertFalse(vault.locallyUnlocked)}
    @Test fun authenticatedDisableRestoresOrdinaryEncryptedLogin(){enable();val r=vault.prepareBiometric(BiometricAction.DISABLE);vault.completeBiometric(r,r.cipher);assertFalse(vault.biometricEnabled);assertEquals("7.test-refresh-secret",SessionVault(context,keys).refresh());assertNull(keys.protected);assertFalse(context.getSharedPreferences("agah_session",0).all.values.toString().contains("test-refresh-secret"))}
    @Test fun passwordFallbackKeepsUncertainReceiptBoundToSameOwner(){vault.pending="test-receipt";enable();vault.clear();assertFalse(vault.biometricEnabled);assertNull(vault.pending);vault.saveRefresh("8.another-secret","8");assertNull(vault.pending);vault.clear();vault.saveRefresh("7.fresh-secret","7");assertEquals("test-receipt",vault.pending)}
    @Test fun serverChangeInvalidatesProtectedLogin(){enable();val r=vault.prepareBiometric(BiometricAction.DISABLE);vault.server="https://different.example.invalid";fails{vault.completeBiometric(r,r.cipher)};assertFalse(vault.biometricEnabled);assertNull(vault.refresh())}
    @Test fun corruptedCiphertextNeverFallsBackToOrdinarySession(){enable();vault.lock();context.getSharedPreferences("agah_session",0).edit().putString("protected_refresh","broken").commit();fails{unlock()};assertFalse(vault.locallyUnlocked);assertTrue(vault.biometricEnabled)}
    @Test fun api28KeyRequiresAuthenticationEveryUse(){val spec=AndroidVaultKeys().specification("test-spec",true);assertEquals(256,spec.keySize);assertTrue(spec.isUserAuthenticationRequired);assertTrue(spec.isInvalidatedByBiometricEnrollment);assertEquals(-1,spec.userAuthenticationValidityDurationSeconds)}
    @Test @Config(sdk=[30]) fun api30KeyAllowsStrongBiometricsOnly(){val spec=AndroidVaultKeys().specification("test-spec",true);assertTrue(spec.isUserAuthenticationRequired);assertEquals(0,spec.userAuthenticationValidityDurationSeconds);assertEquals(KeyProperties.AUTH_BIOMETRIC_STRONG,spec.userAuthenticationType);assertTrue(spec.isInvalidatedByBiometricEnrollment)}
}
