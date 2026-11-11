<?php
declare(strict_types=1);
// CLI-only, public GETs, isolated database. Does not load hosting credentials.
if(PHP_SAPI!=='cli')exit;
define('TEST_DATABASE',':memory:');require dirname(__DIR__).'/src/App.php';
if(isset($argv[1])&&file_exists($argv[1]))throw new RuntimeException('Refusing to overwrite existing evidence');
$started=microtime(true);$cpu=getrusage();$requests=[];$raw=[];$blocked=false;
$report=['kind'=>'LIVE_DIAGNOSTIC','vantage'=>'CLI environment executing this file; not proof of remote hosting','started_at'=>gmdate('c'),'symbol'=>'BTCUSDT','persistent_database'=>false,'signal_snapshot_modified'=>false,'production_enabled'=>OrderFlowConfig::safe()['enabled']];
$c=OrderFlowConfig::defaults();$c['enabled']=true; // In-memory diagnostic policy only.
$transport=static function(string $url,int $timeout)use(&$requests,&$raw,&$blocked):array {
 if($blocked)throw new RuntimeException('Diagnostic circuit breaker: rate limit or request budget');
 $headers=[];$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT_MS=>$timeout,CURLOPT_CONNECTTIMEOUT_MS=>min(1000,$timeout),CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HEADERFUNCTION=>static function($ch,$line)use(&$headers){if(str_contains($line,':')){[$k,$v]=explode(':',$line,2);$headers[strtolower(trim($k))]=trim($v);}return strlen($line);}]);
 $body=curl_exec($ch);$info=curl_getinfo($ch);$errno=curl_errno($ch);$error=curl_error($ch);curl_close($ch);$status=(int)$info['http_code'];$decoded=json_decode($body===false?'':$body,true);
 $requests[]=['url'=>$url,'received_at'=>microtime(true),'http_status'=>$status,'duration_ms'=>round($info['total_time']*1000,3),'curl_errno'=>$errno,'curl_error'=>$error,'tls_verification_enabled'=>true,'tls_verify_result'=>$info['ssl_verify_result']??null,'tls_verified'=>$errno===0&&$status>0&&($info['ssl_verify_result']??-1)===0,'json_valid'=>json_last_error()===JSON_ERROR_NONE,'bytes'=>strlen($body?:''),'used_weight_1m'=>$headers['x-mbx-used-weight-1m']??null,'retry_after'=>$headers['retry-after']??null];
 $raw[]=['url'=>$url,'received_at'=>end($requests)['received_at'],'response'=>$decoded];
 if(in_array($status,[418,429],true)||count($requests)>=16)$blocked=true;
 if($body===false)throw new RuntimeException('Diagnostic transport failure: '.$error);
 return ['status'=>$status,'headers'=>$headers,'body'=>$body];
};
$api=new OrderFlowMarketData($transport);$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);OrderFlowAdvisory::migrate($db);
$now=time();$signal=['id'=>'isolated-live-diagnostic','strategy_id'=>'diagnostic','version'=>'diagnostic','symbol'=>'BTCUSDT','action'=>'LONG','signal_time'=>$now];
OrderFlowAdvisory::queue($db,$signal,'LATEST',$now,$c,$now);$steps=[];
do{$step=OrderFlowAdvisory::step($db,$c,time(),$api);$steps[]=$step;if(($step['status']??'')==='completed')break;usleep(3000000);}while(microtime(true)-$started<45);
$result=$db->query('SELECT payload FROM order_flow_results')->fetchColumn();$report['result']=$result?json_decode($result,true):['advisory_status'=>'UNAVAILABLE','explanation'=>'45 second diagnostic budget exhausted','indicator_values'=>[]];
$report['windows']=[];foreach([60,300] as $seconds)$report['windows'][(string)$seconds]=['start'=>intdiv($now,60)*60-$seconds,'end'=>intdiv($now,60)*60,'metrics'=>$report['result']['indicator_values'][(string)$seconds]??null,'status'=>isset($report['result']['indicator_values'][(string)$seconds])?'CALCULATED':'UNAVAILABLE'];
// Test both requested public resources even when the initial time probe failed.
// These probes never contribute partial data to a successful calculation.
foreach([['/api/v3/aggTrades',['symbol'=>'BTCUSDT','limit'=>1]],['/api/v3/depth',['symbol'=>'BTCUSDT','limit'=>1000]]] as [$path,$params]){
 if($blocked)break;$seen=false;foreach($requests as $request)if(str_contains($request['url'],$path))$seen=true;
 if(!$seen){try{$api->get($path,$params,$c);}catch(Throwable $e){$report['probe_errors'][$path]=$e->getMessage();}}
}
$off=OrderFlowConfig::defaults();$t=hrtime(true);for($i=0;$i<10000;$i++)OrderFlowAdvisory::step($db,$off,time(),$api);
$report['disabled_step_benchmark']=['iterations'=>10000,'total_ms'=>(hrtime(true)-$t)/1e6,'network_requests'=>0,'scope'=>'isolated disabled auxiliary calls; not hosted main-loop latency'];
$after=getrusage();$toMs=static fn($r)=>($r['ru_utime.tv_sec']+$r['ru_stime.tv_sec'])*1000+($r['ru_utime.tv_usec']+$r['ru_stime.tv_usec'])/1000;
$report['resources']=['wall_ms'=>round((microtime(true)-$started)*1000,3),'cpu_ms'=>$toMs($after)-$toMs($cpu),'peak_memory_bytes'=>memory_get_peak_usage(true),'request_count'=>count($requests)];
$report['requests']=$requests;$report['steps']=$steps;$report['raw_public_data']=$raw;$report['book_source_event_timestamp']=null;
$json=json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
if(isset($argv[1])){if(file_exists($argv[1]))throw new RuntimeException('Refusing to overwrite existing evidence');file_put_contents($argv[1],$json,LOCK_EX);}else echo $json;
exit(($report['result']['data_completeness']??'')==='COMPLETE_WITHIN_REQUESTED_WINDOWS'?0:2);
