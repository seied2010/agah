"""Local HTTP integration checks. Credentials here belong only to the disposable fixture."""
import concurrent.futures
import json
import pathlib
import subprocess
import urllib.error
import urllib.request
import uuid

ROOT = pathlib.Path(__file__).resolve().parents[2]
BASE = "http://127.0.0.1:18085/?rest_route=/agah-mobile/v1/"
passed = []

def call(path, method="GET", payload=None, token=None, headers=None):
    req = urllib.request.Request(BASE + path, method=method,
        data=json.dumps(payload).encode() if payload is not None else None,
        headers={"Content-Type": "application/json", **({"Authorization": "Bearer " + token} if token else {}), **(headers or {})})
    try:
        with urllib.request.urlopen(req, timeout=30) as response:
            return response.status, json.load(response)
    except urllib.error.HTTPError as error:
        return error.code, json.load(error)

def check(name, condition):
    assert condition, name
    passed.append(name)
    print("PASS", name, flush=True)

def cli(file, *args):
    result = subprocess.run(["docker", "compose", "-f", "mobile-backend/tests/compose.yml", "--env-file", "mobile-backend/tests/.env", "run", "--rm", "cli", "wp", "eval-file", "/tests/" + file, *args], cwd=ROOT, text=True, capture_output=True, check=True)
    return result.stdout.strip()

def main():
    fixture = json.loads(cli("seed.php"))
    cid, year, foreign = fixture["company"], str(fixture["year"]), fixture["foreign"]
    root = f"companies/{cid}"
    check("anonymous denied", call("companies")[0] == 401)
    status, login = call("auth/login", "POST", {"username":"mobiletest", "password":"LocalIntegration-2026-Only", "device_id":str(uuid.uuid4()), "device_name":"Integration tests"})
    check("real password login", status == 200)
    token = login["data"]["access_token"]
    refresh = login["data"]["refresh_token"]
    check("company isolation", call(f"companies/{foreign}/context", token=token)[0] == 404)
    status, context = call(root + "/context", token=token)
    check("company context and real roles", status == 200 and "party.create" in context["data"]["capabilities"])
    check("year mandatory", call(root + "/invoices", token=token)[0] == 422)
    check("foreign year denied", call(root + "/invoices&year_id=9999999", token=token)[0] == 404)
    path = root + "/parties&year_id=" + year
    body = {"name":"شخص آزمون " + uuid.uuid4().hex[:8],"kind":"real","is_customer":True,"is_active":True,"credit_limit":"0"}
    key = str(uuid.uuid4()); headers = {"Idempotency-Key":key}
    if context["data"]["mode"] == "read":
        check("read-only rejects write", call(path,"POST",body,token,headers)[0] == 403)
    cli("policy-write.php")
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        results = list(pool.map(lambda _:call(path,"POST",body,token,headers), range(2)))
    check("atomic duplicate suppression", all(r[0] == 200 for r in results) and results[0][1]["data"]["id"] == results[1][1]["data"]["id"])
    ident = results[0][1]["data"]["id"]
    check("idempotency body mismatch", call(path,"POST",{**body,"name":"نام متفاوت"},token,headers)[0] == 409)
    status, receipt = call(root + "/operations/" + key, token=token)
    check("uncertain outcome lookup", status == 200 and receipt["data"]["state"] == "committed" and receipt["data"]["result"]["id"] == ident)
    detail_path=root + "/parties/" + ident + "&year_id=" + year
    status, detail=call(detail_path,token=token)
    check("safe party DTO",status == 200 and "national_id_enc" not in detail["data"]["record"] and "national_id_idx" not in detail["data"]["record"])
    check("missing conflict token denied",call(detail_path,"PUT",body,token,{"Idempotency-Key":str(uuid.uuid4())})[0] == 409)
    edit_headers={"Idempotency-Key":str(uuid.uuid4()),"If-Match":detail["data"]["etag"]}
    check("versioned edit",call(detail_path,"PUT",{**body,"name":"شخص اصلاح‌شده"},token,edit_headers)[0] == 200)
    check("stale edit denied",call(detail_path,"PUT",body,token,{**edit_headers,"Idempotency-Key":str(uuid.uuid4())})[0] == 409)
    check("float money denied",call(path,"POST",{**body,"credit_limit":1.5},token,{"Idempotency-Key":str(uuid.uuid4())})[0] == 422)
    check("invalid money denied",call(path,"POST",{**body,"credit_limit":"99999999999999999"},token,{"Idempotency-Key":str(uuid.uuid4())})[0] == 422)
    status, rotated=call("auth/refresh","POST",{"refresh_token":refresh})
    check("refresh rotation",status == 200)
    check("refresh replay denied",call("auth/refresh","POST",{"refresh_token":refresh})[0] == 401)
    check("old access revoked after refresh",call("companies",token=token)[0] == 401)
    new_token=rotated["data"]["access_token"]
    check("new access works",call("companies",token=new_token)[0] == 200)
    check("logout",call("auth/logout","POST",{},new_token)[0] == 200)
    check("logged-out access denied",call("companies",token=new_token)[0] == 401)
    out=pathlib.Path(__file__).parent/"results"
    out.mkdir(exist_ok=True)
    (out/"integration.json").write_text(json.dumps({"passed":passed,"count":len(passed)},ensure_ascii=False,indent=2))

if __name__ == "__main__": main()
