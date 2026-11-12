<?php
define('TEST_DATABASE', ':memory:');
require dirname(__DIR__).'/src/App.php';
require dirname(__DIR__).'/src/AlertHistory.php';
function historyCheck(bool $value,string $label):void {if(!$value)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
$s=json_decode(file_get_contents(dirname(__DIR__).'/example-btc-short.json'),true);
$state=encode(Strategy::fresh($s,100));
db()->prepare('INSERT INTO strategies VALUES(?,?,?,?)')->execute([$s['id'],'v1',encode($s),$state]);
setSetting('interval','30');
for($i=1;$i<=105;$i++)recordAlert($s,'v1','price',$i,75000);
historyCheck(deleteAlertHistory('delete_alert',50)===1,'single alert removed');
historyCheck((int)db()->query('SELECT COUNT(*) FROM alerts')->fetchColumn()===104,'other alerts retained');
historyCheck(deleteAlertHistory('delete_alert',50)===0,'repeated delete harmless');
foreach([null,0,-1,'1',1.5,[],true] as $id){$bad=false;try{deleteAlertHistory('clear_alerts',$id);}catch(InvalidArgumentException $e){$bad=true;}historyCheck($bad,'invalid deletion ID rejected');}
historyCheck(deleteAlertHistory('clear_alerts',104)===103,'clear includes history beyond latest 100');
historyCheck((int)db()->query('SELECT id FROM alerts')->fetchColumn()===105,'new alert after cutoff retained');
historyCheck(db()->query('SELECT state FROM strategies')->fetchColumn()===$state&&setting('interval')==='30','strategy state and settings unchanged');
deleteAlertHistory('clear_alerts',105);
recordAlert($s,'v1','price',106,75000);
historyCheck((int)db()->query('SELECT id FROM alerts')->fetchColumn()>105,'alert IDs remain increasing after clear');

$row=['id'=>$s['id'],'version'=>'v1'];
historyCheck(strategyLastSignal($row)['payload']['event_time']===106,'last entry signal survives later evaluation cycles');
recordAlert($s,'v1','cancelled:price',107,76000,'cancelled');
historyCheck(strategyLastSignal($row)['payload']['event_time']===106,'cancellation does not replace last entry signal');
historyCheck(strategyLastSignal(['id'=>$s['id'],'version'=>'v2'])===null,'previous strategy version is not shown as a new entry');
