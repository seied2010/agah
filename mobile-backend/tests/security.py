"""API object authorization, strict input, private files and delayed write cancellation."""
from integration import call, check, cli, passed
import base64,hashlib,json,uuid
f=json.loads(cli("security-fixture.php")); c,y=f['company'],f['year']
def login(user,password):
    status,res=call('auth/login','POST',{'username':user,'password':password,'device_id':str(uuid.uuid4())})
    assert status==200,(status,res)
    return res['data']['access_token']
t=login('mobiletest','LocalIntegration-2026-Only'); viewer=login('mobileviewer','ViewerLocal-2026-Only')
def path(suffix): return f'companies/{c}/{suffix}&year_id={y}'
def mutation(suffix,body,token=t,key=None): return call(path(suffix),'POST',body,token,{'Idempotency-Key':key or str(uuid.uuid4())})
body={'name':'فرد آزمون فایل','kind':'real','is_customer':True,'is_active':True,'credit_limit':'0'}
check('role denies writes despite company write mode',mutation('parties',body,viewer)[0]==403)
for key,value in [('created_by','123'),('name',{'nested':'invalid'}),('is_customer','1'),('items',None),('company_id',f['foreign'])]:
    check('strict DTO '+key,mutation('parties',{**body,key:value})[0]==422)
status,res=mutation('parties',body); assert status==200,(status,res); party=res['data']['id']
attachment=f'attachments/party/{party}'
status,res=mutation(attachment,{'name':'receipt.png','content_base64':f['png']});check('valid image upload',status==200)
fid=res['data']['id']
status,res=call(path('files/'+fid),token=t)
check('file content integrity',status==200 and hashlib.sha256(base64.b64decode(res['data']['content_base64'])).hexdigest()==res['data']['sha256'])
check('file IDOR blocked',call(path('files/'+f['foreign_file']),token=t)[0]==404)
check('foreign company file blocked',call(f"companies/{f['foreign']}/files/{fid}",token=t)[0]==404)
check('viewer upload blocked',mutation(attachment,{'name':'receipt.png','content_base64':f['png']},viewer)[0]==403)
for name,content in [('active.svg',b'<svg onload="alert(1)"></svg>'),('receipt.png',b'<?php phpinfo();'),('../receipt.png',base64.b64decode(f['png']))]:
    check('unsafe attachment rejected '+name,mutation(attachment,{'name':name,'content_base64':base64.b64encode(content).decode()})[0]==422)
key=str(uuid.uuid4())
status,res=call(path('operations/'+key),'DELETE',token=t)
check('cancel uncommitted request',status==200 and res['data']['state']=='cancelled')
check('delayed cancelled write cannot commit',mutation('parties',body,key=key)[0]==409)
status,res=call(path('operations/'+key),token=t)
check('cancel receipt remains durable',status==200 and res['data']['state']=='cancelled')
for format in ['pdf','csv']:
    status,res=call(path('reports/ledger/export')+'&format='+format,token=t)
    check('server export '+format,status==200)
    binary=base64.b64decode(res['data']['content_base64'])
    check('export integrity '+format,hashlib.sha256(binary).hexdigest()==res['data']['sha256'])
    if format=='pdf': check('real PDF signature',binary.startswith(b'%PDF-'))
    else: check('Persian CSV header','تاریخ' in binary.decode('utf-8-sig'))
print('SECURITY CHECKS',len(passed))
