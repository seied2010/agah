"""Real web permission delegation, confirmation, CSRF, replay and deletion."""
from pathlib import Path
import sys,json,uuid
sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'mobile-backend/tests'))
from integration import call,cli
from playwright.sync_api import sync_playwright,expect
f=json.loads(cli('invoice-delete-web-fixture.php'));cli('policy-write.php');cid=f['company'];year=str(f['year']['id']);base='http://127.0.0.1:18085/accounting/'
s,j=call('auth/login','POST',{'username':'mobiletest','password':'LocalIntegration-2026-Only','device_id':str(uuid.uuid4())});assert s==200;token=j['data']['access_token']
def create(entity,body):
 s,j=call(f'companies/{cid}/{entity}&year_id={year}','POST',body,token,{'Idempotency-Key':str(uuid.uuid4())});assert s==200,(s,j);return j['data']['id']
party=create('parties',{'name':'مشتری آزمون حذف وب '+uuid.uuid4().hex[:7],'kind':'real','is_customer':True,'is_supplier':True,'is_active':True,'credit_limit':'0'})
item=create('items',{'code':'UXWEB'+uuid.uuid4().hex[:8],'name':'خدمت حذف آزمایشی وب','kind':'service','unit_id':f['unit'],'sale_account_id':f['sale_account'],'purchase_account_id':f['purchase_account'],'is_active':True,'sale_price':'10000','purchase_price':'5000'})
def invoice():return create('invoices',{'kind':'sale','party_id':party,'invoice_date':f['year']['start_date'],'due_date':f['year']['start_date'],'lines':[{'item_id':item,'qty_scaled':'10000','unit_price':'10000','discount_amount':'0'}]})
first=invoice();second=invoice();passed=[];out=Path('/workspace/output/agah-invoice-delete-web');out.mkdir(parents=True,exist_ok=True)
def check(label,ok=True):assert ok,label;passed.append(label);print('PASS',label,flush=True)
with sync_playwright() as p:
 b=p.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
 def login(name,password):
  context=b.new_context(viewport={'width':1366,'height':900});page=context.new_page();page.goto(base);page.fill('#user_login',name);page.fill('#user_pass',password);page.click('#wp-submit');page.wait_for_load_state('networkidle');return page
 admin=login('mobiletest','LocalIntegration-2026-Only');cashier=login('uxcashier','Disposable-UX-2026-Only');viewurl=base+f'?c={cid}&p=invoice_view&id={first}'
 cashier.goto(viewurl,wait_until='networkidle');check('cashier has no delete button by default',cashier.locator('#invoice-delete').count()==0)
 admin.goto(base+f'?c={cid}&p=invoice_delete_access&q=uxcashier',wait_until='networkidle')
 uf=admin.locator('form').filter(has=admin.locator('[name=act][value=module_invoice_delete_user]'));uid=uf.locator('[name=user_id]').input_value();uf.locator('[name=permission]').select_option('1')
 with admin.expect_navigation(wait_until='networkidle'):uf.get_by_role('button',name='ذخیرهٔ دسترسی شخص').click()
 check('delegation page preserves search',admin.locator('[name=q]').input_value()=='uxcashier')
 cashier.reload(wait_until='networkidle');expect(cashier.locator('#invoice-delete')).to_be_visible();check('individual grant immediately exposes deletion')
 cashier.goto(viewurl+'#invoice-delete',wait_until='networkidle');expect(cashier.locator('#invoice-delete')).to_have_attribute('open','')
 form=cashier.locator('#invoice-delete form');form.locator('[name=reason]').fill('آزمون حذف از رابط وب');form.locator('[name=confirmed]').check()
 def fields():return dict(form.evaluate('(e)=>[...new FormData(e).entries()]'))
 data=fields();headers={'X-Agah-Form':'1','Accept':'application/json'}
 response=cashier.request.post(viewurl,form={**data,'_n':'invalid'},headers=headers);check('invalid nonce blocks delete',response.status==422 and bool(response.json()['errors']))
 missing={**data};missing.pop('confirmed');response=cashier.request.post(viewurl,form=missing,headers=headers);check('server requires explicit confirmation',response.status==422 and 'confirmed' in response.json()['errors'])
 response=cashier.request.post(viewurl,form={**data,'revision':'0'},headers=headers);check('server rejects stale invoice revision',response.status==422)
 # Forged delegation is independently blocked even though deletion itself is allowed.
 response=cashier.request.post(viewurl,form={'act':'module_invoice_delete_user','c':cid,'user_id':uid,'permission':'1','_n':data['_n'],'_idem':data['_idem'],'back':'invoice_delete_access'},headers=headers)
 check('forged delegation rejected',response.status==422 and bool(response.json()['errors']))
 seen=[]
 def watch(request):
  if request.method=='POST' and request.url.startswith(base):seen.append(request)
 cashier.on('request',watch)
 def cancel(dialog):dialog.dismiss()
 cashier.on('dialog',cancel);form.get_by_role('button',name='حذف فاکتور',exact=True).click();cashier.wait_for_timeout(100);check('cancel sends no deletion request',not seen)
 cashier.remove_listener('dialog',cancel)
 def accept(dialog):dialog.accept()
 cashier.on('dialog',accept)
 with cashier.expect_navigation(wait_until='networkidle'):form.get_by_role('button',name='حذف فاکتور',exact=True).click()
 check('real confirmed form deletes and returns to list','p=trade_sale' in cashier.url)
 check('deleted invoice absent in normal list',cashier.locator(f'table a[href*="p=invoice_view&id={first}"]').count()==0)
 response=cashier.request.post(viewurl,form=data,headers=headers);check('same idempotency token returns original success',response.ok and response.json().get('redirect') and not response.json()['errors'])
 cashier.goto(base+f'?c={cid}&p=trade_sale&status=deleted',wait_until='networkidle');check('authorized archive contains deleted invoice',cashier.locator(f'table a[href*="p=invoice_view&id={first}"]').count()==1)
 cashier.goto(viewurl,wait_until='networkidle');check('archived record cannot be deleted again',cashier.locator('#invoice-delete').count()==0 and 'فقط سابقهٔ بایگانی' in cashier.locator('.erp').inner_text())
 admin.goto(base+f'?c={cid}&p=trade_sale',wait_until='networkidle');admin.locator(f'table a[href*="id={second}#invoice-delete"]').click();expect(admin.locator('#invoice-delete')).to_have_attribute('open','');check('list delete action opens explanation and confirmation')
 admin.screenshot(path=str(out/'delete-desktop.png'),full_page=True);admin.set_viewport_size({'width':390,'height':844});admin.screenshot(path=str(out/'delete-mobile.png'),full_page=True)
 # Withdraw the right and replay with a fresh form token: backend must reject it.
 cashier.goto(base+f'?c={cid}&p=invoice_view&id={second}',wait_until='networkidle');fresh=dict(cashier.locator('#invoice-delete form').evaluate('(e)=>[...new FormData(e).entries()]'))
 admin.goto(base+f'?c={cid}&p=invoice_delete_access&q=uxcashier',wait_until='networkidle');uf=admin.locator('form').filter(has=admin.locator('[name=act][value=module_invoice_delete_user]'));uf.locator('[name=permission]').select_option('0')
 with admin.expect_navigation(wait_until='networkidle'):uf.get_by_role('button',name='ذخیرهٔ دسترسی شخص').click()
 response=cashier.request.post(viewurl,form={**fresh,'confirmed':'1','reason':'آزمون لغو مجوز'},headers=headers);check('revoked permission blocks fresh POST',response.status==422 and bool(response.json()['errors']))
 cashier.reload(wait_until='networkidle');check('revocation removes delete button immediately',cashier.locator('#invoice-delete').count()==0)
 uf=admin.locator('form').filter(has=admin.locator('[name=act][value=module_invoice_delete_user]'));uf.locator('[name=permission]').select_option('-1')
 with admin.expect_navigation(wait_until='networkidle'):uf.get_by_role('button',name='ذخیرهٔ دسترسی شخص').click()
 b.close()
(out/'results.json').write_text(json.dumps({'count':len(passed),'passed':passed},ensure_ascii=False,indent=2)+'\n')
