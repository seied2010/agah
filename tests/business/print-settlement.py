"""Actual web issuance with mixed methods and real PDF output. Local disposable fixtures only."""
from pathlib import Path
import sys,json,uuid
sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'mobile-backend/tests'))
from integration import call,cli
from playwright.sync_api import sync_playwright,expect
import fitz
f=json.loads(cli('business-fixture.php'));cli('policy-write.php');c=f['company'];year=str(f['year']['id']);date=f['year']['start_date']
s,j=call('auth/login','POST',{'username':'mobiletest','password':'LocalIntegration-2026-Only','device_id':str(uuid.uuid4())});assert s==200;t=j['data']['access_token']
def create(entity,body):
 s,j=call(f'companies/{c}/{entity}&year_id={year}','POST',body,t,{'Idempotency-Key':str(uuid.uuid4())});assert s==200,(s,j);return j['data']['id']
party=create('parties',{'name':'مشتری چاپ '+uuid.uuid4().hex[:7],'kind':'real','is_customer':True,'is_supplier':True,'is_active':True,'credit_limit':'0'})
item=create('items',{'code':'PRINT'+uuid.uuid4().hex[:8],'name':'خدمت چاپ آزمایشی','kind':'service','unit_id':f['unit'],'sale_account_id':f['sale_account'],'purchase_account_id':f['purchase_account'],'is_active':True,'sale_price':'123450','purchase_price':'56780'})
inv=create('invoices',{'kind':'sale','party_id':party,'invoice_date':date,'due_date':date,'lines':[{'item_id':item,'qty_scaled':'10000','unit_price':'123450','discount_amount':'0'}]})
passed=[]
def check(label,ok=True):assert ok,label;passed.append(label);print('PASS',label,flush=True)
out=Path('/workspace/output/business-print-settlement');out.mkdir(parents=True,exist_ok=True)
with sync_playwright() as p:
 b=p.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox']);page=b.new_page(viewport={'width':1366,'height':900});page.goto('http://127.0.0.1:18085/accounting/');page.fill('#user_login','mobiletest');page.fill('#user_pass','LocalIntegration-2026-Only');page.click('#wp-submit');page.wait_for_load_state('networkidle')
 url=f'http://127.0.0.1:18085/accounting/?c={c}&p=invoice_view&id={inv}'
 page.goto(url+'&print_layout=customer',wait_until='networkidle');expect(page.locator('.erp-print-state')).to_contain_text('پیش‌نویس');check('draft operational print visibly marked')
 page.goto(url,wait_until='networkidle');body=page.locator('[data-business-rows="invoice_payments"]');expect(body).to_be_visible()
 page.locator('[name="payment_items[0][treasury_account_id]"]').select_option(f['cash']);page.locator('[name="payment_items[0][amount]"]').fill('500')
 page.locator('[data-business-add="invoice_payments"]').click();expect(body.locator('tr')).to_have_count(2)
 page.locator('[name="payment_items[1][method]"]').select_option('card');page.locator('[name="payment_items[1][treasury_account_id]"]').select_option(f['bank']);page.locator('[name="payment_items[1][amount]"]').fill('734.5');check('mixed settlement fields have independent names')
 with page.expect_navigation(wait_until='networkidle',timeout=20000):
  page.get_by_role('button',name='صدور برگه و ثبت اثرها',exact=True).click()
 s,j=call(f'companies/{c}/invoices/{inv}&year_id={year}',token=t);assert s==200
 check('actual web issue commits invoice',j['data']['record']['status']=='issued')
 check('mixed receipt settles exact invoice amount',j['data']['record'].get('outstanding')=='0')
 for layout in ['store','customer','workshop','receipt']:
  page.goto(url+'&print_layout='+layout,wait_until='networkidle');sheet=page.locator('.erp-operational-copy');expect(sheet).to_be_visible()
  if layout=='workshop':check('workshop copy excludes price columns and totals','مبلغ نهایی' not in sheet.inner_text() and sheet.locator('thead th').count()==4)
  else:expect(sheet).to_contain_text('مبلغ نهایی برگه')
  pdf=page.pdf(prefer_css_page_size=True,print_background=True);(out/(layout+'.pdf')).write_bytes(pdf)
  doc=fitz.open(stream=pdf,filetype='pdf');check(layout+' real PDF generated',len(doc)>=1 and len(doc[0].get_text())>40)
  expected=80 if layout=='receipt' else 148;check(layout+' page width matches selected paper',abs(doc[0].rect.width*25.4/72-expected)<2)
  doc[0].get_pixmap(matrix=fitz.Matrix(1,1)).save(str(out/(layout+'.png')))
 b.close()
(out/'result.json').write_text(json.dumps({'count':len(passed),'passed':passed},ensure_ascii=False,indent=2)+'\n')
print('PRINT/SETTLEMENT CHECKS',len(passed))
