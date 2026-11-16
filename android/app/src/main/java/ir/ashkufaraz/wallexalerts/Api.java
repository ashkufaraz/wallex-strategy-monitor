package ir.ashkufaraz.wallexalerts;
import org.json.*;
import java.net.*;
import java.io.*;

final class Api {
 static final String SITE="https://ashkufaraz.ir/public/";
 static class Failure extends Exception {final int status;Failure(int code,String msg){super(msg);status=code;}}
 static JSONObject call(String query,JSONObject body,String token,CookieManager cookies)throws Exception {
  URL url=new URL(SITE+"api.php"+query);HttpURLConnection con=(HttpURLConnection)url.openConnection();
  con.setConnectTimeout(10000);con.setReadTimeout(15000);con.setInstanceFollowRedirects(false);con.setRequestProperty("Accept","application/json");
  if(token!=null&&!token.isEmpty())con.setRequestProperty("Authorization","Bearer "+token);
  if(cookies!=null)for(java.util.Map.Entry<String,java.util.List<String>> entry:cookies.get(url.toURI(),java.util.Collections.emptyMap()).entrySet())con.setRequestProperty(entry.getKey(),String.join("; ",entry.getValue()));
  try{
   if(body!=null){con.setRequestMethod("POST");con.setDoOutput(true);con.setRequestProperty("Content-Type","application/json; charset=utf-8");if(body.has("_csrf")){con.setRequestProperty("X-CSRF-Token",body.getString("_csrf"));body.remove("_csrf");}try(OutputStream out=con.getOutputStream()){out.write(body.toString().getBytes("UTF-8"));}}
   int code=con.getResponseCode();if(cookies!=null)cookies.put(url.toURI(),con.getHeaderFields());
   InputStream stream=code>=400?con.getErrorStream():con.getInputStream();ByteArrayOutputStream out=new ByteArrayOutputStream();
   if(stream!=null)try(InputStream in=stream){byte[] buf=new byte[4096];int n;while((n=in.read(buf))!=-1){out.write(buf,0,n);if(out.size()>2000000)throw new IOException("پاسخ سرور بیش از حد بزرگ است.");}}
   JSONObject data;try{data=new JSONObject(out.toString("UTF-8"));}catch(JSONException e){throw new Failure(code,"پاسخ نامعتبر از سرور؛ دوباره تلاش کنید.");}
   if(code!=200)throw new Failure(code,data.optString("error","خطای ارتباط با سرور: "+code));return data;
  }finally{con.disconnect();}
 }
 static JSONObject login(String email,String password)throws Exception {
  CookieManager cookies=new CookieManager(null,CookiePolicy.ACCEPT_ORIGINAL_SERVER);
  JSONObject initial=call("",null,null,cookies);
  JSONObject logged=call("",new JSONObject().put("action","login").put("email",email).put("password",password).put("_csrf",initial.getString("csrf")),null,cookies);
  JSONObject device=call("",new JSONObject().put("action","mobile_register").put("name",android.os.Build.MANUFACTURER+" "+android.os.Build.MODEL).put("_csrf",logged.getString("csrf")),null,cookies);
  // The web session is not persisted on the phone; only the scoped device credential is retained.
  try{call("",new JSONObject().put("action","logout").put("_csrf",logged.getString("csrf")),null,cookies);}catch(Exception ignored){}
  return device;
 }
}
