/* Fast entry only edits the current draft. Posting, pricing controls and stock checks stay server-side. */
(()=>{'use strict';const box=document.querySelector('[data-trade-entry]');if(!box)return;
const form=box.closest('form'),body=form.querySelector('[data-business-rows="trade_lines"]'),add=form.querySelector('[data-business-add="trade_lines"]');
const query=box.querySelector('[data-entry-query]'),mode=box.querySelector('[data-entry-mode]'),warehouse=box.querySelector('[data-entry-warehouse]'),results=box.querySelector('[data-entry-results]'),status=box.querySelector('[data-entry-status]');
const errors=box.querySelector('[data-entry-errors]'),ack=box.querySelector('[data-entry-ack]');
function scanError(text){const li=document.createElement('li');li.textContent=text;errors.append(li);errors.hidden=false;ack.hidden=false;message(text,true);}
ack.addEventListener('click',()=>{errors.replaceChildren();errors.hidden=true;ack.hidden=true;query.focus();});
let target=null,queue=Promise.resolve(),pending=0,searchVersion=0,timer;
const field=(row,key)=>row.querySelector('[name$="['+key+']"]');
const normalized=s=>String(s).replace(/[۰-۹]/g,c=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(c)).replace(/[٠-٩]/g,c=>'٠١٢٣٤٥٦٧٨٩'.indexOf(c)).replace(/[٬,\s]/g,'').replace(/٫/g,'.');
const quantity=s=>{const value=normalized(s);if(!/^\d+(\.\d{1,4})?$/.test(value))throw Error('مقدار ردیف معتبر نیست.');const [n,d='']=value.split('.');return BigInt(n)*10000n+BigInt(d.padEnd(4,'0'));};
const decimal=n=>String(n/10000n)+(n%10000n?'.'+String(n%10000n).padStart(4,'0').replace(/0+$/,''):'');
const sameNumber=(a,b)=>normalized(a).replace(/(\.\d*?)0+$/,'$1').replace(/\.$/,'')===normalized(b).replace(/(\.\d*?)0+$/,'$1').replace(/\.$/,'');
function message(text,error=false){status.textContent=text;status.classList.toggle('erp-err',error);}
async function lookup(q,kind,wh){const params=new URLSearchParams({action:'agah_trade_entry',nonce:box.dataset.nonce,company:box.dataset.company,kind:box.dataset.kind,mode:kind,q,warehouse:wh});
 const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),12000);
 try{const response=await fetch(box.dataset.endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:params,signal:controller.signal});let data;try{data=await response.json();}catch(e){throw Error('پاسخ دریافت کالا کامل نیست؛ دوباره جست‌وجو کنید.');}if(!response.ok||!data.success)throw Error(data.data?.message||'دریافت کالا کامل نشد.');return data.data;}
 catch(e){if(e.name==='AbortError')throw Error('زمان دریافت کالا تمام شد؛ دوباره اسکن کنید.');throw e;}finally{clearTimeout(timeout);}}
function stock(row,item){const out=row.querySelector('[data-entry-stock]');if(out)out.textContent=item.kind==='service'?'خدمت؛ بدون موجودی انبار':item.stock?'موجودی: '+item.stock.on_hand+' | قابل فروش: '+item.stock.available:'برای نمایش موجودی، انبار و مجوز مشاهدهٔ موجودی لازم است.';}
function apply(item,wh,chosen=null){
 const restoreFocus=document.activeElement===query;
 if(chosen&&!chosen.isConnected)throw Error("ردیف حذف شده است؛ کالا را دوباره انتخاب کنید.");
 let row=chosen;
 if(!row)row=[...body.children].find(r=>field(r,'item_id')?.value===item.id&&field(r,'warehouse_id')?.value===(item.kind==='service'?'0':wh)&&sameNumber(field(r,'unit_price').value,item.price)&&sameNumber(field(r,'discount_amount').value,'0')&&field(r,'tax_rate_id').value==='item');
 if(row&&!chosen){const next=quantity(field(row,'qty').value)+10000n;if(next>9000000000000000n)throw Error('مقدار ردیف بیش از حد مجاز است.');field(row,'qty').value=decimal(next);stock(row,item);}
 else {
  if(!row)row=[...body.children].find(r=>field(r,'item_id')?.value==='0');
  if(!row){if(body.children.length>=60)throw Error('حداکثر ۶۰ ردیف مجاز است؛ برگهٔ جدید باز کنید.');add.click();row=body.lastElementChild;}
  const select=field(row,'item_id');if(![...select.options].some(o=>o.value===item.id))select.add(new Option(item.code+' — '+item.name,item.id));select.value=item.id;
  field(row,'warehouse_id').value=item.kind==='service'?'0':wh;field(row,'unit_price').value=item.price;stock(row,item);
 }
 form.dispatchEvent(new CustomEvent('agah-trade-item',{detail:item}));form.dispatchEvent(new Event('input',{bubbles:true}));searchVersion++;clearTimeout(timer);results.replaceChildren();message(item.name+' به فهرست اضافه شد.');if(restoreFocus)query.focus();
}
function scan(code){const wh=warehouse.value;pending++;queue=queue.then(async()=>{try{message('در حال خواندن بارکد…');const data=await lookup(code,'barcode',wh);if(!data.items.length)throw Error('بارکد «'+code+'» یافت نشد.');apply(data.items[0],wh);}catch(e){scanError(e.message);}finally{pending--;}});}
async function search(){const version=++searchVersion,q=query.value.trim(),wh=warehouse.value;results.replaceChildren();if(!q)return;message('در حال جست‌وجو…');try{const data=await lookup(q,'search',wh);if(version!==searchVersion)return;
 for(const item of data.items){const button=document.createElement('button');button.type='button';button.className='btn btn-outline';button.textContent=item.code+' — '+item.name+' | قیمت: '+item.price;button.addEventListener('click',()=>{try{apply(item,wh,target);target=null;query.value='';query.focus();}catch(e){message(e.message,true);}});results.append(button);}
 message(data.items.length?(data.more?'نتایج بیشتر وجود دارد؛ نام یا کد را دقیق‌تر کنید.':'کالا را انتخاب کنید؛ با کلید Tab هم قابل انتخاب است.'):'کالایی پیدا نشد.');
 }catch(e){if(version===searchVersion)message(e.message,true);}}
function find(){if(mode.value==='barcode'){const code=query.value.trim();if(code){query.value='';searchVersion++;scan(code);}}else search();}
box.querySelector('[data-entry-find]').addEventListener('click',find);
query.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();find();}});
query.addEventListener('input',()=>{clearTimeout(timer);searchVersion++;if(mode.value==='search')timer=setTimeout(search,250);});
mode.addEventListener('change',()=>{target=null;searchVersion++;clearTimeout(timer);results.replaceChildren();query.focus();});warehouse.addEventListener('change',()=>{searchVersion++;results.replaceChildren();if(mode.value==='search'&&query.value.trim())search();});
form.addEventListener('click',e=>{const change=e.target.closest('[data-entry-change]');if(change){target=change.closest('tr');mode.value='search';query.value='';query.focus();message('نام یا کد کالای جایگزین این ردیف را جست‌وجو کنید.');}});
form.addEventListener('change',async e=>{if(!e.target.matches('[name$="[item_id]"],[name$="[warehouse_id]"]'))return;const row=e.target.closest('tr'),id=field(row,'item_id').value,wh=field(row,'warehouse_id').value;if(id==='0')return;const originalPrice=field(row,'unit_price').value;const requested=String(Date.now())+Math.random();row.dataset.lookup=requested;try{const data=await lookup(id,'id',wh);if(row.dataset.lookup!==requested||!row.isConnected)return;const item=data.items[0];if(!item)return;if(e.target===field(row,'item_id')){if(field(row,'unit_price').value===originalPrice)field(row,'unit_price').value=item.price;form.dispatchEvent(new CustomEvent('agah-trade-item',{detail:item}));}stock(row,item);}catch(err){message(err.message,true);}});
// Capture before the shared asynchronous form submit handler; pending scans must finish first.
form.addEventListener('submit',e=>{if(pending||errors.children.length){e.preventDefault();e.stopImmediatePropagation();message(pending?'خواندن بارکدها هنوز تمام نشده است؛ پس از تکمیل ذخیره کنید.':'خطاهای اسکن را پیش از ذخیره بررسی و تأیید کنید.',true);}},true);
document.addEventListener('keydown',e=>{if(e.ctrlKey||e.altKey||e.metaKey)return;if(e.key==='F2'||e.key==='F3'){e.preventDefault();mode.value=e.key==='F2'?'barcode':'search';target=null;query.focus();}else if(e.key==='F9'&&form.contains(document.activeElement)){e.preventDefault();form.requestSubmit();}});
})();
