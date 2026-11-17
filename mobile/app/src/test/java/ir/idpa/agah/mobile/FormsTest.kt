package ir.idpa.agah.mobile
import ir.idpa.agah.mobile.ui.*
import org.junit.Assert.*
import org.junit.Test

class FormsTest {
    @Test fun settlementUsesServerIdsAndExactDisplayUnitConversion() {
        val module=modules.first { it.entity=="treasury"&&it.kind=="receipt" }
        val data=Forms.build(module,mapOf("party_id" to "7","doc_date" to "2026-11-16"),
            listOf(mapOf("method" to "cash","treasury_account_id" to "9","amount" to "123.45","_title_treasury_account_id" to "display only")),1000,
            listOf(mapOf("invoice_id" to "52","amount" to "100","_title_invoice_id" to "display only")))
        assertEquals("123450",data.getJSONArray("items").getJSONObject(0).getString("amount"))
        assertEquals("100000",data.getJSONArray("allocations").getJSONObject(0).getString("amount"))
        assertEquals("52",data.getJSONArray("allocations").getJSONObject(0).getString("invoice_id"))
        assertFalse(data.toString().contains("display only"))
    }
    @Test fun fractionalQuantityRemainsExactAndNoTotalsAreInvented() {
        val module=modules.first { it.entity=="invoices"&&it.kind=="sale" }
        val data=Forms.build(module,mapOf("party_id" to "7","invoice_date" to "2026-11-16","due_date" to "2026-11-16"),
            listOf(mapOf("item_id" to "8","qty_scaled" to "0.0001","unit_price" to "10.01")),100)
        assertEquals("1",data.getJSONArray("lines").getJSONObject(0).getString("qty_scaled"))
        assertEquals("1001",data.getJSONArray("lines").getJSONObject(0).getString("unit_price"))
        assertFalse(data.has("amount"));assertFalse(data.has("tax_amount"));assertFalse(data.has("number"))
    }
}
