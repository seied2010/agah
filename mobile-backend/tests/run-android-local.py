"""Run Android acceptance checks against the disposable Docker backend.

Usage: python3 mobile-backend/tests/run-android-local.py /path/to/agah-mobile-tools
The software emulator and adb server must already be running.
"""
from integration import cli,ROOT
import base64,os,pathlib,subprocess,sys
tools=pathlib.Path(sys.argv[1]).resolve()
cli('policy-write.php')
fixture=base64.b64encode(cli('financial-fixture.php').encode()).decode()
env=dict(os.environ,JAVA_HOME=str(tools/'jdk-17.0.15+6'),ANDROID_USER_HOME=str(tools/'android-user'),GRADLE_USER_HOME=str(tools/'gradle-cache'))
command=[str(tools/'gradle-8.11.1/bin/gradle'),'--no-daemon']
if env.get('AGAH_GRADLE_PROXY'):
    proxy=env['AGAH_GRADLE_PROXY'];host,port=proxy.rsplit(':',1)
    command += ['-Dhttps.proxyHost='+host,'-Dhttps.proxyPort='+port,'-Dhttp.proxyHost='+host,'-Dhttp.proxyPort='+port]
    if (tools/'cacerts').exists():command += ['-Djavax.net.ssl.trustStore='+str(tools/'cacerts'),'-Djavax.net.ssl.trustStorePassword=changeit']
command += ['-Pandroid.testInstrumentationRunnerArguments.fixtureBase64='+fixture,':app:testDebugUnitTest',':app:connectedDebugAndroidTest','--console=plain']
sys.exit(subprocess.run(command,cwd=ROOT/'mobile',env=env).returncode)
