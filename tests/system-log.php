<?php
define('TEST_DATABASE', ':memory:');
require dirname(__DIR__).'/src/App.php';
function verify(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
systemLog('success','test.save','saved',['strategy_id'=>'sample']);
systemLog('error','test.failure','offline');
$rows=systemLogs();
verify(count($rows)===2&&$rows[0]['level']==='error','logs are persisted newest first');
verify($rows[1]['context']['strategy_id']==='sample','diagnostic context survives storage');
Diagnostics::save('sample','v1',['checked'=>time(),'status'=>'error','error'=>'market offline']);
verify(systemLogs()[0]['message']==='market offline','strategy failures reach system log');
db()->beginTransaction();systemLog('info','test.rollback','temporary');db()->rollBack();
verify(count(systemLogs())===3,'transaction rollback stays atomic');
db()->exec("INSERT INTO system_logs(id,created,level,event,message,context) VALUES(20000,0,'info','test','old','{}')");
systemLog('warning','test.retention','retention');
verify((int)db()->query('SELECT MIN(id) FROM system_logs')->fetchColumn()===20000,'old logs are bounded by retention');
