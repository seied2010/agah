/* فرم‌های ERP: جلوگیری از ارسال دوباره، محاسبهٔ دقیق BigInt و تفصیلی وابسته؛ دادهٔ کاربر با innerHTML نوشته نمی‌شود. */
(function () {
    'use strict';
    document.querySelectorAll('.erp-scroll').forEach(el=>{el.tabIndex=0;el.setAttribute('role','region');el.setAttribute('aria-label','جدول؛ برای دیدن ستون‌های بیشتر اسکرول کنید');});
    const digits = s => String(s).replace(/[۰-۹]/g, c => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(c))).replace(/[٠-٩]/g,c=>String('٠١٢٣٤٥٦٧٨٩'.indexOf(c))).replace(/[٬,\s]/g,'').replace(/٫/g,'.');
    const showErrors=(errors,form)=>{
        let modal=document.querySelector('#agah-form-errors');if(!modal){modal=document.createElement('dialog');modal.id='agah-form-errors';modal.className='erp-error-dialog';modal.setAttribute('aria-labelledby','agah-error-title');document.body.append(modal);}
        modal.replaceChildren();const title=document.createElement('h2');title.id='agah-error-title';title.textContent='اطلاعات را اصلاح کنید';modal.append(title);const list=document.createElement('ul');
        let first=null;Object.entries(errors).forEach(([field,message])=>{const li=document.createElement('li');li.textContent=message;li.dataset.errorField=field;list.append(li);const name=field.replace(/\.([^.]*)/g,'[$1]');const input=form?.elements.namedItem(name);if(input?.setAttribute){input.setAttribute('aria-invalid','true');first=first||input;}});modal.append(list);
        const close=document.createElement('button');close.type='button';close.className='btn btn-primary';close.textContent='اصلاح اطلاعات';close.addEventListener('click',()=>{modal.close();first?.focus();});modal.append(close);if(!modal.open)modal.showModal();close.focus();
    };
    document.querySelectorAll('.erp form[method="post"]').forEach(form=>{
        form.addEventListener('invalid',e=>{e.preventDefault();const label=e.target.closest('label')?.childNodes[0]?.textContent?.trim()||'فیلد لازم';showErrors({[e.target.name]:e.target.tagName==='SELECT'?'گزینه '+label+' را انتخاب کنید.':label+' را به‌درستی وارد کنید.'},form);},true);
        form.addEventListener('submit',async e=>{
            const action=form.elements.namedItem('act')?.value;
            if(!action||action==='module_invoice_official'||action==='module_party_reveal'||action==='module_legal_sensitive'||!(action.startsWith('module_')||['company_create','account_create','account_toggle','account_delete','fy_create','period_toggle'].includes(action)))return;
            e.preventDefault();if(form.dataset.submitting)return;const data=new FormData(form);if(e.submitter?.name)data.append(e.submitter.name,e.submitter.value);form.dataset.submitting='1';
            const buttons=[...form.querySelectorAll('button[type="submit"]')];buttons.forEach(b=>{b.disabled=true;b.setAttribute('aria-busy','true');});form.querySelectorAll('[aria-invalid]').forEach(x=>x.removeAttribute('aria-invalid'));
            try{const response=await fetch(form.action||location.href,{method:'POST',body:data,credentials:'same-origin',headers:{'X-Agah-Form':'1','Accept':action==='module_backup_download'?'application/octet-stream, application/json':'application/json'}});
                if(action==='module_backup_download'&&response.ok&&response.headers.get('Content-Type')?.toLowerCase().includes('application/octet-stream')){const blob=await response.blob(),disposition=response.headers.get('Content-Disposition')||'';let filename='agah-backup.agahbackup';const match=disposition.match(/filename\*?=(?:UTF-8''|\")?([^\";\r\n]+)/i);if(match)try{filename=decodeURIComponent(match[1].trim().replace(/^\"|\"$/g,''));}catch(ignore){filename=match[1].trim();}filename=filename.replace(/[\\/]/g,'_');const href=URL.createObjectURL(blob),link=document.createElement('a');link.href=href;link.download=filename;document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(href),30000);return;}
                const result=await response.json();if(result.errors&&Object.keys(result.errors).length)showErrors(result.errors,form);else if(response.ok&&result.redirect)location.assign(result.redirect);else showErrors({form:'پاسخ معتبر دریافت نشد؛ صفحه را تازه و وضعیت ثبت را بررسی کنید.'},form);}
            catch(err){showErrors({form:'ارتباط با سرور کامل نشد؛ اطلاعات فرم حفظ شده است. پیش از ارسال دوباره وضعیت ثبت را بررسی کنید.'},form);}
            finally{delete form.dataset.submitting;buttons.forEach(b=>{b.disabled=false;b.removeAttribute('aria-busy');});}
        });
    });
    document.querySelectorAll('[data-print]').forEach(b=>b.addEventListener('click',()=>window.print()));
    const form=document.querySelector('.erp-voucher-form');
    if(form) {
        const body=form.querySelector('[data-lines]');let next=body.children.length;
        const fmt=n=>{const neg=n<0n;const a=neg?-n:n;const dec=String(a%100n).padStart(2,'0').replace(/0+$/,'');return(neg?'-':'')+new Intl.NumberFormat('fa-IR').format(a/100n)+(dec?'٫'+dec:'');};
        const amount=s=>{
            s=digits(s||'0');if(!/^\d+(\.\d{1,3})?$/.test(s)) throw new Error('invalid');
            const [whole,frac='0']=s.split('.');
            const precision=form.dataset.unit==='toman'?3:2;if(frac.length>precision)throw new Error('precision');return BigInt(whole)*10n**BigInt(precision)+BigInt(frac.padEnd(precision,'0'));
        };
        const totals=()=>{
            let debit=0n,credit=0n;const output=form.querySelector('[data-totals]');
            try {
                body.querySelectorAll('[data-line]').forEach(r=>{debit+=amount(r.querySelector('[data-debit]').value);credit+=amount(r.querySelector('[data-credit]').value);});
                output.textContent='جمع بدهکار: '+fmt(debit)+' ریال | بستانکار: '+fmt(credit)+' ریال | اختلاف: '+fmt(debit-credit)+' ریال';
                output.classList.toggle('erp-err',debit!==credit);
            } catch(e) {output.textContent='مبلغ معتبر وارد کنید؛ جمع نهایی در سرور کنترل می‌شود.';output.classList.add('erp-err');}
        };
        const dependent=r=>{
            const a=r.querySelector('[data-account]'),d=r.querySelector('[data-detail]'),o=a.selectedOptions[0];
            const rule=o?.dataset.rule||'optional',kind=o?.dataset.kind||'';
            [...d.options].forEach(opt=>{opt.hidden=opt.value!=='0' && (rule==='none'||(kind && opt.dataset.kind!==kind));opt.disabled=opt.hidden;});
            if(d.selectedOptions[0]?.disabled) d.value='0';
            d.required=rule==='required';d.options[0].disabled=d.required;
        };
        const bind=r=>{
            const a=r.querySelector('[data-account]');a.addEventListener('change',()=>dependent(r));dependent(r);
            r.querySelector('[data-account-search]')?.addEventListener('input',e=>{
                const q=digits(e.target.value).toLocaleLowerCase();
                [...a.options].forEach(o=>{o.hidden=o.value!=='' && !digits(o.textContent).toLocaleLowerCase().includes(q);});
            });
            r.querySelector('[data-remove-line]').addEventListener('click',()=>{if(body.children.length>1) {r.remove();totals();}});
        };
        const add=()=>{
            if(body.children.length>=200) return;
            const template=document.querySelector('[data-line-template]');const r=template.content.firstElementChild.cloneNode(true);
            r.querySelectorAll('[name]').forEach(el=>{el.name=el.name.replace('__INDEX__',String(next));});next++;
            body.append(r);bind(r);r.querySelector('select').focus();totals();
        };
        body.querySelectorAll('[data-line]').forEach(bind);body.addEventListener('input',totals);
        form.querySelector('[data-add-line]').addEventListener('click',add);totals();
        form.addEventListener('keydown',e=>{if(e.altKey&&e.key.toLowerCase()==='n'){e.preventDefault();add();}if(e.altKey&&e.key.toLowerCase()==='s'){e.preventDefault();form.requestSubmit();}});
    }
    const moneyName=/(?:^|\[)(?:labor_cost|overhead_cost|abnormal_cost|amount|payment_amount|debit|credit|unit_price|discount_amount|extra_cost|credit_limit|sale_price|purchase_price|statement_balance|base_salary|benefits|extra_benefits|deductions|insurance_ceiling|tax_exemption|cost|residual|proceeds|benefits_insurance_exempt|benefits_tax_exempt|overtime_base_extra|extra_insurance_exempt|extra_tax_exempt|upper_\d+|hourly_rate|project_amount|ceiling|tax_override|earning|deduction|trade_price|party_threshold)(?:\]|$)/;
    function formatMoney(input){const start=input.selectionStart??input.value.length;const before=digits(input.value.slice(0,start)).length;const raw=digits(input.value);if(!/^-?\d*(?:\.\d*)?$/.test(raw))return;const parts=raw.split('.');input.value=parts[0].replace(/\B(?=(\d{3})+(?!\d))/g,',')+(parts.length>1?'.'+parts[1]:'');let seen=0,caret=0;while(caret<input.value.length&&seen<before){if(input.value[caret]!==',')seen++;caret++;}if(document.activeElement===input)input.setSelectionRange(caret,caret);}
    function initMoney(root=document){root.querySelectorAll('.erp input').forEach(input=>{if(input.dataset.moneyReady||!moneyName.test(input.name)||input.type==='hidden')return;input.dataset.moneyReady='1';input.inputMode='decimal';if(input.type==='number')input.type='text';input.addEventListener('input',()=>formatMoney(input));formatMoney(input);});}
    window.agahInitMoney=initMoney;initMoney();
    /* انتخابگر محلی شمسی با Intl تقویم ایرانی مرورگر؛ ذخیره و اعتبارسنجی قطعی در PHP/Jalali انجام می‌شود. */
    const calFmt=new Intl.DateTimeFormat('en-US-u-ca-persian',{year:'numeric',month:'numeric',day:'numeric',timeZone:'UTC'});
    const parts=date=>{const p={};calFmt.formatToParts(date).forEach(x=>{if(x.type!=='literal')p[x.type]=Number(x.value);});return p;};
    const plus=(date,n)=>new Date(date.getTime()+n*86400000);
    function initCalendars(root=document){root.querySelectorAll('[data-jalali]').forEach(input=>{
        if(input.dataset.calendarReady)return;input.dataset.calendarReady='1';
        input.placeholder=input.placeholder||'۱۴۰۵/۰۱/۰۱';
        const trigger=document.createElement('button');trigger.type='button';trigger.className='btn btn-outline btn-sm erp-date-trigger';trigger.textContent='انتخاب تاریخ';trigger.setAttribute('aria-label','انتخاب تاریخ شمسی');
        input.insertAdjacentElement('afterend',trigger);
        trigger.addEventListener('click',()=>{
            document.querySelectorAll('.erp-calendar').forEach(x=>x.remove());
            const box=document.createElement('div');box.className='erp-calendar';box.setAttribute('role','dialog');box.setAttribute('aria-label','تقویم شمسی');
            trigger.insertAdjacentElement('afterend',box);
            let current=new Date();current=new Date(Date.UTC(current.getFullYear(),current.getMonth(),current.getDate()));
            const wanted=digits(input.value).match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/);
            if(wanted) {
                const approx=new Date(Date.UTC(Number(wanted[1])+621,Number(wanted[2])+1,20));
                for(let n=-65;n<65;n++){const candidate=plus(approx,n),p=parts(candidate);if(p.year===Number(wanted[1])&&p.month===Number(wanted[2])&&p.day===Number(wanted[3])){current=candidate;break;}}
            }
            const choose=date=>{const p=parts(date);input.value=p.year+'/'+String(p.month).padStart(2,'0')+'/'+String(p.day).padStart(2,'0');input.dispatchEvent(new Event('input',{bubbles:true}));box.remove();input.focus();};
            const button=(text,fn)=>{const b=document.createElement('button');b.type='button';b.textContent=text;b.addEventListener('click',fn);return b;};
            const render=()=>{
                box.replaceChildren();const p=parts(current),first=plus(current,1-p.day),head=document.createElement('div');
                head.append(button('ماه قبل',()=>{current=plus(first,-1);render();}));
                const title=document.createElement('strong');title.textContent=new Intl.DateTimeFormat('fa-IR-u-ca-persian',{year:'numeric',month:'long',timeZone:'UTC'}).format(current);head.append(title);
                head.append(button('ماه بعد',()=>{current=plus(first,32);const np=parts(current);current=plus(current,1-np.day);render();}));box.append(head);
                const grid=document.createElement('div');grid.className='erp-calendar-grid';['ش','ی','د','س','چ','پ','ج'].forEach(t=>{const s=document.createElement('span');s.textContent=t;grid.append(s);});
                const offset=(first.getUTCDay()+1)%7;for(let i=0;i<offset;i++)grid.append(document.createElement('span'));
                for(let i=0;i<31;i++){const date=plus(first,i),v=parts(date);if(v.month!==p.month)break;const b=button(new Intl.NumberFormat('fa-IR').format(v.day),()=>choose(date));b.setAttribute('aria-label',v.year+'/'+v.month+'/'+v.day);grid.append(b);}
                box.append(grid,button('امروز',()=>choose(new Date())),button('بستن',()=>{box.remove();trigger.focus();}));
            };
            box.addEventListener('keydown',e=>{if(e.key==='Escape'){box.remove();trigger.focus();}});render();box.querySelector('button').focus();
        });
    });}
    window.agahInitCalendars=initCalendars;initCalendars();
    // جدول بدون ردیف همیشه همین پیام را نشان می‌دهد؛ مقدار صفر یک ردیف واقعی است و خطای سرور پیام جدا دارد.
    document.querySelectorAll('.erp table.erp-table').forEach(function(table){
        var body=table.tBodies[0];if(!body||body.rows.length)return;
        var cols=table.tHead&&table.tHead.rows[0]?table.tHead.rows[0].cells.length:1;
        var row=body.insertRow(),cell=row.insertCell();cell.colSpan=cols;cell.className='erp-empty';cell.textContent='چیزی برای نمایش وجود ندارد';
    });
    // فیلدی که برای نوع انتخاب‌شده (حقیقی/حقوقی) معنا ندارد پنهان می‌شود؛ مقدارش حفظ می‌شود و سرور آن را الزامی نمی‌داند.
    document.querySelectorAll('.erp form').forEach(function(form){
        var kind=form.querySelector('select[name="kind"]'),parts=form.querySelectorAll('[data-show-kind]');if(!kind||!parts.length)return;
        var sync=function(){parts.forEach(function(el){el.hidden=el.getAttribute('data-show-kind')!==kind.value;});};
        kind.addEventListener('change',sync);sync();
    });
    // بخش وابسته به یک انتخاب: data-show-for="نام‌فیلد:مقدار" فقط وقتی همان مقدار انتخاب شده نمایش داده می‌شود.
    document.querySelectorAll('.erp form [data-show-for]').forEach(function(el){
        var p=el.getAttribute('data-show-for').split(':'),sel=el.closest('form').querySelector('select[name="'+p[0]+'"]');if(!sel)return;
        var sync=function(){el.hidden=sel.value!==p[1];};sel.addEventListener('change',sync);sync();
    });
    // انتخاب حساب و تفصیلی بیرون از فرم سند (ثبت هوشمند، قاعده‌ها): تفصیلی‌های ناسازگار پنهان می‌شوند.
    // اگر کاربر پیشنهاد را عوض کند، منبع تصمیم «انتخاب دستی» ثبت می‌شود.
    document.querySelectorAll('.erp [data-pick]').forEach(function(box){
        var a=box.querySelector('select[data-account]'),d=box.querySelector('select[data-detail]');if(!a||!d)return;
        var form=box.closest('form'),source=form&&form.querySelector('[name="source"]'),rule=form&&form.querySelector('[name="rule_id"]'),a0=a.value,d0=d.value;
        var sync=function(){var o=a.selectedOptions[0],r=(o&&o.dataset.rule)||'optional',k=(o&&o.dataset.kind)||'';
            Array.prototype.forEach.call(d.options,function(opt){opt.hidden=opt.value!=='0'&&(r==='none'||(k&&opt.dataset.kind!==k));opt.disabled=opt.hidden;});
            if(d.selectedOptions[0]&&d.selectedOptions[0].disabled)d.value='0';d.required=r==='required';d.options[0].disabled=d.required;};
        var mark=function(){if(source&&(a.value!==a0||d.value!==d0)){source.value='manual';if(rule)rule.value='0';}};
        a.addEventListener('change',function(){sync();mark();});d.addEventListener('change',mark);sync();
    });
    // UX-06: در فیلد مبلغ، کلید * (ردیف بالا یا NumPad) به‌جای ضرب «۰۰۰» درج می‌کند؛ انتخاب جایگزین می‌شود و Undo مرورگر کار می‌کند.
    var insertZeros=function(input){input.focus();var ok=false;try{ok=document.execCommand('insertText',false,'000');}catch(e){ok=false;}if(!ok){var s=input.selectionStart,t=input.selectionEnd;input.setRangeText('000',s===null?input.value.length:s,t===null?input.value.length:t,'end');input.dispatchEvent(new Event('input',{bubbles:true}));}};
    document.addEventListener('keydown',function(e){var t=e.target;if(e.key!=='*'||e.ctrlKey||e.metaKey||e.altKey||!t||t.tagName!=='INPUT'||t.dataset.moneyReady!=='1'||t.readOnly||t.disabled)return;e.preventDefault();insertZeros(t);});
    // معادل لمسی: دکمهٔ «۰۰۰» کنار فیلد مبلغ فقط روی دستگاه لمسی دیده می‌شود.
    var addZeroButtons=function(){document.querySelectorAll('.erp input[data-money-ready="1"]').forEach(function(input){if(input.dataset.zerosReady||input.readOnly)return;input.dataset.zerosReady='1';var b=document.createElement('button');b.type='button';b.className='erp-zeros';b.textContent='۰۰۰';b.setAttribute('aria-label','افزودن سه صفر');b.addEventListener('click',function(){insertZeros(input);});input.insertAdjacentElement('afterend',b);});};
    addZeroButtons();new MutationObserver(function(){if(window.agahInitMoney)window.agahInitMoney();addZeroButtons();}).observe(document.body,{childList:true,subtree:true});
    // UX-02: حالت باز/بستهٔ دسته‌های منو در همین مرورگر حفظ می‌شود؛ دستهٔ صفحهٔ فعال همیشه باز است.
    document.querySelectorAll('details[data-nav-group]').forEach(function(d){var k='agah_nav_'+d.getAttribute('data-nav-group');try{var v=localStorage.getItem(k);if(v!==null&&!d.hasAttribute('data-nav-active'))d.open=v==='1';}catch(e){}d.addEventListener('toggle',function(){try{localStorage.setItem(k,d.open?'1':'0');}catch(e){}});});
    // TRD-04: کپی پیوند اشتراک؛ اشتراک بومی فقط اگر مرورگر پشتیبانی کند.
    document.querySelectorAll('[data-copy-share]').forEach(function(b){b.addEventListener('click',function(){var i=b.parentNode.querySelector('[data-share-url]');if(!i)return;var done=function(){b.textContent='کپی شد';};if(navigator.clipboard&&navigator.clipboard.writeText)navigator.clipboard.writeText(i.value).then(done,function(){i.select();document.execCommand('copy');done();});else{i.select();document.execCommand('copy');done();}});});
    document.querySelectorAll('[data-web-share]').forEach(function(b){b.hidden=false;b.addEventListener('click',function(){var i=b.parentNode.querySelector('[data-share-url]');if(!i)return;if(navigator.share)navigator.share({title:document.title,url:i.value}).catch(function(){});else{var done=function(){b.textContent='پیوند برای اشتراک‌گذاری کپی شد';};if(navigator.clipboard)navigator.clipboard.writeText(i.value).then(done,function(){i.select();if(document.execCommand('copy'))done();});else{i.select();if(document.execCommand('copy'))done();}}});});
    // UX-07: ماشین‌حساب ساده/پیشرفته؛ حساب دقیق اعشاری با BigInt (بدون float)، انتقال تأییدشده به آخرین فیلد مبلغ.
    (function(){
        if(!document.querySelector('.erp'))return;var SCALE=1000000n,last=null;
        ['focusin','pointerdown','input'].forEach(function(ev){document.addEventListener(ev,function(e){if(e.target&&e.target.dataset&&e.target.dataset.moneyReady==='1')last=e.target;});});
        var unitOf=function(el){return (el&&el.form&&el.form.dataset.unit)||(document.querySelector('.erp[data-unit]')||{dataset:{}}).dataset.unit||'rial';};
        var fa=function(s){return String(s).replace(/[۰-۹]/g,function(c){return '۰۱۲۳۴۵۶۷۸۹'.indexOf(c);}).replace(/[٠-٩]/g,function(c){return '٠١٢٣٤٥٦٧٨٩'.indexOf(c);}).replace(/[×x]/g,'*').replace(/÷/g,'/').replace(/٫/g,'.').replace(/[,٬\s]/g,'');};
        var num=function(t){var p=t.split('.');return BigInt(p[0]||'0')*SCALE+BigInt(((p[1]||'')+'000000').slice(0,6));};
        var div=function(a,b){if(b===0n)throw new Error('تقسیم بر صفر');var n=a*SCALE,q=n/b,r=n%b;if(2n*(r<0n?-r:r)>=(b<0n?-b:b))q+=((n<0n)!==(b<0n))?-1n:1n;return q;};
        var evaluate=function(src){var s=fa(src);if(!/^[0-9+\-*/().%]*$/.test(s)||!s)throw new Error('عبارت معتبر نیست');var toks=s.match(/\d+(?:\.\d+)?|[+\-*/()%]/g)||[],i=0;
            var peek=function(){return toks[i];},eat=function(){return toks[i++];};
            var expr,term,factor;factor=function(){var t=eat();if(t==='-')return -factor();if(t==='+')return factor();if(t==='('){var v=expr();if(eat()!==')')throw new Error('پرانتز بسته نشده');return pct(v);}if(t===undefined||!/^\d/.test(t))throw new Error('عبارت معتبر نیست');return pct(num(t));};
            var pct=function(v){while(peek()==='%'){eat();v=div(v,100n*SCALE);}return v;};
            term=function(){var v=factor();while(peek()==='*'||peek()==='/'){var o=eat(),r=factor();v=o==='*'?(v*r)/SCALE:div(v,r);}return v;};
            expr=function(){var v=term();while(peek()==='+'||peek()==='-'){var o=eat(),r=term();v=o==='+'?v+r:v-r;}return v;};
            var v=expr();if(i<toks.length)throw new Error('عبارت معتبر نیست');return v;};
        var show=function(v,places){var neg=v<0n,a=neg?-v:v,unit=10n**BigInt(6-places),r=(a+unit/2n)/unit,ip=r/(10n**BigInt(places)),fp=String(r%(10n**BigInt(places))).padStart(places,'0').replace(/0+$/,'');return (neg?'-':'')+String(ip)+(fp?'.'+fp:'');};
        var box=document.createElement('div');box.className='erp-calc erp-no-print';box.hidden=true;box.setAttribute('role','dialog');box.setAttribute('aria-label','ماشین‌حساب');
        box.innerHTML='<div class="erp-calc-head"><strong>ماشین‌حساب</strong><label><input type="checkbox" data-calc-adv> پیشرفته</label><button type="button" data-calc-close aria-label="بستن">×</button></div><input type="text" dir="ltr" data-calc-expr aria-label="عبارت محاسبه" autocomplete="off" inputmode="decimal"><output data-calc-out aria-live="polite"></output><div class="erp-calc-keys"></div><button type="button" class="btn btn-primary btn-sm" data-calc-move disabled>انتقال به فیلد</button>';
        var keys=box.querySelector('.erp-calc-keys'),exprI=box.querySelector('[data-calc-expr]'),out=box.querySelector('[data-calc-out]'),move=box.querySelector('[data-calc-move]'),adv=box.querySelector('[data-calc-adv]');
        var simple=['7','8','9','/','4','5','6','*','1','2','3','-','0','.','=','+','C','000'],advanced=['(',')','%'];
        var draw=function(){keys.innerHTML='';simple.concat(adv.checked?advanced:[]).forEach(function(k){var b=document.createElement('button');b.type='button';b.textContent=k==='*'?'×':(k==='/'?'÷':k);b.dataset.k=k;keys.appendChild(b);});};
        var calc=function(){try{var v=evaluate(exprI.value);var places=unitOf(last)==='toman'?3:2;box.dataset.result=show(v,places);out.textContent='= '+box.dataset.result;move.disabled=!last;move.textContent=last?'انتقال به «'+(((last.closest('label')||{}).textContent||'').trim()||last.getAttribute('aria-label')||((last.closest('td')&&last.closest('table'))?(function(){var td=last.closest('td'),th=last.closest('table').querySelectorAll('thead th')[td.cellIndex];return th?th.textContent:'';})():'')||'فیلد مبلغ').trim().slice(0,40)+'»':'ابتدا روی فیلد مبلغ کلیک کنید';}catch(e){box.dataset.result='';out.textContent=e.message;move.disabled=true;}};
        keys.addEventListener('click',function(e){var k=e.target.dataset&&e.target.dataset.k;if(!k)return;if(k==='C'){exprI.value='';out.textContent='';move.disabled=true;return;}if(k==='='){calc();return;}exprI.value+=k;exprI.focus();});
        exprI.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();calc();}});adv.addEventListener('change',draw);
        move.addEventListener('click',function(){if(!last||!box.dataset.result)return;if(last.value&&!window.confirm('مقدار فعلی «'+last.value+'» با '+box.dataset.result+' جایگزین شود؟'))return;last.value=box.dataset.result;last.dispatchEvent(new Event('input',{bubbles:true}));last.focus();});
        box.querySelector('[data-calc-close]').addEventListener('click',function(){box.hidden=true;});
        var open=document.createElement('button');open.type='button';open.className='btn btn-outline btn-sm erp-calc-open erp-no-print';open.textContent='ماشین‌حساب';open.addEventListener('click',function(){box.hidden=!box.hidden;if(!box.hidden){draw();exprI.focus();}});
        var host=document.querySelector('.erp');host.appendChild(open);host.appendChild(box);
        // Pointer events support dragging with mouse, pen and touch in every ERP role.
        var draggable=function(el,handle,key){
            var drag=null,suppress=false,position=null;
            var place=function(x,y){var r=el.getBoundingClientRect();x=Math.max(8,Math.min(x,innerWidth-r.width-8));y=Math.max(8,Math.min(y,innerHeight-r.height-8));el.style.inset='auto';el.style.left=x+'px';el.style.top=y+'px';position={x:x,y:y};};
            var save=function(){try{localStorage.setItem(key,JSON.stringify(position));}catch(e){}};
            handle.style.touchAction='none';handle.style.cursor='grab';
            handle.addEventListener('pointerdown',function(e){if(e.button!==0||e.target.closest('input,select,label,[data-calc-close]'))return;var r=el.getBoundingClientRect();drag={id:e.pointerId,x:e.clientX,y:e.clientY,left:r.left,top:r.top,moved:false};handle.setPointerCapture(e.pointerId);});
            handle.addEventListener('pointermove',function(e){if(!drag||drag.id!==e.pointerId)return;var dx=e.clientX-drag.x,dy=e.clientY-drag.y;if(Math.abs(dx)+Math.abs(dy)>5)drag.moved=true;if(drag.moved){e.preventDefault();place(drag.left+dx,drag.top+dy);}});
            var end=function(e){if(!drag||drag.id!==e.pointerId)return;if(drag.moved){suppress=true;save();setTimeout(function(){suppress=false;},0);}drag=null;};
            handle.addEventListener('pointerup',end);handle.addEventListener('pointercancel',end);
            handle.addEventListener('click',function(e){if(suppress){e.preventDefault();e.stopImmediatePropagation();}},true);
            handle.addEventListener('keydown',function(e){if(e.target!==handle||!['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(e.key))return;e.preventDefault();var r=el.getBoundingClientRect(),step=e.shiftKey?40:10;place(r.left+(e.key==='ArrowRight'?step:e.key==='ArrowLeft'?-step:0),r.top+(e.key==='ArrowDown'?step:e.key==='ArrowUp'?-step:0));save();});
            var restore=function(){if(el.hidden)return;try{var p=position||JSON.parse(localStorage.getItem(key));if(p&&Number.isFinite(p.x)&&Number.isFinite(p.y))place(p.x,p.y);}catch(e){}};
            window.addEventListener('resize',restore);new MutationObserver(restore).observe(el,{attributes:true,attributeFilter:['hidden']});restore();
        };
        var handle=box.querySelector('.erp-calc-head');handle.tabIndex=0;handle.setAttribute('aria-label','جابه‌جایی ماشین‌حساب با کشیدن یا کلیدهای جهت');
        open.title='برای جابه‌جایی بکشید؛ برای بازکردن کلیک کنید';
        draggable(open,open,'agah_calc_button_position');draggable(box,handle,'agah_calc_panel_position');
    }());
}());
