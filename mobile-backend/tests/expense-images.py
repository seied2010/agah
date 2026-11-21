"""Existing private web receipt images and generic attachments remain distinct and scoped."""
from integration import call,check,cli,passed
import base64,hashlib,json,uuid
f=json.loads(cli('expense-images-fixture.php'));main=f['main'];foreign=f['foreign'];c=main['company']
s,j=call('auth/login','POST',{'username':'mobiletest','password':'LocalIntegration-2026-Only','device_id':str(uuid.uuid4())});assert s==200;t=j['data']['access_token']
path=f"companies/{c}/attachments/expense/{main['expense']}"
s,j=call(path,token=t)
check('old client list contains generic files only',s==200 and all(row.get('source')=='file' for row in j['data']['rows']))
s,j=call(path+'&include_expense_images=1',token=t)
check('opt-in includes both existing storage formats',s==200 and {row['source'] for row in j['data']['rows']}=={'file','expense_image'})
image=next(row for row in j['data']['rows'] if row['source']=='expense_image')
check('legacy metadata references correct image',image['id']==main['image'] and image['mime']=='image/png')
s,j=call(f"companies/{c}/expense-images/{main['image']}",token=t)
check('existing web image integrity verified',s==200 and j['data']['sha256']==f['sha256'] and hashlib.sha256(base64.b64decode(j['data']['content_base64'])).hexdigest()==f['sha256'])
check('foreign image IDOR blocked',call(f"companies/{c}/expense-images/{foreign['image']}",token=t)[0]==404)
check('foreign company image blocked',call(f"companies/{foreign['company']}/expense-images/{foreign['image']}",token=t)[0]==404)
check('malformed opt-in rejected',call(path+'&include_expense_images%5B%5D=1',token=t)[0]==422)
print('EXPENSE IMAGE CHECKS',len(passed))
