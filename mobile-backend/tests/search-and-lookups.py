"""Read filters must affect SQL pagination, not merely the visible client page."""
from integration import call,check,cli,passed
import json,uuid,urllib.parse

cli('policy-write.php');f=json.loads(cli('financial-fixture.php'));c=f['company'];year=str(f['year']['id']);date=f['year']['start_date']
s,j=call('auth/login','POST',{'username':'mobiletest','password':'LocalIntegration-2026-Only','device_id':str(uuid.uuid4())});assert s==200
t=j['data']['access_token'];marker='MOB-'+uuid.uuid4().hex[:10]
def path(suffix):return f'companies/{c}/{suffix}&year_id={year}'
def create(entity,body):
    s,j=call(path(entity),'POST',body,t,{'Idempotency-Key':str(uuid.uuid4())});assert s==200,(s,j);return j['data']['id']
party=create('parties',{'name':marker,'kind':'real','is_customer':True,'is_supplier':True,'is_active':True,'credit_limit':'0'})
item=create('items',{'name':marker,'code':marker,'kind':'goods','unit_id':f['unit'],'sale_account_id':f['sale_account'],'purchase_account_id':f['purchase_account'],'is_active':True,'sale_price':'10000','purchase_price':'5000','trade_price':'0'})
invoice=create('invoices',{'kind':'sale','party_id':party,'invoice_date':date,'due_date':date,'description':marker,'lines':[{'item_id':item,'warehouse_id':f['warehouse'],'qty_scaled':'10000','unit_price':'10000','discount_amount':'0'}]})
receipt=create('treasury',{'kind':'receipt','party_id':party,'doc_date':date,'description':marker,'advance':True,'items':[{'method':'cash','treasury_account_id':f['cash'],'amount':'50000'}]})
expense=create('expenses',{'category_id':f['category'],'party_id':party,'doc_date':date,'due_date':date,'amount':'1000','description':marker})
for entity,ident,kind in [('parties',party,''),('items',item,''),('invoices',invoice,'sale'),('treasury',receipt,'receipt'),('expenses',expense,'')]:
    s,j=call(path(entity)+'&kind='+kind+'&q='+urllib.parse.quote(marker),token=t)
    check('server search '+entity,s==200 and j['data']['count']=='1' and j['data']['rows'][0]['id']==ident)
s,j=call(path('stock')+'&q='+urllib.parse.quote(marker),token=t)
check('stock search filters all warehouses',s==200 and len(j['data']['rows'])>0 and all(r['item_id']==item for r in j['data']['rows']))
s,j=call(path('invoices')+'&kind=sale&q='+urllib.parse.quote("' OR 1=1 --"),token=t)
check('search metacharacters are data',s==200 and j['data']['count']=='0')
s,j=call(path('lookups/parties/'+party),token=t)
check('lookup DTO has no private fields',s==200 and set(j['data'])=={'id','name','code'})
s,j=call('companies/999999/context&company='+c,token=t)
check('company path cannot be overridden by query',s==404)
s,j=call(path('lookups/items')+'&q%5B0%5D=invalid',token=t)
check('query arrays rejected cleanly',s==422)
print('SEARCH/LOOKUP CHECKS',len(passed))
