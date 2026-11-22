"""Focused real-browser checks for phone entry cards and the invoice's white paper preview."""
from pathlib import Path
import json,sys,uuid,argparse
sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'mobile-backend/tests'))
from integration import cli,call
from playwright.sync_api import sync_playwright,expect
parser=argparse.ArgumentParser();parser.add_argument('--axe',required=True,type=Path);args=parser.parse_args()
f=json.loads(cli('business-fixture.php'));cli('policy-write.php');c=f['company'];y=str(f['year']['id']);base='http://127.0.0.1:18085/accounting/'
s,j=call('auth/login','POST',{'username':'mobiletest','password':'LocalIntegration-2026-Only','device_id':str(uuid.uuid4())});assert s==200;t=j['data']['access_token']
def create(entity,body):
 s,j=call(f'companies/{c}/{entity}&year_id={y}','POST',body,t,{'Idempotency-Key':str(uuid.uuid4())});assert s==200,(s,j);return j['data']['id']
party=create('parties',{'name':'مشتری آزمون نمایش','kind':'real','is_customer':True,'is_supplier':True,'is_active':True,'credit_limit':'0'})
item=create('items',{'name':'خدمت آزمون نمایش فاکتور','code':'LAY'+uuid.uuid4().hex[:8],'kind':'service','unit_id':f['unit'],'sale_account_id':f['sale_account'],'purchase_account_id':f['purchase_account'],'is_active':True,'sale_price':'123450','purchase_price':'5000'})
inv=create('invoices',{'kind':'sale','party_id':party,'invoice_date':f['year']['start_date'],'due_date':f['year']['start_date'],'lines':[{'item_id':item,'qty_scaled':'30000','unit_price':'123450','discount_amount':'0'}]})
out=Path('/workspace/output/agah-ux-details');out.mkdir(exist_ok=True);passed=[];audits=[]
def check(name,ok=True):assert ok,name;passed.append(name);print('PASS',name,flush=True)
with sync_playwright() as p:
 b=p.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox']);page=b.new_page(viewport={'width':390,'height':844});page.goto(base);page.fill('#user_login','mobiletest');page.fill('#user_pass','LocalIntegration-2026-Only');page.click('#wp-submit');page.wait_for_load_state('networkidle')
 for width in [320,390,680,768,1366]:
  page.set_viewport_size({'width':width,'height':900})
  for kind in ['receipt','payment']:
   page.goto(base+f'?c={c}&p=treasury_new&kind={kind}',wait_until='networkidle')
   area=page.locator('.erp-treasury-form>.erp-scroll');row=area.locator('tbody tr').first
   if width<=680:
    check(f'{kind} entry card fits {width}px',area.evaluate('(e)=>e.scrollWidth<=e.clientWidth+1') and row.locator('td').count()==5)
    for name in ['method','treasury_account_id','amount','reference']:
     field=row.locator(f'[name$="[{name}]"]');expect(field).to_be_visible();assert field.evaluate('(e)=>{const r=e.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth}')
   if width==390:
    expect(row.locator('[name$="[cheque_no]"]')).to_be_hidden();row.locator('[name$="[method]"]').select_option('cheque');expect(row.locator('[name$="[cheque_no]"]')).to_be_visible();row.locator('[name$="[method]"]').select_option('cash');check(f'{kind} shows cheque fields only when needed')
   if width==390 and kind=='receipt':page.evaluate('scrollTo(0,0)');page.screenshot(path=str(out/'receipt-phone.png'),full_page=True)
  page.goto(base+f'?c={c}&p=invoice_view&id={inv}',wait_until='networkidle')
  check(f'invoice scroll is bounded at {width}px',page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'))
 for theme in ['light','dark']:
  for route in [f'invoice_view&id={inv}','treasury_new&kind=receipt']:
   page.set_viewport_size({'width':390,'height':844});page.goto(base+f'?c={c}&p={route}',wait_until='networkidle')
   if page.locator('.erp').get_attribute('data-theme-active')!=theme:page.locator('.erp-theme-toggle').click()
   page.wait_for_timeout(250);page.evaluate(args.axe.read_text());audit=page.evaluate("async()=>{const r=await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa','wcag22aa']}});return r.violations.map(x=>({id:x.id,impact:x.impact,nodes:x.nodes.map(n=>({target:n.target,summary:n.failureSummary}))}))}");audits.append({'route':route,'theme':theme,'violations':audit})
   assert not audit,(theme,route,audit)
  check(f'phone entry and invoice accessibility in {theme}')
 page.set_viewport_size({'width':1366,'height':900});page.goto(base+f'?c={c}&p=invoice_view&id={inv}',wait_until='networkidle');page.evaluate('scrollTo(0,0)');page.screenshot(path=str(out/'invoice-desktop.png'),full_page=True)
 b.close()
(out/'results.json').write_text(json.dumps({'count':len(passed),'passed':passed,'audits':audits},ensure_ascii=False,indent=2)+'\n')
