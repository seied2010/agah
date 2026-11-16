/* Security setup never sends a key to the browser. */
(function(){'use strict';
const form=document.getElementById('agah-key-setup');if(!form)return;
form.addEventListener('submit',async function(event){
 event.preventDefault();const button=form.querySelector('button'),status=form.querySelector('[role="status"]');
 if(button.disabled)return;button.disabled=true;status.textContent='در حال بررسی و ذخیره امن…';
 try{const response=await fetch(form.getAttribute('action'),{method:'POST',body:new FormData(form),credentials:'same-origin'});const result=await response.json();
 if(!result.success)throw new Error(result.data&&result.data.message||'تنظیم انجام نشد؛ دوباره بررسی کنید.');
 status.textContent=result.data.message;button.textContent='کلید پایدار فعال شد';
 document.querySelectorAll('.notice-warning').forEach(function(n){if(n.textContent.includes('کلید پایدار رمزنگاری هنوز فعال نیست'))n.remove();});
 }catch(error){status.textContent=(error instanceof SyntaxError?'پاسخ سرور معتبر نبود؛ صفحه را تازه کنید و دوباره بررسی کنید.':error.message)||'ارتباط برقرار نشد؛ صفحه را تازه کنید و وضعیت را بررسی کنید.';button.disabled=false;}
});})();
