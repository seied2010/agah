"""Stock document atomicity, concurrent depletion and closed-period boundaries."""
from integration import call,check,cli,passed
import concurrent.futures,json,uuid

cli('policy-write.php')
f=json.loads(cli('financial-fixture.php'));c=f['company'];year=str(f['year']['id']);date=f['year']['start_date']
s,j=call('auth/login','POST',{'username':'mobiletest','password':'LocalIntegration-2026-Only','device_id':str(uuid.uuid4())});assert s==200
t=j['data']['access_token']
def path(entity):return f'companies/{c}/{entity}&year_id={year}'
def post(entity,body,key=None):return call(path(entity),'POST',body,t,{'Idempotency-Key':key or str(uuid.uuid4())})
def create(entity,body):
    s,j=post(entity,body);assert s==200,(s,j);return j['data']['id']
item=create('items',{'code':uuid.uuid4().hex[:15],'name':'کالای آزمون همزمانی','kind':'goods','unit_id':f['unit'],'sale_account_id':f['sale_account'],'purchase_account_id':f['purchase_account'],'is_active':True,'sale_price':'100000','purchase_price':'50000','trade_price':'0'})
def balance(warehouse):
    s,j=call(path('reports/kardex')+f'&warehouse_id={warehouse}&item_id={item}',token=t);assert s==200,(s,j)
    return j['data']['balance']
def stock(kind,quantity,target=None):
    line={'warehouse_id':f['warehouse'],'item_id':item,'qty_scaled':quantity,'cost':'1000000' if kind=='receipt' else '0'}
    if target:line['target_warehouse_id']=target
    return {'kind':kind,'doc_date':date,'lines':[line]}
create('stock',stock('receipt','100000'))
check('stock receipt exact quantity/value',balance(f['warehouse'])=={'qty':'100000','value':'1000000'})
key=str(uuid.uuid4());body=stock('issue','20000');s,j=post('stock',body,key);assert s==200
s,replay=post('stock',body,key)
check('stock duplicate does not deplete twice',s==200 and replay['data']['id']==j['data']['id'] and balance(f['warehouse'])['qty']=='80000')
create('stock',stock('transfer','30000',f['target_warehouse']))
check('warehouse transfer conserves quantities',balance(f['warehouse'])['qty']=='50000' and balance(f['target_warehouse'])['qty']=='30000')
key=str(uuid.uuid4());s,j=post('stock',stock('issue','90000'),key)
check('negative stock rejected atomically',s==409 and balance(f['warehouse'])['qty']=='50000')
s,j=call(path('operations/'+key),token=t)
check('failed stock write has no committed receipt',s==200 and j['data']['state']=='not_found')
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
    results=list(pool.map(lambda _:post('stock',stock('issue','40000')),range(2)))
check('concurrent stock cannot overdraw',sorted(s for s,_ in results)==[200,409] and balance(f['warehouse'])['qty']=='10000')
voucher=create('vouchers',{'voucher_date':date,'description':'آزمون سند نامتوازن','lines':[{'account_id':f['manual_debit'],'debit':'10000','credit':'0'},{'account_id':f['manual_credit'],'debit':'0','credit':'9000'}]})
s,j=call(path('vouchers/'+voucher),token=t);assert s==200
s,j=call(path('vouchers/'+voucher+'/approve'),'POST',{},t,{'Idempotency-Key':str(uuid.uuid4()),'If-Match':j['data']['etag']})
check('unbalanced draft cannot post',s==409)
cli('period-fixture.php','closed')
try:
    s,j=post('vouchers',{'voucher_date':date,'description':'دوره بسته','lines':[]})
    check('closed period rejects document write',s==409)
finally:cli('period-fixture.php','open')
s,j=post('vouchers',{'voucher_date':'2020-01-01','description':'سال بیگانه','lines':[]})
check('selected fiscal year enforced',s==422)
print('STOCK/PERIOD CHECKS',len(passed))
