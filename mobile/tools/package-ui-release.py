"""Package only the Android presentation release; existing backend packages are unchanged."""
import hashlib,json,pathlib,shutil,zipfile
root=pathlib.Path(__file__).resolve().parents[2]
version='0.4.0-ui-rc1'
out=root/'releases/android-ui-rc1';out.mkdir(parents=True,exist_ok=True)
shutil.copyfile(root/'mobile/app/build/outputs/apk/acceptance/app-acceptance.apk',out/f'agah-android-{version}-acceptance.apk')
excluded={'.git','.gradle','.kotlin','.local','.idea','build','__pycache__'}
with zipfile.ZipFile(out/f'agah-android-{version}-source.zip','w',zipfile.ZIP_DEFLATED) as z:
 for p in sorted((root/'mobile').rglob('*')):
  rel=p.relative_to(root)
  if not p.is_file() or p.is_symlink() or any(x in excluded for x in rel.parts):continue
  if p.name=='local.properties' or p.name.startswith('.env') or p.suffix.lower() in {'.jks','.keystore','.key','.pem','.p12'}:continue
  info=zipfile.ZipInfo(rel.as_posix(),(1980,1,1,0,0,0));info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=(0o100755 if p.name=='gradlew' else 0o100644)<<16
  z.writestr(info,p.read_bytes())
shutil.copyfile(root/'mobile/docs/UI-RELEASE-FA.md',out/'INSTALL-FA.md')
shutil.copyfile(root/'mobile/docs/UI-VERIFICATION.md',out/'VERIFICATION.md')
(out/'SHA256SUMS.json').write_text(json.dumps({p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(out.iterdir()) if p.is_file() and p.name!='SHA256SUMS.json'},ensure_ascii=False,indent=2)+'\n')
for p in out.glob('*.zip'):
 with zipfile.ZipFile(p) as z:assert z.testzip() is None
print('Android UI release packaged:',out)
