package ir.idpa.agah.mobile.data

import android.content.Context
import android.content.Intent
import androidx.core.content.FileProvider
import android.net.Uri
import android.provider.OpenableColumns
import android.util.Base64
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.io.File
import java.security.MessageDigest
import java.util.UUID

object PrivateFiles {
    suspend fun upload(context: Context, uri: Uri): JSONObject = withContext(Dispatchers.IO) {
        val type=context.contentResolver.getType(uri)
        val extension=mapOf("application/pdf" to "pdf","image/jpeg" to "jpg","image/png" to "png","image/webp" to "webp")[type]
            ?: throw IllegalArgumentException("فقط PDF و تصویر JPG، PNG یا WEBP مجاز است.")
        val bytes=context.contentResolver.openInputStream(uri)?.use { stream ->
            val out=java.io.ByteArrayOutputStream(); val buffer=ByteArray(8192)
            while(out.size()<=2097152) { val n=stream.read(buffer,0,minOf(buffer.size,2097153-out.size())); if(n<0) break; out.write(buffer,0,n) }
            out.toByteArray()
        } ?: throw IllegalArgumentException("فایل قابل خواندن نیست.")
        require(bytes.isNotEmpty()&&bytes.size<=2097152) { "حجم فایل باید حداکثر دو مگابایت باشد." }
        JSONObject().put("name","attachment.$extension").put("content_base64",Base64.encodeToString(bytes,Base64.NO_WRAP))
    }
    suspend fun share(context: Context,data: JSONObject) = withContext(Dispatchers.IO) {
        val mime=data.getString("mime")
        val extension=mapOf("application/pdf" to "pdf","text/csv" to "csv","image/jpeg" to "jpg","image/png" to "png","image/webp" to "webp")[mime]
            ?: throw IllegalArgumentException("نوع خروجی مجاز نیست.")
        val bytes=Base64.decode(data.getString("content_base64"),Base64.NO_WRAP)
        require(bytes.size<=3_000_000) { "فایل بیش از حد بزرگ است." }
        val hash=MessageDigest.getInstance("SHA-256").digest(bytes).joinToString("") { "%02x".format(it.toInt() and 255) }
        require(hash==data.getString("sha256")) { "یکپارچگی فایل تأیید نشد." }
        val dir=File(context.cacheDir,"exports").apply { mkdirs() }
        dir.listFiles()?.filter { it.lastModified()<System.currentTimeMillis()-86_400_000 }?.forEach { it.delete() }
        val file=File(dir,"agah-${UUID.randomUUID()}.$extension").apply { writeBytes(bytes) }
        val uri=FileProvider.getUriForFile(context,context.packageName+".files",file)
        val send=Intent(Intent.ACTION_SEND).setType(mime).putExtra(Intent.EXTRA_STREAM,uri).addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        withContext(Dispatchers.Main) {
            context.startActivity(Intent.createChooser(send,"ذخیره یا اشتراک‌گذاری خروجی آگاه").addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
        }
    }
}
