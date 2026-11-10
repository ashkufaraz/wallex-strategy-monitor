<?php
define('TEST_DATABASE',':memory:');
require dirname(__DIR__).'/src/App.php';
function expectIndicator(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
class IndicatorMarket extends Wallex {
 public bool $entryMatch=true;public int $historyCalls=0;public bool $fail=false;public bool $priceFail=false;public bool $gap=false;public array $requests=[];
 function price(string $symbol):float{if($this->priceFail)throw new RuntimeException('price offline');return 100.;}
 function minutes(string $symbol,int $from,int $to):array{$a=[];for($t=$from;$t+60<=$to;$t+=60)$a[$t]=['open'=>100.,'high'=>102.,'low'=>99.,'close'=>$this->entryMatch?101.:99.,'volume'=>1.];return $a;}
 function get(string $path,array $params=[]):array {
  $this->historyCalls++;$this->requests[]=$params;if($this->fail)throw new RuntimeException('indicator API offline');
  $step=$params['resolution']==='1'?60:3600;$d=['s'=>'ok','symbol'=>$params['symbol'],'t'=>[],'o'=>[],'h'=>[],'l'=>[],'c'=>[],'v'=>[]];
  for($t=$params['from'];$t<=$params['to'];$t+=$step){if($this->gap&&$t===$params['from']+$step)continue;$c=100+$t/$step*0.01;
   $d['t'][]=$t;$d['o'][]=$c;$d['h'][]=$c+2;$d['l'][]=$c-2;$d['c'][]=$c+1;$d['v'][]=10.;
  }
  return $d;
 }
}
$template=json_decode(file_get_contents(dirname(__DIR__).'/example-btc-short.json'),true);unset($template['cancellation']);
$template['initial_stage']='start';$template['invalidation']=['left'=>['price'=>true],'op'=>'gt','right'=>1000];
$template['stages']=[['id'=>'start','transitions'=>[['event'=>'price','when'=>['left'=>['price'=>true],'op'=>'gt','right'=>1],'signal'=>true]]]];
$condition=['id'=>'ema','indicator'=>'EMA','timeframe'=>'1m','period'=>2,'candle'=>'last_closed','metric'=>'value','operator'=>'gt','value'=>0];
$block=['enabled'=>true,'logic'=>'all','conditions'=>[$condition]];
$reset=function() {db()->exec('DELETE FROM strategies; DELETE FROM alerts; DELETE FROM check_logs; DROP TABLE IF EXISTS indicator_cache;');setSetting('interval','60');};
$put=function(array $s,int $at){Strategy::validate($s);db()->prepare('INSERT INTO strategies VALUES(?,?,?,?)')->execute([$s['id'],'v1',encode($s),encode(Strategy::fresh($s,$at))]);};
$count=fn()=>(int)db()->query('SELECT COUNT(*) FROM alerts')->fetchColumn();
$state=fn()=>json_decode(db()->query('SELECT state FROM strategies LIMIT 1')->fetchColumn(),true);
$latest=fn()=>json_decode(db()->query('SELECT payload FROM check_logs ORDER BY id DESC LIMIT 1')->fetchColumn(),true);
$reset();$api=new IndicatorMarket();$put($template,1440000);runCycle($api,1440001);
expectIndicator($count()===1&&$api->historyCalls===0,'legacy price-only strategy alerts with zero extra candle calls');
$reset();$s=$template;$s['indicator_conditions']=['enabled'=>false];$put($s,1440000);runCycle($api,1440001);
expectIndicator($count()===1&&$api->historyCalls===0,'disabled indicators preserve legacy behavior and fetch no history');
$reset();$s['indicator_conditions']=$block;$s['stages'][0]['transitions'][0]['when']['right']=200;$put($s,1440000);runCycle($api,1440001);
expectIndicator($count()===0&&$api->historyCalls===0,'indicators never evaluated before final price condition passes');
$reset();$s=$template;$s['indicator_conditions']=$block;$api=new IndicatorMarket();$put($s,1440000);runCycle($api,1440001);
expectIndicator($count()===1&&$latest()['indicator_snapshot']['passed']===true,'price plus passing indicator records alert');
$payload=json_decode(db()->query('SELECT payload FROM alerts')->fetchColumn(),true);
expectIndicator($payload['indicators']['conditions'][0]['candle_close']===1440000&&$payload['indicators']['conditions'][0]['observed']>0,'alert contains immutable closed candle indicator snapshot');
$before=$api->historyCalls;runCycle($api,1440001);expectIndicator($count()===1&&$api->historyCalls===$before,'same event does not duplicate alert and reuses cache');
$reset();$s['indicator_conditions']['conditions'][0]['value']=999999;$api=new IndicatorMarket();$put($s,1440000);runCycle($api,1440001);
expectIndicator($count()===0&&$latest()['indicator_snapshot']['conditions'][0]['status']==='FAIL','passing price and failing EMA block alert');
$reset();$s['indicator_conditions']=$block;$s['indicator_conditions']['conditions'][0]['indicator']='RSI';$s['indicator_conditions']['conditions'][0]['operator']='lt';$s['indicator_conditions']['conditions'][0]['value']=50;$api=new IndicatorMarket();$put($s,1440000);runCycle($api,1440001);
expectIndicator($count()===0&&$latest()['indicator_snapshot']['conditions'][0]['status']==='FAIL','passing price and failing RSI block alert');
$reset();$s['indicator_conditions']=$block;$api=new IndicatorMarket();$api->fail=true;$put($s,1440000);runCycle($api,1440001);
expectIndicator($count()===0&&$latest()['status']==='indicator_blocked'&&$latest()['indicator_snapshot']['conditions'][0]['status']==='UNAVAILABLE','API failure is unavailable and fails closed');
expectIndicator($state()['stage']==='start'&&$state()['error']===null,'indicator API failure does not reset stage or trigger generic recovery');
$reset();$api=new IndicatorMarket();$api->gap=true;$put($s,1440000);runCycle($api,1440001);
expectIndicator($count()===0&&$latest()['indicator_snapshot']['conditions'][0]['status']==='UNAVAILABLE','missing history fails closed');
$reset();$api=new IndicatorMarket();$s['id']='first';$put($s,1440000);$s['id']='second';$s['indicator_conditions']['conditions'][0]['period']=1;$put($s,1440000);runCycle($api,1440001);
expectIndicator($count()===2&&$api->historyCalls===1,'two same-market strategies share larger cached history');
$newApi=new IndicatorMarket();runCycle($newApi,1440002);expectIndicator($newApi->historyCalls===0,'persistent cache reused across separate scheduler requests');
$newApi=new IndicatorMarket();runCycle($newApi,1440062);expectIndicator($newApi->historyCalls>0,'new closed candle refreshes history');
// No later indicator may approve an old candle event, even after restart.
$reset();$api=new IndicatorMarket();$api->fail=true;$s=$template;$s['indicator_conditions']=$block;
$s['stages'][0]['transitions'][0]=['event'=>'bar_close:15m','when'=>['left'=>['candle'=>['timeframe'=>'15m','offset'=>1,'field'=>'close']],'op'=>'gt','right'=>100],'signal'=>true];
$put($s,1440899);setSetting('interval','600');runCycle($api,1440900);
expectIndicator($count()===0&&!isset($state()['pending_signal']),'unavailable candle entry is consumed without retaining an old candidate');
initializeWorkerStates(1440910);$api=new IndicatorMarket();runCycle($api,1440960);
expectIndicator($count()===0,'API recovery before new entry candle cannot approve old event');
$api->entryMatch=false;runCycle($api,1441800);
expectIndicator($count()===0,'next candle rechecks original entry condition, not just indicator');
$api->entryMatch=true;runCycle($api,1442700);
expectIndicator($count()===1,'new matching candle and passing indicator emit alert');
$alert=json_decode(db()->query('SELECT payload FROM alerts')->fetchColumn(),true);
$key=db()->query('SELECT unique_key FROM alerts')->fetchColumn();
expectIndicator($alert['event_time']===1442700&&$alert['indicators']['as_of']===1442700&&str_ends_with($key,':bar_close:15m:1442700'),'event time, snapshot and unique key all identify the new candle');
runCycle($api,1442700);expectIndicator($count()===1,'repeated new-event check does not duplicate alert');
$reset();$api=new IndicatorMarket();$s['indicator_conditions']['conditions'][0]['value']=999999;$put($s,1440899);setSetting('interval','600');runCycle($api,1440900);
expectIndicator($count()===0,'initial FAIL blocks candle entry');
// Restore threshold through test-only storage to isolate event freshness from configuration versioning.
$s['indicator_conditions']=$block;db()->prepare('UPDATE strategies SET config=?')->execute([encode($s)]);
runCycle(new IndicatorMarket(),1440960);expectIndicator($count()===0,'FAIL to PASS within same candle cannot issue late alert');
setSetting('interval','600');runCycle(new IndicatorMarket(),1441800);expectIndicator($count()===1,'FAIL to PASS waits for a new entry candle');
$reset();$api=new IndicatorMarket();$api->fail=true;$s['max_setup_seconds']=30;$put($s,1440899);$st=Strategy::fresh($s,1440899);$st['setup']=1440890;db()->prepare('UPDATE strategies SET state=?')->execute([encode($st)]);runCycle($api,1440900);runCycle(new IndicatorMarket(),1440960);
expectIndicator($count()===0,'expired setup cannot emit recovered indicator alert');
$reset();$api=new IndicatorMarket();$provider=new IndicatorData($api);
foreach(['1m'=>60,'15m'=>900,'1h'=>3600,'4h'=>14400] as $tf=>$seconds){$asOf=1440000+$seconds-1;$history=$provider->history('BTCUSDT',$tf,6,$asOf,$asOf);$last=array_key_last($history);expectIndicator($last+$seconds<= $asOf&&$last===intdiv($asOf,$seconds)*$seconds-$seconds,'last_closed excludes open '.$tf.' candle');}
expectIndicator(array_column($api->requests,'resolution')===['1','1','60','60'],'verified base resolutions used for all timeframes');
expectIndicator(Strategy::$signalFilter===null&&Strategy::$onCheck===null,'callbacks cleared after cycle');
// A faster timeframe may change while the original 15m price setup stays valid.
$reset();$api=new IndicatorMarket();$s=$template;$s['indicator_conditions']=$block;$s['indicator_conditions']['conditions'][0]['period']=1;$s['indicator_conditions']['conditions'][0]['value']=341.145;
$s['stages'][0]['transitions'][0]=['event'=>'bar_close:15m','when'=>['left'=>['candle'=>['timeframe'=>'15m','offset'=>1,'field'=>'close']],'op'=>'gt','right'=>1],'signal'=>true];
$put($s,1440899);setSetting('interval','600');runCycle($api,1440900);expectIndicator($count()===0&&$latest()['indicator_snapshot']['conditions'][0]['status']==='FAIL','faster timeframe indicator may initially fail');
runCycle(new IndicatorMarket(),1440960);expectIndicator($count()===0,'new 1m indicator cannot reuse an old 15m entry candle');
setSetting('interval','600');runCycle(new IndicatorMarket(),1441800);expectIndicator($count()===1,'new 15m entry evaluates fresh 1m indicator');
// A failed market request must not erase the final stage on recovery.
$reset();$s=$template;$s['indicator_conditions']=$block;$s['stages']=[['id'=>'start','transitions'=>[['event'=>'price','when'=>['left'=>['price'=>true],'op'=>'gt','right'=>1],'to'=>'confirm']]],['id'=>'confirm','transitions'=>[['event'=>'price','when'=>['left'=>['price'=>true],'op'=>'gt','right'=>1],'signal'=>true]]]];
$api=new IndicatorMarket();$put($s,1440000);runCycle($api,1440001);$api->priceFail=true;runCycle($api,1440061);expectIndicator($state()['stage']==='confirm','price API error preserves final indicator stage');
$api->priceFail=false;runCycle($api,1440121);expectIndicator($count()===1,'price API recovery evaluates preserved final stage');
// Cancellation after a long outage takes priority over entry retries.
$reset();$s=$template;$s['indicator_conditions']=$block;$s['cancellation']=[['event'=>'bar_close:4h','when'=>['left'=>['candle'=>['timeframe'=>'4h','offset'=>1,'field'=>'close']],'op'=>'gt','right'=>1],'reason'=>'cancel test']];
$api=new IndicatorMarket();$put($s,1440001);$api->priceFail=true;runCycle($api,1440061);$api->priceFail=false;runCycle($api,1454401);
$alerts=db()->query('SELECT payload FROM alerts')->fetchAll(PDO::FETCH_COLUMN);expectIndicator(count($alerts)===1&&json_decode($alerts[0],true)['type']==='cancelled','latest closed cancellation wins after gap recovery');
// A multi-timeframe all block requires every condition to pass.
$reset();$s=$template;$s['indicator_conditions']=['enabled'=>true,'logic'=>'all','conditions'=>[]];
foreach(['RSI'=>'value','EMA'=>'close_minus_ema_pct','ATR'=>'atr_pct','VOLUME'=>'ratio_to_previous_avg'] as $name=>$metric){$c=$condition;$c['id']=strtolower($name);$c['indicator']=$name;$c['metric']=$metric;$c['period']=2;$c['timeframe']=$name==='ATR'?'4h':'15m';$c['operator']='gte';$c['value']=0;$s['indicator_conditions']['conditions'][]=$c;}
$api=new IndicatorMarket();$put($s,1440000);runCycle($api,1440001);
expectIndicator($count()===1&&count($latest()['indicator_snapshot']['conditions'])===4,'RSI EMA ATR and volume all participate across timeframes');
expectIndicator(count(array_unique(array_column($latest()['indicator_snapshot']['conditions'],'status')))===1,'all four required indicators pass');

// API publication lag: successful HTTP response may still omit the newest closed bar.
class DelayedIndicatorMarket extends IndicatorMarket {
 public int $failResponses=0;public int $omitResponses=0;public int $minuteCalls=0;public int $omitMinutes=0;
 function get(string $path,array $params=[]):array{
  if($this->failResponses>0){$this->failResponses--;$this->historyCalls++;throw new RuntimeException('temporary disconnect');}
  $d=parent::get($path,$params);
  if($this->omitResponses>0){$this->omitResponses--;foreach(['t','o','h','l','c','v'] as $key)array_pop($d[$key]);foreach(['t','o','h','l','c','v'] as $key)array_pop($d[$key]);}
  return $d;
 }
 function minutes(string $symbol,int $from,int $to):array{
  $this->minuteCalls++;$data=parent::minutes($symbol,$from,$to);
  if($this->omitMinutes>0){$this->omitMinutes--;unset($data[$to-60]);}return $data;
 }
}
$reset();$s=$template;$s['indicator_conditions']=$block;$api=new DelayedIndicatorMarket();$api->omitResponses=1;
$r=(new IndicatorData($api))->evaluate($s,1440001,1440001);
expectIndicator($r['passed']&&$api->historyCalls===2,'delayed closed indicator tail retried and complete response used');
$reset();$api=new DelayedIndicatorMarket();$api->omitResponses=2;$provider=new IndicatorData($api);
$r=$provider->evaluate($s,1440001,1440001);
expectIndicator(!$r['passed']&&$r['conditions'][0]['status']==='UNAVAILABLE'&&$api->historyCalls===2,'persistent lag is unavailable after exactly two attempts, never stale fallback');
$r=$provider->evaluate($s,1440001,1440001);
expectIndicator($r['passed']&&$api->historyCalls===3,'incomplete response was not cached, recovery refetches immediately');
$reset();$s['stages'][0]['transitions'][0]=['event'=>'bar_close:15m','when'=>['left'=>['candle'=>['timeframe'=>'15m','offset'=>1,'field'=>'close']],'op'=>'gt','right'=>100],'signal'=>true];
$api=new DelayedIndicatorMarket();$api->omitMinutes=1;$put($s,1440899);runCycle($api,1440900);
expectIndicator($count()===1&&$api->minuteCalls===2,'delayed entry candle retries within same cycle before issuing valid alert');
$reset();$api=new DelayedIndicatorMarket();$api->omitMinutes=2;$put($s,1440899);runCycle($api,1440900);
expectIndicator($count()===0&&$api->minuteCalls===2&&$state()['error']!==null,'persistent entry candle delay fails closed with bounded retry');
runCycle($api,1440960);expectIndicator($count()===0,'entry API recovery cannot replay consumed old event');
// Migration: discard candidates left by the earlier release.
$reset();$api=new IndicatorMarket();$put($s,1440900);$st=$state();$st['pending_signal']=['stage'=>'start','event'=>'bar_close:15m','time'=>1440900];db()->prepare('UPDATE strategies SET state=?')->execute([encode($st)]);runCycle($api,1440960);
expectIndicator($count()===0&&!isset($state()['pending_signal']),'old release pending candidates discarded without late alerts');

$reset();$s=$template;$s['indicator_conditions']=$block;$api=new DelayedIndicatorMarket();$api->failResponses=1;
$r=(new IndicatorData($api))->evaluate($s,1440001,1440001);
expectIndicator($r['passed']&&$api->historyCalls===2,'temporary disconnect recovers on bounded second fetch');
$reset();$s['stages'][0]['transitions'][0]=['event'=>'bar_close:15m','when'=>['left'=>['candle'=>['timeframe'=>'15m','offset'=>1,'field'=>'close']],'op'=>'gt','right'=>100],'signal'=>true];
$put($s,1440899);setSetting('interval','600');runCycle(new IndicatorMarket(),1441860);
$alert=json_decode(db()->query('SELECT payload FROM alerts')->fetchColumn(),true);
expectIndicator($count()===1&&$alert['event_time']===1441800,'backlog may only emit the latest closed entry event, never historical final signals');

foreach([null,['enabled'=>false]] as $legacyBlock){
 $reset();$legacy=$s;if($legacyBlock===null)unset($legacy['indicator_conditions']);else $legacy['indicator_conditions']=$legacyBlock;
 $api=new DelayedIndicatorMarket();$api->omitMinutes=1;$put($legacy,1440899);runCycle($api,1440900);
 expectIndicator($api->minuteCalls===1&&$api->historyCalls===0&&$count()===0,'legacy or disabled candle strategy keeps previous no-retry/no-indicator-request behavior');
}

class NoTradeMarket extends IndicatorMarket {
 function get(string $path,array $params=[]):array{$d=parent::get($path,$params);$d['v']=array_fill(0,count($d['t']),0.);return $d;}
 function minutes(string $symbol,int $from,int $to):array{$a=parent::minutes($symbol,$from,$to);foreach($a as &$b)$b['volume']=0.;unset($b);return $a;}
}
$reset();$s=$template;$s['indicator_conditions']=$block;$put($s,1440000);runCycle(new NoTradeMarket(),1440001);
expectIndicator($count()===0&&$latest()['indicator_snapshot']['conditions'][0]['status']==='UNAVAILABLE'&&str_contains($latest()['indicator_snapshot']['conditions'][0]['error'],'no trades'),'complete no-trade indicator history is UNAVAILABLE and cannot alert');
$reset();$s['stages'][0]['transitions'][0]=['event'=>'bar_close:4h','when'=>['left'=>['candle'=>['timeframe'=>'4h','offset'=>1,'field'=>'close']],'op'=>'gt','right'=>1],'signal'=>true];$put($s,1439999);runCycle(new NoTradeMarket(),1440000);
expectIndicator($count()===0&&str_contains($state()['error'],'UNAVAILABLE: no trades'),'strategy engine records explicit no-trade unavailability without alert');
