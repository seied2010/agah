"""Reproducible desktop checkpoint, reusing verified Windows runtime without personal state."""
from pathlib import Path
import hashlib,json,shutil,zipfile
root=Path(__file__).resolve().parents[2]
seed=root/'releases/Agah-Local-Windows-0.1.0-prototype.zip'
assert hashlib.sha256(seed.read_bytes()).hexdigest()=='42c5070fff813657f3cb803fb6dfaeaacaf76b2d8cccf70492d8e5f80c8fe08d'
stage=Path('/workspace/work/agah-desktop-020');stage.mkdir(exist_ok=True)
with zipfile.ZipFile(seed) as z:
 for n in z.namelist():
  assert '..' not in Path(n).parts and not Path(n).is_absolute()
 z.extractall(stage)
folder=next(stage.rglob('Install.cmd')).parent
plugin=folder/'app/wp-content/plugins/agah-erp'
if plugin.exists():shutil.rmtree(plugin)
shutil.copytree(root/'source/agah-erp',plugin)
for name in ['install.ps1','update.ps1']:shutil.copyfile(root/'desktop/launcher'/name,folder/'launcher'/name)
shutil.copyfile(root/'desktop/Update.cmd',folder/'Update.cmd')
readme=folder/'README-fa.html'
html=readme.read_text();notice='''<section dir="rtl"><h1>به‌روزرسانی دسکتاپ 0.2.0 — هستهٔ 0.28.0-alpha1</h1><p>ورود سریع بارکد، قیمت پیش‌فرض، تسویهٔ ترکیبی و چهار قالب چاپ وب به نسخهٔ محلی منتقل شده‌اند. همچنان مستقل و آفلاین است.</p><p>نصب جدید: Install.cmd. نصب قبلی: ابتدا Stop.cmd و انتظار برای توقف کامل، سپس Update.cmd از ZIP تازهٔ استخراج‌شده. پوشهٔ state را حذف نکنید. به‌روزرسان، پوشهٔ هستهٔ برنامه را جایگزین می‌کند؛ داده، کلید و پیوست را جایگزین نمی‌کند. برای شروع از میان‌بر قبلی استفاده کنید.</p><p>این بسته همچنان آزمایشی است؛ اجرای نصب/به‌روزرسانی روی ویندوز واقعی در این محیط انجام نشده است. سقف فعلی ۶۰ ردیف متمایز و چاپ با پنجرهٔ مرورگر است. امکانات باقی‌ماندهٔ فاز هنوز اضافه نشده‌اند.</p></section>'''
if 'به‌روزرسانی دسکتاپ 0.2.0' not in html:html=html.replace('<body>','<body>'+notice,1) if '<body>' in html else notice+html
readme.write_text(html)
(folder/'TEST-RESULTS.txt').write_text('Desktop 0.2.0 / ERP 0.28.0-alpha1. See DESKTOP-VERIFICATION.md in the release for actual executed checks. Windows execution is not claimed.\n')
manifest={}
for p in sorted(folder.rglob('*')):
 if p.is_file() and p.name!='MANIFEST-SHA256.json':manifest[p.relative_to(folder).as_posix()]=hashlib.sha256(p.read_bytes()).hexdigest()
assert not any(n.startswith('state/') or n.endswith('wp-config.php') for n in manifest)
(folder/'MANIFEST-SHA256.json').write_text(json.dumps(manifest,indent=2)+'\n')
out=root/'releases/desktop-020';out.mkdir(parents=True,exist_ok=True)
archive=out/'Agah-Local-Windows-0.2.0-prototype.zip'
with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED) as z:
 for p in sorted(folder.rglob('*')):
  if p.is_file():
   info=zipfile.ZipInfo('Agah-Local/'+p.relative_to(folder).as_posix(),(1980,1,1,0,0,0));info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=0o100644<<16;z.writestr(info,p.read_bytes())
with zipfile.ZipFile(archive) as z:assert z.testzip() is None
update=out/'Agah-Local-Update-0.2.0.zip'
with zipfile.ZipFile(update,'w',zipfile.ZIP_DEFLATED) as z:
 names=['Update.cmd','README-fa.html','launcher/update.ps1','launcher/install.ps1']+[n for n in manifest if n.startswith('app/wp-content/plugins/agah-erp/')]
 for name in names:
  z.write(folder/name,'Agah-Local-Update/'+name)
 z.writestr('Agah-Local-Update/MANIFEST-SHA256.json',json.dumps({n:manifest[n] for n in names},indent=2))
shutil.copyfile(root/'docs/DESKTOP-020-FA.md',out/'DESKTOP-VERIFICATION.md')
(out/'SHA256SUMS.json').write_text(json.dumps({p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(out.iterdir()) if p.is_file() and p.name!='SHA256SUMS.json'},indent=2)+'\n')
print(folder);print(archive,archive.stat().st_size)
