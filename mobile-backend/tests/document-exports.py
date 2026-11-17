"""Exercise real PDF generation and document scope through HTTP."""
from integration import call, check, cli, passed
import base64, hashlib, json, uuid

cli('policy-write.php')
f=json.loads(cli('financial-fixture.php'))
s,j=call('auth/login','POST',{'username':'mobiletest','password':'LocalIntegration-2026-Only','device_id':str(uuid.uuid4())})
assert s==200,(s,j)
t=j['data']['access_token'];c=f['company'];year=str(f['year']['id'])
for entity,kind in [('invoices','sale'),('vouchers',''),('treasury','receipt'),('expenses','')]:
    s,j=call(f'companies/{c}/{entity}&year_id={year}&kind={kind}',token=t)
    assert s==200 and j['data']['rows'],(entity,s,j)
    ident=j['data']['rows'][0]['id']
    s,j=call(f'companies/{c}/{entity}/{ident}/export&year_id={year}',token=t)
    assert s==200,(entity,s,j)
    d=j['data'];raw=base64.b64decode(d['content_base64'])
    check(entity+' PDF integrity',raw.startswith(b'%PDF-') and hashlib.sha256(raw).hexdigest()==d['sha256'] and d['mime']=='application/pdf')
    s,j=call(f'companies/999999/{entity}/{ident}/export&year_id={year}',token=t)
    check(entity+' export company scope',s==404)
print('DOCUMENT EXPORT CHECKS',len(passed))
