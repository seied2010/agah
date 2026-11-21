"""Assemble paired deliverables; callers must complete the acceptance checks first."""
import argparse,hashlib,json,pathlib,re,shutil,zipfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
parser=argparse.ArgumentParser()
parser.add_argument('--apk',type=pathlib.Path,required=True)
parser.add_argument('--output',type=pathlib.Path,default=ROOT/'releases/android-rc1')
args=parser.parse_args();args.apk=args.apk.resolve();out=args.output.resolve()
if not args.apk.is_file():raise SystemExit('Verified acceptance APK is missing')
out.mkdir(parents=True,exist_ok=True)
app=re.search(r'versionName = "([^"]+)"',(ROOT/'mobile/app/build.gradle.kts').read_text()).group(1)
plugin=re.search(r'\* Version: ([^\n]+)',(ROOT/'mobile-backend/agah-mobile-api/agah-mobile-api.php').read_text()).group(1).strip()
core=re.search(r'\* Version: ([^\n]+)',(ROOT/'source/agah-erp/agah-erp.php').read_text()).group(1).strip()
excluded={'.git','.github','.gradle','.kotlin','.local','.idea','build','__pycache__','results'}
def permitted(path,base,source=False):
    rel=path.relative_to(base)
    if any(part in excluded or (source and part=='vendor') for part in rel.parts):return False
    if path.name=='local.properties' or (path.name.startswith('.env') and path.name!='.env.example'):return False
    return path.suffix.lower() not in {'.keystore','.jks','.p12','.pem','.key'}
def add(z,path,name):
    info=zipfile.ZipInfo(name,(1980,1,1,0,0,0));info.compress_type=zipfile.ZIP_DEFLATED
    info.external_attr=(0o100755 if path.name in {'gradlew'} else 0o100644)<<16
    z.writestr(info,path.read_bytes())
def archive(name,folders,source=False):
    dest=out/name
    with zipfile.ZipFile(dest,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=6) as z:
        for base,prefix in folders:
            for path in sorted(base.rglob('*')):
                if path.is_file() and not path.is_symlink() and permitted(path,base,source):add(z,path,prefix+'/'+path.relative_to(base).as_posix())
    with zipfile.ZipFile(dest) as z:
        if z.testzip() is not None:raise SystemExit('ZIP integrity failed: '+name)
archive('agah-erp-'+core+'.zip',[(ROOT/'source/agah-erp','agah-erp')])
archive('agah-mobile-api-'+plugin+'.zip',[(ROOT/'mobile-backend/agah-mobile-api','agah-mobile-api')])
archive('agah-online-android-rc1-source.zip',[(ROOT/'mobile','mobile'),(ROOT/'mobile-backend','mobile-backend'),(ROOT/'source/agah-erp','source/agah-erp')],True)
shutil.copyfile(args.apk,out/('agah-android-'+app+'-acceptance.apk'))
shutil.copyfile(ROOT/'mobile/docs/DELIVERY-RC1-FA.md',out/'راهنمای نصب.md')
files=[p for p in sorted(out.iterdir()) if p.is_file() and p.name!='SHA256SUMS.json']
(out/'SHA256SUMS.json').write_text(json.dumps({p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in files},ensure_ascii=False,indent=2)+'\n')
for path in files:print(path.name,path.stat().st_size)
