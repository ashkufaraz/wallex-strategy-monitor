<?php
declare(strict_types=1);

// Run from cPanel Cron Jobs; never expose a web-triggered scheduler.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/src/BrowserRunner.php';

// Each invocation resumes persisted state instead of resetting worker stages.
// browserCheck shares worker.lock with both the browser and the CLI worker.
set_time_limit(60);
$deadline=microtime(true)+45;
$checked=0;
try {
 SmsFallback::dispatch(db(),time());
 try{ScannerService::tick();}catch(Throwable $scannerError){systemLog('error','scanner.cron',$scannerError->getMessage());}
 do {
  $result=browserCheck(new Wallex($deadline));
  if($result['status']==='checked')$checked++;
  else break;
 } while(microtime(true)<$deadline);
 SmsFallback::dispatch(db(),time());
 echo encode(['checked'=>$checked,'status'=>$result['status'],'finished_at'=>time()])."\n";
} catch(Throwable $e) {
 systemLog('error','cron',$e->getMessage());
 try{SmsFallback::dispatch(db(),time());}catch(Throwable $smsError){systemLog('error','sms.dispatch','SMS dispatch failed');}
 fwrite(STDERR,'Cron: '.$e->getMessage()."\n");
 exit(1);
}
