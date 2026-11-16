package ir.ashkufaraz.wallexalerts;
import android.content.*;
import android.security.keystore.*;
import android.util.Base64;
import java.security.KeyStore;
import javax.crypto.*;
import javax.crypto.spec.GCMParameterSpec;

final class Store {
 static android.content.SharedPreferences prefs(Context c){return c.getSharedPreferences("alerts",Context.MODE_PRIVATE);}
 static javax.crypto.SecretKey key() throws Exception {
  KeyStore ks=KeyStore.getInstance("AndroidKeyStore");ks.load(null);
  if(!ks.containsAlias("wallex-device")){KeyGenerator gen=KeyGenerator.getInstance("AES","AndroidKeyStore");gen.init(new KeyGenParameterSpec.Builder("wallex-device",KeyProperties.PURPOSE_ENCRYPT|KeyProperties.PURPOSE_DECRYPT).setBlockModes(KeyProperties.BLOCK_MODE_GCM).setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE).build());gen.generateKey();}
  return (javax.crypto.SecretKey)ks.getKey("wallex-device",null);
 }
 static void saveToken(Context c,String token)throws Exception {
  Cipher cipher=Cipher.getInstance("AES/GCM/NoPadding");cipher.init(Cipher.ENCRYPT_MODE,key());
  prefs(c).edit().putString("token",Base64.encodeToString(cipher.doFinal(token.getBytes("UTF-8")),Base64.NO_WRAP)).putString("iv",Base64.encodeToString(cipher.getIV(),Base64.NO_WRAP)).commit();
 }
 static String token(Context c)throws Exception {
  String encrypted=prefs(c).getString("token","");if(encrypted.isEmpty())return "";
  Cipher cipher=Cipher.getInstance("AES/GCM/NoPadding");cipher.init(Cipher.DECRYPT_MODE,key(),new GCMParameterSpec(128,Base64.decode(prefs(c).getString("iv",""),Base64.NO_WRAP)));
  return new String(cipher.doFinal(Base64.decode(encrypted,Base64.NO_WRAP)),"UTF-8");
 }
 static void clear(Context c){prefs(c).edit().clear().commit();}
}
