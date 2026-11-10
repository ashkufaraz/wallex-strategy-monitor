<?php
define('TEST_DATABASE',':memory:');
require dirname(__DIR__).'/src/App.php';
function expectIndicator(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
class IndicatorMarket extends Wallex {
 public int $historyCalls=0;public bool $fail=false;public bool $priceFail=false;public bool $gap=false;public array $requests=[];
 function price(string $symbol):float{if($this->priceFail)throw new RuntimeException('price offline');return 100.;}
 function minutes(string $symbol,int $from,int $to):array{$a=[];for($t=$from;$t+60<=$to;$t+=60)$a[$t]=['open'=>100.,'high'=>102.,'low'=>99.,'close'=>101.,'volume'=>1.];return $a;}
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
// A candle candidate with missing indicator data remains retryable until its next close.
$reset();$api=new IndicatorMarket();$api->fail=true;$s=$template;$s['indicator_conditions']=$block;
$s['stages'][0]['transitions'][0]=['event'=>'bar_close:15m','when'=>['left'=>['candle'=>['timeframe'=>'15m','offset'=>1,'field'=>'close']],'op'=>'gt','right'=>1],'signal'=>true];
$put($s,1440899);runCycle($api,1440900);
expectIndicator($count()===0&&isset($state()['pending_signal']),'unavailable candle entry stays pending');
initializeWorkerStates(1440910);expectIndicator(isset($state()['pending_signal']),'worker restart preserves indicator pending state');
$api=new IndicatorMarket();runCycle($api,1440960);
expectIndicator($count()===1&&!isset($state()['pending_signal']),'pending candle re-evaluates on next cycle after API recovery');
$alert=json_decode(db()->query('SELECT payload FROM alerts')->fetchColumn(),true);
expectIndicator($alert['event_time']===1440900&&$alert['indicators']['as_of']===1440960,'retry keeps original event key and records current closed-candle snapshot');
runCycle($api,1440960);expectIndicator($count()===1,'repeated pending retry cannot duplicate alert');
$reset();$api=new IndicatorMarket();$api->fail=true;$put($s,1440899);runCycle($api,1440900);
// Break the original entry price condition by replacing its candle with a nonmatching one on retry.
$cfg=$s;$cfg['invalidation']['right']=50;db()->prepare('UPDATE strategies SET config=?')->execute([encode($cfg)]);$api=new IndicatorMarket();runCycle($api,1440960);
expectIndicator($count()===0&&!isset($state()['pending_signal']),'invalidation clears pending entry before retry');
$reset();$api=new IndicatorMarket();$api->fail=true;$s['max_setup_seconds']=30;$put($s,1440899);$st=Strategy::fresh($s,1440899);$st['setup']=1440890;db()->prepare('UPDATE strategies SET state=?')->execute([encode($st)]);runCycle($api,1440900);$api=new IndicatorMarket();runCycle($api,1440960);
expectIndicator($count()===0&&!isset($state()['pending_signal']),'expired setup cannot emit recovered indicator alert');
$reset();$api=new IndicatorMarket();$provider=new IndicatorData($api);
foreach(['1m'=>60,'15m'=>900,'1h'=>3600,'4h'=>14400] as $tf=>$seconds){$asOf=1440000+$seconds-1;$history=$provider->history('BTCUSDT',$tf,6,$asOf,$asOf);$last=array_key_last($history);expectIndicator($last+$seconds<= $asOf&&$last===intdiv($asOf,$seconds)*$seconds-$seconds,'last_closed excludes open '.$tf.' candle');}
expectIndicator(array_column($api->requests,'resolution')===['1','1','60','60'],'verified base resolutions used for all timeframes');
expectIndicator(Strategy::$signalFilter===null&&Strategy::$onCheck===null,'callbacks cleared after cycle');
// A faster timeframe may change while the original 15m price setup stays valid.
$reset();$api=new IndicatorMarket();$s=$template;$s['indicator_conditions']=$block;$s['indicator_conditions']['conditions'][0]['period']=1;$s['indicator_conditions']['conditions'][0]['value']=341.145;
$s['stages'][0]['transitions'][0]=['event'=>'bar_close:15m','when'=>['left'=>['candle'=>['timeframe'=>'15m','offset'=>1,'field'=>'close']],'op'=>'gt','right'=>1],'signal'=>true];
$put($s,1440899);runCycle($api,1440900);expectIndicator($count()===0&&$latest()['indicator_snapshot']['conditions'][0]['status']==='FAIL','faster timeframe indicator may initially fail');
runCycle(new IndicatorMarket(),1440960);expectIndicator($count()===1,'pending price setup admits newly passing last-closed 1m indicator');
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
