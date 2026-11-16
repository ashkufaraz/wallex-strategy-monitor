<?php
require dirname(__DIR__).'/src/MobileAlerts.php';require dirname(__DIR__).'/src/SmsFallback.php';
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,password_hash TEXT);INSERT INTO users VALUES(1,'hash'),(2,'hash');CREATE TABLE alerts(id INTEGER PRIMARY KEY,created INTEGER,payload TEXT);CREATE TABLE alert_owners(alert_id INTEGER,user_id INTEGER);");
SmsFallback::migrate($db);$device=MobileAlerts::register($db,1,'A36',1000);$header='Bearer '.$device['token'];
assert(strlen($device['token'])===64);if(MobileAlerts::authenticate($db,$header,1001)!==1)throw new Exception('auth');
for($i=1;$i<=105;$i++){$db->prepare('INSERT INTO alerts VALUES(?,?,?)')->execute([$i,1000,'{}']);$db->prepare('INSERT INTO alert_owners VALUES(?,?)')->execute([$i,$i===2?2:1]);}
$first=MobileAlerts::feed($db,1,0);if(count($first['alerts'])!==100||$first['cursor']!==101)throw new Exception('pagination');
$next=MobileAlerts::feed($db,1,$first['cursor']);if(count($next['alerts'])!==4||$next['cursor']!==105)throw new Exception('missed cursor');
MobileAlerts::acknowledge($db,1,[1,2],1100);
if((int)$db->query('SELECT ack_at FROM sms_fallback WHERE alert_id=1')->fetchColumn()!==1100||$db->query('SELECT ack_at FROM sms_fallback WHERE alert_id=2')->fetchColumn()!==null)throw new Exception('receipt isolation');
if(count(MobileAlerts::feed($db,1,null)['alerts'])!==30)throw new Exception('bootstrap');
MobileAlerts::revoke($db,$header);try{MobileAlerts::authenticate($db,$header,1100);throw new Exception('revoked token accepted');}catch(RuntimeException $e){}
$expired=MobileAlerts::register($db,1,'Expired',1);try{MobileAlerts::authenticate($db,'Bearer '.$expired['token'],20000000);throw new Exception('expired token accepted');}catch(RuntimeException $e){}
echo "PASS mobile token hash, expiry/revocation, owner isolation, paginated cursor and SMS receipt isolation\n";
