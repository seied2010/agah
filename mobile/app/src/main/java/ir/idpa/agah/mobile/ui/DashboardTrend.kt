package ir.idpa.agah.mobile.ui

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import ir.idpa.agah.mobile.data.ExactValues
import org.json.JSONObject
import java.math.BigDecimal
import java.math.RoundingMode

/** Ratios are visual only; all labelled monetary values come unchanged from the core. */
@Composable internal fun DashboardTrend(data: JSONObject,scale: Long,unit: String) {
    val months=data.rows("months")
    val visible=data.optJSONObject("visible") ?: JSONObject()
    val series=listOf("sale" to "فروش","purchase" to "خرید","expense" to "هزینه","income" to "درآمد").filter { visible.optBoolean(it.first) }
    val maximum=months.flatMap { month -> series.map { month.optString(it.first,"0").toBigDecimalOrNull()?.abs() ?: BigDecimal.ZERO } }.maxOrNull() ?: BigDecimal.ZERO
    Card(Modifier.fillMaxWidth()) { Column(Modifier.padding(16.dp),verticalArrangement=Arrangement.spacedBy(12.dp)) {
        Text("روند شش ماه تقویمی",style=MaterialTheme.typography.titleMedium)
        Text("بازهٔ نمودار ممکن است از ابتدای سال مالی عبور کند. ماه آخر تا تاریخ مبناست.",style=MaterialTheme.typography.bodySmall)
        months.forEach { month ->
            Text(month.optString("label"),style=MaterialTheme.typography.titleSmall)
            series.forEach { (key,label) ->
                val raw=month.optString(key,"0")
                val ratio=if(maximum.signum()==0) 0f else (raw.toBigDecimalOrNull()?.abs() ?: BigDecimal.ZERO).divide(maximum,6,RoundingMode.HALF_UP).toFloat().coerceIn(0f,1f)
                Text(label+": "+ExactValues.pretty(raw,scale)+" "+unit,style=MaterialTheme.typography.bodySmall)
                LinearProgressIndicator(progress={ratio},modifier=Modifier.fillMaxWidth())
            }
        }
        if(series.isEmpty()) Text("مجوز مشاهدهٔ روند مالی برای این حساب وجود ندارد.")
    } }
}
