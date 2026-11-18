<?php
declare(strict_types=1);

final class MobileAlerts {
 static function migrate(PDO $db):void {
  $db->exec('CREATE TABLE IF NOT EXISTS mobile_devices(token_hash TEXT PRIMARY KEY,user_id INTEGER NOT NULL,name TEXT NOT NULL,created INTEGER NOT NULL,expires INTEGER NOT NULL);');
 }
 static function register(PDO $db,int $userId,string $name,int $now):array {
  self::migrate($db);$token=bin2hex(random_bytes(32));
  $db->prepare('DELETE FROM mobile_devices WHERE expires<?')->execute([$now]);
  $q=$db->prepare('SELECT count(*) FROM mobile_devices WHERE user_id=?');$q->execute([$userId]);
  if((int)$q->fetchColumn()>=10)throw new RuntimeException('حداکثر ۱۰ دستگاه مجاز است. دستگاه‌های قدیمی را قطع کنید.');
  $expires=$now+180*86400;
  $db->prepare('INSERT INTO mobile_devices VALUES(?,?,?,?,?)')->execute([hash('sha256',$token),$userId,substr($name,0,120),$now,$expires]);
  return ['token'=>$token,'expires'=>$expires];
 }
 static function authenticate(PDO $db,string $header,int $now):int {
  self::migrate($db);
  if(!preg_match('/^Bearer ([a-f0-9]{64})$/D',$header,$match)){http_response_code(401);throw new RuntimeException('ورود مجدد در اپ لازم است.');}
  $q=$db->prepare('SELECT d.user_id FROM mobile_devices d JOIN users u ON u.id=d.user_id WHERE token_hash=? AND expires>? AND u.password_hash IS NOT NULL');$q->execute([hash('sha256',$match[1]),$now]);$id=$q->fetchColumn();
  if($id===false){http_response_code(401);throw new RuntimeException('دسترسی دستگاه منقضی یا لغو شده است.');}
  return (int)$id;
 }
 static function feed(PDO $db,int $userId,?int $after):array {
  $q=$db->prepare('SELECT a.id,a.created,a.payload FROM alerts a JOIN alert_owners o ON o.alert_id=a.id WHERE o.user_id=? AND a.id>? ORDER BY a.id '.($after===null?'DESC LIMIT 30':'ASC LIMIT 100'));
  $q->execute([$userId,$after??0]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);if($after===null)$rows=array_reverse($rows);
  $alerts=[];$cursor=$after??0;
  foreach($rows as $r){$cursor=max($cursor,(int)$r['id']);$alerts[]=['id'=>(int)$r['id'],'created'=>(int)$r['created'],'payload'=>array_replace(['exchange'=>'wallex'],json_decode($r['payload'],true))];}
  return ['alerts'=>$alerts,'cursor'=>$cursor,'server_time'=>time()];
 }
 static function acknowledge(PDO $db,int $userId,array $ids,int $now):void {
  if(count($ids)>100)throw new InvalidArgumentException('Too many receipts');
  $q=$db->prepare('SELECT 1 FROM alert_owners WHERE alert_id=? AND user_id=?');
  foreach($ids as $id){if(!is_int($id)||$id<1)throw new InvalidArgumentException('Invalid alert ID');$q->execute([$id,$userId]);if($q->fetchColumn())SmsFallback::acknowledge($db,$id,$now);}
 }
 static function revoke(PDO $db,string $header):void {
  $db->prepare('DELETE FROM mobile_devices WHERE token_hash=?')->execute([hash('sha256',substr($header,7))]);
 }
}
