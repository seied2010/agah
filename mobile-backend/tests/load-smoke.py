"""Bounded local-only concurrency smoke, not a production capacity claim."""
from integration import call,cli
from concurrent.futures import ThreadPoolExecutor
import json,time,uuid,statistics
f=json.loads(cli('financial-fixture.php'))
s,j=call('auth/login','POST',{'username':'mobiletest','password':'LocalIntegration-2026-Only','device_id':str(uuid.uuid4())});assert s==200
t=j['data']['access_token'];prefix=f"companies/{f['company']}/";year=str(f['year']['id'])
paths=['parties','items','invoices','vouchers','stock']
def request(index):
    start=time.monotonic();s,j=call(prefix+paths[index%len(paths)]+'&year_id='+year+'&kind=sale',token=t)
    assert s==200,(index,s,j)
    return time.monotonic()-start
start=time.monotonic()
with ThreadPoolExecutor(max_workers=5) as pool:times=sorted(pool.map(request,range(40)))
print(json.dumps({'environment':'isolated WordPress PHP8.3 MariaDB; loopback only','requests':40,'concurrency':5,'errors':0,'elapsed_seconds':round(time.monotonic()-start,3),'p50_ms':round(statistics.median(times)*1000,1),'p95_ms':round(times[int(len(times)*.95)-1]*1000,1),'max_ms':round(max(times)*1000,1)},ensure_ascii=False))
