package ir.idpa.agah.mobile

import ir.idpa.agah.mobile.ui.*
import org.json.JSONObject
import org.junit.Test
import org.junit.Assert.*

class InvoiceEntryTest {
    private fun item(goods: Boolean=false)=JSONObject().put("id","7").put("name","کالا").put("kind",if(goods) "goods" else "service").put("price","123450").put("warehouse_name","انبار")
    @Test fun repeatedScanIncrementsExactFractionalQuantity() {
        val first=InvoiceEntry.add(emptyList(),item(),"0",100)
        first[0]["qty_scaled"]="0.0001"
        val result=InvoiceEntry.add(first,item(),"0",100)
        assertEquals(1,result.size);assertEquals("1.0001",result[0]["qty_scaled"])
        assertEquals("1234.5",result[0]["unit_price"])
        assertEquals("0.0001",first[0]["qty_scaled"])
    }
    @Test fun manualPriceDiscountAndWarehouseAreNotMerged() {
        val first=InvoiceEntry.add(emptyList(),item(true),"3",100)
        first[0]["unit_price"]="1500"
        assertEquals(2,InvoiceEntry.add(first,item(true),"3",100).size)
        first[0]["unit_price"]="1234.5";first[0]["discount_amount"]="1"
        assertEquals(2,InvoiceEntry.add(first,item(true),"3",100).size)
        first[0]["discount_amount"]="0"
        assertEquals(2,InvoiceEntry.add(first,item(true),"4",100).size)
    }
    @Test fun goodsRequireWarehouseAndCoreLineLimitIsRespected() {
        assertThrows(IllegalArgumentException::class.java) {InvoiceEntry.add(emptyList(),item(true),"0",100)}
        val lines=(1..60).map {mapOf("item_id" to "other$it")}
        assertThrows(IllegalArgumentException::class.java) {InvoiceEntry.add(lines,item(),"0",100)}
    }
    @Test fun settlementUsesExactExistingTreasuryFields() {
        val result=Forms.issuePayments(listOf(mapOf("method" to "cash","treasury_account_id" to "2","amount" to "0.001"),mapOf("method" to "card","treasury_account_id" to "3","amount" to "1.234")),1000)
        assertEquals("1",result.getJSONObject(0).getString("amount"));assertEquals("1234",result.getJSONObject(1).getString("amount"))
        assertEquals(0,Forms.issuePayments(emptyList(),100).length())
        assertThrows(IllegalArgumentException::class.java) {Forms.issuePayments(listOf(mapOf("method" to "cheque","treasury_account_id" to "2","amount" to "10")),100)}
        assertThrows(IllegalArgumentException::class.java) {Forms.issuePayments(listOf(mapOf("method" to "cash","treasury_account_id" to "0","amount" to "1")),100)}
    }
}
