from integration import call,check,cli,passed
import json,uuid
cli('policy-write.php');f=json.loads(cli('financial-fixture.php'));c=f['company']
s,j=call('auth/login','POST',{'username':'mobiletest','password':'LocalIntegration-2026-Only','device_id':str(uuid.uuid4())});assert s==200
t=j['data']['access_token'];root=f'companies/{c}/parties'
digits='128'+str(uuid.uuid4().int)[0:6];r=sum(int(d)*(10-i) for i,d in enumerate(digits))%11
national=digits+str(r if r<2 else 11-r)
body={'name':'Private version fixture','kind':'real','is_customer':True,'is_active':True,'credit_limit':'0','national_id':national}
s,j=call(root,'POST',body,t,{'Idempotency-Key':str(uuid.uuid4())});assert s==200,(s,j)
ident=j['data']['id'];s,j=call(root+'/'+ident,token=t);assert s==200
before=j['data'];assert 'national_id_enc' not in before['record']
cli('private-version-fixture.php',ident)
s,j=call(root+'/'+ident,token=t);assert s==200
check('private change preserves safe DTO',before['record']==j['data']['record'])
check('private change invalidates opaque ETag',before['etag']!=j['data']['etag'])
s,j=call(root+'/'+ident,'PUT',body,t,{'Idempotency-Key':str(uuid.uuid4()),'If-Match':before['etag']})
check('stale private version rejected',s==409)
print('PRIVATE VERSION CHECKS',len(passed))
