package ir.ashkufaraz.wallexalerts;
import android.app.*;
import android.content.*;
import android.os.*;
import android.provider.Settings;
import android.net.Uri;
import android.graphics.Color;
import android.view.*;
import android.widget.*;
import org.json.*;
import java.text.DateFormat;
import java.util.Date;

public final class MainActivity extends Activity {
 LinearLayout layout;TextView status,history;EditText email,password;Button login,toggle,logout;Handler handler=new Handler(Looper.getMainLooper());boolean busy;
 final Runnable update=new Runnable(){public void run(){render();handler.postDelayed(this,2000);}};
 TextView text(String value,int size){TextView t=new TextView(this);t.setText(value);t.setTextSize(size);t.setTextColor(Color.rgb(230,237,250));t.setPadding(0,12,0,12);layout.addView(t);return t;}
 Button button(String label,Runnable run){Button b=new Button(this);b.setText(label);layout.addView(b,new LinearLayout.LayoutParams(-1,-2));b.setOnClickListener(v->run.run());return b;}
 @Override public void onCreate(Bundle saved){super.onCreate(saved);getWindow().setStatusBarColor(Color.rgb(13,23,40));getWindow().setNavigationBarColor(Color.rgb(13,23,40));
  ScrollView scroll=new ScrollView(this);layout=new LinearLayout(this);layout.setOrientation(LinearLayout.VERTICAL);layout.setPadding(28,24,28,32);layout.setLayoutDirection(View.LAYOUT_DIRECTION_RTL);layout.setBackgroundColor(Color.rgb(13,23,40));scroll.addView(layout);setContentView(scroll);AlertService.channels(this);
  scroll.setOnApplyWindowInsetsListener((view,insets)->{if(Build.VERSION.SDK_INT>=30){android.graphics.Insets bars=insets.getInsets(WindowInsets.Type.systemBars());view.setPadding(bars.left,bars.top,bars.right,bars.bottom);}else view.setPadding(insets.getSystemWindowInsetLeft(),insets.getSystemWindowInsetTop(),insets.getSystemWindowInsetRight(),insets.getSystemWindowInsetBottom());return insets;});
  text("هشدارهای والکس و نوبیتکس",26);text("فقط دریافت هشدارهای حساب شما؛ برای تحلیل و مدیریت، سایت را باز کنید.",15);
  status=text("",15);email=new EditText(this);email.setHint("ایمیل حساب سایت");email.setInputType(android.text.InputType.TYPE_CLASS_TEXT|android.text.InputType.TYPE_TEXT_VARIATION_EMAIL_ADDRESS);email.setTextColor(Color.WHITE);email.setHintTextColor(Color.LTGRAY);email.setLayoutDirection(View.LAYOUT_DIRECTION_LTR);layout.addView(email);
  password=new EditText(this);password.setHint("رمز عبور");password.setInputType(129);password.setTextColor(Color.WHITE);password.setHintTextColor(Color.LTGRAY);layout.addView(password);
  login=button("ورود و اتصال گوشی",this::login);
  toggle=button("فعال‌سازی دریافت",this::toggle);
  button("اجازه و تنظیمات اعلان",()->{if(Build.VERSION.SDK_INT>=33&&checkSelfPermission(android.Manifest.permission.POST_NOTIFICATIONS)!=android.content.pm.PackageManager.PERMISSION_GRANTED)requestPermissions(new String[]{android.Manifest.permission.POST_NOTIFICATIONS},5);else startActivity(new Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS).putExtra(Settings.EXTRA_APP_PACKAGE,getPackageName()));});
  button("آزمایش اعلان روی گوشی",()->{try{AlertService.show(this,99,"آزمایش هشدار والکس و نوبیتکس","اعلان گوشی فعال است؛ این یک سیگنال معاملاتی نیست.");}catch(Exception e){status.setText(e.getMessage());Toast.makeText(this,e.getMessage(),Toast.LENGTH_LONG).show();}});
  button("رفع محدودیت باتری",()->{try{startActivity(new Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS,Uri.parse("package:"+getPackageName())));}catch(Exception e){startActivity(new Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS,Uri.parse("package:"+getPackageName())));}});
  button("بازکردن سایت",()->startActivity(new Intent(Intent.ACTION_VIEW,Uri.parse(Api.SITE))));
  logout=button("قطع اتصال و خروج",this::logout);
  text("برای دریافت با صفحه خاموش، سرویس باید فعال و اینترنت متصل باشد. در تنظیمات سامسونگ، باتری این اپ را روی «بدون محدودیت / Unrestricted» بگذارید و آن را از Sleeping apps خارج کنید. توقف اجباری، دریافت را قطع می‌کند. این نسخه سرویس دائمی دارد و مصرف باتری آن از Push بیشتر است.",14);
  text("آخرین هشدارها",20);history=text("",15);
 }
 boolean connected(){try{return !Store.token(this).isEmpty();}catch(Exception e){return false;}}
 void render(){
  boolean connected=connected();email.setVisibility(connected?View.GONE:View.VISIBLE);password.setVisibility(connected?View.GONE:View.VISIBLE);login.setVisibility(connected?View.GONE:View.VISIBLE);toggle.setVisibility(connected?View.VISIBLE:View.GONE);logout.setVisibility(connected?View.VISIBLE:View.GONE);toggle.setText(Store.prefs(this).getBoolean("enabled",false)?"توقف دریافت هشدار":"فعال‌سازی دریافت هشدار");
  if(!busy){String error=Store.prefs(this).getString("error","");long last=Store.prefs(this).getLong("last_check",0);status.setText((connected?"حساب: "+Store.prefs(this).getString("email",""):"با حساب فعلی سایت وارد شوید؛ ثبت‌نام در سایت انجام می‌شود.")+"\n"+(Store.prefs(this).getBoolean("enabled",false)?"دریافت فعال است":"دریافت متوقف است")+(last>0?"\nآخرین اتصال: "+DateFormat.getDateTimeInstance().format(new Date(last)):"")+(error.isEmpty()?"":"\nخطا: "+error));}
  try{JSONArray list=new JSONArray(Store.prefs(this).getString("history","[]"));StringBuilder s=new StringBuilder();for(int i=list.length()-1;i>=0;i--){JSONObject alert=list.getJSONObject(i),p=alert.getJSONObject("payload");s.append(AlertService.title(p)).append("\n").append(AlertService.body(p)).append("\n").append(DateFormat.getDateTimeInstance().format(new Date(alert.getLong("created")*1000))).append("\n────────────\n");}history.setText(s.length()>0?s.toString():"هنوز هشداری برای نمایش وجود ندارد.");}catch(Exception ignored){}
 }
 void login(){if(busy)return;String address=email.getText().toString().trim(),secret=password.getText().toString();if(address.isEmpty()||secret.isEmpty()){Toast.makeText(this,"ایمیل و رمز را وارد کنید.",Toast.LENGTH_LONG).show();return;}busy=true;login.setEnabled(false);status.setText("در حال اتصال امن…");
  new Thread(()->{try{JSONObject device=Api.login(address,secret);String token=device.getString("token");JSONObject feed=Api.call("?mobile_feed=1",null,token,null);Store.clear(this);Store.saveToken(this,token);Store.prefs(this).edit().putString("email",address).putLong("cursor",feed.getLong("cursor")).putString("history",feed.getJSONArray("alerts").toString()).commit();runOnUiThread(()->{password.setText("");Toast.makeText(this,"متصل شد؛ اجازه اعلان را بدهید و دریافت را فعال کنید.",Toast.LENGTH_LONG).show();});}catch(Exception e){Store.prefs(this).edit().putString("error",e.getMessage()).apply();}finally{runOnUiThread(()->{busy=false;login.setEnabled(true);render();});}}).start();
 }
 void toggle(){if(Store.prefs(this).getBoolean("enabled",false)){Store.prefs(this).edit().putBoolean("enabled",false).apply();stopService(new Intent(this,AlertService.class));}else{if(!AlertService.allowed(this)){Toast.makeText(this,"ابتدا اجازه اعلان را فعال کنید.",Toast.LENGTH_LONG).show();if(Build.VERSION.SDK_INT>=33)requestPermissions(new String[]{android.Manifest.permission.POST_NOTIFICATIONS},5);return;}Store.prefs(this).edit().putBoolean("enabled",true).putString("error","").apply();startForegroundService(new Intent(this,AlertService.class));}render();}
 void logout(){
  if(busy)return;
  new AlertDialog.Builder(this).setMessage("اتصال گوشی به حساب قطع شود؟").setPositiveButton("قطع اتصال",(d,w)->{
   busy=true;new Thread(()->{try{
    try{Api.call("?mobile_action=revoke",new JSONObject(),Store.token(this),null);}catch(Api.Failure failure){if(failure.status!=401)throw failure;}
    Store.prefs(this).edit().putBoolean("enabled",false).commit();stopService(new Intent(this,AlertService.class));Store.clear(this);
   }catch(Exception e){Store.prefs(this).edit().putString("error","خروج انجام نشد؛ اینترنت را بررسی کنید. "+e.getMessage()).apply();}
   finally{runOnUiThread(()->{busy=false;render();});}}).start();
  }).setNegativeButton("انصراف",null).show();
 }
 @Override protected void onResume(){super.onResume();handler.post(update);}
 @Override protected void onPause(){handler.removeCallbacks(update);super.onPause();}
}
