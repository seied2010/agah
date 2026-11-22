"""Package matched online client/API; desktop is a separately published offline distribution."""
from pathlib import Path
import zipfile,hashlib,json,shutil,re
root=Path(__file__).resolve().parents[2];out=root/'releases/android-business-rc1';out.mkdir(parents=True,exist_ok=True)
app=re.search(r'versionName = "([^"]+)"',(root/'mobile/app/build.gradle.kts').read_text()).group(1)
api=re.search(r'\* Version: ([^\n]+)',(root/'mobile-backend/agah-mobile-api/agah-mobile-api.php').read_text()).group(1).strip()
excluded={'.git','.gradle','.kotlin','.local','.idea','build','__pycache__','results'}
def archive(dest,folders,source=False):
 with zipfile.ZipFile(out/dest,'w',zipfile.ZIP_DEFLATED) as z:
  for base,prefix in folders:
   for p in sorted(base.rglob('*')):
    rel=p.relative_to(base)
    if not p.is_file() or p.is_symlink() or any(x in excluded or source and x=='vendor' for x in rel.parts):continue
    if p.name=='local.properties' or p.name.startswith('.env') and p.name!='.env.example' or p.suffix.lower() in {'.jks','.keystore','.p12','.pem','.key'}:continue
    info=zipfile.ZipInfo(prefix+'/'+rel.as_posix(),(1980,1,1,0,0,0));info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=(0o100755 if p.name=='gradlew' else 0o100644)<<16;z.writestr(info,p.read_bytes())
 with zipfile.ZipFile(out/dest) as z:assert z.testzip() is None
archive('agah-mobile-api-'+api+'.zip',[(root/'mobile-backend/agah-mobile-api','agah-mobile-api')])
archive('agah-mobile-business-source.zip',[(root/'mobile','mobile'),(root/'mobile-backend','mobile-backend'),(root/'source/agah-erp','source/agah-erp'),(root/'desktop','desktop'),(root/'docs','docs')],True)
with zipfile.ZipFile(out/'agah-mobile-business-source.zip','a',zipfile.ZIP_DEFLATED) as z:z.write(root/'AGENTS.md','AGENTS.md')
shutil.copyfile(root/'mobile/app/build/outputs/apk/acceptance/app-acceptance.apk',out/('agah-android-'+app+'-acceptance.apk'))
shutil.copyfile(root/'docs/CROSS-PLATFORM-028-FA.md',out/'INSTALL-FA.md')
shutil.copyfile(root/'docs/CROSS-PLATFORM-VERIFICATION.md',out/'VERIFICATION-FA.md')
(out/'SHA256SUMS.json').write_text(json.dumps({p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(out.iterdir()) if p.is_file() and p.name!='SHA256SUMS.json'},indent=2)+'\n')
for p in out.iterdir():print(p.name,p.stat().st_size)
