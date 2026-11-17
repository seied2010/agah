package ir.idpa.agah.mobile
import ir.idpa.agah.mobile.data.ExactValues
import org.junit.Assert.*
import org.junit.Test

class ExactValuesTest {
    @Test fun persianAmountsStayExact() {
        assertEquals("123456789123456",ExactValues.scaled("۱٬۲۳۴٬۵۶۷٬۸۹۱٬۲۳۴٫۵۶",100))
        assertEquals("12500",ExactValues.scaled("۱٫۲۵",10000))
        assertEquals("120000",ExactValues.scaled("120",1000))
        assertEquals("123450",ExactValues.scaled("1,234.50",100))
    }
    @Test fun maximumRoundTripsWithoutDouble() {
        assertEquals("9000000000000000",ExactValues.scaled("90000000000000",100))
        assertEquals("90000000000000",ExactValues.display("9000000000000000",100))
    }
    @Test fun rejectsPrecisionLossAndOverflow() {
        for(value in listOf("0.001","1e3","-1","90000000000001","NaN","12abc","1,2","12,,345","1,2345")) {
            assertTrue(value,runCatching { ExactValues.scaled(value,100) }.isFailure)
        }
    }
}
