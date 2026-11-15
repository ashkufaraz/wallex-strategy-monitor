<?php
require dirname(__DIR__).'/src/SmsFallback.php';
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE alerts(id INTEGER PRIMARY KEY,created INTEGER,payload TEXT);CREATE TABLE alert_owners(alert_id INTEGER,user_id INTEGER);CREATE TABLE users(id INTEGER,email TEXT);INSERT INTO users VALUES(1,\'owner@example.com\'),(2,\'other@example.com\');');
SmsFallback::migrate($db);
$config=['enabled'=>true,'enabled_at'=>1000,'owner_email'=>'owner@example.com'];$sent=[];$logs=[];
$send=function($c,$text)use(&$sent){$sent[]=$text;return ['message_id'=>'123','status'=>1];};
$log=function(...$args)use(&$logs){$logs[]=$args;};
function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message."\n";}
function add($db,$id,$at=1000,$owner=1){$db->prepare('INSERT INTO alerts VALUES(?,?,?)')->execute([$id,$at,json_encode(['name'=>'Test','symbol'=>'BTCUSDT','price'=>70000,'event_time'=>$at])]);$db->prepare('INSERT INTO alert_owners VALUES(?,?)')->execute([$id,$owner]);}
add($db,1);SmsFallback::dispatch($db,1089,$config,$send,$log);check(!$sent,'grace period does not send early');
SmsFallback::acknowledge($db,1,1089);SmsFallback::dispatch($db,1100,$config,$send,$log);check(!$sent,'confirmed browser receipt suppresses SMS');
add($db,2);SmsFallback::dispatch($db,1100,$config,$send,$log);check(count($sent)===1,'offline owner receives SMS after grace');
SmsFallback::dispatch($db,1200,$config,$send,$log);check(count($sent)===1,'repeated cron never duplicates accepted SMS');
add($db,3,1000,2);add($db,4,900);add($db,5,1000);SmsFallback::dispatch($db,5000,$config,$send,$log);check(count($sent)===1,'other owners, preactivation and alerts older than one hour excluded');
add($db,6,1200);$failure=function(){throw new RuntimeException('SMS transport outcome unknown');};
SmsFallback::dispatch($db,1300,$config,$failure,$log);$count=count($logs);SmsFallback::dispatch($db,1301,$config,$send,$log);check(count($logs)===$count&&count($sent)===1,'ambiguous network failure logged without automatic paid retry');
add($db,7,1400);$nested=function($c,$text)use($db,$config,$send,$log){SmsFallback::dispatch($db,1500,$config,$send,$log);return ['message_id'=>'456','status'=>1];};
SmsFallback::dispatch($db,1500,$config,$nested,$log);check(count($sent)===1,'persisted claim prevents overlapping dispatch duplication');
add($db,8,1600);$disabled=$config;$disabled['enabled']=false;SmsFallback::dispatch($db,1700,$disabled,$send,$log);check(count($sent)===1,'disabled configuration never calls provider');
$p=['type'=>'entry_conditions_only','name'=>'Test','symbol'=>'BTCUSDT'];check(str_contains(SmsFallback::message($p),'اندیکاتورها تأیید نشده‌اند'),'partial entry alert not described as final signal');
echo "SMS fallback tests passed.\n";
