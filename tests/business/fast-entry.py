"""Disposable local WP + real Chromium. Never targets the production host."""
from pathlib import Path
import sys,json,uuid
sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'mobile-backend/tests'))
from integration import call,cli
from playwright.sync_api import sync_playwright,expect
f=json.loads(cli('financial-fixture.php'));cli('policy-write.php')
s,j=call('auth/login','POST',{'username':'mobiletest','password':'LocalIntegration-2026-Only','device_id':str(uuid.uuid4())});assert s==200
t=j['data']['access_token'];c=f['company'];year=str(f['year']['id']);tag=uuid.uuid4().hex[:10]
def create(entity,data):
 s,j=call(f'companies/{c}/{entity}&year_id={year}','POST',data,t,{'Idempotency-Key':str(uuid.uuid4())});assert s==200,(s,j);return j['data']['id']
items=[]
for kind in ['service','goods']:
 barcode='001'+('1' if kind=='service' else '2')+tag
 ident=create('items',{'code':'FAST'+tag+kind[0],'name':'آزمون سریع '+tag+' '+kind,'barcode':barcode,'kind':kind,'unit_id':f['unit'],'sale_account_id':f['sale_account'],'purchase_account_id':f['purchase_account'],'is_active':True,'sale_price':'123450','purchase_price':'56780','trade_price':'0'})
 items.append((ident,barcode))
create('stock',{'kind':'receipt','doc_date':f['year']['start_date'],'lines':[{'warehouse_id':f['warehouse'],'item_id':items[1][0],'qty_scaled':'100000','cost':'1000000'}]})
passed=[]
def check(label,condition=True):assert condition,label;passed.append(label);print('PASS',label)
out=Path('/workspace/output/business-fast-entry');out.mkdir(parents=True,exist_ok=True)
with sync_playwright() as p:
 browser=p.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox']);page=browser.new_page(viewport={'width':1366,'height':900});errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
 page.goto('http://127.0.0.1:18085/accounting/');page.locator('#user_login').fill('mobiletest');page.locator('#user_pass').fill('LocalIntegration-2026-Only');page.locator('#wp-submit').click();page.wait_for_load_state('networkidle')
 def open_form(kind='sale'):
  page.goto(f'http://127.0.0.1:18085/accounting/?c={c}&p=invoice_new&kind={kind}',wait_until='networkidle');expect(page.locator('[data-trade-entry]')).to_be_visible();page.locator('[data-entry-warehouse]').select_option(f['warehouse'])
 def scan(code):page.locator('[data-entry-query]').fill(code);page.locator('[data-entry-query]').press('Enter')
 def value(row,key):return row.locator(f'[name$="[{key}]"]')
 open_form();body=page.locator('[data-business-rows="trade_lines"]');q=page.locator('[data-entry-query]')
 check('large catalog is not embedded in each row',body.locator('[name$="[item_id]"] option').count()==1)
 scan(items[0][1]);row=body.locator('tr').first;expect(value(row,'item_id')).to_have_value(items[0][0]);expect(value(row,'unit_price')).to_have_value('1234.5');check('leading-zero barcode and sale price')
 scan(items[0][1]);expect(value(row,'qty')).to_have_value('2');check('repeat scan merges one exact unit')
 value(row,'unit_price').fill('1400');scan(items[0][1]);expect(body.locator('tr')).to_have_count(2);expect(value(row,'unit_price')).to_have_value('1,400');check('manual price preserved in separate row')
 # Burst HID-like input while earlier requests are still pending.
 for _ in range(5):scan(items[1][1])
 expect(body.locator('tr')).to_have_count(3);goods=body.locator('tr').nth(2);expect(value(goods,'qty')).to_have_value('5');expect(goods.locator('[data-entry-stock]')).to_contain_text('قابل فروش');check('queued burst retains all scans and stock display')
 check('scanner retains keyboard focus',q.evaluate('(el)=>el===document.activeElement'))
 scan('UNKNOWN-'+tag);expect(page.locator('[data-entry-errors]')).to_contain_text('یافت نشد');scan(items[0][1]);expect(value(body.locator('tr').nth(1),'qty')).to_have_value('2');expect(page.locator('[data-entry-errors]')).to_be_visible();check('unknown scan remains visible after a later success')
 page.locator('input[name="invoice_date"]').evaluate('(el)=>el.value="1405/01/01"');page.locator('input[name="due_date"]').evaluate('(el)=>el.value="1405/01/01"')
 page.evaluate('document.querySelector(".erp-trade-form").dispatchEvent(new Event("submit",{bubbles:true,cancelable:true}))');expect(page.locator('[data-entry-status]')).to_contain_text('خطاهای اسکن');check('unacknowledged scan error blocks submit')
 page.locator('[data-entry-ack]').click();expect(page.locator('[data-entry-errors]')).to_be_hidden()
 page.locator('[data-entry-mode]').select_option('search');q.fill('FAST'+tag);expect(page.locator('[data-entry-results] button')).to_have_count(2);check('server search by product code')
 # Read endpoint nonce/company protections.
 data=page.locator('[data-trade-entry]').evaluate('(e)=>({...e.dataset})')
 params={'action':'agah_trade_entry','nonce':data['nonce'],'company':c,'kind':'sale','mode':'barcode','q':items[0][1],'warehouse':f['warehouse']}
 result=page.request.post(data['endpoint'],form={**params,'nonce':'invalid'});check('invalid nonce rejected',result.status==422 and not result.json()['success'])
 result=page.request.post(data['endpoint'],form={**params,'company':'99999999'});check('foreign company rejected',result.status==404)
 result=page.request.post(data['endpoint'],form={**params,'mode':'search','q':"%' OR 1=1 --"});check('SQL metacharacters are data',result.ok and result.json()['data']['items']==[])
 page.screenshot(path=str(out/'desktop.png'),full_page=True)
 page.set_viewport_size({'width':390,'height':844});page.screenshot(path=str(out/'mobile.png'),full_page=True);check('mobile document stays within viewport',page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'))
 open_form('purchase');scan(items[0][1]);expect(value(page.locator('[data-business-rows] tr').first,'unit_price')).to_have_value('567.8');check('purchase defaults to purchase price')
 party=create('parties',{'name':'طرف خرید سریع '+tag,'kind':'real','is_customer':True,'is_supplier':True,'is_active':True,'credit_limit':'0'})
 page.locator('select[name="party_id"]').evaluate('(el,party)=>{el.add(new Option("طرف آزمون",party));el.value=party;}',party)
 with page.expect_navigation(wait_until='networkidle',timeout=20000):
  page.get_by_role('button',name='ذخیرهٔ پیش‌نویس',exact=True).click()
 from urllib.parse import urlparse,parse_qs
 saved=parse_qs(urlparse(page.url).query)['id'][0]
 s,j=call(f'companies/{c}/invoices/{saved}&year_id={year}',token=t);assert s==200
 check('fast entry saves exact draft through actual web form',j['data']['record']['kind']=='purchase' and j['data']['record']['lines'][0]['unit_price']=='56780')
 check('no JavaScript runtime errors',not errors)
 browser.close()
(out/'result.json').write_text(json.dumps({'passed':passed,'count':len(passed)},ensure_ascii=False,indent=2)+'\n')
print('FAST ENTRY CHECKS',len(passed))
