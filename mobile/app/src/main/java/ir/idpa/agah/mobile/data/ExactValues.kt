package ir.idpa.agah.mobile.data

import java.math.BigDecimal
import java.math.RoundingMode

object ExactValues {
    const val MAX = 9_000_000_000_000_000L
    fun normalize(text: String): String = buildString {
        text.trim().forEach { c -> append(when(c) {
            in '۰'..'۹' -> '0' + (c - '۰')
            in '٠'..'٩' -> '0' + (c - '٠')
            '٫' -> '.'
            else -> c
        }) }
    }.replace("٬", ",")

    fun scaled(text: String, scale: Long): String {
        val normalized = normalize(text)
        require(Regex("(?:[0-9]+|[0-9]{1,3}(?:,[0-9]{3})+)(\\.[0-9]+)?").matches(normalized)) { "عدد و جداکنندهٔ هزارگان معتبر وارد کنید؛ اعشار با نقطه است." }
        val n = try { BigDecimal(normalized.replace(",", "")).multiply(BigDecimal.valueOf(scale))
            .setScale(0, RoundingMode.UNNECESSARY).longValueExact() }
        catch(e: ArithmeticException) { throw IllegalArgumentException("مقدار یا تعداد رقم اعشار خارج از محدودهٔ مجاز است.") }
        require(n in 0..MAX) { "مقدار خارج از محدوده است." }
        return n.toString()
    }

    fun display(raw: String, scale: Long): String = runCatching {
        BigDecimal(raw).divide(BigDecimal.valueOf(scale)).stripTrailingZeros().toPlainString()
    }.getOrDefault("—")
    fun pretty(raw: String,scale: Long): String = runCatching {
        java.text.NumberFormat.getNumberInstance(java.util.Locale("fa","IR")).apply {
            maximumFractionDigits=4; minimumFractionDigits=0; isGroupingUsed=true
        }.format(BigDecimal(raw).divide(BigDecimal.valueOf(scale)))
    }.getOrDefault("—")
}
