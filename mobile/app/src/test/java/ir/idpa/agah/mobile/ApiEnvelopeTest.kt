package ir.idpa.agah.mobile
import ir.idpa.agah.mobile.data.*
import org.junit.Assert.*
import org.junit.Test

class ApiEnvelopeTest {
    private fun failure(status: Int,body: String) = runCatching { ApiEnvelope.parse(status,body.toByteArray()) }.exceptionOrNull() as ApiFailure
    @Test fun proxyErrorsKeepOutcomeUncertain() {
        assertFalse(failure(403,"{\"message\":\"proxy blocked\"}").definitive)
        assertFalse(failure(409,"<html>gateway error</html>").definitive)
    }
    @Test fun contractualRejectionsAreRecognized() {
        assertTrue(failure(422,"{\"error\":{\"message\":\"invalid\",\"fields\":{}},\"request_id\":\"0123456789abcdef\"}").definitive)
        assertTrue(failure(401,"{\"code\":\"agah_mobile_auth\",\"message\":\"login\",\"data\":{\"status\":401}}").definitive)
        assertTrue(failure(500,"{\"error\":{\"message\":\"internal error\"},\"request_id\":\"0123456789abcdef\"}").message.contains("0123456789abcdef"))
    }
    @Test fun malformedSuccessCannotConfirmFinancialWrite() {
        assertEquals(502,failure(200,"{\"api_version\":\"9\",\"data\":{}}").status)
        assertFalse(failure(200,"bad response").definitive)
        assertTrue(runCatching { ApiEnvelope.committed(org.json.JSONObject()) }.isFailure)
        assertTrue(runCatching { ApiEnvelope.committed(org.json.JSONObject("{\"state\":\"committed\"}")) }.isFailure)
        assertEquals("52",ApiEnvelope.committed(org.json.JSONObject("{\"state\":\"committed\",\"id\":\"52\"}")).getString("id"))
    }
}
