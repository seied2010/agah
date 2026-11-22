"""Package only the checked biometric Android build; never rebuild server or desktop outputs."""
from pathlib import Path
import hashlib,json,os,re,shutil,subprocess,zipfile
import xml.etree.ElementTree as ET
root=Path(__file__).resolve().parents[2]
out=root/'releases/android-biometric-rc1'
version=re.search(r'versionName = "([^"]+)"',(root/'mobile/app/build.gradle.kts').read_text()).group(1)
apk=root/'mobile/app/build/outputs/apk/acceptance/app-acceptance.apk'
sdk=Path(os.environ.get('ANDROID_HOME',root.parent/'work/agah-mobile-tools/sdk'))
badging=subprocess.check_output([str(sdk/'build-tools/35.0.0/aapt'),'dump','badging',str(apk)],text=True)
assert f"versionName='{version}-acceptance'" in badging and "versionCode='7'" in badging
assert "sdkVersion:'26'" in badging and "targetSdkVersion:'35'" in badging
assert 'application-debuggable' not in badging
signing=subprocess.check_output([str(sdk/'build-tools/35.0.0/apksigner'),'verify','--verbose','--print-certs',str(apk)],text=True)
assert '92834477963d485a0acc16ba85d9d9f569d56dbf7200fc42b8ccf1387505ceab' in signing
manifest=subprocess.check_output([str(sdk/'build-tools/35.0.0/aapt'),'dump','xmltree',str(apk),'AndroidManifest.xml'],text=True)
for flag in ('allowBackup','usesCleartextTraffic'):
 assert any('android:'+flag+'(' in line and line.rstrip().endswith('0x0') for line in manifest.splitlines())
assert not any('android:debuggable(' in line and not line.rstrip().endswith('0x0') for line in manifest.splitlines())
assert 'android.permission.USE_BIOMETRIC' in manifest
xmls=sorted((root/'mobile/app/build/test-results/testDebugUnitTest').glob('TEST-*.xml'))
assert xmls
count=0
for p in xmls:
 t=ET.parse(p).getroot();assert int(t.attrib.get('failures',0))==0 and int(t.attrib.get('errors',0))==0 and int(t.attrib.get('skipped',0))==0
 count+=int(t.attrib['tests'])
assert count>=51
lint=root/'mobile/app/build/reports/lint-results-debug.xml'
assert lint.exists() and all(i.attrib.get('severity') not in ('Error','Fatal') for i in ET.parse(lint).getroot())
out.mkdir(parents=True,exist_ok=True)
(out/'APK-METADATA.txt').write_text(badging+'\n'+signing+'\n'+manifest)
shutil.copyfile(apk,out/f'agah-android-{version}-acceptance.apk')
shutil.copyfile(root/'mobile/docs/BIOMETRIC-FA.md',out/'INSTALL-FA.md')
excluded={'.git','.gradle','.kotlin','.local','.idea','build','__pycache__','results'}
with zipfile.ZipFile(out/f'agah-android-{version}-source.zip','w',zipfile.ZIP_DEFLATED) as z:
 for p in sorted((root/'mobile').rglob('*')):
  rel=p.relative_to(root)
  if not p.is_file() or p.is_symlink() or any(x in excluded for x in rel.parts):continue
  if p.name=='local.properties' or p.name.startswith('.env') or p.suffix.lower() in {'.jks','.keystore','.p12','.pem','.key','.apk'}:continue
  info=zipfile.ZipInfo(rel.as_posix(),(1980,1,1,0,0,0));info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=(0o100755 if p.name=='gradlew' else 0o100644)<<16;z.writestr(info,p.read_bytes())
 for name in ('AGENTS.md','docs/PLATFORM-POLICY.md','docs/NEXT-PRIORITY.md'):
  z.writestr(name,(root/name).read_bytes())
assert zipfile.ZipFile(out/f'agah-android-{version}-source.zip').testzip() is None
with zipfile.ZipFile(out/'automated-verification.zip','w',zipfile.ZIP_DEFLATED) as z:
 for p in xmls:z.write(p,'junit/'+p.name)
 z.write(lint,'lint-results-debug.xml')
 for p in sorted((root/'mobile/app/build/reports/ui-screenshots').glob('biometric*.png')):z.write(p,'screenshots/'+p.name)
(out/'VERIFICATION-FA.md').write_text(f'''# نتیجهٔ بررسی اثر انگشت\n\n- {count} آزمون خودکار موفق، بدون خطا، شکست یا تست ردشده؛ رمزنگاری با فراهم‌کنندهٔ آزمایشی، درخواست‌های شبکه با MockWebServer و رابط با Robolectric.\n- lintDebug بدون خطای Error/Fatal؛ اخطارها در گزارش XML پیوست‌اند.\n- خروجی assembleAcceptance با R8، نسخهٔ {version}-acceptance، کد ۷، حداقل Android 8 و target 35.\n- امضای APK با گواهی پذیرش قبلی مطابقت دارد؛ manifest بدون debuggable، پشتیبان‌گیری Android و ترافیک cleartext است. حسگر در manifest اختیاری است.\n- حسگر فیزیکی، Android Keystore سخت‌افزاری و نصب روی گوشی کاربر در این محیط آزموده نشده‌اند؛ چک پذیرش در INSTALL-FA.md است.\n- وب، API سرور، دسکتاپ و دامنه‌ها تغییر نکرده‌اند.\n''')
(out/'SHA256SUMS.json').write_text(json.dumps({p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(out.iterdir()) if p.is_file() and p.name!='SHA256SUMS.json'},indent=2)+'\n')
for p in sorted(out.iterdir()):print(p.name,p.stat().st_size)
