<?php
declare(strict_types=1);
require_once __DIR__.'/MarketData.php';
require_once __DIR__.'/Wallex.php';
require_once __DIR__.'/Engine.php';
require_once __DIR__.'/NobitexMarketDataProvider.php';
require_once __DIR__.'/Diagnostics.php';
require_once __DIR__.'/IndicatorData.php';
require_once __DIR__.'/CandleAggregation.php';
require_once __DIR__.'/MarketArchitecture.php';
require_once __DIR__.'/BinanceMarketDataProvider.php';
require_once __DIR__.'/BinanceFuturesMarketDataProvider.php';
require_once __DIR__.'/WallexExecutionMarketProvider.php';
require_once __DIR__.'/ExecutionGate.php';
require_once __DIR__.'/ExecutionMonitor.php';
require_once __DIR__.'/OrderFlowConfig.php';
require_once __DIR__.'/OrderFlowMarketData.php';
require_once __DIR__.'/OrderFlowAdvisory.php';
require_once __DIR__.'/SystemLog.php';
require_once __DIR__.'/Accounts.php';
require_once __DIR__.'/SmsFallback.php';
require_once __DIR__.'/MobileAlerts.php';
require_once __DIR__.'/ScannerService.php';
require_once __DIR__.'/StrategyStore.php';
require_once __DIR__.'/StrategyOutcomes.php';
function db():PDO {static $db; if($db) return $db; $db=new PDO('sqlite:'.(defined('TEST_DATABASE') && PHP_SAPI==='cli' ? TEST_DATABASE : dirname(__DIR__).'/data/app.sqlite'),null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->exec('PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL; CREATE TABLE IF NOT EXISTS strategies(id TEXT PRIMARY KEY, version TEXT NOT NULL, config TEXT NOT NULL, state TEXT NOT NULL); CREATE TABLE IF NOT EXISTS alert_archives(alert_id INTEGER PRIMARY KEY); CREATE TABLE IF NOT EXISTS settings(k TEXT PRIMARY KEY,v TEXT NOT NULL); CREATE TABLE IF NOT EXISTS check_logs(id INTEGER PRIMARY KEY AUTOINCREMENT,strategy_id TEXT NOT NULL,version TEXT NOT NULL,checked INTEGER NOT NULL,payload TEXT NOT NULL); CREATE INDEX IF NOT EXISTS check_logs_strategy ON check_logs(strategy_id,id); CREATE TABLE IF NOT EXISTS alerts(id INTEGER PRIMARY KEY AUTOINCREMENT, unique_key TEXT UNIQUE NOT NULL, created INTEGER NOT NULL, payload TEXT NOT NULL);');accountSchema($db);SmsFallback::migrate($db);StrategyOutcomes::migrate($db);return $db;}
function setting(string $key,string $default=''):string {$q=db()->prepare('SELECT v FROM settings WHERE k=?');$q->execute([$key]);$v=$q->fetchColumn();return $v===false?$default:$v;}
function setSetting(string $key,string $value):void {db()->prepare('INSERT INTO settings(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute([$key,$value]);}
function encode(mixed $x):string {return json_encode($x,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
function recordAlert(array $s,string $version,string $event,int $at,float $price,?string $cancellationReason=null,?array $indicators=null):void {$p=['strategy_created_at'=>StrategyOutcomes::created($s['id']),'strategy_version'=>$version,'exchange'=>$s['exchange']??'wallex','analysis_sources'=>$s['analysis_sources']??[],'strategy_id'=>$s['id'],'name'=>$s['name'],'symbol'=>$s['symbol'],'action'=>$s['action'],'price'=>$price,'event'=>$event,'event_time'=>$at];if($cancellationReason!==null){$p['type']='cancelled';$p['reason']=$cancellationReason;}if($cancellationReason===null&&isset($s['trade'])) {$p['trade']=$s['trade'];$p['trade']['entry']['reference_price']=$s['trade']['entry']['price']??$price;}if($indicators!==null)$p['indicators']=$indicators;db()->prepare('INSERT OR IGNORE INTO alerts(unique_key,created,payload) VALUES(?,?,?)')->execute([$s['id'].':'.$version.':'.$event.':'.$at,time(),encode($p)]);$q=db()->prepare('SELECT id,created FROM alerts WHERE unique_key=?');$q->execute([$s['id'].':'.$version.':'.$event.':'.$at]);$r=$q->fetch(PDO::FETCH_ASSOC);StrategyOutcomes::capture((int)$r['id'],$p,(int)$r['created']);}
// Look up the current version only; cancellation is not an entry signal.
function recordEntryOnlyAlert(array $s,string $version,array $candidate,float $price,int $now,?string $provider=null):void {
 $event=$candidate['event'];$at=$candidate['time'];
 // Price conditions stay true between polls: notify at most once per uninterrupted match.
 $episode=$event.':'.($candidate['episode']??$at);
 $key=$s['id'].':'.$version.':entry_conditions_only:'.($candidate['stage']??'').':'.($candidate['rule']??'').':'.$episode;
 $p=['strategy_created_at'=>StrategyOutcomes::created($s['id']),'strategy_version'=>$version,'exchange'=>$s['exchange']??'wallex','analysis_sources'=>$s['analysis_sources']??[],'entry_evidence'=>$candidate['evidence']??[],'entry_stage'=>$candidate['stage']??null,'entry_rule'=>$candidate['rule']??null,'type'=>'entry_conditions_only','strategy_id'=>$s['id'],'name'=>$s['name'],'symbol'=>$s['symbol'],'action'=>$s['action'],'price'=>$price,'event'=>$event,'event_time'=>$at,'indicators'=>$candidate['snapshot'],'reason'=>'شرط‌های یک مسیر مجاز ورود، بدون تأیید اندیکاتورها، در زمان صدور هشدار برقرار بوده‌اند. مسیرهای جایگزین لازم نیست هم‌زمان تأیید شوند. جزئیات استراتژی وضعیت فعلی را نشان می‌دهد؛ این پیام تأیید نهایی ورود نیست.'];
 if(isset($s['trade'])){$p['evaluation_trade']=$s['trade'];$p['evaluation_trade']['entry']['reference_price']=$s['trade']['entry']['price']??$price;}
 $p['analysis_provider']=$provider??($s['exchange']??'wallex');
 db()->prepare('INSERT OR IGNORE INTO alerts(unique_key,created,payload) VALUES(?,?,?)')->execute([$key,$now,encode($p)]);
 $q=db()->prepare('SELECT id,created FROM alerts WHERE unique_key=?');$q->execute([$key]);$r=$q->fetch(PDO::FETCH_ASSOC);StrategyOutcomes::capture((int)$r['id'],$p,(int)$r['created']);
}
function strategyLastSignal(array $row):?array {
 $prefix=$row['id'].':'.$row['version'].':';
 $q=db()->prepare('SELECT id,created,payload FROM alerts WHERE substr(unique_key,1,?)=? ORDER BY id DESC');$q->execute([strlen($prefix),$prefix]);
 while($r=$q->fetch(PDO::FETCH_ASSOC)){$p=json_decode($r['payload'],true);if(in_array($p['type']??'',['cancelled','entry_conditions_only'],true))continue;return ['id'=>(int)$r['id'],'created'=>(int)$r['created'],'payload'=>$p];}
 return null;
}

function lookback(mixed $x,?string $timeframe=null):int {if(!is_array($x)) return 0;$n=0;if(isset($x['candle'])&&is_array($x['candle'])&&($timeframe===null||$x['candle']['timeframe']===$timeframe)) $n=$x['candle']['offset']*Strategy::TIMEFRAMES[$x['candle']['timeframe']];foreach($x as $v) $n=max($n,lookback($v,$timeframe));return $n;}
function nativeEntryFrom(array $s,string $tf,int $cursor,int $now,string $exchange):int {$sec=Strategy::TIMEFRAMES[$tf];return max(0,min(MarketData::boundary($cursor,$sec,$exchange),MarketData::boundary($now,$sec,$exchange)-$sec)-max(0,lookback($s,$tf)-$sec));}
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
function persistCycleState(array $row,array $state,bool $binance):bool {
 if($binance){$q=db()->prepare("UPDATE analysis_states SET state=? WHERE strategy_id=? AND version=? AND provider='binance_spot' AND state=? AND EXISTS(SELECT 1 FROM strategies WHERE id=? AND version=?)");$q->execute([encode($state),$row['id'],$row['version'],$row['state'],$row['id'],$row['version']]);}
 else{$q=db()->prepare('UPDATE strategies SET state=? WHERE id=? AND version=? AND state=?');$q->execute([encode($state),$row['id'],$row['version'],$row['state']]);}
 return $q->rowCount()>0;
}
function displayedState(array $row,array $architecture):array {
 if((json_decode($row['config'],true)['exchange']??'wallex')!=='wallex'||!$architecture['analysis_enabled']||!MarketArchitecture::approved($architecture,$row['id'],$row['version']))return json_decode($row['state'],true);
 if(!db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='analysis_states'")->fetchColumn())return Strategy::fresh(json_decode($row['config'],true),time());
 $q=db()->prepare("SELECT state FROM analysis_states WHERE strategy_id=? AND version=? AND provider='binance_spot'");$q->execute([$row['id'],$row['version']]);$state=$q->fetchColumn();return $state===false?Strategy::fresh(json_decode($row['config'],true),time()):json_decode($state,true);
}
function runCycle(Wallex $api,int $now,?string $onlyId=null):void {
 $architecture=MarketArchitecture::config();
 if($architecture['analysis_enabled']){
  if(!$api instanceof BinanceMarketDataProvider)$api=new BinanceMarketDataProvider(null,null,microtime(true)+($onlyId===null?30:15));
  ExecutionGate::migrate(db());
 }
 $providedApi=$api;$providers=['wallex'=>$api];$indicatorProviders=[];
 foreach(db()->query('SELECT * FROM strategies')->fetchAll(PDO::FETCH_ASSOC) as $row) {
 if($onlyId!==null&&$row['id']!==$onlyId)continue;
 $s=json_decode($row['config'],true);$exchange=MarketScope::exchange($s['exchange']??'wallex');$useAnalysis=$architecture['analysis_enabled']&&$exchange==='wallex';$api=($providedApi->exchange()===$exchange||($exchange==='wallex'&&$providedApi->exchange()==='binance_spot'))?$providedApi:($providers[$exchange]??=MarketData::provider($exchange));$indicatorData=$indicatorProviders[$exchange]??=new IndicatorData($api);if(!$s['enabled']) continue;$original=json_decode($row['state'],true);$st=$original;if(isset($st['cancelled_at'])) continue;$events=[];$price=null;$bars=[];$trace=[];$indicatorReports=[];
 if($useAnalysis){
  if(!MarketArchitecture::approved($architecture,$row['id'],$row['version']))continue;
  db()->prepare("INSERT OR IGNORE INTO analysis_states VALUES(?,?,?,?)")->execute([$row['id'],$row['version'],'binance_spot',encode(Strategy::fresh($s,$now))]);
  $q=db()->prepare("SELECT state FROM analysis_states WHERE strategy_id=? AND version=? AND provider='binance_spot'");$q->execute([$row['id'],$row['version']]);$row['state']=$q->fetchColumn();$original=json_decode($row['state'],true);$st=$original;
  if(isset($st['cancelled_at']))continue;
  if(ExecutionMonitor::pending(db(),$row['id'],$row['version'])){$st['last_check']=$now;$st['cursor']=$now;persistCycleState($row,$st,true);continue;}
 }
 $recoveringGap=false;$entryOnly=[];
 $withIndicators=Indicators::enabled($s);if($withIndicators)unset($st['pending_signal']);
 Strategy::$signalFilter=$withIndicators?function($config,$event,$at,$ctx)use($indicatorData,$now,$exchange,&$indicatorReports,&$st,&$entryOnly){
  if(str_starts_with($event,'bar_close:')){
   $sec=Strategy::TIMEFRAMES[substr($event,10)];
   if($at!==MarketData::boundary($now,$sec,$exchange))return false;
  }
  $snapshot=$indicatorData->evaluate($config,$at,$now);$indicatorReports[]=['event'=>$event,'event_time'=>$at,'snapshot'=>$snapshot];$st['indicators']=$snapshot;if(!$snapshot['passed']){if($event==='price')$st['entry_only_price_at']??=$at;$evidence=array_values($st['completed_rules']??[]);$trigger=null;
   foreach($config['stages'] as $stage)if($stage['id']===$st['stage'])foreach($stage['transitions'] as $i=>$rule)if($rule['event']===$event&&isset($rule['signal'])&&Strategy::check($rule['when'],$ctx)){$trigger=['stage'=>$stage['id'],'rule'=>$i+1,'event'=>$event,'kind'=>'transition','to'=>'signal','checked'=>$at,'condition'=>Diagnostics::inspect($rule['when'],$ctx)];break;}
   if($trigger)$evidence[]=$trigger;
   $entryOnly[]=['event'=>$event,'time'=>$at,'episode'=>$event==='price'?$st['entry_only_price_at']:$at,'snapshot'=>$snapshot,'stage'=>$st['stage'],'rule'=>$trigger['rule']??null,'evidence'=>$evidence];}return $snapshot['passed'];
 }:null;
 Strategy::$onCheck=function($kind,$event,$condition,$ctx,$meta)use(&$trace,&$st,&$now){
  $record=array_merge(['kind'=>$kind,'event'=>$event,'candle_close'=>$ctx['close']??null,'condition'=>Diagnostics::inspect($condition,$ctx)],$meta);
  if(count($trace)<100)$trace[]=$record;
  if($kind==='transition'&&($meta['to']??'signal')!=='signal'&&$record['condition']['result']===true)
   $st['completed_rules'][$meta['stage'].':'.$meta['rule']]=$record+['checked'=>$now];
 };
 try {$price=$api->price($s['symbol']);if($api instanceof BinanceMarketDataProvider)$now=max($now,$api->now());if(!$withIndicators){if($st['error']!==null || $now-$st['cursor']>max(180,3*(int)setting('interval','60'))) $st=Strategy::fresh($s,$now);}
 elseif($now-$st['cursor']>max(180,3*(int)setting('interval','60'))){$st['cursor']=$now;$recoveringGap=true;}$active=$st['setup']!==null;
 $cancelReason=Strategy::cancellation($s,'price',['price'=>$price]);
 if($cancelReason!==null) {$st=Strategy::fresh($s,$now);$st['cancelled_at']=$now;$st['cancel_reason']=$cancelReason;$events[]=['event'=>'cancelled:price','time'=>$now,'reason'=>$cancelReason];}
 else {
 Strategy::trace('invalidation','price',$s['invalidation'],['price'=>$price]);
 $blocked=Strategy::check($s['invalidation'],['price'=>$price]) || ($active && $now-$st['setup']>$s['max_setup_seconds']);
 if($blocked){$st['stage']=$s['initial_stage'];$st['setup']=null;unset($st['completed_rules']);if($withIndicators)unset($st['pending_signal']);}
 $timeframes=strategyTimeframes($s);$alignment=$timeframes?max($timeframes):3600;
 $from=max(0,intdiv(min($st['cursor'],$now)-max(lookback($s),$alignment)-$alignment,$alignment)*$alignment);
 $minutes=[];
 if($timeframes&&!$api instanceof NobitexMarketDataProvider&&!$api instanceof BinanceFuturesMarketDataProvider){
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
 }$bars=[];foreach($timeframes as $tf=>$seconds)$bars[$tf]=($api instanceof BinanceMarketDataProvider||$api instanceof NobitexMarketDataProvider)?$api->candles($s['symbol'],$tf,$api instanceof NobitexMarketDataProvider?nativeEntryFrom($s,$tf,$st['cursor'],$now,$exchange):MarketData::boundary($from,$seconds,$exchange),MarketData::boundary($now,$seconds,$exchange)):Wallex::aggregate($minutes,$seconds);
 // Only events after activation/cursor. Each expected closed candle must exist.
 $timeline=[];foreach($timeframes as $tf=>$sec) for($close=MarketData::boundary($st['cursor'],$sec,$exchange)+$sec;$close<=$now;$close+=$sec) {$timeline[]=['time'=>$close,'tf'=>$tf,'seconds'=>$sec];}
 usort($timeline,fn($a,$b)=>($a['time']<=>$b['time'])?:($b['seconds']<=>$a['seconds']));
 foreach($timeline as $e) {if(!isset($bars[$e['tf']][$e['time']-$e['seconds']])){$why=CandleAggregation::inspect($minutes,60,$e['seconds'])['unavailable'][$e['time']-$e['seconds']]??('Incomplete '.$e['tf'].' candle; retry pending');throw new RuntimeException($why);}if($st['setup']!==null && $e['time']-$st['setup']>$s['max_setup_seconds']) {$st['stage']=$s['initial_stage'];$st['setup']=null;unset($st['completed_rules']);if($withIndicators)unset($st['pending_signal']);}
 if($withIndicators&&isset($st['pending_signal'])&&$st['pending_signal']['event']==='bar_close:'.$e['tf']&&$e['time']>$st['pending_signal']['time'])unset($st['pending_signal']);
 if(($reason=Strategy::cancellation($s,'bar_close:'.$e['tf'],['close'=>$e['time'],'seconds'=>$e['seconds'],'bars'=>$bars[$e['tf']]]))!==null){$st=Strategy::fresh($s,$now);$st['cancelled_at']=$e['time'];$st['cancel_reason']=$reason;$events=[['event'=>'cancelled:bar_close:'.$e['tf'],'time'=>$e['time'],'reason'=>$reason]];break;}
 if(!$blocked&&Strategy::event($s,$st,'bar_close:'.$e['tf'],$e['time'],['close'=>$e['time'],'seconds'=>$e['seconds'],'bars'=>$bars[$e['tf']]])) $events[]=['event'=>'bar_close:'.$e['tf'],'time'=>$e['time'],'indicators'=>$withIndicators?($st['indicators']??null):null];}
 if($withIndicators&&$recoveringGap&&!isset($st['cancelled_at']))foreach($timeframes as $tf=>$sec){
  $close=MarketData::boundary($now,$sec,$exchange);
  if(($reason=Strategy::cancellation($s,'bar_close:'.$tf,['close'=>$close,'seconds'=>$sec,'bars'=>$bars[$tf]]))!==null){$st=Strategy::fresh($s,$now);$st['cancelled_at']=$close;$st['cancel_reason']=$reason;$events=[['event'=>'cancelled:bar_close:'.$tf,'time'=>$close,'reason'=>$reason]];break;}
 }
 if(!$blocked&&!isset($st['cancelled_at'])&&Strategy::event($s,$st,'price',$now,['price'=>$price])) $events[]=['event'=>'price','time'=>$now,'indicators'=>$withIndicators?($st['indicators']??null):null];$st['cursor']=$now;
 }
 if(!array_filter($entryOnly,fn($candidate)=>$candidate['event']==='price'))unset($st['entry_only_price_at']);
 $st['error']=null;$st['last_check']=$now;$st['last_price']=$price;$st['price_at']=$now;
 $report=['exchange'=>$exchange,'analysis_provider'=>$api instanceof BinanceMarketDataProvider?$api->exchange():$exchange,'action'=>$s['action'],'checked'=>$now,'price'=>$price,'from_stage'=>$original['stage'],'stage'=>$st['stage'],'status'=>isset($st['cancelled_at'])?'cancelled':($events?'signal':($blocked??false?'blocked':'checked')),'trace'=>$trace,'waiting'=>Diagnostics::waiting($s,$st,$bars,$now,$price),'trade'=>$s['trade']??null,'events'=>$events];
 if($withIndicators){$report['indicators']=$indicatorReports;$report['indicator_snapshot']=$st['indicators']??null;if(!$events&&$indicatorReports)$report['status']='indicator_blocked';}
 // Optimistic comparison prevents a concurrent edit from being overwritten.
 db()->beginTransaction();if(persistCycleState($row,$st,$useAnalysis)){if(!isset($st['cancelled_at']))foreach($entryOnly as $candidate)recordEntryOnlyAlert($s,$row['version'],$candidate,$price,$now,$api->exchange());foreach($events as $event){if($useAnalysis&&!isset($event['reason']))ExecutionGate::create(db(),$s,$row['version'],$event['event'],$event['time'],str_starts_with($event['event'],'bar_close:')?$bars[substr($event['event'],10)][$event['time']-Strategy::TIMEFRAMES[substr($event['event'],10)]]['close']:$price,$event['indicators']??null,$architecture,$now);else recordAlert($s,$row['version'],$event['event'],$event['time'],$price,$event['reason']??null,$event['indicators']??null);}Diagnostics::save($s['id'],$row['version'],$report);}db()->commit();if($architecture['analysis_enabled']&&$events)OrderFlowAdvisory::afterSignals(db(),$now);
 } catch(Throwable $e) {if(db()->inTransaction()) db()->rollBack();$original['error']=$e->getMessage();if($withIndicators){$original['cursor']=$now;unset($original['pending_signal']);}$original['last_check']=$now;db()->beginTransaction();if(persistCycleState($row,$original,$useAnalysis))Diagnostics::save($s['id'],$row['version'],['exchange'=>$exchange,'analysis_provider'=>$api instanceof BinanceMarketDataProvider?$api->exchange():$exchange,'action'=>$s['action'],'checked'=>$now,'price'=>$price,'from_stage'=>$original['stage'],'stage'=>$original['stage'],'status'=>'error','error'=>$e->getMessage(),'trace'=>$trace,'waiting'=>[],'trade'=>$s['trade']??null]);db()->commit();}finally{Strategy::$onCheck=null;Strategy::$signalFilter=null;}
 }
 StrategyOutcomes::importExisting();StrategyOutcomes::tick($now,$onlyId,$providedApi);
 if($architecture['analysis_enabled'])ExecutionMonitor::run($providedApi,$now,$architecture);
}
