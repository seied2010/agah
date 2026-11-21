package ir.idpa.agah.mobile.data

import org.json.JSONObject
import java.io.IOException

class ApiFailure(val status: Int, override val message: String, val fields: JSONObject = JSONObject(), val definitive: Boolean = false) : IOException(message)

object ApiEnvelope {
    fun parse(status: Int,bytes: ByteArray): JSONObject {
        val json=runCatching { JSONObject(String(bytes,Charsets.UTF_8)) }.getOrElse {
            throw ApiFailure(status,"پاسخ سرور معتبر نیست؛ نتیجهٔ عملیات را بررسی کنید.")
        }
        if(status !in 200..299) {
            val error=json.optJSONObject("error")
            val knownApplication=error!=null&&Regex("[a-f0-9]{16}").matches(json.optString("request_id"))
            val knownPermission=json.optString("code")=="agah_mobile_auth"&&json.optJSONObject("data")?.optInt("status")==status
            val message=(error?.optString("message") ?: json.optString("message","خطای ارتباط با سرور"))+
                if(knownApplication) "\nشناسهٔ پیگیری: "+json.getString("request_id") else ""
            throw ApiFailure(status,message,error?.optJSONObject("fields") ?: JSONObject(),knownApplication||knownPermission)
        }
        if(json.optString("api_version")!="1") throw ApiFailure(502,"نسخهٔ API سرور سازگار نیست.")
        val data=json.opt("data")
        if(data is JSONObject) return data
        if(data is org.json.JSONArray) return JSONObject().put("rows",data)
        throw ApiFailure(502,"پاسخ سرور ناقص است.")
    }
    fun committed(data: JSONObject): JSONObject {
        if(data.optString("state")!="committed"||!Regex("[1-9][0-9]{0,17}").matches(data.optString("id"))) throw ApiFailure(502,"رسید ثبت معتبر دریافت نشد؛ وضعیت عملیات را استعلام کنید.")
        return data
    }
}
