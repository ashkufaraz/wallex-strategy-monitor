"""Build and sign the sideload APK; retain its signing identity outside the workspace."""
import hashlib,json,os,secrets,subprocess
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
SDK=Path(os.environ.get('ANDROID_HOME',str(Path.home()/'AppData/Local/Android/Sdk')))
JAVA=Path(os.environ.get('WALLEX_JAVA_HOME','C:/Program Files/Android/Android Studio/jbr'))
gradle=next((Path.home()/'.gradle/wrapper/dists/gradle-8.10.2-bin').rglob('gradle.bat'))
private=Path.home()/'.config/wallex-strategy-monitor';private.mkdir(parents=True,exist_ok=True)
identity=private/'android-signing.json';keystore=private/'android-release.jks'
if identity.exists():password=json.loads(identity.read_text())['password']
else:
 password=secrets.token_urlsafe(32);identity.write_text(json.dumps(dict(password=password)))
env={**os.environ,'JAVA_HOME':str(JAVA),'ANDROID_HOME':str(SDK),'WALLEX_SIGNING_PASSWORD':password}
def run(args,cwd=None):
 result=subprocess.run([str(a) for a in args],cwd=cwd,env=env,capture_output=True,text=True,encoding='utf-8',errors='replace')
 if result.returncode:
  print(result.stdout[-5000:]);print(result.stderr[-2000:]);raise SystemExit(result.returncode)
 return result.stdout
if not keystore.exists():
 run([JAVA/'bin/keytool.exe','-genkeypair','-keystore',keystore,'-alias','wallex','-keyalg','RSA','-keysize','3072','-validity','10000','-storepass:env','WALLEX_SIGNING_PASSWORD','-keypass:env','WALLEX_SIGNING_PASSWORD','-dname','CN=Wallex Alerts, O=Ashkufaraz, C=IR'])
run([gradle,'--offline','--no-daemon',':app:assembleRelease',':app:lintRelease'],ROOT/'android')
tools=SDK/'build-tools/35.0.0';out=ROOT/'public/downloads';out.mkdir(exist_ok=True)
unsigned=ROOT/'android/app/build/outputs/apk/release/app-release-unsigned.apk';aligned=ROOT/'android/app/build/outputs/apk/release/aligned.apk'
run([tools/'zipalign.exe','-f','-p','4',unsigned,aligned])
apk=out/'wallex-alerts-1.0.0.apk'
run([tools/'apksigner.bat','sign','--ks',keystore,'--ks-key-alias','wallex','--ks-pass','env:WALLEX_SIGNING_PASSWORD','--key-pass','env:WALLEX_SIGNING_PASSWORD','--out',apk,aligned])
verification=run([tools/'apksigner.bat','verify','--verbose','--print-certs',apk])
digest=hashlib.sha256(apk.read_bytes()).hexdigest();(out/'wallex-alerts-1.0.0.apk.sha256').write_text(digest+'  '+apk.name+'\n')
idsig=Path(str(apk)+'.idsig')
if idsig.exists():idsig.unlink()
print('Signed APK:',apk)
print('SHA256:',digest)
print(verification)
