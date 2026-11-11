<?php
declare(strict_types=1);

final class OrderFlowAdvisory {
 static function migrate(PDO $db):void{$db->exec('CREATE TABLE IF NOT EXISTS order_flow_jobs(id TEXT PRIMARY KEY,signal_id TEXT NOT NULL,kind TEXT NOT NULL,target INTEGER NOT NULL,created INTEGER NOT NULL,next_at INTEGER NOT NULL,lease INTEGER NOT NULL DEFAULT 0,status TEXT NOT NULL,payload TEXT NOT NULL);
 CREATE TABLE IF NOT EXISTS order_flow_results(job_id TEXT PRIMARY KEY,signal_id TEXT NOT NULL,strategy_id TEXT NOT NULL,version TEXT NOT NULL,kind TEXT NOT NULL,target INTEGER NOT NULL,payload TEXT NOT NULL);
 CREATE INDEX IF NOT EXISTS order_flow_signal ON order_flow_results(signal_id,kind,target);
 CREATE TABLE IF NOT EXISTS order_flow_meta(k TEXT PRIMARY KEY,v TEXT NOT NULL);
 CREATE TABLE IF NOT EXISTS order_flow_visibility(strategy_id TEXT PRIMARY KEY,visible INTEGER NOT NULL);');}
 static function exists(PDO $db,string $table):bool{$q=$db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);return (bool)$q->fetchColumn();}
 static function queue(PDO $db,array $signal,string $kind,int $target,array $c,int $now):void {
  $id=hash('sha256',encode([$signal['id'],$kind,$kind==='SIGNAL'?0:$target]));
  $p=['signal'=>$signal,'config'=>$c,'phase'=>'time','rows'=>[],'pages'=>0,'end'=>intdiv($target,60)*60];
  $db->prepare('INSERT OR IGNORE INTO order_flow_jobs(id,signal_id,kind,target,created,next_at,status,payload) VALUES(?,?,?,?,?,?,?,?)')->execute([$id,$signal['id'],$kind,$target,$now,$now,'PENDING',encode($p)]);
 }
 static function discover(PDO $db,array $c,int $now):void {
  if(!$c['enabled']||!self::exists($db,'execution_signals'))return;self::migrate($db);
  $q=$db->prepare('SELECT payload FROM execution_signals WHERE signal_time>=? ORDER BY signal_time DESC LIMIT 100');$q->execute([$now-900]);
  foreach($q->fetchAll(PDO::FETCH_COLUMN) as $raw){$p=json_decode($raw,true);if(!OrderFlowConfig::forStrategy($c,$p['strategy_id']))continue;
   self::queue($db,$p,'SIGNAL',$p['signal_time'],$c,$now);
   if(!in_array($p['status'],ExecutionGate::TERMINAL,true)&&$now-$p['signal_time']>=$c['refresh_seconds'])self::queue($db,$p,'LATEST',intdiv($now,$c['refresh_seconds'])*$c['refresh_seconds'],$c,$now);
  }
 }
 // Only enqueues local work after the primary transaction. Never fetches a price.
 static function afterSignals(PDO $db,int $now):void{try{$c=OrderFlowConfig::get();if($c['enabled'])self::discover($db,$c,$now);}catch(Throwable $e){/* Auxiliary configuration/storage failure must not affect signals. */}}
 static function unavailable(array $p,array $job,int $now,string $reason):array{return ['symbol'=>$p['signal']['symbol'],'provider'=>'binance_spot','signal_id'=>$p['signal']['id'],'strategy_id'=>$p['signal']['strategy_id'],'version'=>$p['signal']['version'],'kind'=>$job['kind'],'signal_time'=>$p['signal']['signal_time'],'calculated_at'=>$now,'trade_window_start'=>$p['end']-max($p['config']['windows']),'trade_window_end'=>$p['end'],'trade_count'=>count($p['rows']),'data_completeness'=>'UNVERIFIED','indicator_values'=>[],'advisory_status'=>'UNAVAILABLE','explanation'=>$reason,'policy'=>$p['config'],'depth_received_at'=>$p['depth_received_at']??null,'book_event_timestamp'=>null];}
 static function trade(array $row,?array $previous=null):array {
  foreach(['a','f','l','T'] as $key)if(!is_int($row[$key]??null)||$row[$key]<0)throw new RuntimeException('Invalid aggregate ID or timestamp');
  if(!is_bool($row['m']??null)||$row['l']<$row['f'])throw new RuntimeException('Invalid taker side or raw trade range');
  $price=BinanceMarketDataProvider::number($row['p']??null);$quantity=BinanceMarketDataProvider::number($row['q']??null);if($price<=0||$quantity<=0)throw new RuntimeException('Invalid aggregate price/quantity');
  if($previous&&($row['a']!==$previous['a']+1||$row['T']<$previous['T']||$row['f']<=$previous['l']))throw new RuntimeException('Duplicate, unordered or missing aggregate trades');
  return $row;
 }
 static function book(array $depth,array $c):array {
  if(!is_int($depth['lastUpdateId']??null)||$depth['lastUpdateId']<0)throw new RuntimeException('Missing book update ID');
  $b=WallexExecutionMarketProvider::levels($depth['bids']??[],$depth['asks']??[]);if($b['ask']<=$b['bid']||!is_finite($b['mid']))throw new RuntimeException('Invalid book');
  $band=$b['mid']*$c['book_band_bps']/10000;$lo=$b['mid']-$band;$hi=$b['mid']+$band;
  if((float)end($b['bids'])[0]>$lo||(float)end($b['asks'])[0]<$hi)throw new RuntimeException('Visible book does not cover the complete symmetric band');
  $bid=0.;$ask=0.;foreach($b['bids'] as [$price,$qty])if($price>=$lo)$bid+=(float)$qty;foreach($b['asks'] as [$price,$qty])if($price<=$hi)$ask+=(float)$qty;
  if($bid+$ask<=0||!is_finite($bid+$ask))throw new RuntimeException('Insufficient band liquidity');
  return ['mid'=>$b['mid'],'lower'=>$lo,'upper'=>$hi,'bid_base_quantity'=>$bid,'ask_base_quantity'=>$ask,'imbalance'=>($bid-$ask)/($bid+$ask),'last_update_id'=>$depth['lastUpdateId'],'coverage'=>'COMPLETE_WITHIN_BAND','source_event_timestamp'=>null];
 }
 static function calculate(array $rows,array $bars,?array $depth,array $c,string $action,int $end):array {
  if(!in_array($action,['LONG','SHORT'],true))throw new RuntimeException('Invalid signal direction');$book=$depth===null?null:self::book($depth,$c);$previous=null;
  foreach($rows as $row){self::trade($row,$previous);if($row['T']<($end-max($c['windows']))*1000||$row['T']>=$end*1000)throw new RuntimeException('Trade outside half-open UTC window');$previous=$row;}
  $values=[];$statuses=[];$direction=$action==='LONG'?1:-1;
  foreach($c['windows'] as $window){$start=$end-$window;$buy=0.;$sell=0.;$count=0;$minutes=[];
   foreach($rows as $row)if($row['T']>=$start*1000){$q=(float)$row['q'];if($row['m'])$sell+=$q;else $buy+=$q;$minute=intdiv($row['T'],60000)*60;$minutes[$minute]=($minutes[$minute]??0)+($row['m']?-$q:$q);$count++;}
   $total=$buy+$sell;if($total<=0||!is_finite($total))throw new RuntimeException('No complete traded volume for direction');$delta=$buy-$sell;$cvd=0.;$curve=[['at'=>$start,'value'=>0.]];
   $current=0.;for($at=$start;$at<$end;$at+=60){if(!isset($bars[$at]))throw new RuntimeException('Missing current volume candle');$current+=$bars[$at]['volume'];$cvd+=$minutes[$at]??0.;$curve[]=['at'=>$at+60,'value'=>$cvd];}
   if(abs($total-$current)>max(1e-8,$current*1e-7))throw new RuntimeException('Aggregate volume disagrees with closed candle volume; interval completeness unverified');
   $past=[];for($i=1;$i<=$c['baseline_windows'];$i++){$volume=0.;for($at=$start-$i*$window;$at<$end-$i*$window;$at+=60){if(!isset($bars[$at]))throw new RuntimeException('Missing equal-length historical volume window');$volume+=$bars[$at]['volume'];}$past[]=$volume;}
   $average=array_sum($past)/count($past);if($average<=0||!is_finite($average))throw new RuntimeException('Historical volume baseline unavailable');$relative=$current/$average;$pressure=$delta/$total;
   $vote=abs($pressure)>=$c['taker_threshold']?($pressure>0?1:-1):0;$bookVote=$book!==null&&abs($book['imbalance'])>=$c['book_threshold']?($book['imbalance']>0?1:-1):0;
   // Delta and CVD share one trade-pressure family. Relative volume is context,
   // never a directional vote. Neither trade pressure nor depth alone can win.
   $status=$book===null?'UNAVAILABLE':($vote!==0&&$vote===$bookVote?($vote===$direction?'SUPPORTIVE':'CONTRADICTORY'):'MIXED');$statuses[]=$status;
   $values[(string)$window]=['window_seconds'=>$window,'start'=>$start,'end'=>$end,'trade_count'=>$count,'aggressive_buy_volume'=>$buy,'aggressive_sell_volume'=>$sell,'delta'=>$delta,'normalized_delta'=>$pressure,'cvd'=>$cvd,'cvd_curve'=>$curve,'cvd_trend'=>$cvd>0?'UP':($cvd<0?'DOWN':'FLAT'),'order_book'=>$book,'relative_volume'=>$relative,'historical_mean_volume'=>$average,'baseline_windows'=>$c['baseline_windows'],'volume_label'=>$relative>=$c['relative_volume_high']?'HIGH':($relative<=$c['relative_volume_low']?'LOW':'NORMAL'),'advisory_status'=>$status];
  }
  $status=count(array_unique($statuses))===1?$statuses[0]:'MIXED';return ['indicator_values'=>$values,'advisory_status'=>$status,'trade_count'=>count($rows),'data_completeness'=>$book===null?'TRADE_VOLUME_COMPLETE_BOOK_MISSING':'COMPLETE_WITHIN_REQUESTED_WINDOWS'];
 }
 static function step(PDO $db,array $c,int $now,?OrderFlowMarketData $api=null):array {
  if(!$c['enabled'])return ['status'=>'disabled'];
  $previous=(int)$db->query('PRAGMA busy_timeout')->fetchColumn();$db->exec('PRAGMA busy_timeout=100');
  try{return self::process($db,$c,$now,$api);}finally{$db->exec('PRAGMA busy_timeout='.$previous);}
 }
 private static function process(PDO $db,array $c,int $now,?OrderFlowMarketData $api):array {
  if(!$c['enabled'])return ['status'=>'disabled'];self::migrate($db);$api??=new OrderFlowMarketData();
  $db->prepare('INSERT OR REPLACE INTO order_flow_meta VALUES(?,?)')->execute(['heartbeat',(string)$now]);
  $db->beginTransaction();try{
   $get=$db->prepare('SELECT v FROM order_flow_meta WHERE k=?');$get->execute(['next_request']);if((int)$get->fetchColumn()>$now){$db->commit();return ['status'=>'throttled'];}
   $q=$db->prepare("SELECT * FROM order_flow_jobs WHERE status='PENDING' AND next_at<=? AND lease<=? ORDER BY CASE kind WHEN 'SIGNAL' THEN 0 ELSE 1 END,created LIMIT 1");$q->execute([$now,$now]);$job=$q->fetch(PDO::FETCH_ASSOC);if(!$job){$db->commit();return ['status'=>'idle'];}
   $db->prepare('UPDATE order_flow_jobs SET lease=? WHERE id=?')->execute([$now+30,$job['id']]);
   $db->prepare('INSERT OR REPLACE INTO order_flow_meta VALUES(?,?)')->execute(['next_request',(string)($now+$c['step_interval_seconds'])]);$db->commit();
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
  $p=json_decode($job['payload'],true);$policy=$p['config'];$result=null;
  try{
   if(!OrderFlowConfig::forStrategy($c,$p['signal']['strategy_id']))throw new RuntimeException('Order Flow disabled for this strategy');
   if($now-$p['end']>$policy['max_age_seconds'])throw new RuntimeException('Order Flow data/job is too old');
   if($p['phase']==='time'&&$now<$p['end']+2){$db->prepare('UPDATE order_flow_jobs SET lease=0,next_at=? WHERE id=?')->execute([$p['end']+2,$job['id']]);return ['status'=>'settling'];}
   $symbol=$p['signal']['symbol'];$start=$p['end']-max($policy['windows']);
   [$path,$params]=match($p['phase']){
    'time'=>['/api/v3/time',[]],
    'depth'=>['/api/v3/depth',['symbol'=>$symbol,'limit'=>$policy['depth_limit']]],
    'volume'=>['/api/v3/klines',['symbol'=>$symbol,'interval'=>'1m','startTime'=>($p['end']-max($policy['windows'])*($policy['baseline_windows']+1))*1000,'endTime'=>$p['end']*1000-1,'limit'=>1000]],
    'trades'=>['/api/v3/aggTrades',isset($p['next_id'])?['symbol'=>$symbol,'fromId'=>$p['next_id'],'limit'=>1000]:['symbol'=>$symbol,'startTime'=>$start*1000,'endTime'=>$p['end']*1000-1,'limit'=>1000]],
    default=>throw new RuntimeException('Invalid Order Flow phase')};
   $weight=OrderFlowMarketData::weight($path,$params);$bucket=intdiv($now,60);
   $q=$db->prepare('SELECT v FROM order_flow_meta WHERE k=?');$q->execute(['weight']);$limit=json_decode($q->fetchColumn()?:'{}',true);$used=($limit['bucket']??null)===$bucket?($limit['used']??0):0;
   if($used+$weight>$c['max_weight_per_minute']){$db->prepare('UPDATE order_flow_jobs SET lease=0,next_at=? WHERE id=?')->execute([($bucket+1)*60,$job['id']]);return ['status'=>'weight_limited'];}
   $db->prepare('INSERT OR REPLACE INTO order_flow_meta VALUES(?,?)')->execute(['weight',encode(['bucket'=>$bucket,'used'=>$used+$weight])]);
   $db->prepare('INSERT OR REPLACE INTO order_flow_meta VALUES(?,?)')->execute(['next_request',(string)($now+$c['step_interval_seconds'])]);
   $response=$api->get($path,$params,$policy);$received=$api->now();
   if(isset($response['backoff'])){$db->prepare('INSERT OR REPLACE INTO order_flow_meta VALUES(?,?)')->execute(['next_request',(string)($now+$response['backoff'])]);throw new RuntimeException('Rate limit; Order Flow paused independently');}
   if(($response['used_weight']??0)>1000)$db->prepare('INSERT OR REPLACE INTO order_flow_meta VALUES(?,?)')->execute(['next_request',(string)($now+60)]);
   $data=$response['data'];
   if($p['phase']==='time'){
    $ms=BinanceMarketDataProvider::number($data['serverTime']??null);if(abs($ms/1000-$received)>5)throw new RuntimeException('Clock skew');
    if($ms<$p['end']*1000+2000){
     if(($p['settle_retries']??0)>=2)throw new RuntimeException('Unsettled closed window after bounded retries');$p['settle_retries']=($p['settle_retries']??0)+1;
     $db->prepare('UPDATE order_flow_jobs SET lease=0,next_at=?,payload=? WHERE id=?')->execute([$received+$c['step_interval_seconds'],encode($p),$job['id']]);return ['status'=>'settling'];
    }
    $p['server_time']=$ms;$p['phase']='depth';
   }
   elseif($p['phase']==='depth'){
    if($job['kind']==='SIGNAL'&&abs($received-$p['signal']['signal_time'])>$policy['max_signal_book_lag_seconds'])throw new RuntimeException('Historical book cannot be reconstructed; signal-time capture arrived too late');
    self::book($data,$policy);$p['depth']=$data;$p['depth_received_at']=$received;$p['phase']='volume';
   }elseif($p['phase']==='volume'){$from=$p['end']-max($policy['windows'])*($policy['baseline_windows']+1);$p['bars']=BinanceMarketDataProvider::parse($data,60,$from,$p['end']);for($t=$from;$t<$p['end'];$t+=60)if(!isset($p['bars'][$t]))throw new RuntimeException('Missing historical volume candle');$p['phase']='trades';}
   else{
    if(!array_is_list($data)||count($data)>1000)throw new RuntimeException('Invalid aggregate page');$complete=false;$previous=$p['rows']?end($p['rows']):null;
    foreach($data as $row){self::trade($row,$previous);if(isset($p['next_id'])&&$previous===null&&$row['a']!==$p['next_id'])throw new RuntimeException('Aggregate continuation gap');if($row['T']>=$p['end']*1000){$complete=true;break;}if($row['T']<$start*1000)throw new RuntimeException('Wrong aggregate interval');$p['rows'][]=$row;$previous=$row;}
    $p['pages']++;$complete=$complete||count($data)<1000;
    if($complete){$base=self::unavailable($p,$job,$received,'');$result=array_replace($base,self::calculate($p['rows'],$p['bars'],$p['depth'],$policy,$p['signal']['action'],$p['end']));$result['depth_received_at']=$p['depth_received_at'];$result['explanation']=match($result['advisory_status']){'SUPPORTIVE'=>'جریان معاملات و دفتر مشاهده‌شده با جهت سیگنال همسو هستند.','CONTRADICTORY'=>'فشار معاملات و دفتر مشاهده‌شده مخالف جهت سیگنال هستند؛ سیگنال اصلی تغییر نمی‌کند.',default=>'شواهد جریان سفارش‌ها خنثی یا ترکیبی هستند.'};}
    elseif($p['pages']>=$policy['max_pages'])throw new RuntimeException('Aggregate page cap reached; incomplete interval');
    else $p['next_id']=end($p['rows'])['a']+1;
   }
   if($received-$p['end']>$policy['max_age_seconds'])throw new RuntimeException('Delayed response made Order Flow data stale');
  }catch(Throwable $e){$result=self::unavailable($p,$job,max($now,$api->now()),$e->getMessage());}
  if($result){$db->beginTransaction();try{$db->prepare('INSERT OR IGNORE INTO order_flow_results VALUES(?,?,?,?,?,?,?)')->execute([$job['id'],$job['signal_id'],$p['signal']['strategy_id'],$p['signal']['version'],$job['kind'],$job['target'],encode($result)]);$db->prepare("UPDATE order_flow_jobs SET status='DONE',lease=0,payload=? WHERE id=?")->execute([encode(['signal_id'=>$job['signal_id'],'result_status'=>$result['advisory_status']]),$job['id']]);$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}}
  else $db->prepare('UPDATE order_flow_jobs SET payload=?,lease=0,next_at=? WHERE id=?')->execute([encode($p),$now+$c['step_interval_seconds'],$job['id']]);
  return ['status'=>$result?'completed':'pending','job_id'=>$job['id'],'advisory_status'=>$result['advisory_status']??null];
 }
 static function details(PDO $db,string $strategy,string $version,array $c):array {
  $out=['enabled'=>OrderFlowConfig::forStrategy($c,$strategy),'visible'=>true,'results'=>[],'max_age_seconds'=>$c['max_age_seconds'],'processor_last_seen'=>null,'browser_processing_enabled'=>$c['browser_processing_enabled']];if(!$out['enabled'])return $out;
  if(self::exists($db,'order_flow_meta')){$q=$db->query("SELECT v FROM order_flow_meta WHERE k='heartbeat'");$out['processor_last_seen']=(int)$q->fetchColumn()?:null;}
  if(self::exists($db,'order_flow_visibility')){$q=$db->prepare('SELECT visible FROM order_flow_visibility WHERE strategy_id=?');$q->execute([$strategy]);$v=$q->fetchColumn();if($v!==false)$out['visible']=(bool)$v;}
  if(self::exists($db,'order_flow_results')){$q=$db->prepare('SELECT payload FROM order_flow_results WHERE strategy_id=? AND version=? ORDER BY target DESC LIMIT 30');$q->execute([$strategy,$version]);$out['results']=array_map(fn($x)=>json_decode($x,true),$q->fetchAll(PDO::FETCH_COLUMN));}
  return $out;
 }
 static function safeDetails(PDO $db,string $strategy,string $version,array $c):array {
  try{return self::details($db,$strategy,$version,$c);}catch(Throwable $e){return ['enabled'=>$c['enabled'],'visible'=>true,'results'=>[],'max_age_seconds'=>$c['max_age_seconds'],'processor_last_seen'=>null,'storage_available'=>false];}
 }
}
