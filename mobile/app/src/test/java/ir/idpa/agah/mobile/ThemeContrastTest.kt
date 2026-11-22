package ir.idpa.agah.mobile

import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.luminance
import ir.idpa.agah.mobile.ui.AgahDark
import ir.idpa.agah.mobile.ui.AgahLight
import org.junit.Assert.assertTrue
import org.junit.Test

class ThemeContrastTest {
    private fun contrast(a: Color,b: Color): Float {
        val first=a.luminance();val second=b.luminance()
        return (maxOf(first,second)+0.05f)/(minOf(first,second)+0.05f)
    }
    @Test fun bothThemesMeetNormalTextContrastForSemanticSurfaces() {
        listOf(AgahLight,AgahDark).forEach { c ->
            listOf("body" to (c.onSurface to c.surface),"background" to (c.onBackground to c.background),"supporting" to (c.onSurfaceVariant to c.surface),"button" to (c.onPrimary to c.primary),"tonal" to (c.onPrimaryContainer to c.primaryContainer),"secondary" to (c.onSecondaryContainer to c.secondaryContainer),"error" to (c.onErrorContainer to c.errorContainer),"error text" to (c.error to c.surface)).forEach { (name,colors) ->
                assertTrue("$name text contrast must meet WCAG AA 4.5:1",contrast(colors.first,colors.second)>=4.5f)
            }
        }
    }
}
