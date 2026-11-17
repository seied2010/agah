package ir.idpa.agah.mobile

import android.app.Application
import android.util.Base64
import androidx.test.core.app.ApplicationProvider
import androidx.test.platform.app.InstrumentationRegistry
import androidx.test.ext.junit.runners.AndroidJUnit4
import ir.idpa.agah.mobile.data.*
import ir.idpa.agah.mobile.ui.*
import kotlinx.coroutines.runBlocking
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Assume.assumeTrue
import org.junit.Test
import org.junit.runner.RunWith
import java.util.UUID

/** Real local server, shared Android transport and form serialization; no production host. */
@RunWith(AndroidJUnit4::class)
class FinancialRoundTripTest {
    @Test fun nativeFinancialFormsCommitAndSettleOnTheSharedCore()=runBlocking {
        val encoded=InstrumentationRegistry.getArguments().getString("fixtureBase64")
        assumeTrue("Requires local financial-fixture.php output",encoded!=null)
        val f=JSONObject(String(Base64.decode(encoded,Base64.NO_WRAP)))
        val vault=SessionVault(ApplicationProvider.getApplicationContext<Application>()).apply { clear();server="http://10.0.2.2:18085" }
        val repo=AgahRepository(vault);repo.setServer(vault.server)
        try {
            repo.login("mobiletest","LocalIntegration-2026-Only","")
            val prefix="companies/${f.getString("company")}/"
            val year=f.getJSONObject("year");val date=year.getString("start_date")
            fun path(s: String)=prefix+s+"?year_id="+year.getString("id")
            suspend fun create(entity: String,body: JSONObject,key: String=UUID.randomUUID().toString()): JSONObject =
                ApiEnvelope.committed(repo.request(path(entity),"POST",body,mapOf("Idempotency-Key" to key)))
            suspend fun action(entity: String,id: String,action: String) {
                val detail=repo.request(path("$entity/$id"))
                ApiEnvelope.committed(repo.request(path("$entity/$id/$action"),"POST",JSONObject().put("reason","local Android test"),mapOf("Idempotency-Key" to UUID.randomUUID().toString(),"If-Match" to detail.getString("etag"))))
            }
            val marker="Android financial "+UUID.randomUUID().toString().take(8)
            val party=create("parties",JSONObject().put("name",marker).put("kind","real").put("is_customer",true).put("is_supplier",true).put("is_active",true).put("credit_limit","0")).getString("id")
            val item=create("items",JSONObject().put("name",marker).put("code",UUID.randomUUID().toString().take(12)).put("kind","service").put("unit_id",f.getString("unit")).put("sale_account_id",f.getString("sale_account")).put("purchase_account_id",f.getString("purchase_account")).put("is_active",true).put("sale_price","10000").put("purchase_price","5000").put("trade_price","0")).getString("id")
            val sale=modules.first { it.kind=="sale" }
            val body=Forms.build(sale,Forms.defaults(sale,date)+mapOf("party_id" to party),listOf(mapOf("item_id" to item,"qty_scaled" to "2.5","unit_price" to "100")),100)
            val key=UUID.randomUUID().toString();val invoice=create("invoices",body,key).getString("id")
            assertEquals(invoice,create("invoices",body,key).getString("id"))
            action("invoices",invoice,"issue")
            assertEquals("issued",repo.request(path("invoices/$invoice")).getJSONObject("record").getString("status"))
            val receipt=modules.first { it.entity=="treasury"&&it.kind=="receipt" }
            val payment=Forms.build(receipt,Forms.defaults(receipt,date)+mapOf("party_id" to party),listOf(mapOf("method" to "cash","treasury_account_id" to f.getString("cash"),"amount" to "250")),100,listOf(mapOf("invoice_id" to invoice,"amount" to "250")))
            val receiptId=create("treasury",payment).getString("id")
            assertEquals("25000",repo.request(path("treasury/$receiptId")).getJSONObject("record").getString("amount"))
            val voucher=modules.first { it.entity=="vouchers" }
            val voucherBody=Forms.build(voucher,Forms.defaults(voucher,date)+mapOf("description" to marker),listOf(mapOf("account_id" to f.getString("manual_debit"),"debit" to "50"),mapOf("account_id" to f.getString("manual_credit"),"credit" to "50")),100)
            val voucherId=create("vouchers",voucherBody).getString("id")
            action("vouchers",voucherId,"approve");action("vouchers",voucherId,"finalize")
            assertEquals("final",repo.request(path("vouchers/$voucherId")).getJSONObject("record").getString("status"))
            val pdf=repo.request(path("invoices/$invoice/export"));val bytes=Base64.decode(pdf.getString("content_base64"),Base64.NO_WRAP)
            assertTrue(String(bytes.take(5).toByteArray()).startsWith("%PDF"))
            assertEquals(pdf.getString("sha256"),java.security.MessageDigest.getInstance("SHA-256").digest(bytes).joinToString("") { "%02x".format(it.toInt() and 255) })
        } finally { repo.logout();vault.clear();vault.server="https://idpa.ir" }
    }
}
