"""Package the scoped web checkpoint, excluding local test data and credentials."""
from pathlib import Path
import zipfile,hashlib,json,shutil
root=Path(__file__).resolve().parents[2];out=root/'releases/business-alpha1';out.mkdir(parents=True,exist_ok=True)
base=root/'source/agah-erp';dest=out/'agah-erp-0.28.0-alpha1.zip'
with zipfile.ZipFile(dest,'w',zipfile.ZIP_DEFLATED) as z:
 for p in sorted(base.rglob('*')):
  if not p.is_file() or p.is_symlink():continue
  assert p.suffix not in ['.key','.pem','.keystore','.jks']
  assert p.name!='.env'
  info=zipfile.ZipInfo('agah-erp/'+p.relative_to(base).as_posix(),(1980,1,1,0,0,0));info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=0o100644<<16;z.writestr(info,p.read_bytes())
with zipfile.ZipFile(dest) as z:assert z.testzip() is None and 'agah-erp/agah-erp.php' in z.namelist()
shutil.copyfile(root/'docs/BUSINESS-ALPHA1-FA.md',out/'INSTALL-FA.md')
(out/'SHA256SUMS.json').write_text(json.dumps({p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(out.iterdir()) if p.is_file() and p.name!='SHA256SUMS.json'},indent=2)+'\n')
print('Packaged',dest.stat().st_size,'bytes')
