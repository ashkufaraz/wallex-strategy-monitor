<?php
declare(strict_types=1);
require_once __DIR__.'/Engine.php';
require_once __DIR__.'/Diagnostics.php';
require_once __DIR__.'/IndicatorData.php';
require_once __DIR__.'/CandleAggregation.php';
function db():PDO {static $db; if($db) return $db; $db=new PDO('sqlite:'.(defined('TEST_DATABASE') && PHP_SAPI==='cli' ? TEST_DATABASE : dirname(__DIR__).'/data/app.sqlite'),null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->exec('PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL; CREATE TABLE IF NOT EXISTS strategies(id TEXT PRIMARY KEY, version TEXT NOT NULL, config TEXT NOT NULL, state TEXT NOT NULL); CREATE TABLE IF NOT EXISTS settings(k TEXT PRIMARY KEY,v TEXT NOT NULL); CREATE TABLE IF NOT EXISTS check_logs(id INTEGER PRIMARY KEY AUTOINCREMENT,strategy_id TEXT NOT NULL,version TEXT NOT NULL,checked INTEGER NOT NULL,payload TEXT NOT NULL); CREATE INDEX IF NOT EXISTS check_logs_strategy ON check_logs(strategy_id,id); CREATE TABLE IF NOT EXISTS alerts(id INTEGER PRIMARY KEY AUTOINCREMENT, unique_key TEXT UNIQUE NOT NULL, created INTEGER NOT NULL, payload TEXT NOT NULL);');return $db;}
function setting(string $key,string $default=''):string {$q=db()->prepare('SELECT v FROM settings WHERE k=?');$q->execute([$key]);$v=$q->fetchColumn();return $v===false?$default:$v;}
function setSetting(string $key,string $value):void {db()->prepare('INSERT INTO settings(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute([$key,$value]);}
function encode(mixed $x):string {return json_encode($x,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
function recordAlert(array $s,string $version,string $event,int $at,float $price,?string $cancellationReason=null,?array $indicators=null):void {$p=['strategy_id'=>$s['id'],'name'=>$s['name'],'symbol'=>$s['symbol'],'action'=>$s['action'],'price'=>$price,'event'=>$event,'event_time'=>$at];if($cancellationReason!==null){$p['type']='cancelled';$p['reason']=$cancellationReason;}if($cancellationReason===null&&isset($s['trade'])) {$p['trade']=$s['trade'];$p['trade']['entry']['reference_price']=$s['trade']['entry']['price']??$price;}if($indicators!==null)$p['indicators']=$indicators;db()->prepare('INSERT OR IGNORE INTO alerts(unique_key,created,payload) VALUES(?,?,?)')->execute([$s['id'].':'.$version.':'.$event.':'.$at,time(),encode($p)]);}
class Wallex {
 private array $cache=[];
 function __construct(private ?float $deadline=null) {}
 function withBudget(float $seconds,Closure $operation):mixed {
  $previous=$this->deadline;$this->deadline=min($previous??INF,microtime(true)+$seconds);
  try{return $operation();}finally{$this->deadline=$previous;}
 }
 protected function fetchResponse(string $url,int $remaining):string {
  $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>min(8,$remaining),CURLOPT_TIMEOUT=>min(20,$remaining),CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_ENCODING=>'',CURLOPT_USERAGENT=>'WallexStrategyAlerts/1.0']);
  $raw=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
  if($raw===false||$status!==200)throw new RuntimeException('Wallex HTTP '.$status.' '.$error);
  return $raw;
 }
 function get(string $path,array $params=[]):array {
  $url='https://api.wallex.ir'.$path.($params?'?'.http_build_query($params):'');
  $deadline=min($this->deadline??INF,microtime(true)+20);
  for($attempt=0;$attempt<2;$attempt++){
   $remaining=(int)floor($deadline-microtime(true));if($remaining<1)throw new RuntimeException('Market request time budget exceeded; retry on next check');
   $raw=$this->fetchResponse($url,$remaining);
   try{$d=json_decode($raw,true,512,JSON_THROW_ON_ERROR);}
   catch(JsonException $e){if($attempt===0)continue;throw new RuntimeException('Invalid market JSON at '.$path.': '.$e->getMessage(),0,$e);}
   if(!is_array($d))throw new RuntimeException('Invalid API response');return $d;
  }
  throw new RuntimeException('Invalid API response');
 }
 function invalidateMinuteCache():void {foreach(array_keys($this->cache) as $key)if($key!=='markets')unset($this->cache[$key]);}
 function markets():array {return $this->cache['markets']??=$this->get('/v1/markets')['result']['symbols']??throw new RuntimeException('Markets missing');}
 function price(string $symbol):float {$m=$this->markets()[$symbol]??throw new RuntimeException('Market not found: '.$symbol);if(($m['quoteAsset']??null)!=='USDT') throw new RuntimeException('Market must be USDT');$v=$m['stats']['lastPrice']??null;if(!is_numeric($v)||!is_finite((float)$v)||(float)$v<=0) throw new RuntimeException('Invalid market price');return (float)$v;}
 function minutes(string $symbol,int $from,int $to):array {$key="$symbol:$from:$to";if(isset($this->cache[$key])) return $this->cache[$key];$out=[];
 for($start=$from;$start<$to;$start+=21600) {$end=min($to,$start+21600);$d=$this->get('/v1/udf/history',['symbol'=>$symbol,'resolution'=>'1','from'=>$start,'to'=>$end]);if(($d['s']??'')!=='ok') throw new RuntimeException('Candle API returned no data');$times=$d['t']??[];if(!is_array($times)||!array_is_list($times)||!count($times)) throw new RuntimeException('Empty candles');foreach(['o','h','l','c','v'] as $f) if(!isset($d[$f])||!is_array($d[$f])||!array_is_list($d[$f])||count($d[$f])!==count($times)) throw new RuntimeException('Unequal candle arrays');
 foreach($times as $i=>$t) {if(!is_numeric($t)||!is_finite((float)$t)||(float)(int)$t!==(float)$t||(int)$t%60!==0) throw new RuntimeException('Invalid minute timestamp');$t=(int)$t;if($t<$from||$t+60>$to) continue;$bar=[];foreach(['o'=>'open','h'=>'high','l'=>'low','c'=>'close','v'=>'volume'] as $short=>$field) {if(!is_numeric($d[$short][$i])||!is_finite((float)$d[$short][$i])) throw new RuntimeException('Invalid OHLC value');$bar[$field]=(float)$d[$short][$i];}if(min($bar['open'],$bar['high'],$bar['low'],$bar['close'])<=0||$bar['volume']<0||$bar['high']<max($bar['open'],$bar['close'],$bar['low'])||$bar['low']>min($bar['open'],$bar['close'])) throw new RuntimeException('Invalid OHLC range');if(isset($out[$t])&&$out[$t]!==$bar) throw new RuntimeException('Conflicting candles');$out[$t]=$bar;}}
 ksort($out);return $this->cache[$key]=$out;
 }
 static function aggregate(array $minutes,int $seconds):array {return CandleAggregation::aggregate($minutes,60,$seconds);}
}
function lookback(mixed $x):int {if(!is_array($x)) return 0;$n=0;if(isset($x['candle'])&&is_array($x['candle'])) $n=$x['candle']['offset']*Strategy::TIMEFRAMES[$x['candle']['timeframe']];foreach($x as $v) $n=max($n,lookback($v));return $n;}
function strategyTimeframes(array $s):array {
 $used=[];
 $rules=$s['cancellation']??[];
 foreach($s['stages'] as $stage) foreach($stage['transitions'] as $rule)$rules[]=$rule;
 foreach($rules as $rule) if(str_starts_with($rule['event'],'bar_close:'))$used[substr($rule['event'],10)]=true;
 return array_intersect_key(Strategy::TIMEFRAMES,$used);
}
function initializeWorkerStates(int $now):void {
 foreach(db()->query('SELECT * FROM strategies')->fetchAll(PDO::FETCH_ASSOC) as $r) {
 if(isset(json_decode($r['state'],true)['cancelled_at'])) continue;
 $s=json_decode($r['config'],true);
 if(Indicators::enabled($s))continue;
 db()->prepare('UPDATE strategies SET state=? WHERE id=? AND version=? AND state=?')->execute([encode(Strategy::fresh($s,$now)),$r['id'],$r['version'],$r['state']]);
 }
}
function runCycle(Wallex $api,int $now,?string $onlyId=null):void {
 $indicatorData=new IndicatorData($api);
 foreach(db()->query('SELECT * FROM strategies')->fetchAll(PDO::FETCH_ASSOC) as $row) {
 if($onlyId!==null&&$row['id']!==$onlyId)continue;
 $s=json_decode($row['config'],true);if(!$s['enabled']) continue;$original=json_decode($row['state'],true);$st=$original;if(isset($st['cancelled_at'])) continue;$events=[];$price=null;$bars=[];$trace=[];$indicatorReports=[];
 $recoveringGap=false;
 $withIndicators=Indicators::enabled($s);if($withIndicators)unset($st['pending_signal']);
 Strategy::$signalFilter=$withIndicators?function($config,$event,$at,$ctx)use($indicatorData,$now,&$indicatorReports,&$st){
  if(str_starts_with($event,'bar_close:')){
   $sec=Strategy::TIMEFRAMES[substr($event,10)];
   if($at!==intdiv($now,$sec)*$sec)return false;
  }
  $snapshot=$indicatorData->evaluate($config,$at,$now);$indicatorReports[]=['event'=>$event,'event_time'=>$at,'snapshot'=>$snapshot];$st['indicators']=$snapshot;return $snapshot['passed'];
 }:null;
 Strategy::$onCheck=function($kind,$event,$condition,$ctx,$meta)use(&$trace){if(count($trace)<100)$trace[]=array_merge(['kind'=>$kind,'event'=>$event,'candle_close'=>$ctx['close']??null,'condition'=>Diagnostics::inspect($condition,$ctx)],$meta);};
 try {$price=$api->price($s['symbol']);if(!$withIndicators){if($st['error']!==null || $now-$st['cursor']>max(180,3*(int)setting('interval','60'))) $st=Strategy::fresh($s,$now);}
 elseif($now-$st['cursor']>max(180,3*(int)setting('interval','60'))){$st['cursor']=$now;$recoveringGap=true;}$active=$st['setup']!==null;
 $cancelReason=Strategy::cancellation($s,'price',['price'=>$price]);
 if($cancelReason!==null) {$st=Strategy::fresh($s,$now);$st['cancelled_at']=$now;$st['cancel_reason']=$cancelReason;$events[]=['event'=>'cancelled:price','time'=>$now,'reason'=>$cancelReason];}
 else {
 Strategy::trace('invalidation','price',$s['invalidation'],['price'=>$price]);
 $blocked=Strategy::check($s['invalidation'],['price'=>$price]) || ($active && $now-$st['setup']>$s['max_setup_seconds']);
 if($blocked){$st['stage']=$s['initial_stage'];$st['setup']=null;if($withIndicators)unset($st['pending_signal']);}
 $timeframes=strategyTimeframes($s);$alignment=$timeframes?max($timeframes):3600;
 $from=max(0,intdiv(min($st['cursor'],$now)-max(lookback($s),$alignment)-$alignment,$alignment)*$alignment);
 $minutes=[];
 if($timeframes){
  if(!$withIndicators)$minutes=$api->minutes($s['symbol'],$from,intdiv($now,60)*60);
  else $minutes=$api->withBudget(12,function()use($api,$s,$from,$now,$timeframes){
   for($attempt=0;$attempt<2;$attempt++)try{
    $data=$api->minutes($s['symbol'],$from,intdiv($now,60)*60);
    foreach($timeframes as $tf=>$sec){$end=intdiv($now,$sec)*$sec;
     for($t=$end-$sec;$t<$end;$t+=60)if(!isset($data[$t]))throw new RuntimeException('Delayed or missing entry candle '.$tf.' at '.$t);
    }
    return $data;
   }catch(Throwable $e){if($attempt===1)throw $e;$api->invalidateMinuteCache();usleep(200000);}
   throw new RuntimeException('Entry history unavailable');
  });
 }$bars=[];foreach($timeframes as $tf=>$seconds)$bars[$tf]=Wallex::aggregate($minutes,$seconds);
 // Only events after activation/cursor. Each expected closed candle must exist.
 $timeline=[];foreach($timeframes as $tf=>$sec) for($close=(intdiv($st['cursor'],$sec)+1)*$sec;$close<=$now;$close+=$sec) {$timeline[]=['time'=>$close,'tf'=>$tf,'seconds'=>$sec];}
 usort($timeline,fn($a,$b)=>($a['time']<=>$b['time'])?:($b['seconds']<=>$a['seconds']));
 foreach($timeline as $e) {if(!isset($bars[$e['tf']][$e['time']-$e['seconds']])){$why=CandleAggregation::inspect($minutes,60,$e['seconds'])['unavailable'][$e['time']-$e['seconds']]??('Incomplete '.$e['tf'].' candle; retry pending');throw new RuntimeException($why);}if($st['setup']!==null && $e['time']-$st['setup']>$s['max_setup_seconds']) {$st['stage']=$s['initial_stage'];$st['setup']=null;if($withIndicators)unset($st['pending_signal']);}
 if($withIndicators&&isset($st['pending_signal'])&&$st['pending_signal']['event']==='bar_close:'.$e['tf']&&$e['time']>$st['pending_signal']['time'])unset($st['pending_signal']);
 if(($reason=Strategy::cancellation($s,'bar_close:'.$e['tf'],['close'=>$e['time'],'seconds'=>$e['seconds'],'bars'=>$bars[$e['tf']]]))!==null){$st=Strategy::fresh($s,$now);$st['cancelled_at']=$e['time'];$st['cancel_reason']=$reason;$events=[['event'=>'cancelled:bar_close:'.$e['tf'],'time'=>$e['time'],'reason'=>$reason]];break;}
 if(!$blocked&&Strategy::event($s,$st,'bar_close:'.$e['tf'],$e['time'],['close'=>$e['time'],'seconds'=>$e['seconds'],'bars'=>$bars[$e['tf']]])) $events[]=['event'=>'bar_close:'.$e['tf'],'time'=>$e['time'],'indicators'=>$withIndicators?($st['indicators']??null):null];}
 if($withIndicators&&$recoveringGap&&!isset($st['cancelled_at']))foreach($timeframes as $tf=>$sec){
  $close=intdiv($now,$sec)*$sec;
  if(($reason=Strategy::cancellation($s,'bar_close:'.$tf,['close'=>$close,'seconds'=>$sec,'bars'=>$bars[$tf]]))!==null){$st=Strategy::fresh($s,$now);$st['cancelled_at']=$close;$st['cancel_reason']=$reason;$events=[['event'=>'cancelled:bar_close:'.$tf,'time'=>$close,'reason'=>$reason]];break;}
 }
 if(!$blocked&&!isset($st['cancelled_at'])&&Strategy::event($s,$st,'price',$now,['price'=>$price])) $events[]=['event'=>'price','time'=>$now,'indicators'=>$withIndicators?($st['indicators']??null):null];$st['cursor']=$now;
 }
 $st['error']=null;$st['last_check']=$now;$st['last_price']=$price;$st['price_at']=$now;
 $report=['action'=>$s['action'],'checked'=>$now,'price'=>$price,'from_stage'=>$original['stage'],'stage'=>$st['stage'],'status'=>isset($st['cancelled_at'])?'cancelled':($events?'signal':($blocked??false?'blocked':'checked')),'trace'=>$trace,'waiting'=>Diagnostics::waiting($s,$st,$bars,$now,$price),'trade'=>$s['trade']??null,'events'=>$events];
 if($withIndicators){$report['indicators']=$indicatorReports;$report['indicator_snapshot']=$st['indicators']??null;if(!$events&&$indicatorReports)$report['status']='indicator_blocked';}
 // Optimistic comparison prevents a concurrent edit from being overwritten.
 db()->beginTransaction();$q=db()->prepare('UPDATE strategies SET state=? WHERE id=? AND version=? AND state=?');$q->execute([encode($st),$s['id'],$row['version'],$row['state']]);if($q->rowCount()){foreach($events as $event) recordAlert($s,$row['version'],$event['event'],$event['time'],$price,$event['reason']??null,$event['indicators']??null);Diagnostics::save($s['id'],$row['version'],$report);}db()->commit();
 } catch(Throwable $e) {if(db()->inTransaction()) db()->rollBack();$original['error']=$e->getMessage();if($withIndicators){$original['cursor']=$now;unset($original['pending_signal']);}$original['last_check']=$now;db()->beginTransaction();$q=db()->prepare('UPDATE strategies SET state=? WHERE id=? AND version=? AND state=?');$q->execute([encode($original),$s['id'],$row['version'],$row['state']]);if($q->rowCount())Diagnostics::save($s['id'],$row['version'],['action'=>$s['action'],'checked'=>$now,'price'=>$price,'from_stage'=>$original['stage'],'stage'=>$original['stage'],'status'=>'error','error'=>$e->getMessage(),'trace'=>$trace,'waiting'=>[],'trade'=>$s['trade']??null]);db()->commit();}finally{Strategy::$onCheck=null;Strategy::$signalFilter=null;}
 }
}
