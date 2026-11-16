"""Exercise the APK's actual Java HTTP/login implementation against the isolated PHP fixture."""
import os,subprocess,urllib.request
from pathlib import Path

def check(root,directory,url):
    java=Path(os.environ.get('WALLEX_JAVA_HOME','C:/Program Files/Android/Android Studio/jbr'))/'bin'
    jar=root/'data/android-json-test.jar'
    if not jar.exists():
        opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
        jar.write_bytes(opener.open('https://repo.maven.apache.org/maven2/org/json/json/20240303/json-20240303.jar',timeout=25).read())
    folder=Path(directory)/'native-api-test';source=folder/'ir/ashkufaraz/wallexalerts';source.mkdir(parents=True)
    api=(root/'android/app/src/main/java/ir/ashkufaraz/wallexalerts/Api.java').read_text(encoding='utf-8')
    (source/'Api.java').write_text(api.replace('https://ashkufaraz.ir/public/',url),encoding='utf-8')
    stub=folder/'android/os';stub.mkdir(parents=True)
    (stub/'Build.java').write_text('package android.os; public class Build {public static String MANUFACTURER="Test",MODEL="A36";}')
    (source/'NativeTest.java').write_text('''package ir.ashkufaraz.wallexalerts;
import org.json.*;
public class NativeTest {
 public static void main(String[] args)throws Exception {
  JSONObject device=Api.login("a@example.com","test-password-123");
  String token=device.getString("token");
  JSONArray alerts=Api.call("?mobile_feed=1&after=0",null,token,null).getJSONArray("alerts");
  if(alerts.length()!=1||!alerts.getJSONObject(0).getJSONObject("payload").getString("strategy_id").equals("alice"))throw new Exception("owner isolation");
  Api.call("?mobile_action=ack",new JSONObject().put("ids",new JSONArray().put(alerts.getJSONObject(0).getInt("id"))),token,null);
  Api.call("?mobile_action=revoke",new JSONObject(),token,null);
  try{Api.call("?mobile_feed=1",null,token,null);throw new Exception("revocation failed");}catch(Api.Failure e){if(e.status!=401)throw e;}
  System.out.println("PASS actual Android Java API: cookie/CSRF login, device registration, scoped alerts, acknowledgement and revocation");
 }
}''',encoding='utf-8')
    executable='.exe' if os.name=='nt' else ''
    subprocess.run([str(java/('javac'+executable)),'-encoding','UTF-8','-cp',str(jar),'-d',str(folder),*[str(p) for p in folder.rglob('*.java')]],check=True,capture_output=True)
    subprocess.run([str(java/('java'+executable)),'-cp',str(folder)+os.pathsep+str(jar),'ir.ashkufaraz.wallexalerts.NativeTest'],check=True)
