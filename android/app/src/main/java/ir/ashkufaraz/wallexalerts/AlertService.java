package ir.ashkufaraz.wallexalerts;
import android.app.*;
import android.content.*;
import android.os.*;
import org.json.*;
import java.util.concurrent.*;

public final class AlertService extends Service {
 static final String ALERTS="trade-alerts-v1",STATUS="monitor-status-v1";
 static final int SERVICE_ID=1;
 ScheduledExecutorService executor;PowerManager.WakeLock wake;long renewed;
 static void channels(Context c){
  NotificationManager nm=c.getSystemService(NotificationManager.class);
  NotificationChannel alerts=new NotificationChannel(ALERTS,"هشدار معاملات",NotificationManager.IMPORTANCE_HIGH);alerts.setDescription("هشدارهای استراتژی‌های حساب شما");alerts.enableVibration(true);nm.createNotificationChannel(alerts);
  NotificationChannel status=new NotificationChannel(STATUS,"وضعیت دریافت هشدار",NotificationManager.IMPORTANCE_LOW);status.setSound(null,null);nm.createNotificationChannel(status);
 }
 static PendingIntent open(Context c){return PendingIntent.getActivity(c,0,new Intent(c,MainActivity.class),PendingIntent.FLAG_IMMUTABLE|PendingIntent.FLAG_UPDATE_CURRENT);}
 static boolean allowed(Context c){NotificationManager nm=c.getSystemService(NotificationManager.class);NotificationChannel ch=nm.getNotificationChannel(ALERTS);return nm.areNotificationsEnabled()&&ch!=null&&ch.getImportance()!=NotificationManager.IMPORTANCE_NONE;}
 static String exchange(JSONObject p){return p.optString("exchange","wallex").equals("nobitex")?"نوبیتکس":"والکس";}
 static String title(JSONObject p){String type=p.optString("type");return (type.equals("notification_test")?"آزمون اعلان":type.equals("scanner_pattern")?"تأیید الگوی نموداری":type.equals("entry_conditions_only")?"فقط شروط ورود تأیید شد":type.equals("cancelled")?"استراتژی لغو شد":"هشدار معاملاتی")+" · "+exchange(p)+" · "+p.optString("symbol");}
 static String body(JSONObject p){if(p.optString("type").equals("notification_test"))return exchange(p)+" · آزمون اعلان؛ سیگنال معاملاتی نیست";if(p.optString("type").equals("scanner_pattern"))return p.optString("message",exchange(p)+" · "+p.optString("name"));return exchange(p)+" · "+p.optString("name")+"\n"+p.optString("action")+" · قیمت: "+p.optString("price")+(p.optLong("event_time")>0?"\nزمان هشدار: "+java.text.DateFormat.getDateTimeInstance().format(new java.util.Date(p.optLong("event_time")*1000)):"")+(p.optString("type").equals("entry_conditions_only")?"\nاندیکاتورها هنوز تأیید نشده‌اند؛ تأیید نهایی ورود نیست.":"")+"\nجزئیات را در سایت بررسی کنید.";}
 static void show(Context c,int id,String title,String body){
  channels(c);if(!allowed(c))throw new IllegalStateException("اجازه اعلان یا کانال هشدار غیرفعال است.");
  Notification notification=new Notification.Builder(c,ALERTS).setSmallIcon(ir.ashkufaraz.wallexalerts.R.drawable.ic_alert).setContentTitle(title).setContentText(body).setStyle(new Notification.BigTextStyle().bigText(body)).setContentIntent(open(c)).setAutoCancel(true).setCategory(Notification.CATEGORY_MESSAGE).setVisibility(Notification.VISIBILITY_PRIVATE).build();
  c.getSystemService(NotificationManager.class).notify(id,notification);
 }
 Notification status(String message){
  Intent stop=new Intent(this,AlertService.class).setAction("STOP");PendingIntent action=PendingIntent.getService(this,1,stop,PendingIntent.FLAG_IMMUTABLE|PendingIntent.FLAG_UPDATE_CURRENT);
  return new Notification.Builder(this,STATUS).setSmallIcon(R.drawable.ic_alert).setContentTitle("دریافت هشدارهای والکس").setContentText(message).setContentIntent(open(this)).setOnlyAlertOnce(true).setOngoing(true).addAction(new Notification.Action.Builder(null,"توقف",action).build()).build();
 }
 @Override public int onStartCommand(Intent intent,int flags,int startId){
  if(intent!=null&&"STOP".equals(intent.getAction())){Store.prefs(this).edit().putBoolean("enabled",false).apply();stopForeground(STOP_FOREGROUND_REMOVE);stopSelf();return START_NOT_STICKY;}
  if(!Store.prefs(this).getBoolean("enabled",false)){stopSelf();return START_NOT_STICKY;}
  channels(this);startForeground(SERVICE_ID,status("در حال اتصال به سرور…"));
  if(executor==null){wake=((PowerManager)getSystemService(POWER_SERVICE)).newWakeLock(PowerManager.PARTIAL_WAKE_LOCK,"WallexAlerts:receive");executor=Executors.newSingleThreadScheduledExecutor();executor.scheduleWithFixedDelay(this::poll,0,20,TimeUnit.SECONDS);}
  return START_STICKY;
 }
 void poll(){
  try{
   if(!Store.prefs(this).getBoolean("enabled",false))return;
   if(!wake.isHeld()||SystemClock.elapsedRealtime()-renewed>240000){if(wake.isHeld())wake.release();wake.acquire(600000);renewed=SystemClock.elapsedRealtime();}
   String token=Store.token(this);if(token.isEmpty())throw new Api.Failure(401,"دوباره وارد حساب شوید.");
   JSONArray receipts=new JSONArray(Store.prefs(this).getString("receipts","[]"));
   if(receipts.length()>0){Api.call("?mobile_action=ack",new JSONObject().put("ids",receipts),token,null);Store.prefs(this).edit().putString("receipts","[]").commit();receipts=new JSONArray();}
   long cursor=Store.prefs(this).getLong("cursor",0);
   JSONObject feed=Api.call("?mobile_feed=1&after="+cursor,null,token,null);JSONArray alerts=feed.getJSONArray("alerts");
   for(int i=0;i<alerts.length();i++){
    JSONObject alert=alerts.getJSONObject(i);long id=alert.getLong("id");JSONObject payload=alert.getJSONObject("payload");
    show(this,(int)(id%2000000000)+100,title(payload),body(payload));
    JSONArray history=new JSONArray(Store.prefs(this).getString("history","[]"));history.put(alert);JSONArray bounded=new JSONArray();for(int j=Math.max(0,history.length()-50);j<history.length();j++)bounded.put(history.get(j));
    receipts.put(id);Store.prefs(this).edit().putLong("cursor",id).putString("history",bounded.toString()).putString("receipts",receipts.toString()).commit();
   }
   if(receipts.length()>0){Api.call("?mobile_action=ack",new JSONObject().put("ids",receipts),token,null);Store.prefs(this).edit().putString("receipts","[]").commit();}
   String message=allowed(this)?"فعال · بررسی هر ۲۰ ثانیه":"اجازه اعلان غیرفعال است؛ هشدارها تأیید دریافت نمی‌شوند.";
   Store.prefs(this).edit().putLong("last_check",System.currentTimeMillis()).putString("error","").apply();getSystemService(NotificationManager.class).notify(SERVICE_ID,status(message));
  }catch(Exception error){
   Store.prefs(this).edit().putString("error",error.getMessage()==null?"خطای اتصال":error.getMessage()).apply();
   getSystemService(NotificationManager.class).notify(SERVICE_ID,status("خطای دریافت؛ برای جزئیات اپ را باز کنید."));
   if(error instanceof Api.Failure&&((Api.Failure)error).status==401){Store.prefs(this).edit().putBoolean("enabled",false).remove("token").remove("iv").apply();stopForeground(STOP_FOREGROUND_REMOVE);stopSelf();}
  }
 }
 @Override public void onDestroy(){if(executor!=null)executor.shutdownNow();if(wake!=null&&wake.isHeld())wake.release();super.onDestroy();}
 @Override public IBinder onBind(Intent intent){return null;}
}
