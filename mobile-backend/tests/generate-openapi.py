"""Regenerate the machine-readable contract from the server DTO allowlists."""
import json,pathlib,re
root=pathlib.Path(__file__).resolve().parents[2]
php=(root/'mobile-backend/agah-mobile-api/src/Payload.php').read_text()
def section(name):
    text=re.search(r'private const '+name+r'=\[(.*?)\];',php,re.S).group(1)
    return dict(re.findall(r"'([^']+)'=>'([^']*)'",text))
headers,lines=section('HEADERS'),section('LINES')
flags={'is_customer','is_supplier','is_employee','is_active','clear_national_id','advance'}
def scalar(key):
    if key in flags:return {'type':'boolean'}
    result={'type':'string','maxLength':4096}
    if key.endswith('_date'):result['format']='date'
    if key in {'amount','debit','credit','cost','credit_limit','sale_price','purchase_price','trade_price','unit_price','discount_amount','extra_cost','qty_scaled'}:
        result.update(pattern='^[0-9]{1,16}$',description='Exact scaled integer string; money 100/rial, quantity 10000/unit. Core bounds and business constraints apply.')
    return result
def dto(fields):return {'type':'object','additionalProperties':False,'properties':{key:scalar(key) for key in fields.split()}}
schemas={}
for entity,fields in headers.items():
    schema=dto(fields)
    for key in ['lines','items','payment','allocations']:
        if key in fields.split():schema['properties'][key]={'type':'array','maxItems':200,'items':dto('invoice_id amount' if key=='allocations' else lines.get(entity,''))}
    schemas[entity]=schema
schemas['Envelope']={'type':'object','required':['data','api_version','request_id'],'properties':{'data':{},'api_version':{'type':'string','enum':['1']},'request_id':{'type':'string','pattern':'^[a-f0-9]{16}$'}}}
schemas['File']={'type':'object','required':['name','mime','sha256','content_base64'],'properties':{k:{'type':'string'} for k in ['name','mime','sha256','content_base64']}}
paths={}
def route(path,method,summary,body=None,public=False,query=(),write=False,edit=False):
    parameters=[{'name':name,'in':'path','required':True,'schema':{'type':'string'}} for name in re.findall(r'{([^}]+)}',path)]
    parameters += [{'name':q,'in':'query','schema':{'type':'string'}} for q in query]
    if write:parameters.append({'name':'Idempotency-Key','in':'header','required':True,'schema':{'type':'string','pattern':'^[A-Za-z0-9-]{20,100}$'}})
    if edit:parameters.append({'name':'If-Match','in':'header','required':True,'schema':{'type':'string'}})
    op={'summary':summary,'parameters':parameters,'responses':{'200':{'description':'Success; exact integers are serialized as strings.','content':{'application/json':{'schema':{'$ref':'#/components/schemas/Envelope'}}}},'default':{'description':'401 authentication, 403 authorization, 404 scoped object missing, 409 stale/conflict, 413 size, 422 validation, 429 rate limit, 500/503 unavailable. An unrecognized proxy error leaves a write uncertain.'}}}
    if public:op['security']=[]
    if body:op['requestBody']={'required':True,'content':{'application/json':{'schema':body}}}
    paths.setdefault(path,{})[method]=op
route('/auth/login','post','Authenticate with the existing web account',dto('username password factor device_id device_name'),True)
route('/auth/refresh','post','Rotate the one-use refresh token',dto('refresh_token'),True)
route('/auth/logout','post','Revoke the current session')
route('/auth/devices','get','List own active sessions')
route('/auth/devices/{id}','delete','Revoke an owned session')
route('/companies','get','List authorized companies')
base='/companies/{company}'
route(base+'/context','get','Company policy, capabilities, fiscal years and scales')
route(base+'/dashboard','get','Core period cards, six-calendar-month trend and alerts',query=['year_id','from','as_of'])
for entity in headers:
    path=base+'/'+entity
    route(path,'get','Paged '+entity,query=['year_id','page','q','kind','from','to','status'])
    route(path,'post','Create '+entity,{'$ref':'#/components/schemas/'+entity},query=['year_id'],write=True)
    if entity!='stock':
        route(path+'/{id}','get','Authorized detail and opaque ETag',query=['year_id'])
    if entity in ['parties','items','invoices','vouchers']:
        route(path+'/{id}','put','Edit '+entity,{'$ref':'#/components/schemas/'+entity},query=['year_id'],write=True,edit=True)
    actions={'invoices':['issue','void'],'vouchers':['approve','finalize','reopen','void'],'treasury':['void'],'expenses':['void']}.get(entity,[])
    for action in actions:route(path+'/{id}/'+action,'post',action+' '+entity,dto('reason'),query=['year_id'],write=True,edit=True)
    if actions:route(path+'/{id}/export','get','Authorized document PDF; company signature labels for vouchers',query=['year_id'])
for method in ['get','delete']:route(base+'/operations/{key}',method,'Inspect receipt / establish durable cancellation',query=['year_id'])
route(base+'/lookups/{source}','get','Paged authorized lookup',query=['year_id','q','page','party_id'])
route(base+'/lookups/{source}/{id}','get','Safe scoped label',query=['year_id'])
route(base+'/barcode','get','Exact authorized barcode match',query=['code'])
route(base+'/reports/{report}','get','Core report',query=['year_id','from','to','page','mode','warehouse_id','item_id','party_id'])
route(base+'/reports/{report}/export','get','Selected-page PDF/CSV; statements include all three statements',query=['year_id','from','to','page','mode','format'])
route(base+'/attachments/{target}/{id}','get','Private file metadata; opt-in legacy expense images',query=['include_expense_images'])
route(base+'/attachments/{target}/{id}','post','Attach validated PDF/image, maximum 2 MiB decoded',dto('name content_base64'),write=True)
route(base+'/files/{id}','get','Authorized private content and SHA-256')
route(base+'/expense-images/{id}','get','Authorized legacy expense image and SHA-256')
doc={'openapi':'3.0.3','info':{'title':'Agah Mobile API','version':'1','description':'Companion API for the online ERP. Service validation is authoritative. See API-CONTRACT.md for scale, scope and uncertain-write semantics.'},'servers':[{'url':'https://idpa.ir/wp-json/agah-mobile/v1','description':'Example configured WordPress root; deployment not asserted.'}],'security':[{'Bearer':[]}],'paths':paths,'components':{'securitySchemes':{'Bearer':{'type':'http','scheme':'bearer'}},'schemas':schemas}}
(root/'mobile/docs/openapi.json').write_text(json.dumps(doc,ensure_ascii=False,indent=2)+'\n')
print('Generated',len(paths),'paths')
