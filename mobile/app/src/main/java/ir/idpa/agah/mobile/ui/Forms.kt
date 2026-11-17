package ir.idpa.agah.mobile.ui

import ir.idpa.agah.mobile.data.ExactValues
import org.json.JSONArray
import org.json.JSONObject

enum class InputKind { Text, Money, Quantity, Date, Flag, Lookup, Choice }
data class Field(val key: String, val title: String, val type: InputKind = InputKind.Text, val lookup: String = "", val choices: List<Pair<String,String>> = emptyList(), val required: Boolean = false)
data class Module(val entity: String, val title: String, val capability: String, val kind: String = "")
val modules = listOf(
    Module("parties","اشخاص","party"), Module("items","کالا و خدمات","item"),
    Module("invoices","فاکتور فروش","sale_invoice","sale"), Module("invoices","فاکتور خرید","purchase_invoice","purchase"),
    Module("treasury","دریافت","receipt","receipt"), Module("treasury","پرداخت","payment","payment"),
    Module("expenses","هزینه‌ها","expense"), Module("vouchers","اسناد حسابداری","voucher"), Module("stock","انبار","stock")
)
object Forms {
    private fun lookup(key: String,title: String,source: String,required: Boolean = true) = Field(key,title,InputKind.Lookup,source,required=required)
    private fun money(key: String,title: String) = Field(key,title,InputKind.Money)
    private fun date(key: String,title: String) = Field(key,title,InputKind.Date,required=true)
    private fun choice(key: String,title: String,vararg values: Pair<String,String>) = Field(key,title,InputKind.Choice,choices=values.toList())
    fun header(module: Module): List<Field> = when(module.entity) {
        "parties" -> listOf(Field("name","نام شخص",required=true),choice("kind","نوع شخص","real" to "حقیقی","legal" to "حقوقی"),
            Field("is_customer","مشتری",InputKind.Flag),Field("is_supplier","تأمین‌کننده",InputKind.Flag),Field("is_employee","پرسنل",InputKind.Flag),
            Field("is_active","فعال",InputKind.Flag),Field("phone","تلفن"),Field("email","ایمیل"),Field("address","نشانی"),Field("postal_code","کد پستی"),
            Field("national_id","شناسهٔ ملی جدید (اختیاری)"),money("credit_limit","سقف اعتبار"))
        "items" -> listOf(Field("code","کد کالا",required=true),Field("name","نام کالا",required=true),choice("kind","نوع","goods" to "کالا","service" to "خدمت"),
            lookup("unit_id","واحد","units"),lookup("group_id","گروه","groups",false),lookup("tax_rate_id","نرخ مالیات","taxes",false),
            lookup("sale_account_id","حساب فروش","accounts"),lookup("purchase_account_id","حساب خرید","accounts"),Field("barcode","بارکد"),
            money("sale_price","قیمت فروش"),money("purchase_price","قیمت خرید"),money("trade_price","قیمت همکاری"),Field("is_active","فعال",InputKind.Flag))
        "invoices" -> listOf(lookup("party_id",if(module.kind=="purchase") "تأمین‌کننده" else "مشتری","parties"),date("invoice_date","تاریخ فاکتور"),date("due_date","سررسید"),
            money("discount_amount","تخفیف کل")) + (if(module.kind=="purchase") listOf(money("extra_cost","هزینهٔ جانبی"),choice("cost_basis","تسهیم هزینه","value" to "ارزش","quantity" to "مقدار")) else emptyList()) + listOf(Field("description","شرح"),Field("price_reason","دلیل تغییر قیمت (در صورت نیاز)"))
        "treasury" -> listOf(lookup("party_id","شخص","parties"),date("doc_date","تاریخ"),Field("description","شرح"),Field("advance","علی‌الحساب",InputKind.Flag))
        "expenses" -> listOf(lookup("category_id","سرفصل هزینه","expense_categories"),lookup("party_id","شخص","parties"),date("doc_date","تاریخ"),date("due_date","سررسید"),money("amount","مبلغ"),Field("description","شرح"))
        "vouchers" -> listOf(date("voucher_date","تاریخ سند"),Field("description","شرح سند"))
        "stock" -> listOf(choice("kind","نوع گردش","receipt" to "ورود","issue" to "خروج","transfer" to "انتقال","count" to "شمارش"),date("doc_date","تاریخ"),Field("description","شرح"))
        else -> emptyList()
    }
    fun lines(module: Module): List<Field> = when(module.entity) {
        "invoices" -> listOf(lookup("item_id","کالا / خدمت","items"),lookup("warehouse_id","انبار (برای کالا الزامی)","warehouses",false),Field("qty_scaled","مقدار",InputKind.Quantity),money("unit_price","قیمت واحد"),money("discount_amount","تخفیف ردیف"))
        "treasury" -> listOf(choice("method","روش پرداخت","cash" to "نقد","card" to "کارت","transfer" to "حواله","cheque" to "چک"),lookup("treasury_account_id","حساب خزانه","treasury_accounts"),money("amount","مبلغ"),Field("reference","شمارهٔ پیگیری"),Field("cheque_no","شماره چک"),Field("bank_name","نام بانک چک"),Field("due_date","سررسید چک",InputKind.Date))
        "vouchers" -> listOf(lookup("account_id","حساب معین","accounts"),lookup("detail_id","تفصیلی","details",false),money("debit","بدهکار"),money("credit","بستانکار"),Field("description","شرح ردیف"))
        "stock" -> listOf(lookup("warehouse_id","انبار مبدأ","warehouses"),lookup("target_warehouse_id","انبار مقصد (انتقال)","warehouses",false),lookup("item_id","کالا","items"),Field("qty_scaled","مقدار",InputKind.Quantity),money("cost","بهای کل ورود"))
        else -> emptyList()
    }
    fun allocations(module: Module): List<Field> = if(module.entity=="treasury") listOf(
        lookup("invoice_id","فاکتور","${if(module.kind=="receipt") "sale" else "purchase"}_invoices"),money("amount","مبلغ تخصیص")
    ) else emptyList()
    fun defaults(module: Module, date: String): MutableMap<String,String> = mutableMapOf(
        "kind" to module.kind.ifEmpty { when(module.entity) { "parties" -> "real"; "items" -> "goods"; "stock" -> "receipt"; else -> "" } },
        "is_active" to "1","is_customer" to "1","invoice_date" to date,"voucher_date" to date,"doc_date" to date,"due_date" to date,"cost_basis" to "value","method" to "cash"
    )
    fun values(fields: List<Field>, record: JSONObject, moneyScale: Long): Map<String,String> = fields.associate { f ->
        val v = record.optString(f.key,"").takeUnless { it=="null" } ?: ""
        f.key to if(v.isNotEmpty() && f.type in listOf(InputKind.Money,InputKind.Quantity)) ExactValues.display(v,if(f.type==InputKind.Quantity) 10000 else moneyScale) else v
    }
    fun payload(fields: List<Field>, values: Map<String,String>, scale: Long): JSONObject = JSONObject().also { out ->
        fields.forEach { f ->
            val text=values[f.key].orEmpty()
            require(!f.required || text.isNotBlank()) { "${f.title} را وارد کنید." }
            out.put(f.key, when(f.type) {
                InputKind.Money -> ExactValues.scaled(text.ifBlank { "0" },scale)
                InputKind.Quantity -> ExactValues.scaled(text.ifBlank { "0" },10000)
                InputKind.Flag -> text=="1"
                InputKind.Lookup -> text.ifBlank { "0" }
                else -> text
            })
        }
    }
    fun build(module: Module, headerValues: Map<String,String>, lineValues: List<Map<String,String>>, scale: Long,allocationValues: List<Map<String,String>> = emptyList()): JSONObject {
        val out=payload(header(module),headerValues,scale)
        if(module.kind.isNotEmpty()) out.put("kind",module.kind)
        val fields=lines(module)
        if(fields.isNotEmpty()) out.put(if(module.entity=="treasury") "items" else "lines",JSONArray().also { a -> lineValues.forEach { a.put(payload(fields,it,scale)) } })
        if(allocationValues.isNotEmpty()) out.put("allocations",JSONArray().also { a -> allocationValues.forEach { a.put(payload(allocations(module),it,scale)) } })
        return out
    }
}
