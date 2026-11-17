package ir.idpa.agah.mobile

import androidx.test.ext.junit.runners.AndroidJUnit4
import ir.idpa.agah.mobile.data.PersianDates
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class PersianDatesTest {
    @Test fun financialDatesDoNotDependOnDeviceTimeZone() {
        val old=java.util.TimeZone.getDefault()
        try {
            for(zone in listOf("Pacific/Honolulu","Pacific/Kiritimati","UTC","Asia/Tehran")) {
                java.util.TimeZone.setDefault(java.util.TimeZone.getTimeZone(zone))
                assertEquals("1405/01/01",PersianDates.fromIso("2026-03-21"))
                assertEquals("2026-03-21",PersianDates.toIso("۱۴۰۵","۱","۱"))
            }
        } finally { java.util.TimeZone.setDefault(old) }
    }
    @Test fun invalidPersianMonthLengthsAreRejected() {
        assertEquals("2025-03-20",PersianDates.toIso("1403","12","30"))
        assertTrue(runCatching { PersianDates.toIso("1404","12","30") }.isFailure)
        assertTrue(runCatching { PersianDates.toIso("1405","7","31") }.isFailure)
        assertTrue(runCatching { PersianDates.toIso("1405","0","1") }.isFailure)
    }
}
