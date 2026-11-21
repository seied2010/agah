"""Path captures cannot be replaced by query parameters; exported text remains inert."""
from integration import call,check,cli,passed
import base64,csv,io,json,uuid
cli('policy-write.php');f=json.loads(cli('financial-fixture.php'));c=f['company'];year=str(f['year']['id'])
s,j=call('auth/login','POST',{'username':'mobiletest','password':'LocalIntegration-2026-Only','device_id':str(uuid.uuid4())});assert s==200
t=j['data']['access_token']
def path(suffix):return f'companies/{c}/{suffix}&year_id={year}'
def create(name):
    s,j=call(path('parties'),'POST',{'name':name,'kind':'real','is_customer':True,'is_active':True,'credit_limit':'0'},t,{'Idempotency-Key':str(uuid.uuid4())});assert s==200,(s,j);return j['data']['id']
a=create('Routed party '+uuid.uuid4().hex[:8]);b=create('Other routed party '+uuid.uuid4().hex[:8])
s,j=call(path('parties/'+a)+'&id='+b+'&entity=items',token=t)
check('detail path overrides query id and entity',s==200 and j['data']['record']['id']==a and j['data']['record']['name'].startswith('Routed party'))
s,j=call(path('lookups/parties/'+a)+'&id='+b+'&lookup=items',token=t)
check('lookup path overrides query',s==200 and j['data']['id']==a)
s,j=call(path('reports/ledger')+'&report=unknown',token=t)
check('report path overrides query',s==200 and 'rows' in j['data'])
key1,key2=str(uuid.uuid4()),str(uuid.uuid4())
s,j=call(path('operations/'+key2),'DELETE',token=t);assert s==200
s,j=call(path('operations/'+key1)+'&operation='+key2,token=t)
check('operation path overrides query',s==200 and j['data']['state']=='not_found')
name='\ufeff=1+1 '+uuid.uuid4().hex[:6];party=create(name)
s,j=call(path('parties/'+party),token=t);assert s==200;detail=j['data']['record']['detail_id']
body={'voucher_date':f['year']['start_date'],'description':'CSV security fixture','lines':[{'account_id':f['receivable'],'detail_id':detail,'debit':'10000','credit':'0'},{'account_id':f['manual_credit'],'debit':'0','credit':'10000'}]}
s,j=call(path('vouchers'),'POST',body,t,{'Idempotency-Key':str(uuid.uuid4())});assert s==200,(s,j);voucher=j['data']['id']
s,j=call(path('vouchers/'+voucher),token=t);assert s==200
s,j=call(path('vouchers/'+voucher+'/approve'),'POST',{'reason':'CSV security fixture'},t,{'Idempotency-Key':str(uuid.uuid4()),'If-Match':j['data']['etag']});assert s==200,(s,j)
found=False
for page in range(1,11):
    s,j=call(path('reports/parties')+'&page='+str(page),token=t);assert s==200
    if any(row.get('name')==name for row in j['data']['rows']):
        s,j=call(path('reports/parties/export')+'&format=csv&page='+str(page),token=t);assert s==200
        cells=[cell for row in csv.reader(io.StringIO(base64.b64decode(j['data']['content_base64']).decode('utf-8-sig'))) for cell in row]
        check('BOM-prefixed formula is escaped in CSV',"'"+name in cells)
        check('party CSV includes real closing balance','مانده پایان' in cells and '100' in cells)
        found=True;break
    if page>=int(j['data'].get('pages','1')):break
assert found,'CSV fixture not found in party report'
print('ROUTED INPUT CHECKS',len(passed))
