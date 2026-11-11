<?php
declare(strict_types=1);

final class ExecutionGate {
 const TERMINAL=['CANCELLED_STOP_LOSS','CANCELLED_INVALIDATION','EXPIRED_TP1_REACHED','EXPIRED_TIMEOUT','MANUALLY_EXECUTED'];
 static function migrate(PDO $db):void {
  $db->exec('CREATE TABLE IF NOT EXISTS execution_signals(id TEXT PRIMARY KEY,strategy_id TEXT NOT NULL,version TEXT NOT NULL,status TEXT NOT NULL,signal_time INTEGER NOT NULL,deadline INTEGER NOT NULL,payload TEXT NOT NULL);
   CREATE TABLE IF NOT EXISTS analysis_states(strategy_id TEXT NOT NULL,version TEXT NOT NULL,provider TEXT NOT NULL,state TEXT NOT NULL,PRIMARY KEY(strategy_id,version,provider));
   CREATE UNIQUE INDEX IF NOT EXISTS execution_one_pending ON execution_signals(strategy_id,version) WHERE status NOT IN (\'CANCELLED_STOP_LOSS\',\'CANCELLED_INVALIDATION\',\'EXPIRED_TP1_REACHED\',\'EXPIRED_TIMEOUT\',\'MANUALLY_EXECUTED\');
   CREATE TABLE IF NOT EXISTS execution_events(id INTEGER PRIMARY KEY AUTOINCREMENT,signal_id TEXT NOT NULL,at INTEGER NOT NULL,status TEXT NOT NULL,reason TEXT NOT NULL);
   CREATE TABLE IF NOT EXISTS basis_samples(id INTEGER PRIMARY KEY AUTOINCREMENT,series TEXT NOT NULL,symbol TEXT NOT NULL,at INTEGER NOT NULL,reference_time REAL NOT NULL,wallex_time REAL NOT NULL,sample_key TEXT NOT NULL,basis REAL NOT NULL,deviation REAL,payload TEXT NOT NULL,UNIQUE(series,sample_key),UNIQUE(series,at));
   CREATE INDEX IF NOT EXISTS basis_window ON basis_samples(series,at);');
 }
 static function median(array $v):float{if(!$v)throw new RuntimeException('Empty median');sort($v,SORT_NUMERIC);$n=count($v);return $n%2?$v[intdiv($n,2)]:($v[$n/2-1]+$v[$n/2])/2;}
 static function percentile(array $v,float $p):float{if(!$v)throw new RuntimeException('Empty percentile');sort($v,SORT_NUMERIC);$i=(count($v)-1)*$p/100;$lo=(int)floor($i);return $v[$lo]+($v[(int)ceil($i)]-$v[$lo])*($i-$lo);}
 static function fresh(array $q,int $now,array $c):void {
  if(($q['provider']??null)==='wallex'&&($q['trust_status']??null)!=='VERIFIED')throw new RuntimeException('Wallex quote UNVERIFIED: source timestamp and freshness are not verified');
  foreach(['bid','ask','timestamp','observed_at'] as $k)if(!isset($q[$k])||!is_numeric($q[$k])||!is_finite((float)$q[$k]))throw new RuntimeException('Missing quote '.$k);
  if($q['bid']<=0||$q['ask']<$q['bid']||$q['timestamp']>$now+1||$q['observed_at']>$now+1||$now-$q['timestamp']>$c['max_quote_age_seconds']||$now-$q['observed_at']>$c['max_quote_age_seconds'])throw new RuntimeException('Stale or invalid '.$q['provider'].' quote');
 }
 // Advisory receipts establish local acquisition, never exchange freshness.
 static function advisory(array $c):bool{return ($c['manual_advisory_enabled']??false)===true;}
 static function marketFresh(array $q,int $now,array $c):void {
  if(!self::advisory($c)||($q['provider']??null)!=='wallex'){self::fresh($q,$now,$c);return;}
  if(!is_numeric($q['received_at']??null)||$q['received_at']>$now+1||$now-$q['received_at']>$c['max_quote_age_seconds']||!is_string($q['receipt_id']??null)||!$q['receipt_id'])throw new RuntimeException('Recent independent Wallex receipt unavailable');
  $b=WallexExecutionMarketProvider::levels($q['bids']??[],$q['asks']??[]);
  if($b['ask']<=$b['bid']||!is_finite($b['mid'])||$b['bid']!==($q['bid']??null)||$b['ask']!==($q['ask']??null))throw new RuntimeException('Inconsistent or locked advisory book');
 }
 static function series(string $symbol,array $c):string{$key=[$symbol,$c['instrument'],$c['orderbook'],$c['basis_window_seconds'],$c['min_samples_60m']];if(self::advisory($c))$key[]='MANUAL_ADVISORY';return hash('sha256',encode($key));}
 static function sample(PDO $db,string $symbol,array $reference,array $wallex,int $now,array $c):?array {
  self::fresh($reference,$now,$c);self::marketFresh($wallex,$now,$c);
  $wallexTime=self::advisory($c)?$wallex['received_at']:$wallex['timestamp'];
  if(($reference['symbol']??null)!==$symbol||($wallex['symbol']??null)!==$symbol)throw new RuntimeException('Quote symbol mismatch');
  $series=self::series($symbol,$c);$q=$db->prepare('SELECT * FROM basis_samples WHERE series=? ORDER BY at DESC LIMIT 1');$q->execute([$series]);$last=$q->fetch(PDO::FETCH_ASSOC);
  if($last&&($now-(int)$last['at']<$c['basis_sample_interval_seconds']||$reference['timestamp']<=$last['reference_time']||$wallexTime<=$last['wallex_time']))return null;
  $q=$db->prepare('SELECT basis FROM basis_samples WHERE series=? AND at>=? AND at<?');$q->execute([$series,$now-$c['basis_window_seconds'],$now]);$window=array_map('floatval',$q->fetchAll(PDO::FETCH_COLUMN));
  $basis=((($wallex['bid']+$wallex['ask'])/2)/(($reference['bid']+$reference['ask'])/2)-1)*100;
  $deviation=count($window)>=$c['min_samples_60m']?abs($basis-self::median($window)):null;
  $key=hash('sha256',encode(self::advisory($c)?['manual-receipt',$wallex['receipt_id']]:[$reference['sample_id'],$wallex['sample_id']]));
  $q=$db->prepare('INSERT OR IGNORE INTO basis_samples(series,symbol,at,reference_time,wallex_time,sample_key,basis,deviation,payload) VALUES(?,?,?,?,?,?,?,?,?)');$q->execute([$series,$symbol,$now,$reference['timestamp'],$wallexTime,$key,$basis,$deviation,encode(['reference'=>$reference,'wallex'=>$wallex])]);
  $db->prepare('DELETE FROM basis_samples WHERE at<?')->execute([$now-$c['calibration_history_days']*86400]);
  return $q->rowCount()?['id'=>$key,'at'=>$now,'basis'=>$basis]:null;
 }
 static function statistics(PDO $db,string $symbol,int $now,array $c):array {
  $series=self::series($symbol,$c);$q=$db->prepare('SELECT basis FROM basis_samples WHERE series=? AND at>=? AND at<?');$q->execute([$series,$now-$c['basis_window_seconds'],$now]);$v=array_map('floatval',$q->fetchAll(PDO::FETCH_COLUMN));
  $q=$db->prepare('SELECT deviation FROM basis_samples WHERE series=? AND at>=? AND at<? AND deviation IS NOT NULL');$q->execute([$series,$now-$c['calibration_history_days']*86400,$now]);$history=array_map('floatval',$q->fetchAll(PDO::FETCH_COLUMN));
  $r=['window_count'=>count($v),'calibration_count'=>count($history),'median'=>null,'mad'=>null,'robust_sigma'=>null,'historical_percentile'=>null,'tolerance'=>null,'calibrated'=>false];
  if(count($v)<$c['min_samples_60m']||count($history)<$c['min_samples_7d'])return $r;
  $r['median']=self::median($v);$r['mad']=self::median(array_map(fn($x)=>abs($x-$r['median']),$v));$r['robust_sigma']=1.4826*$r['mad'];$r['historical_percentile']=self::percentile($history,$c['historical_percentile']);
  $r['tolerance']=max($c['mad_multiplier']*$r['robust_sigma'],$r['historical_percentile']);$r['calibrated']=true;return $r;
 }
 static function create(PDO $db,array $s,string $version,string $event,int $at,float $price,?array $indicators,array $c,int $created):string {
  $id=hash('sha256',encode([$s['id'],$version,'binance_spot',$event,$at]));
  $p=['mode'=>self::advisory($c)?'MANUAL_ADVISORY':'VERIFIED','id'=>$id,'strategy_id'=>$s['id'],'version'=>$version,'name'=>$s['name'],'symbol'=>$s['symbol'],'action'=>$s['action'],'signal_time'=>$at,'signal_price'=>$price,'analysis_provider'=>'binance_spot','signal_event'=>$event,'source_candle_close'=>str_starts_with($event,'bar_close:')?$at:null,
   'stop'=>$s['trade']['stop_loss']??null,'take_profits'=>$s['trade']['take_profits']??[],'tp1'=>$s['trade']['take_profits'][0]??null,'invalidation'=>$s['invalidation'],'cancellation'=>$s['cancellation']??[],'strategy'=>$s,'indicators'=>$indicators,'atr_snapshot'=>array_values(array_filter($indicators['conditions']??[],fn($x)=>$x['indicator']==='ATR')),
   'created'=>$created,'deadline'=>$at+$c['max_wait_seconds'],'status'=>'SIGNAL_CREATED','reason'=>'Binance signal generated; execution has not been approved','confirmations'=>[],'notified_at'=>null];
  $q=$db->prepare('INSERT OR IGNORE INTO execution_signals VALUES(?,?,?,?,?,?,?)');$q->execute([$id,$s['id'],$version,'SIGNAL_CREATED',$at,$p['deadline'],encode($p)]);
  if($q->rowCount())$db->prepare('INSERT INTO execution_events(signal_id,at,status,reason) VALUES(?,?,?,?)')->execute([$id,$created,$p['status'],$p['reason']]);
  if($q->rowCount()&&self::advisory($c)){
   $alert=['type'=>'binance_signal','signal_id'=>$id,'strategy_id'=>$s['id'],'name'=>$s['name'],'symbol'=>$s['symbol'],'action'=>$s['action'],'price'=>$price,'event'=>'binance_signal','event_time'=>$at,'trade'=>$s['trade']??null,'reason'=>'سیگنال بایننس شناسایی شد؛ بررسی شرایط تخمینی ورود در والکس آغاز شد.'];
   $db->prepare('INSERT OR IGNORE INTO alerts(unique_key,created,payload) VALUES(?,?,?)')->execute(['binance-signal:'.$id,$created,encode($alert)]);
  }
  return $id;
 }
 static function instrument(array $p,array $c):bool {
  $i=$c['instrument'];
  // This version has only a Binance SPOT reference. Neither margin nor futures is silently compared to it.
  return $i!==null&&($i['verified']??false)===true&&($i['type']??null)==='spot'&&($i['reference_type']??null)==='spot'&&($i['symbol']??null)===$p['symbol']&&$p['action']==='LONG'&&strlen($i['evidence']??'')>0;
 }
 static function rr(array $p,array $r,array $w,array $c,?float $estimatedEntry=null):array {
  if(!is_numeric($p['stop']??null)||!is_numeric($p['tp1']??null)||$p['stop']<=0||$p['tp1']<=0)throw new RuntimeException('Missing stop-loss or TP1');
  $ratio=(($w['bid']+$w['ask'])/2)/(($r['bid']+$r['ask'])/2);$entry=$estimatedEntry??($p['action']==='SHORT'?$w['bid']:$w['ask']);$stop=$p['stop']*$ratio;$tp=$p['tp1']*$ratio;
  $risk=$p['action']==='SHORT'?$stop-$entry:$entry-$stop;$reward=$p['action']==='SHORT'?$entry-$tp:$tp-$entry;
  if($risk<=0||$reward<=0)throw new RuntimeException('Invalid remaining risk/reward geometry');
  $gross=$reward/$risk;$net=null;
  if($c['fees_pct']!==null||$c['slippage_pct']!==null){$cost=($entry+$tp)*(($c['fees_pct']??0)+($c['slippage_pct']??0))/100;$net=($reward-$cost)/($risk+($entry+$stop)*(($c['fees_pct']??0)+($c['slippage_pct']??0))/100);}
  return ['entry'=>$entry,'conversion_ratio'=>$ratio,'stop'=>$stop,'tp1'=>$tp,'take_profits'=>array_map(fn($v)=>$v*$ratio,$p['take_profits']??[$p['tp1']]),'risk'=>$risk,'reward'=>$reward,'gross_rr'=>$gross,'net_rr'=>$net,'minimum_rr'=>$c['min_remaining_rr'],'cost_model'=>'per-side percentage of entry and exit notionals'];
 }
 static function validity(array $p,BinanceMarketDataProvider $api,int $now):array {
  $price=$api->price($p['symbol']);$start=intdiv($p['signal_time'],60)*60;$bars=$api->monitoringMinutes($p['symbol'],$start,$now);
  $touch=function($high,$low)use($p){if($p['stop']!==null&&($p['action']==='SHORT'?$high>=$p['stop']:$low<=$p['stop']))return 'CANCELLED_STOP_LOSS';if($p['tp1']!==null&&($p['action']==='SHORT'?$low<=$p['tp1']:$high>=$p['tp1']))return 'EXPIRED_TP1_REACHED';return null;};
  $current=$touch($price,$price);if($current)return ['status'=>$current,'reason'=>'Current Binance price touched original level','at'=>$now,'current_price'=>$price];
  foreach($bars as $t=>$bar){$hit=$touch($bar['high'],$bar['low']);if(!$hit)continue;if($t<$p['signal_time'])throw new RuntimeException('Signal-minute extreme cannot be attributed exclusively to post-signal trades');return ['status'=>$hit,'reason'=>'Post-signal Binance 1m extreme touched original level; exact intra-minute touch time unavailable','at'=>min($now,$t+60),'touch_interval'=>[$t,min($now,$t+60)],'current_price'=>$price];}
  if(Strategy::check($p['invalidation'],['price'=>$price]))return ['status'=>'CANCELLED_INVALIDATION','reason'=>'Original Binance price invalidation','at'=>$now,'current_price'=>$price];
  foreach($p['cancellation'] as $rule){
   $contexts=[];
   if($rule['event']==='price')$contexts[$now]=['price'=>$price];
   else{$tf=substr($rule['event'],10);$sec=Strategy::TIMEFRAMES[$tf];$first=(intdiv($p['signal_time'],$sec)+1)*$sec;$last=intdiv($now,$sec)*$sec;
    if($first<=$last){$history=$api->candles($p['symbol'],$tf,$first-max($sec,lookback($rule)),$last);for($at=$first;$at<=$last;$at+=$sec)$contexts[$at]=['close'=>$at,'seconds'=>$sec,'bars'=>$history];}}
   foreach($contexts as $at=>$context)if(Strategy::check($rule['when'],$context))return ['status'=>'CANCELLED_INVALIDATION','reason'=>'Original cancellation: '.$rule['reason'],'at'=>$at,'current_price'=>$price];
  }
  // Price-only rules must also detect post-signal extremes that reversed between polls.
  foreach($bars as $t=>$bar){if($t<$p['signal_time'])continue;foreach([$bar['low'],$bar['high']] as $extreme){
   if(Strategy::check($p['invalidation'],['price'=>$extreme]))return ['status'=>'CANCELLED_INVALIDATION','reason'=>'Original invalidation at post-signal minute extreme','at'=>min($now,$t+60),'current_price'=>$price];
   foreach($p['cancellation'] as $rule)if($rule['event']==='price'&&Strategy::check($rule['when'],['price'=>$extreme]))return ['status'=>'CANCELLED_INVALIDATION','reason'=>$rule['reason'].' (post-signal extreme)','at'=>min($now,$t+60),'current_price'=>$price];
  }}
  return ['status'=>null,'current_price'=>$price,'checked_at'=>$now];
 }
 static function decide(array $p,array $c,int $now,?array $r,?array $w,?array $sample,array $stats,array $validity):array {
  if(in_array($p['status'],self::TERMINAL,true))return $p;
  $p['checked_at']=$now;$p['minimum_rr']=$c['min_remaining_rr'];$p['statistics']=$stats;$p['validity']=$validity;
  $set=function($status,$reason)use(&$p){$p['status']=$status;$p['reason']=$reason;$p['confirmations']=[];return $p;};
  if($now>=$p['deadline'])return $set('EXPIRED_TIMEOUT','Original signal execution deadline expired');
  if(!empty($validity['status']))return $set($validity['status'],$validity['reason']);
  $manual=self::advisory($c)&&($p['mode']??'VERIFIED')==='MANUAL_ADVISORY';
  if(($p['mode']??'VERIFIED')==='MANUAL_ADVISORY'&&!$manual)return $set('WAITING_DATA','Manual advisory feature is disabled');
  if(self::advisory($c)&&!$manual)return $set('WAITING_DATA','Signal belongs to another execution mode');
  if(!$manual&&!$c['enabled'])return $set('WAITING_DATA','ExecutionGate is disabled');
  if($c['min_remaining_rr']===null)return $set('BLOCKED_RR_THRESHOLD_NOT_CONFIGURED','Minimum remaining RR has not been explicitly configured');
  if(!$manual&&!self::instrument($p,$c))return $set('BLOCKED_INSTRUMENT_MISMATCH','Verified matching SPOT LONG instrument mapping required; SHORT capability is not established');
  if(!empty($validity['error']))return $set('WAITING_DATA',$validity['error']);
  try{if(!$r||!$w)throw new RuntimeException('Verified market quotes unavailable');self::fresh($r,$now,$c);self::marketFresh($w,$now,$c);$p['quotes']=['reference'=>$r,'wallex'=>$w];
   if($manual){
    if(($r['symbol']??null)!==$p['symbol']||($w['symbol']??null)!==$p['symbol'])throw new RuntimeException('Quote symbol mismatch');
    $quantity=$p['strategy']['trade']['quantity']??null;
    if(!is_numeric($quantity)||$quantity<=0)throw new RuntimeException('Trade quantity required for size-specific VWAP');
    $p['estimate']=WallexExecutionMarketProvider::estimate($w,$p['action'],(float)$quantity);
    if(!$p['estimate']['depth_sufficient'])throw new RuntimeException('Insufficient visible depth for requested quantity');
   }
   $p['rr']=self::rr($p,$r,$w,$c,$manual?($p['estimate']['vwap']??null):null);
   if($manual){
    foreach(['entry','conversion_ratio','stop','tp1','risk','reward','gross_rr'] as $field)if(!is_finite((float)$p['rr'][$field]))throw new RuntimeException('Non-finite advisory risk calculation');
    $p['basis']=((($w['bid']+$w['ask'])/2)/(($r['bid']+$r['ask'])/2)-1)*100;
   }
   if($manual&&($c['fees_pct']===null||$c['slippage_pct']===null))$p['rr']['net_rr']=null;
  }catch(Throwable $e){return $set('WAITING_DATA',$e->getMessage());}
  if(!$stats['calibrated'])return $set('WAITING_CALIBRATION','Insufficient preceding rolling or historical calibration samples');
  $basis=((($w['bid']+$w['ask'])/2)/(($r['bid']+$r['ask'])/2)-1)*100;$p['basis']=$basis;
  if(abs($basis-$stats['median'])>$stats['tolerance'])return $set('WAITING_BASIS','Current signed basis outside reproducible normal tolerance');
  if(($p['rr']['net_rr']??$p['rr']['gross_rr'])<$c['min_remaining_rr'])return $set('WAITING_BASIS','Remaining RR below configured minimum');
  $confirm=$p['confirmations']??[];
  if($sample&&(!count($confirm)||end($confirm)['id']!==$sample['id'])){
   if($confirm&&$sample['at']-end($confirm)['at']>$c['basis_sample_interval_seconds']+$c['max_quote_age_seconds'])$confirm=[];
   $confirm[]=['id'=>$sample['id'],'at'=>$sample['at']];$confirm=array_slice($confirm,-$c['confirmation_samples']);
  }
  $p['confirmations']=$confirm;
  if(count($confirm)<$c['confirmation_samples']){$p['status']='WAITING_BASIS';$p['reason']='Waiting for consecutive distinct normal samples';return $p;}
  $ready=$manual?'MANUAL_REVIEW_AVAILABLE':'READY_FOR_MANUAL_ENTRY';
  if($p['status']!==$ready)$p['confirmed_at']=$now;
  $p['status']=$ready;$p['reason']=$manual?'شرایط تخمینی برای بررسی ورود دستی فراهم است؛ تازگی دفتر و اجرای تعهدی تأیید نشده‌اند.':'Signal confirmed. Manual execution on Wallex is required.';return $p;
 }
 static function persist(PDO $db,array $before,array $after,int $now):void {
  if($before['status']==='READY_FOR_MANUAL_ENTRY'&&!in_array($after['status'],['READY_FOR_MANUAL_ENTRY','MANUALLY_EXECUTED'],true)){
   $db->prepare('INSERT INTO execution_events(signal_id,at,status,reason) VALUES(?,?,?,?)')->execute([$after['id'],$now,'READY_REVOKED',$after['reason']]);$after['revoked_at']=$now;
  }
  if($before['status']!==$after['status'])$db->prepare('INSERT INTO execution_events(signal_id,at,status,reason) VALUES(?,?,?,?)')->execute([$after['id'],$now,$after['status'],$after['reason']]);
  if($before['status']==='MANUAL_REVIEW_AVAILABLE'&&$after['status']!=='MANUAL_REVIEW_AVAILABLE'){$after['revoked_at']=$now;$db->prepare('INSERT INTO execution_events(signal_id,at,status,reason) VALUES(?,?,?,?)')->execute([$after['id'],$now,'MANUAL_REVIEW_REVOKED',$after['reason']]);}
  if($after['status']==='MANUAL_REVIEW_AVAILABLE'&&empty($before['advisory_notified_at'])){
   $after['advisory_notified_at']=$now;
   $payload=['type'=>'manual_review','signal_id'=>$after['id'],'strategy_id'=>$after['strategy_id'],'name'=>$after['name'],'symbol'=>$after['symbol'],'action'=>$after['action'],'price'=>$after['rr']['entry'],'event'=>'manual_review','event_time'=>$now,'reason'=>$after['reason'],'execution'=>$after,'trade'=>['entry'=>['type'=>'MARKET','reference_price'=>$after['rr']['entry']],'stop_loss'=>$after['rr']['stop'],'take_profits'=>$after['rr']['take_profits']]];
   $db->prepare('INSERT OR IGNORE INTO alerts(unique_key,created,payload) VALUES(?,?,?)')->execute(['manual-review:'.$after['id'],$now,encode($payload)]);
  }
  if($after['status']==='READY_FOR_MANUAL_ENTRY'&&empty($before['notified_at'])){
   $after['notified_at']=$now;$after['ready_snapshot']=$after;
   $payload=['type'=>'execution_ready','signal_id'=>$after['id'],'strategy_id'=>$after['strategy_id'],'name'=>$after['name'],'symbol'=>$after['symbol'],'action'=>$after['action'],'price'=>$after['rr']['entry'],'event'=>'execution_ready','event_time'=>$now,'reason'=>$after['reason'],'execution'=>$after,'trade'=>['entry'=>['type'=>'MARKET','reference_price'=>$after['rr']['entry']],'stop_loss'=>$after['rr']['stop'],'take_profits'=>$after['rr']['take_profits']]];
   $db->prepare('INSERT OR IGNORE INTO alerts(unique_key,created,payload) VALUES(?,?,?)')->execute(['execution-ready:'.$after['id'],$now,encode($payload)]);
  }
  $db->prepare('UPDATE execution_signals SET status=?,payload=? WHERE id=?')->execute([$after['status'],encode($after),$after['id']]);
 }
 static function acknowledge(PDO $db,string $id,int $now):void {
  $db->beginTransaction();try{$q=$db->prepare('SELECT payload FROM execution_signals WHERE id=?');$q->execute([$id]);$raw=$q->fetchColumn();if($raw===false)throw new RuntimeException('Signal not found');$p=json_decode($raw,true);
   if($p['status']!=='READY_FOR_MANUAL_ENTRY'||$now>=$p['deadline']||$now-($p['checked_at']??0)>MarketArchitecture::config()['max_quote_age_seconds'])throw new RuntimeException('Fresh READY required for manual acknowledgement');
   $after=$p;$after['status']='MANUALLY_EXECUTED';$after['reason']='User acknowledged manual execution; no order was transmitted';$after['manual_at']=$now;self::persist($db,$p,$after,$now);$db->commit();
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
 static function details(PDO $db,string $id):array {
  $exists=$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='execution_signals'")->fetchColumn();if(!$exists)return [];
  $q=$db->prepare('SELECT payload FROM execution_signals WHERE strategy_id=? ORDER BY signal_time DESC LIMIT 10');$q->execute([$id]);return array_map(fn($x)=>json_decode($x,true),$q->fetchAll(PDO::FETCH_COLUMN));
 }
}
