"""Deterministic web/update artifacts; Windows runtime comes unchanged from the verified previous bundle."""
from pathlib import Path
import zipfile,json,hashlib,shutil,html
root=Path(__file__).resolve().parents[2]
def sha(path):return hashlib.sha256(path.read_bytes()).hexdigest()
def archive(dest,entries):
 dest.parent.mkdir(parents=True,exist_ok=True)
 with zipfile.ZipFile(dest,'w',zipfile.ZIP_DEFLATED) as z:
  for name,path in sorted(entries):
   assert '..' not in Path(name).parts and not Path(name).is_absolute()
   assert not path.is_symlink()
   info=zipfile.ZipInfo(name,(1980,1,1,0,0,0));info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=0o100644<<16;z.writestr(info,path.read_bytes())
 with zipfile.ZipFile(dest) as z:assert z.testzip() is None
def hashes(folder):
 (folder/'SHA256SUMS.json').write_text(json.dumps({p.name:sha(p) for p in sorted(folder.iterdir()) if p.is_file() and p.name!='SHA256SUMS.json'},indent=2)+'\n')
plugin=root/'source/agah-erp';web=root/'releases/ux-0281';web.mkdir(parents=True,exist_ok=True)
files=[p for p in sorted(plugin.rglob('*')) if p.is_file()]
assert all(p.suffix not in ['.env','.key','.keystore','.pem'] and p.name not in ['wp-config.php','.env','debug.log'] for p in files)
archive(web/'agah-erp-0.28.1-ux1.zip',[('agah-erp/'+p.relative_to(plugin).as_posix(),p) for p in files])
shutil.copyfile(root/'docs/UX-0281-FA.md',web/'INSTALL-FA.md');hashes(web)

previous=root/'releases/desktop-020';seed=previous/'Agah-Local-Windows-0.2.0-prototype.zip'
assert sha(seed)==json.loads((previous/'SHA256SUMS.json').read_text())[seed.name]
stage=Path('/workspace/work/agah-desktop-021-ux1');stage.mkdir(exist_ok=True)
with zipfile.ZipFile(seed) as z:
 assert all('..' not in Path(n).parts and not Path(n).is_absolute() for n in z.namelist());z.extractall(stage)
bundle=stage/'Agah-Local';dest=bundle/'app/wp-content/plugins/agah-erp'
shutil.rmtree(dest);shutil.copytree(plugin,dest)
for name in ['install.ps1','update.ps1']:shutil.copyfile(root/'desktop/launcher'/name,bundle/'launcher'/name)
doc=(root/'docs/UX-0281-FA.md').read_text();shutil.copyfile(root/'docs/UX-0281-FA.md',bundle/'UX-VERIFICATION-FA.md')
notice='<section dir="rtl"><h1>نسخهٔ محلی 0.2.1-ux1 — هستهٔ 0.28.1-ux1</h1><p>اصلاح اسکرول و فوکوس، دسترس‌پذیری، حذف اتمیک فاکتور و تفویض مجوز حذف. این برنامه همچنان محلی و مستقل از وب است.</p><p>نصب قبلی: Stop.cmd، استخراج ZIP به‌روزرسانی در پوشهٔ تازه، سپس Update.cmd. نصب تازه: Install.cmd از بستهٔ کامل. پوشهٔ state را حذف نکنید.</p><p>راهنمای همین نسخه و حدود آزمون‌ها: <a href="UX-VERIFICATION-FA.md">UX-VERIFICATION-FA.md</a>. اجرای واقعی ویندوز و تجهیزات در این محیط آزموده نشده است.</p></section>'
readme=bundle/'README-fa.html';old=readme.read_text();readme.write_text(old.replace('<body>','<body>'+notice,1) if '<body>' in old else notice+old)
(bundle/'TEST-RESULTS.txt').write_text('Desktop 0.2.1-ux1 / ERP 0.28.1-ux1. See UX-VERIFICATION-FA.md for executed Linux/offline HTTP, browser and financial checks. Windows execution is not claimed.\n')
manifest={p.relative_to(bundle).as_posix():sha(p) for p in sorted(bundle.rglob('*')) if p.is_file() and p.name!='MANIFEST-SHA256.json'}
assert not any(n.startswith('state/') or n.endswith('wp-config.php') for n in manifest)
oldhashes=json.loads((bundle/'MANIFEST-SHA256.json').read_text())
assert all(manifest[n]==h for n,h in oldhashes.items() if n.startswith('runtime/'))
(bundle/'MANIFEST-SHA256.json').write_text(json.dumps(manifest,indent=2)+'\n')
out=root/'releases/desktop-021-ux1';out.mkdir(parents=True,exist_ok=True)
archive(out/'Agah-Local-Windows-0.2.1-ux1-prototype.zip',[('Agah-Local/'+p.relative_to(bundle).as_posix(),p) for p in sorted(bundle.rglob('*')) if p.is_file()])
names=['Update.cmd','README-fa.html','UX-VERIFICATION-FA.md','launcher/update.ps1','launcher/install.ps1']+[n for n in manifest if n.startswith('app/wp-content/plugins/agah-erp/')]
temp=stage/'update-manifest.json';temp.write_text(json.dumps({n:manifest[n] for n in names},indent=2)+'\n')
archive(out/'Agah-Local-Update-0.2.1-ux1.zip',[('Agah-Local-Update/'+n,bundle/n) for n in names]+[('Agah-Local-Update/MANIFEST-SHA256.json',temp)])
shutil.copyfile(root/'docs/UX-0281-FA.md',out/'INSTALL-FA.md');hashes(out)
for folder in [web,out]:
 for p in sorted(folder.iterdir()):print(p.name,p.stat().st_size)
