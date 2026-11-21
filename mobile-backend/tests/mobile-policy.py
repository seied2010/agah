"""Company ceiling and per-user narrowing on a separate fixture company."""
from integration import call,check,cli,passed
import json,uuid
f=json.loads(cli('policy-fixture.php','company','read'));c=f['company'];cli('policy-fixture.php','user','read')
s,j=call('auth/login','POST',{'username':'othermobile','password':'OtherLocal-2026-Only','device_id':str(uuid.uuid4())});assert s==200
t=j['data']['access_token'];body={'name':'Policy fixture '+uuid.uuid4().hex[:8],'kind':'real','is_customer':True,'is_active':True,'credit_limit':'0'}
def write():return call('companies/'+c+'/parties','POST',body,t,{'Idempotency-Key':str(uuid.uuid4())})[0]
try:
    check('company read mode denies write',write()==403)
    cli('policy-fixture.php','user','write')
    check('personal write cannot exceed company read',write()==403)
    cli('policy-fixture.php','company','write');cli('policy-fixture.php','user','read')
    check('personal read narrows company write',write()==403)
    cli('policy-fixture.php','user','write')
    check('company and personal write permit role-authorized create',write()==200)
    cli('policy-fixture.php','user','off')
    s,j=call('companies',token=t)
    check('personal off removes company from selection',s==200 and all(row['id']!=c for row in j['data']['rows']))
    check('personal off also blocks direct company URL',call('companies/'+c+'/context',token=t)[0]==403)
    cli('policy-fixture.php','user','write');cli('policy-fixture.php','company','off')
    check('company off overrides personal write',call('companies/'+c+'/context',token=t)[0]==403)
finally:
    cli('policy-fixture.php','company','read');cli('policy-fixture.php','user','read')
print('MOBILE POLICY CHECKS',len(passed))
