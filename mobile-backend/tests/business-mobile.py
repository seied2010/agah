"""Online mobile contract for fast entry, atomic mixed settlement and shared print templates."""
from integration import call,cli,check,passed
import json,uuid,base64,hashlib
import fitz
f=json.loads(cli('business-fixture.php'));cli('policy-write.php');c=f['company'];y=str(f['year']['id']);date=f['year']['start_date']
s,j=call('auth/login','POST',{'username':'mobiletest','password':'LocalIntegration-2026-Only','device_id':str(uuid.uuid4())});assert s==200;t=j['data']['access_token']
def path(entity):return f'companies/{c}/{entity}&year_id={y}'
def post(entity,body):return call(path(entity),'POST',body,t,{'Idempotency-Key':str(uuid.uuid4())})
def create(entity,body):
 s,j=post(entity,body);assert s==200,(s,j);return j['data']['id']
s,j=call(path('context'),token=t);check('server explicitly advertises supported features',s==200 and j['data']['features']=={'invoice_entry':True,'mixed_settlement':True,'invoice_layouts':True})
item=create('items',{'code':'MBIZ'+uuid.uuid4().hex[:8],'name':'خدمت ورود سریع موبایل','kind':'service','unit_id':f['unit'],'sale_account_id':f['sale_account'],'purchase_account_id':f['purchase_account'],'is_active':True,'sale_price':'123450','purchase_price':'56780','barcode':'000'+uuid.uuid4().hex[:12]})
for kind,price in [('sale','123450'),('purchase','56780')]:
 s,j=call(path('invoice-entry-item')+f'&item_id={item}&kind={kind}&warehouse_id=0',token=t)
 check(kind+' price projection is exact and scoped',s==200 and j['data']['price']==price and j['data']['stock'] is None and 'purchase_price' not in j['data'])
s,j=call(path('invoice-entry-item')+f'&item_id={item}&kind=sale&warehouse_id=999999999',token=t);check('foreign warehouse denied',s==404)
s,j=call(path('invoice-entry-item')+f'&item_id={item}&kind=sale&company=99999999',token=t);check('query cannot override company capture',s==200)
goods=create('items',{'code':'MG'+uuid.uuid4().hex[:8],'name':'کالای موجودی موبایل','kind':'goods','unit_id':f['unit'],'sale_account_id':f['sale_account'],'purchase_account_id':f['purchase_account'],'is_active':True,'sale_price':'123450','purchase_price':'56780'})
create('stock',{'kind':'receipt','doc_date':date,'lines':[{'warehouse_id':f['warehouse'],'item_id':goods,'qty_scaled':'100000','cost':'1000000'}]})
s,j=call(path('invoice-entry-item')+f'&item_id={goods}&kind=sale&warehouse_id='+f['warehouse'],token=t)
check('warehouse stock projection uses exact scaled quantities',s==200 and j['data']['stock']=={'on_hand':'100000','available':'100000'})
s,j=call(path('invoice-entry-item')+f'&item_id=999999999&kind=sale',token=t);check('foreign or missing item never leaks',s==404)
party=create('parties',{'name':'طرف تسویه موبایل','kind':'real','is_customer':True,'is_supplier':True,'is_active':True,'credit_limit':'0'})
def invoice():return create('invoices',{'kind':'sale','party_id':party,'invoice_date':date,'due_date':date,'lines':[{'item_id':item,'qty_scaled':'10000','unit_price':'123450','discount_amount':'0'}]})
inv=invoice();s,j=call(path('invoices/'+inv),token=t);assert s==200;etag=j['data']['etag'];key=str(uuid.uuid4())
body={'reason':'','payment_items':[{'method':'cash','treasury_account_id':f['cash'],'amount':'50000'},{'method':'card','treasury_account_id':f['bank'],'amount':'73450'}]}
headers={'Idempotency-Key':key,'If-Match':etag}
s,j=call(path('invoices/'+inv+'/issue'),'POST',body,t,headers);check('mixed mobile settlement committed',s==200)
s,replay=call(path('invoices/'+inv+'/issue'),'POST',body,t,headers);check('duplicate mixed issue returns same receipt',s==200 and replay['data']['id']==inv)
s,j=call(path('invoices/'+inv),token=t);check('mixed issue leaves zero outstanding',s==200 and j['data']['record']['outstanding']=='0')
other=invoice();s,j=call(path('invoices/'+other),token=t);etag=j['data']['etag']
s,j=call(path('invoices/'+other+'/issue'),'POST',{'payment_items':[{'method':'cash','treasury_account_id':f['cash'],'amount':'999999999'}]},t,{'Idempotency-Key':str(uuid.uuid4()),'If-Match':etag});check('overpayment rejected',s in [409,422])
s,j=call(path('invoices/'+other),token=t);check('overpayment rolls back invoice issuance',s==200 and j['data']['record']['status']=='draft')
s,j=call(path('invoices/'+other+'/void'),'POST',{'reason':'ابطال آزمون','payment_items':[]},t,{'Idempotency-Key':str(uuid.uuid4()),'If-Match':j['data']['etag']});check('payment data not accepted for void',s==422)
for layout in ['store','customer','workshop','receipt']:
 s,j=call(path('invoices/'+inv+'/export')+'&layout='+layout,token=t);assert s==200,(s,j)
 data=j['data'];raw=base64.b64decode(data['content_base64']);doc=fitz.open(stream=raw,filetype='pdf')
 check(layout+' shared template PDF integrity',raw.startswith(b'%PDF-') and hashlib.sha256(raw).hexdigest()==data['sha256'])
 expected=80 if layout=='receipt' else 148
 check(layout+' PDF paper width',abs(doc[0].rect.width*25.4/72-expected)<2)
s,j=call(path('invoices/'+inv+'/export')+'&layout=unexpected',token=t);check('unknown print layout rejected',s==422)
print('MOBILE BUSINESS CHECKS',len(passed))
