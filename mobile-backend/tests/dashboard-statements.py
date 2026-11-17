"""Core dashboard dates and complete statement exports, never client-derived totals."""
from integration import call,check,cli,passed
import base64,json,uuid
f=json.loads(cli('financial-fixture.php'));c=f['company'];y=str(f['year']['id'])
s,j=call('auth/login','POST',{'username':'mobiletest','password':'LocalIntegration-2026-Only','device_id':str(uuid.uuid4())});assert s==200
t=j['data']['access_token']
def path(suffix):return f'companies/{c}/{suffix}&year_id={y}'
s,j=call(path('dashboard')+'&as_of='+f['year']['start_date'],token=t)
check('dashboard chosen date',s==200 and j['data']['date']==f['year']['start_date'])
check('core trend and alerts returned',len(j['data']['months'])==6 and isinstance(j['data']['notifications'],list))
s,j=call(path('dashboard')+'&as_of=2000-01-01',token=t)
check('dashboard outside year rejected',s==422)
s,j=call(path('reports/statements/export')+'&format=csv',token=t)
assert s==200,(s,j)
text=base64.b64decode(j['data']['content_base64']).decode('utf-8-sig')
for section in ['سود و زیان','ترازنامه','جریان وجوه نقد']:
    check('complete CSV '+section,section in text)
s,j=call(path('reports/statements/export')+'&format=pdf',token=t)
check('complete statement PDF',s==200 and base64.b64decode(j['data']['content_base64']).startswith(b'%PDF-'))
print('DASHBOARD/STATEMENTS CHECKS',len(passed))
