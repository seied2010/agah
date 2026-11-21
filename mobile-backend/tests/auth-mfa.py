"""Actual TOTP/recovery login and session invalidation against the local HTTP API."""
from integration import call,check,cli,passed
import json,uuid
name='mfafixture-'+uuid.uuid4().hex[:12];f=json.loads(cli('mfa-fixture.php','create',name))
def login(factor='',password='MfaLocal-2026-Only'):
    return call('auth/login','POST',{'username':name,'password':password,'factor':factor,'device_id':str(uuid.uuid4())})
check('invalid password denied',login(password='wrong-password')[0]==401)
check('missing second factor denied',login()[0]==401)
check('incorrect second factor denied',login('000000' if f['factor']!='000000' else '999999')[0]==401)
s,j=login(f['factor']);check('real TOTP login accepted',s==200);t=j['data']['access_token'];refresh=j['data']['refresh_token']
check('TOTP proof permits protected read',call('companies',token=t)[0]==200)
check('TOTP replay denied',login(f['factor'])[0]==401)
s,j=login(f['recovery']);check('recovery code accepted once',s==200)
check('recovery replay denied',login(f['recovery'])[0]==401)
cli('mfa-fixture.php','disable',name)
check('MFA security change invalidates access',call('companies',token=t)[0]==401)
check('MFA security change invalidates refresh',call('auth/refresh','POST',{'refresh_token':refresh})[0]==401)
print('AUTH/MFA CHECKS',len(passed))
