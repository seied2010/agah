package ir.idpa.agah.mobile.ui

import ir.idpa.agah.mobile.data.ExactValues
import org.json.JSONObject

/** Draft-only editing. No invoice totals, tax, ledger or settlement math runs here. */
object InvoiceEntry {
    fun add(lines: List<Map<String,String>>,item: JSONObject,warehouse: String,scale: Long): List<MutableMap<String,String>> {
        val id=item.getString("id");val goods=item.getString("kind")=="goods"
        require(!goods||warehouse.isNotBlank()&&warehouse!="0") {"ابتدا انبار کالا را انتخاب کنید."}
        val wh=if(goods) warehouse else "0";val price=item.getString("price")
        val result=lines.map {it.toMutableMap()}.toMutableList()
        val index=result.indexOfFirst { r -> r["item_id"]==id&&r["warehouse_id"].orEmpty().ifBlank {"0"}==wh&&runCatching {ExactValues.scaled(r["unit_price"].orEmpty(),scale)==price&&ExactValues.scaled(r["discount_amount"].orEmpty().ifBlank {"0"},scale)=="0"}.getOrDefault(false) }
        val row=if(index>=0) result[index] else {
            require(result.size<60) {"حداکثر ۶۰ ردیف متمایز مجاز است."}
            mutableMapOf("item_id" to id,"warehouse_id" to wh,"qty_scaled" to "0","unit_price" to ExactValues.display(price,scale),"discount_amount" to "0").also {result.add(it)}
        }
        val quantity=ExactValues.scaled(row["qty_scaled"].orEmpty(),10000).toLong()
        require(quantity<=ExactValues.MAX-10000) {"مقدار بیش از حد مجاز است."}
        row["qty_scaled"]=ExactValues.display((quantity+10000).toString(),10000)
        row["_title_item_id"]=item.getString("name");row["_title_warehouse_id"]=item.optString("warehouse_name")
        val stock=item.optJSONObject("stock")
        row["_stock_hint"]=if(!goods) "خدمت؛ بدون موجودی انبار" else stock?.let {"موجودی: "+ExactValues.pretty(it.getString("on_hand"),10000)+" • آزاد: "+ExactValues.pretty(it.getString("available"),10000)} ?: "موجودی نمایش داده نشده است؛ مجوز و انبار را بررسی کنید."
        return result
    }
}
