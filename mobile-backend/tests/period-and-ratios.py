"""Period cards exclude drafts/voids and ratios preserve core undefined-state semantics."""
from integration import call,check,cli,passed
import base64,json,uuid,datetime
cli('policy-write.php');f=json.loads(cli('financial-fixture.php'));c=f['company'];y=str(f['year']['id'])
date=(datetime.date.fromisoformat(f['year']['start_date'])+datetime.timedelta(days=10)).isoformat()
s,j=call('auth/login','POST',{'username':'mobiletest','password':'LocalIntegration-2026-Only','device_id':str(uuid.uuid4())});assert s==200
t=j['data']['access_token']
def path(suffix):return f'companies/{c}/{suffix}&year_id={y}'
def create(entity,body):
    s,j=call(path(entity),'POST',body,t,{'Idempotency-Key':str(uuid.uuid4())});assert s==200,(s,j);return j['data']['id']
def act(entity,ident,action):
    s,j=call(path(entity+'/'+ident),token=t);assert s==200
    s,j=call(path(entity+'/'+ident+'/'+action),'POST',{'reason':'local period regression'},t,{'Idempotency-Key':str(uuid.uuid4()),'If-Match':j['data']['etag']});assert s==200,(s,j)
def cards():
    s,j=call(path('dashboard')+'&from='+date+'&as_of='+date,token=t);assert s==200,(s,j)
    return j['data']['cards']
party=create('parties',{'name':'Period fixture '+uuid.uuid4().hex[:8],'kind':'real','is_customer':True,'is_supplier':True,'is_active':True,'credit_limit':'0'})
item=create('items',{'name':'Period service','code':uuid.uuid4().hex[:12],'kind':'service','unit_id':f['unit'],'sale_account_id':f['sale_account'],'purchase_account_id':f['purchase_account'],'is_active':True,'sale_price':'10000','purchase_price':'5000','trade_price':'0'})
before=cards();sale_key='فروش خالص بازه (با مالیات)';receipt_key='دریافت بازه'
body={'kind':'sale','party_id':party,'invoice_date':date,'due_date':date,'lines':[{'item_id':item,'qty_scaled':'10000','unit_price':'10000','discount_amount':'0'}]}
invoice=create('invoices',body)
check('draft does not affect period sales',cards()[sale_key]==before[sale_key])
act('invoices',invoice,'issue')
check('issued invoice enters chosen period',int(cards()[sale_key])-int(before[sale_key])==10000)
s,j=call(path('invoices/'+invoice),token=t)
check('invoice exposes core outstanding',s==200 and j['data']['record']['outstanding']=='10000')
act('invoices',invoice,'void')
check('void invoice excluded from period sales',cards()[sale_key]==before[sale_key])
receipt=create('treasury',{'kind':'receipt','party_id':party,'doc_date':date,'advance':True,'items':[{'method':'cash','treasury_account_id':f['cash'],'amount':'5000'}]})
check('receipt enters chosen period',int(cards()[receipt_key])-int(before[receipt_key])==5000)
act('treasury',receipt,'void')
check('void receipt excluded from period total',cards()[receipt_key]==before[receipt_key])
s,j=call(path('dashboard')+'&from='+date+'&as_of='+f['year']['start_date'],token=t)
check('reversed dashboard range rejected',s==422)
s,j=call(path('reports/ratios'),token=t);assert s==200,(s,j)
rows=j['data']['rows'];check('all thirteen core ratios',len(rows)==13 and all(r['description'] and r['formula'] for r in rows))
undefined=[r for r in rows if r['result_text']=='غیرقابل محاسبه']
check('undefined ratios retain reason rather than zero',all(r['reason'] for r in undefined) and len(undefined)>0)
s,j=call(path('reports/ratios/export')+'&format=csv',token=t)
check('ratio CSV keeps explanation',s==200 and 'توان شرکت'.encode() in base64.b64decode(j['data']['content_base64']))
print('PERIOD/RATIO CHECKS',len(passed))
