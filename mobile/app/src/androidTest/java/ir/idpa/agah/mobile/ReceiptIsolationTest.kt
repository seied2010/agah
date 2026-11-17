package ir.idpa.agah.mobile

import androidx.test.core.app.ApplicationProvider
import androidx.test.ext.junit.runners.AndroidJUnit4
import ir.idpa.agah.mobile.data.SessionVault
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class ReceiptIsolationTest {
    @Test fun unknownReceiptSurvivesLogoutButCannotCrossUserOrServer() {
        val vault=SessionVault(ApplicationProvider.getApplicationContext())
        try {
            vault.server="https://receipt-a.invalid";vault.saveRefresh("1.fixture","1");vault.pending="owner-one"
            vault.clear();assertNull(vault.pending)
            vault.saveRefresh("2.fixture","2");assertNull(vault.pending);vault.pending="owner-two"
            vault.server="https://receipt-b.invalid";vault.saveRefresh("1.fixture","1");assertNull(vault.pending)
            vault.server="https://receipt-a.invalid";vault.saveRefresh("1.fixture","1");assertEquals("owner-one",vault.pending);vault.pending=null
            vault.clear();vault.saveRefresh("2.fixture","2");assertEquals("owner-two",vault.pending);vault.pending=null
        } finally { vault.clear();vault.server="https://idpa.ir" }
    }
}
