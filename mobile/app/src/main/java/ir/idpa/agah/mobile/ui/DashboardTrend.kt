package ir.idpa.agah.mobile.ui

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.semantics.*
import androidx.compose.ui.unit.dp
import ir.idpa.agah.mobile.data.ExactValues
import org.json.JSONObject
import java.math.BigDecimal
import java.math.RoundingMode

/** Bar lengths are presentation only. Amounts are unchanged server values, never client totals. */
@Composable internal fun DashboardTrend(data: JSONObject,scale: Long,unit: String) {
    val months=data.rows("months")
    val visible=data.optJSONObject("visible") ?: JSONObject()
    val series=listOf("sale" to "فروش","purchase" to "خرید","expense" to "هزینه","income" to "درآمد").filter { visible.optBoolean(it.first) }
    val maximum=months.flatMap { month -> series.map { month.optString(it.first,"0").toBigDecimalOrNull()?.abs() ?: BigDecimal.ZERO } }.maxOrNull() ?: BigDecimal.ZERO
    val colors=listOf(MaterialTheme.colorScheme.primary,MaterialTheme.colorScheme.tertiary,MaterialTheme.colorScheme.secondary,MaterialTheme.colorScheme.onSurfaceVariant)
    OutlinedCard(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(20.dp),verticalArrangement=Arrangement.spacedBy(16.dp)) {
            SectionTitle("روند شش ماه تقویمی","مقایسهٔ ماهانه؛ ماه آخر تا تاریخ مبناست و بازه ممکن است از ابتدای سال مالی عبور کند.")
            if(series.isEmpty()) InfoBanner("مجوز مشاهدهٔ روند مالی برای این حساب وجود ندارد.")
            else if(months.isEmpty()) EmptyState("روندی برای نمایش نیست","پس از دریافت اطلاعات، روند ماهانه اینجا نمایش داده می‌شود.")
            else BoxWithConstraints(Modifier.fillMaxWidth()) {
                val columns=when {maxWidth>=840.dp->3;maxWidth>=520.dp->2;else->1}
                Column(verticalArrangement=Arrangement.spacedBy(20.dp)) {
                    months.chunked(columns).forEach { group -> Row(horizontalArrangement=Arrangement.spacedBy(20.dp)) {
                        group.forEach { month -> Column(Modifier.weight(1f),verticalArrangement=Arrangement.spacedBy(10.dp)) {
                            Text(month.optString("label"),style=MaterialTheme.typography.titleMedium,modifier=Modifier.semantics { heading() })
                            series.forEachIndexed { index,(key,label) ->
                                val raw=month.optString(key,"0")
                                val ratio=if(maximum.signum()==0) 0f else (raw.toBigDecimalOrNull()?.abs() ?: BigDecimal.ZERO).divide(maximum,6,RoundingMode.HALF_UP).toFloat().coerceIn(0f,1f)
                                Column(Modifier.semantics(mergeDescendants=true) { contentDescription=label+": "+ExactValues.pretty(raw,scale)+" "+unit }) {
                                    Text(label+": "+ExactValues.pretty(raw,scale)+" "+unit,style=MaterialTheme.typography.bodySmall)
                                    LinearProgressIndicator(progress={ratio},modifier=Modifier.fillMaxWidth().height(6.dp).clearAndSetSemantics {},color=colors[index],trackColor=MaterialTheme.colorScheme.surfaceVariant)
                                }
                            }
                        } }
                        repeat(columns-group.size) {Spacer(Modifier.weight(1f))}
                    } }
                }
            }
        }
    }
}
