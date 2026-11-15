<?php
declare(strict_types=1);

final class SmsFallback {
 static function config():array {
  $path=dirname(__DIR__).'/config/sms.json';
  $c=is_file($path)?json_decode(file_get_contents($path),true):[];
  return is_array($c)?$c:[];
 }
 static function migrate(PDO $db):void {
  $db->exec("CREATE TABLE IF NOT EXISTS sms_fallback(alert_id INTEGER PRIMARY KEY,due INTEGER NOT NULL,ack_at INTEGER,status TEXT NOT NULL DEFAULT 'pending',message_id TEXT,provider_status INTEGER,updated INTEGER,error TEXT);
   CREATE TRIGGER IF NOT EXISTS sms_fallback_enqueue AFTER INSERT ON alerts BEGIN INSERT OR IGNORE INTO sms_fallback(alert_id,due) VALUES(NEW.id,NEW.created+90); END;");
 }
 static function acknowledge(PDO $db,int $id,int $now):void {
  $db->prepare("UPDATE sms_fallback SET ack_at=COALESCE(ack_at,?),status=CASE WHEN status='pending' THEN 'acknowledged' ELSE status END,updated=? WHERE alert_id=?")->execute([$now,$now,$id]);
 }
 static function api(array $config,string $method,array $params):array {
  $key=$config['api_key']??'';
  if(!preg_match('/^[A-Za-z0-9]{20,200}$/D',$key))throw new RuntimeException('SMS configuration missing');
  $ch=curl_init('https://api.kavenegar.com/v1/'.$key.'/sms/'.$method.'.json');
  curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($params),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true]);
  $raw=curl_exec($ch);$http=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
  // Never include the request URL or credentials in exceptions or system logs.
  if($raw===false)throw new RuntimeException('SMS transport outcome unknown; not retried automatically');
  $response=json_decode($raw,true);
  if(!is_array($response)||!isset($response['return']['status']))throw new RuntimeException('SMS response unknown (HTTP '.$http.'); not retried automatically');
  if((int)$response['return']['status']!==200){$code=(int)$response['return']['status'];throw new RuntimeException($code===430?'کاوه‌نگار: حساب کاربری احراز هویت نشده است (430).':'Kavenegar rejected request: code '.$code);}
  return $response;
 }
 static function send(array $config,string $text):array {
  $params=['receptor'=>$config['receptor']??'','message'=>$text];
  if(!preg_match('/^09[0-9]{9}$/D',$params['receptor']))throw new RuntimeException('SMS receptor invalid');
  if(!empty($config['sender']))$params['sender']=$config['sender'];
  $result=self::api($config,'send',$params);$entry=$result['entries'][0]??null;
  if(!is_array($entry)||empty($entry['messageid']))throw new RuntimeException('SMS acceptance unknown; not retried automatically');
  return ['message_id'=>(string)$entry['messageid'],'status'=>(int)($entry['status']??0)];
 }
 static function message(array $p):string {
  $type=($p['type']??'')==='entry_conditions_only'?'فقط شرط‌های ورود؛ اندیکاتورها تأیید نشده‌اند':(($p['type']??'')==='cancelled'?'لغو استراتژی':'هشدار استراتژی');
  return "پایش والکس\n".$type."\n".implode('',array_slice(preg_split('//u',(string)($p['name']??''),-1,PREG_SPLIT_NO_EMPTY)?:[],0,100))."\n".($p['symbol']??'').' '.($p['action']??'')."\nقیمت: ".($p['price']??'')."\nزمان UTC: ".gmdate('Y-m-d H:i',(int)($p['event_time']??time()))."\nashkufaraz.ir/public/";
 }
 static function dispatch(PDO $db,int $now,?array $config=null,?callable $send=null,?callable $log=null):array {
  $config??=self::config();if(empty($config['enabled']))return ['sent'=>0,'failed'=>0];
  self::migrate($db);$send??=fn($c,$text)=>self::send($c,$text);
  $log??=fn($level,$event,$message,$context)=>systemLog($level,$event,$message,$context);
  $q=$db->prepare("SELECT f.alert_id,a.payload FROM sms_fallback f JOIN alerts a ON a.id=f.alert_id JOIN alert_owners o ON o.alert_id=a.id JOIN users u ON u.id=o.user_id WHERE f.status='pending' AND f.ack_at IS NULL AND f.due<=? AND a.created>=? AND a.created>=? AND u.email=? ORDER BY f.alert_id LIMIT 5");
  $q->execute([$now,(int)($config['enabled_at']??$now),$now-3600,$config['owner_email']??'']);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$result=['sent'=>0,'failed'=>0];
  foreach($rows as $row){
   // Persist the claim BEFORE network I/O. A crash or timeout must never cause a duplicate paid SMS.
   $claim=$db->prepare("UPDATE sms_fallback SET status='sending',updated=? WHERE alert_id=? AND status='pending' AND ack_at IS NULL");$claim->execute([$now,$row['alert_id']]);if(!$claim->rowCount())continue;
   try{
    $sent=$send($config,self::message(json_decode($row['payload'],true)));
    $db->prepare("UPDATE sms_fallback SET status='accepted',message_id=?,provider_status=?,updated=? WHERE alert_id=?")->execute([$sent['message_id'],$sent['status'],$now,$row['alert_id']]);$result['sent']++;
    $log('success','sms.accepted','پیامک پشتیبان توسط کاوه‌نگار پذیرفته شد؛ پذیرش به معنی تحویل نهایی نیست.',['alert_id'=>(int)$row['alert_id'],'message_id'=>$sent['message_id'],'provider_status'=>$sent['status']]);
   }catch(Throwable $e){
    $db->prepare("UPDATE sms_fallback SET status='failed',error=?,updated=? WHERE alert_id=?")->execute([$e->getMessage(),$now,$row['alert_id']]);$result['failed']++;
    $log('error','sms.failed',$e->getMessage(),['alert_id'=>(int)$row['alert_id']]);
   }
  }
  return $result;
 }
}
