<?php
require __DIR__.'/src/App.php';
if(PHP_SAPI!=='cli') exit;
$lock=fopen(__DIR__.'/data/worker.lock','c');if(!flock($lock,LOCK_EX|LOCK_NB)) exit("Worker already running\n");
if(!MarketArchitecture::config()['analysis_enabled'])initializeWorkerStates(time());

systemLog('info','worker.started','Strategy worker started');
echo "Wallex alerts worker started. Ctrl+C to stop.\n";
$next=0;
while(true) {try {setSetting('heartbeat',(string)time());if(time()>=$next) {runCycle(new Wallex(),time());setSetting('last_cycle',(string)time());$next=time()+(int)setting('interval','60');} $interval=(int)setting('interval','60');$next=min($next,(int)setting('last_cycle','0')+$interval);}catch(Throwable $e){systemLog('error','worker',$e->getMessage());fwrite(STDERR,$e->getMessage()."\n");}sleep(1);}
