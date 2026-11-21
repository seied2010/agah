"""Optional expense payment posts once, and an overpayment rolls back the whole expense."""
from integration import call,check,cli,passed
import json,uuid
cli('policy-write.php');f=json.loads(cli('financial-fixture.php'));c=f['company'];year=str(f['year']['id']);date=f['year']['start_date']
s,j=call('auth/login','POST',{'username':'mobiletest','password':'LocalIntegration-2026-Only','device_id':str(uuid.uuid4())});assert s==200;t=j['data']['access_token']
def path(suffix):return f'companies/{c}/{suffix}&year_id={year}'
s,j=call(path('parties'),'POST',{'name':'Paid expense fixture '+uuid.uuid4().hex[:8],'kind':'real','is_supplier':True,'is_active':True,'credit_limit':'0'},t,{'Idempotency-Key':str(uuid.uuid4())});assert s==200;party=j['data']['id']
body={'category_id':f['category'],'party_id':party,'doc_date':date,'due_date':date,'amount':'10000','description':'Paid expense fixture','payment':[{'method':'cash','treasury_account_id':f['cash'],'amount':'10000'}]};key=str(uuid.uuid4())
s,j=call(path('expenses'),'POST',body,t,{'Idempotency-Key':key});check('expense with direct payment commits',s==200);ident=j['data']['id']
s,j=call(path('expenses/'+ident),token=t)
check('direct expense payment settles exact amount',s==200 and j['data']['record']['paid']=='10000' and j['data']['record']['outstanding']=='0')
s,j=call(path('expenses'),'POST',body,t,{'Idempotency-Key':key});check('duplicate returns same expense',s==200 and j['data']['id']==ident)
s,j=call(path('expenses/'+ident),token=t);check('duplicate did not pay again',s==200 and j['data']['record']['paid']=='10000')
marker='OVERPAY-'+uuid.uuid4().hex[:10];bad={**body,'description':marker,'payment':[{'method':'cash','treasury_account_id':f['cash'],'amount':'20000'}]}
s,j=call(path('expenses'),'POST',bad,t,{'Idempotency-Key':str(uuid.uuid4())});check('overpayment rejected',s in [409,422])
s,j=call(path('expenses')+'&q='+marker,token=t);check('failed payment leaves no expense document',s==200 and j['data']['count']=='0')
print('EXPENSE PAYMENT CHECKS',len(passed))
