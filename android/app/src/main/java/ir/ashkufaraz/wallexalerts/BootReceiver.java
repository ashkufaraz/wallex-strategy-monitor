package ir.ashkufaraz.wallexalerts;
import android.content.*;
public final class BootReceiver extends BroadcastReceiver {
 @Override public void onReceive(Context c,Intent intent){if(Intent.ACTION_BOOT_COMPLETED.equals(intent.getAction())&&Store.prefs(c).getBoolean("enabled",false))try{c.startForegroundService(new Intent(c,AlertService.class));}catch(RuntimeException error){Store.prefs(c).edit().putString("error","پس از روشن‌شدن گوشی، اپ را باز کنید و دریافت را فعال کنید.").apply();}}
}
