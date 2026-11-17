package ir.idpa.agah.mobile.data

import android.icu.util.Calendar
import android.icu.util.TimeZone
import android.icu.util.ULocale
import java.time.LocalDate
import java.time.ZoneId
import java.time.Instant

object PersianDates {
    private val zone=ZoneId.of("Asia/Tehran")
    private fun calendar()=Calendar.getInstance(TimeZone.getTimeZone(zone.id),ULocale("fa_IR@calendar=persian"))
    fun fromIso(iso: String): String {
        val date=LocalDate.parse(iso.take(10));val c=calendar()
        c.timeInMillis=date.atTime(12,0).atZone(zone).toInstant().toEpochMilli()
        return "${c.get(Calendar.YEAR)}/${(c.get(Calendar.MONTH)+1).toString().padStart(2,'0')}/${c.get(Calendar.DAY_OF_MONTH).toString().padStart(2,'0')}"
    }
    fun toIso(year: String,month: String,day: String): String {
        val y=ExactValues.normalize(year).toInt();val m=ExactValues.normalize(month).toInt();val d=ExactValues.normalize(day).toInt()
        require(y in 1300..1600&&m in 1..12&&d in 1..31)
        val c=calendar();c.isLenient=false;c.clear();c.set(y,m-1,d,12,0,0)
        return Instant.ofEpochMilli(c.timeInMillis).atZone(zone).toLocalDate().toString()
    }
}
