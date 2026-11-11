<?php
declare(strict_types=1);
require_once __DIR__.'/App.php';

// One bounded strategy check per request; shared lock also excludes the CLI worker.
function browserCheck(?Wallex $api=null,?int $now=null,?string $lockPath=null):array {
 $now??=time();
 $lock=fopen($lockPath??dirname(__DIR__).'/data/worker.lock','c');
 if(!$lock)throw new RuntimeException('Cannot open checking lock');
 if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);return ['status'=>'busy'];}
 try {
  $interval=max(5,(int)setting('interval','60'));
  $due=[];
  foreach(db()->query('SELECT id,version,config,state FROM strategies')->fetchAll(PDO::FETCH_ASSOC) as $row){
   $s=json_decode($row['config'],true);$architecture=MarketArchitecture::config();if($architecture['analysis_enabled']&&!MarketArchitecture::approved($architecture,$row['id'],$row['version']))continue;$state=displayedState($row,$architecture);
   if(!$s['enabled']||isset($state['cancelled_at']))continue;
   if($now-(int)($state['last_check']??0)>=$interval)$due[]=['id'=>$row['id'],'last'=>(int)($state['last_check']??0)];
  }
  usort($due,fn($a,$b)=>($a['last']<=>$b['last'])?:strcmp($a['id'],$b['id']));
  setSetting('heartbeat',(string)$now);
  if(!$due){
   $architecture=MarketArchitecture::config();
   if($architecture['analysis_enabled']){
    ExecutionMonitor::run($api instanceof BinanceMarketDataProvider?$api:new BinanceMarketDataProvider(null,null,microtime(true)+12),$now,$architecture);
    return ['status'=>'gate_checked'];
   }
   return ['status'=>'idle'];
  }
  runCycle($api??new Wallex(microtime(true)+15),$now,$due[0]['id']);
  setSetting('last_cycle',(string)time());
  return ['status'=>'checked','strategy_id'=>$due[0]['id']];
 } finally {flock($lock,LOCK_UN);fclose($lock);}
}
