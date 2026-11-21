"""Real WordPress cookie/nonce form submission interoperates with the mobile API."""
from integration import call,check,cli,passed
import http.cookiejar,urllib.request,urllib.parse,html.parser,json,uuid
class Forms(html.parser.HTMLParser):
    def __init__(self):super().__init__();self.forms=[];self.current=None
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if tag=='form':self.current={};self.forms.append(self.current)
        elif tag=='input' and self.current is not None and a.get('name'):
            if a.get('type') in ['checkbox','radio'] and 'checked' not in a:return
            self.current[a['name']]=a.get('value','')
    def handle_endtag(self,tag):
        if tag=='form':self.current=None
f=json.loads(cli('financial-fixture.php'));c=f['company'];y=str(f['year']['id']);origin='http://127.0.0.1:18085'
opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def web(path,body=None):
    data=urllib.parse.urlencode(body).encode() if body is not None else None
    with opener.open(urllib.request.Request(origin+path,data=data,headers={'Referer':origin+'/accounting/'}),timeout=30) as r:return r.read().decode()
login=Forms();login.feed(web('/accounting/'))
auth=next(x for x in login.forms if 'log' in x)
auth.update(log='mobiletest',pwd='LocalIntegration-2026-Only',testcookie='1',redirect_to=origin+'/accounting/')
web('/wp-login.php',auth)
page=f'/accounting/?c={c}&p=parties';parser=Forms();parser.feed(web(page))
form=next((x for x in parser.forms if x.get('act')=='module_party_save'),None)
assert form,'Authenticated web party form not rendered'
name='Web channel '+uuid.uuid4().hex[:10]
form.update(erp_name=name,kind='real',is_customer='1',is_active='1',credit_limit='0',phone='09120000000')
web(page,form)
s,j=call('auth/login','POST',{'username':'mobiletest','password':'LocalIntegration-2026-Only','device_id':str(uuid.uuid4())});assert s==200
t=j['data']['access_token'];root=f'companies/{c}/parties'
s,j=call(root+'&year_id='+y+'&q='+urllib.parse.quote(name),token=t)
check('web form commit visible through mobile API',s==200 and j['data']['count']=='1' and j['data']['rows'][0]['name']==name)
ident=j['data']['rows'][0]['id'];s,j=call(root+'/'+ident,token=t);assert s==200
edited=name+' API edit'
s,j=call(root+'/'+ident,'PUT',{'name':edited,'kind':'real','is_customer':True,'is_active':True,'credit_limit':'0','phone':'09121111111'},t,{'Idempotency-Key':str(uuid.uuid4()),'If-Match':j['data']['etag']});assert s==200,(s,j)
html=web(page+'&q='+urllib.parse.quote(edited))
check('API edit visible in actual web HTML',edited in html)
parser=Forms();parser.feed(web(page+'&edit='+ident));edit=next(x for x in parser.forms if x.get('act')=='module_party_save')
s,j=call(root+'/'+ident,token=t);assert s==200;stale=j['data']['etag']
edit.update(erp_name=name+' web edit',kind='real',is_customer='1',is_active='1',credit_limit='0')
web(page+'&edit='+ident,edit)
s,j=call(root+'/'+ident,'PUT',{'name':'stale API write','kind':'real','is_customer':True,'is_active':True,'credit_limit':'0'},t,{'Idempotency-Key':str(uuid.uuid4()),'If-Match':stale})
check('web edit invalidates stale mobile ETag',s==409)
print('WEB/API ROUNDTRIP CHECKS',len(passed))
