<?php
// Separate auxiliary process: never invoked by the main strategy worker.
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/src/App.php';
$once=in_array('--once',$argv,true);
do{
 try{$c=OrderFlowConfig::get();if(!$c['enabled']){echo "Order Flow disabled; no work performed.\n";exit;}
  OrderFlowAdvisory::discover(db(),$c,time());$result=OrderFlowAdvisory::step(db(),$c,time());echo encode($result)."\n";
 }catch(Throwable $e){systemLog('error','order_flow.worker',$e->getMessage());fwrite(STDERR,'Order Flow: '.$e->getMessage()."\n");}
 if(!$once)sleep(3);
}while(!$once);
