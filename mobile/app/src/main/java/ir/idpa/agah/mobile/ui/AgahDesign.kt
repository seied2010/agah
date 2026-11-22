package ir.idpa.agah.mobile.ui

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.semantics.*
import androidx.compose.ui.text.font.Font
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import ir.idpa.agah.mobile.R

/** Presentation-only tokens. Financial values always come from the existing core. */
internal val AgahLight=lightColorScheme(
    primary=Color(0xFF145B4B),onPrimary=Color.White,primaryContainer=Color(0xFFD3EDE2),onPrimaryContainer=Color(0xFF103D33),
    secondary=Color(0xFF715B2B),onSecondary=Color.White,secondaryContainer=Color(0xFFF5E8C7),onSecondaryContainer=Color(0xFF483910),
    tertiary=Color(0xFF355B83),tertiaryContainer=Color(0xFFDCE9F8),onTertiaryContainer=Color(0xFF183C60),
    background=Color(0xFFF3F6F5),onBackground=Color(0xFF172D2A),surface=Color(0xFFFFFFFF),onSurface=Color(0xFF172D2A),
    surfaceVariant=Color(0xFFE7EEEA),onSurfaceVariant=Color(0xFF4C605A),outline=Color(0xFF71817A),outlineVariant=Color(0xFFD5E0D9),
    error=Color(0xFFAF302E),errorContainer=Color(0xFFFFDAD6),onErrorContainer=Color(0xFF6B1114),surfaceTint=Color(0xFF145B4B),surfaceContainerLowest=Color.White,surfaceContainerLow=Color(0xFFF6F8F7),surfaceContainer=Color(0xFFEDF2EF),surfaceContainerHigh=Color(0xFFE7EEEA),surfaceContainerHighest=Color(0xFFDDE7E1))
internal val AgahDark=darkColorScheme(
    primary=Color(0xFF9BD6BE),onPrimary=Color(0xFF00382B),primaryContainer=Color(0xFF1F5142),onPrimaryContainer=Color(0xFFD3EDE2),
    secondary=Color(0xFFE1C786),onSecondary=Color(0xFF3E2F06),secondaryContainer=Color(0xFF54451F),onSecondaryContainer=Color(0xFFF5E8C7),
    tertiary=Color(0xFFADCDF3),tertiaryContainer=Color(0xFF244564),onTertiaryContainer=Color(0xFFDAE9FC),
    background=Color(0xFF0E1B18),onBackground=Color(0xFFE3ECE7),surface=Color(0xFF15251F),onSurface=Color(0xFFE3ECE7),
    surfaceVariant=Color(0xFF283B33),onSurfaceVariant=Color(0xFFBDCEC4),outline=Color(0xFF8A9F92),outlineVariant=Color(0xFF3A5044),
    error=Color(0xFFFFB4AB),errorContainer=Color(0xFF702A29),onErrorContainer=Color(0xFFFFDAD6),surfaceTint=Color(0xFF9BD6BE),surfaceContainerLowest=Color(0xFF0B1712),surfaceContainerLow=Color(0xFF182A21),surfaceContainer=Color(0xFF203329),surfaceContainerHigh=Color(0xFF293E32),surfaceContainerHighest=Color(0xFF334B3B))
private val font=FontFamily(Font(R.font.vazirmatn))
internal val AgahType=Typography().let { t -> Typography(
    displayLarge=t.displayLarge.copy(fontFamily=font),displayMedium=t.displayMedium.copy(fontFamily=font),displaySmall=t.displaySmall.copy(fontFamily=font),
    headlineLarge=t.headlineLarge.copy(fontFamily=font,fontWeight=FontWeight.Bold),headlineMedium=t.headlineMedium.copy(fontFamily=font,fontWeight=FontWeight.Bold),headlineSmall=t.headlineSmall.copy(fontFamily=font,fontWeight=FontWeight.Bold),
    titleLarge=t.titleLarge.copy(fontFamily=font,fontWeight=FontWeight.SemiBold),titleMedium=t.titleMedium.copy(fontFamily=font,fontWeight=FontWeight.SemiBold),titleSmall=t.titleSmall.copy(fontFamily=font),
    bodyLarge=t.bodyLarge.copy(fontFamily=font,fontSize=16.sp,lineHeight=28.sp),bodyMedium=t.bodyMedium.copy(fontFamily=font,lineHeight=24.sp),bodySmall=t.bodySmall.copy(fontFamily=font,lineHeight=21.sp),
    labelLarge=t.labelLarge.copy(fontFamily=font,fontWeight=FontWeight.SemiBold),labelMedium=t.labelMedium.copy(fontFamily=font),labelSmall=t.labelSmall.copy(fontFamily=font)) }
internal val AgahShapes=Shapes(extraSmall=RoundedCornerShape(8.dp),small=RoundedCornerShape(12.dp),medium=RoundedCornerShape(16.dp),large=RoundedCornerShape(24.dp),extraLarge=RoundedCornerShape(28.dp))
internal fun moduleIcon(m: Module): ImageVector=when(m.capability) {
    "party"->Icons.Default.People;"item"->Icons.Default.Inventory2;"sale_invoice"->Icons.Default.ReceiptLong
    "purchase_invoice"->Icons.Default.ShoppingBag;"receipt"->Icons.Default.CallReceived;"payment"->Icons.Default.CallMade
    "expense"->Icons.Default.AccountBalanceWallet;"voucher"->Icons.Default.AccountBalance;else->Icons.Default.Warehouse
}
internal fun moduleHint(m: Module)=when(m.entity) {
    "parties"->"مشتریان، تأمین‌کنندگان و پرسنل";"items"->"فهرست کالا، خدمات و قیمت‌ها";"invoices"->if(m.kind=="sale") "فروش و پیگیری تسویه" else "خرید و حساب تأمین‌کنندگان"
    "treasury"->if(m.kind=="receipt") "وصول وجه و تخصیص به فاکتور" else "پرداخت وجه و مدیریت تعهدات"
    "expenses"->"ثبت و بررسی هزینه‌های کسب‌وکار";"vouchers"->"اسناد، آرتیکل‌ها و تأیید حسابداری";else->"موجودی و گردش کالا"
}
@Composable internal fun SectionTitle(title: String,subtitle: String?=null) {
    Column(verticalArrangement=Arrangement.spacedBy(4.dp)) {
        Text(title,style=MaterialTheme.typography.titleLarge,modifier=Modifier.semantics { heading() })
        if(!subtitle.isNullOrBlank()) Text(subtitle,style=MaterialTheme.typography.bodySmall,color=MaterialTheme.colorScheme.onSurfaceVariant)
    }
}
@Composable internal fun InfoBanner(text: String,warning: Boolean=false) {
    Surface(color=if(warning) MaterialTheme.colorScheme.errorContainer else MaterialTheme.colorScheme.primaryContainer,shape=MaterialTheme.shapes.medium,modifier=Modifier.fillMaxWidth().semantics { liveRegion=LiveRegionMode.Polite }) {
        Row(Modifier.padding(12.dp),horizontalArrangement=Arrangement.spacedBy(10.dp),verticalAlignment=Alignment.CenterVertically) {
            Icon(if(warning) Icons.Default.Info else Icons.Default.VerifiedUser,null,Modifier.size(20.dp),tint=if(warning) MaterialTheme.colorScheme.onErrorContainer else MaterialTheme.colorScheme.onPrimaryContainer)
            Text(text,style=MaterialTheme.typography.bodySmall,color=if(warning) MaterialTheme.colorScheme.onErrorContainer else MaterialTheme.colorScheme.onPrimaryContainer)
        }
    }
}
@Composable internal fun EmptyState(title: String,description: String,icon: ImageVector=Icons.Default.Inbox) {
    OutlinedCard(Modifier.fillMaxWidth(),border=BorderStroke(1.dp,MaterialTheme.colorScheme.outlineVariant)) {
        Column(Modifier.fillMaxWidth().padding(28.dp),horizontalAlignment=Alignment.CenterHorizontally,verticalArrangement=Arrangement.spacedBy(10.dp)) {
            Surface(shape=RoundedCornerShape(20.dp),color=MaterialTheme.colorScheme.primaryContainer) { Icon(icon,null,Modifier.padding(16.dp).size(28.dp),tint=MaterialTheme.colorScheme.onPrimaryContainer) }
            Text(title,style=MaterialTheme.typography.titleMedium)
            Text(description,style=MaterialTheme.typography.bodyMedium,color=MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}
@Composable internal fun BrandMark(modifier: Modifier=Modifier) {
    Surface(modifier=modifier,shape=RoundedCornerShape(18.dp),color=MaterialTheme.colorScheme.primary) {
        Box(Modifier.size(52.dp),contentAlignment=Alignment.Center) { Text("آ",style=MaterialTheme.typography.headlineMedium,color=MaterialTheme.colorScheme.onPrimary) }
    }
}
@Composable internal fun OverviewHero(title: String,subtitle: String,content: @Composable ColumnScope.()->Unit={}) {
    val colors=MaterialTheme.colorScheme
    Surface(shape=MaterialTheme.shapes.large,color=colors.primaryContainer,modifier=Modifier.fillMaxWidth()) {
        Column(Modifier.background(Brush.linearGradient(listOf(colors.primaryContainer,colors.tertiaryContainer))).padding(24.dp),verticalArrangement=Arrangement.spacedBy(10.dp)) {
            Text("آگاه  /  مدیریت مالی",style=MaterialTheme.typography.labelLarge,color=colors.onPrimaryContainer)
            Text(title,style=MaterialTheme.typography.headlineSmall,color=colors.onPrimaryContainer,modifier=Modifier.semantics { heading() })
            Text(subtitle,style=MaterialTheme.typography.bodyMedium,color=colors.onPrimaryContainer)
            content()
        }
    }
}

/** Wrap actions at narrow widths and large font scales instead of clipping labels. */
@OptIn(androidx.compose.foundation.layout.ExperimentalLayoutApi::class)
@Composable internal fun AdaptiveActions(content: @Composable FlowRowScope.()->Unit) {
    FlowRow(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.spacedBy(8.dp),verticalArrangement=Arrangement.spacedBy(4.dp),content=content)
}

@Composable internal fun MetricCard(label: String,raw: String,scale: Long,unit: String) {
    OutlinedCard(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(20.dp),verticalArrangement=Arrangement.spacedBy(12.dp)) {
            Row(verticalAlignment=Alignment.CenterVertically,horizontalArrangement=Arrangement.spacedBy(10.dp)) {
                Surface(shape=MaterialTheme.shapes.small,color=MaterialTheme.colorScheme.primaryContainer) { Icon(Icons.Default.AccountBalanceWallet,null,Modifier.padding(10.dp).size(20.dp),tint=MaterialTheme.colorScheme.onPrimaryContainer) }
                Text(label,Modifier.weight(1f),style=MaterialTheme.typography.labelLarge,color=MaterialTheme.colorScheme.onSurfaceVariant)
            }
            Text(ir.idpa.agah.mobile.data.ExactValues.pretty(raw,scale),style=MaterialTheme.typography.headlineSmall,color=MaterialTheme.colorScheme.onSurface)
            Text(unit,style=MaterialTheme.typography.labelMedium,color=MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}
@Composable internal fun WorkspaceTile(module: Module,enabled: Boolean,onClick:()->Unit) {
    Card(onClick=onClick,modifier=Modifier.fillMaxWidth(),enabled=enabled,colors=CardDefaults.cardColors(containerColor=MaterialTheme.colorScheme.surface)) {
        Column(Modifier.padding(20.dp),verticalArrangement=Arrangement.spacedBy(12.dp)) {
            Icon(moduleIcon(module),null,tint=MaterialTheme.colorScheme.primary,modifier=Modifier.size(28.dp))
            Text(module.title,style=MaterialTheme.typography.titleMedium)
            Text(moduleHint(module),style=MaterialTheme.typography.bodySmall,color=MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}
