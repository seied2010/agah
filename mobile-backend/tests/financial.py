"""Real financial service integration through HTTP, tested against shared web services."""
from integration import call, check, cli, passed
import json, uuid

f=json.loads(cli("financial-fixture.php"))
c,y=f["company"],str(f["year"]["id"])
date=f["year"]["start_date"]
status,login=call("auth/login","POST",{"username":"mobiletest","password":"LocalIntegration-2026-Only","device_id":str(uuid.uuid4()),"device_name":"Financial HTTP tests"})
assert status==200
t=login["data"]["access_token"]
def path(entity, suffix=""):
    return f"companies/{c}/{entity}{suffix}&year_id={y}"
def create(entity,body):
    status,res=call(path(entity),"POST",body,t,{"Idempotency-Key":str(uuid.uuid4())})
    assert status==200, (entity,status,res)
    return res["data"]["id"]
def get(entity,id):
    status,res=call(path(entity,"/"+id),token=t)
    assert status==200,(status,res)
    return res["data"]
def transition(entity,id,action):
    detail=get(entity,id)
    status,res=call(path(entity,f"/{id}/{action}"),"POST",{"reason":"ابطال آزمون مالی"},t,{"Idempotency-Key":str(uuid.uuid4()),"If-Match":detail["etag"]})
    assert status==200,(entity,action,status,res)

party=create("parties",{"name":"طرف حساب مالی "+uuid.uuid4().hex[:6],"kind":"real","is_customer":True,"is_supplier":True,"is_active":True,"credit_limit":"0"})
item=create("items",{"name":"خدمت آزمون","code":uuid.uuid4().hex[:15],"kind":"service","unit_id":f["unit"],"sale_account_id":f["sale_account"],"purchase_account_id":f["purchase_account"],"is_active":True,"sale_price":"100000","purchase_price":"50000","trade_price":"0"})
check("party and catalog writes",bool(item))
invoices={}
for kind,price in [("sale","100000"),("purchase","50000")]:
    id=create("invoices",{"kind":kind,"party_id":party,"invoice_date":date,"due_date":date,"discount_amount":"0","extra_cost":"0","cost_basis":"value","lines":[{"item_id":item,"qty_scaled":"10000","unit_price":price,"discount_amount":"0"}]})
    transition("invoices",id,"issue")
    record=get("invoices",id)["record"]
    check(kind+" invoice issued with exact total",record["status"]=="issued" and record["amount"]==price)
    invoices[kind]=id
for kind,inv,amount in [("receipt",invoices["sale"],"100000"),("payment",invoices["purchase"],"50000")]:
    id=create("treasury",{"kind":kind,"party_id":party,"doc_date":date,"items":[{"method":"cash","treasury_account_id":f["cash"],"amount":amount}],"allocations":[{"invoice_id":inv,"amount":amount}]})
    check(kind+" posted and balanced",get("treasury",id)["record"]["amount"]==amount)
expense=create("expenses",{"category_id":f["category"],"party_id":party,"doc_date":date,"due_date":date,"amount":"12000","description":"هزینه آزمون"})
check("expense creates posted voucher",bool(get("expenses",expense)["record"].get("voucher_id")))
voucher=create("vouchers",{"voucher_date":date,"description":"سند دستی آزمون","lines":[{"account_id":f["manual_debit"],"debit":"10000","credit":"0"},{"account_id":f["manual_credit"],"debit":"0","credit":"10000"}]})
transition("vouchers",voucher,"approve"); transition("vouchers",voucher,"finalize")
check("manual voucher balanced and finalized",get("vouchers",voucher)["record"]["status"]=="final")
for report in ["ledger","parties","statements","sales","purchases","expenses","inventory"]:
    status,res=call(path("reports/"+report),token=t)
    check(report+" report",status==200)
print("FINANCIAL CHECKS",len(passed))
