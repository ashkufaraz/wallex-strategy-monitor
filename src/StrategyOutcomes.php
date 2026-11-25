<?php
declare(strict_types=1);

/** Immutable alert cohorts, evaluated as hypothetical trades on their own exchange. */
final class StrategyOutcomes
{
 static function migrate(PDO $db):void {
  $db->exec('CREATE TABLE IF NOT EXISTS strategy_registration(strategy_id TEXT PRIMARY KEY,created INTEGER);
   CREATE TABLE IF NOT EXISTS strategy_outcomes(alert_id INTEGER PRIMARY KEY,strategy_id TEXT NOT NULL,version TEXT,exchange TEXT NOT NULL,symbol TEXT NOT NULL,cohort TEXT NOT NULL,started INTEGER NOT NULL,owner_id INTEGER,snapshot TEXT NOT NULL,state TEXT NOT NULL,last_check INTEGER NOT NULL DEFAULT 0);
   CREATE INDEX IF NOT EXISTS strategy_outcomes_market ON strategy_outcomes(exchange,symbol,last_check);');
  // Keep outcome deletion atomic with every alert deletion, including bulk/owner-scoped deletes.
  $db->exec('CREATE TRIGGER IF NOT EXISTS alert_outcome_delete AFTER DELETE ON alerts BEGIN DELETE FROM strategy_outcomes WHERE alert_id=OLD.id; END;');
  $db->exec("INSERT OR IGNORE INTO settings(k,v) VALUES('outcomes_since',strftime('%s','now')); INSERT OR IGNORE INTO settings(k,v) SELECT 'outcomes_min_alert_id',COALESCE(MAX(id),0) FROM alerts");
  // There was no durable creation field before this migration. Do not invent a date for old rows.
  $db->exec('INSERT OR IGNORE INTO strategy_registration(strategy_id,created) SELECT id,NULL FROM strategies');
  // User-requested fresh reporting period. The marker makes this reset run once.
  $db->beginTransaction();
  try {
   $marker=$db->prepare("INSERT OR IGNORE INTO settings(k,v) VALUES('outcomes_reset_20261124_v1',?)");$marker->execute([(string)time()]);
   if($marker->rowCount()){
    $last=(int)$db->query('SELECT COALESCE(MAX(id),0) FROM alerts')->fetchColumn();
    $db->exec('DELETE FROM strategy_outcomes');
    $set=$db->prepare('INSERT OR REPLACE INTO settings(k,v) VALUES(?,?)');
    foreach(['outcomes_since'=>time(),'outcomes_min_alert_id'=>$last,'outcomes_import_cursor'=>$last] as $key=>$value)$set->execute([$key,(string)$value]);
   }
   // Repair the reporting boundary for the alert the user identified after the reset.
   // Match its original timestamp and strategy so unrelated installations are untouched.
   $repair=$db->prepare("INSERT OR IGNORE INTO settings(k,v) VALUES('outcomes_period_20261124_v2',?)");$repair->execute([(string)time()]);
   if($repair->rowCount()){
    $alert=$db->query("SELECT id,created FROM alerts WHERE id=467 AND created=1795510038 AND json_extract(payload,'$.strategy_id')='btc-long-bounce-59400-20261124'")->fetch(PDO::FETCH_ASSOC);
    if($alert){
     $set=$db->prepare('UPDATE settings SET v=CAST(MIN(CAST(v AS INTEGER),CAST(? AS INTEGER)) AS TEXT) WHERE k=?');
     foreach(['outcomes_since'=>(int)$alert['created'],'outcomes_min_alert_id'=>(int)$alert['id']-1,'outcomes_import_cursor'=>(int)$alert['id']-1] as $key=>$value)$set->execute([$value,$key]);
    }
   }
   $cleanup=$db->prepare("INSERT OR IGNORE INTO settings(k,v) VALUES('outcomes_orphan_cleanup_v1',?)");$cleanup->execute([(string)time()]);
   if($cleanup->rowCount())$db->exec('DELETE FROM strategy_outcomes WHERE NOT EXISTS (SELECT 1 FROM alerts WHERE alerts.id=strategy_outcomes.alert_id)');
   $db->commit();
  }catch(Throwable $e){$db->rollBack();throw $e;}
 }
 static function created(string $id):?int {
  $q=db()->prepare('SELECT created FROM strategy_registration WHERE strategy_id=?');$q->execute([$id]);$v=$q->fetchColumn();return $v===false||$v===null?null:(int)$v;
 }
 static function capture(int $alertId,array $p,int $created):void {
  if($alertId<=(int)setting('outcomes_min_alert_id','0')||$created<(int)setting('outcomes_since','0'))return;
  if(!in_array($p['type']??'signal',['signal','entry_conditions_only','binance_signal'],true))return;
  $trade=$p['evaluation_trade']??$p['trade']??null;$cohort=($p['type']??'')==='entry_conditions_only'?'preliminary':'confirmed';
  $entry=$trade['entry']['reference_price']??$trade['entry']['price']??$p['price']??null;
  $state=['status'=>'UNAVAILABLE','reason'=>'missing_trade_snapshot','cursor'=>intdiv($created,60)*60,'partial_minute'=>$created%60!==0,
   'targets'=>[],'entered_at'=>null,'closed_at'=>null,'tp1_result'=>null,'last_price'=>$p['price']??null,
   'coverage'=>'closed_minutes_only; barrier touches in the partial creation minute are ambiguous'];
  if(is_array($trade)&&in_array($p['action']??null,['LONG','SHORT'],true)){
   try {$validated=$trade;unset($validated['entry']['reference_price']);Strategy::trade($validated,$p['action']);$entry=(float)$entry;
    if(!is_finite($entry)||$entry<=0||($p['action']==='LONG'?($entry<=(float)$trade['stop_loss']||$entry>=(float)$trade['take_profits'][0]):($entry>=(float)$trade['stop_loss']||$entry<=(float)$trade['take_profits'][0])))throw new RuntimeException('invalid_reference');
    $state['status']='OPEN';$state['reason']=null;
    $state['entered_at']=$state['status']==='OPEN'?$created:null;
    $state['targets']=array_map(fn($v)=>['price'=>(float)$v,'hit_at'=>null],$trade['take_profits']);
   }catch(Throwable $e){$state['reason']='invalid_trade_snapshot';}
  }
  if(($p['type']??'')==='binance_signal'||isset($p['analysis_provider'])&&$p['analysis_provider']!==($p['exchange']??'wallex')){
   $state['status']='UNAVAILABLE';$state['reason']='legacy_reference_provider_differs_from_execution_exchange';
  }
  $snapshot=['name'=>$p['name']??'','action'=>$p['action']??null,'entry_price'=>$entry,'trade'=>$trade,
   'strategy_created_at'=>$p['strategy_created_at']??null,'event_time'=>$p['event_time']??null,'price_at_alert'=>$p['price']??null];
  $state['evaluation_basis']='alert_from_issuance';$state['evaluation_mode']='shared_alert_price_v2';$state['coverage']='sampled_strategy_prices; movements between checks are not observed';
  if(is_numeric($snapshot['price_at_alert']??null))$state=self::advancePrice($snapshot,$state,(float)$snapshot['price_at_alert'],$created);
  $q=db()->prepare('SELECT user_id FROM alert_owners WHERE alert_id=?');$q->execute([$alertId]);$owner=$q->fetchColumn();
  db()->prepare('INSERT OR IGNORE INTO strategy_outcomes(alert_id,strategy_id,version,exchange,symbol,cohort,started,owner_id,snapshot,state,last_check) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
   ->execute([$alertId,$p['strategy_id'],$p['strategy_version']??null,$p['exchange']??'wallex',$p['symbol'],$cohort,$created,$owner===false?null:(int)$owner,encode($snapshot),encode($state),(int)($state['sample_at']??0)]);
 }
 static function importExisting():void {
  $cursor=(int)setting('outcomes_import_cursor','0');$q=db()->prepare('SELECT id,created,payload FROM alerts WHERE id>? ORDER BY id LIMIT 100');$q->execute([$cursor]);
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$p=json_decode($r['payload'],true);if(is_array($p)&&isset($p['strategy_id'],$p['symbol']))self::capture((int)$r['id'],$p,(int)$r['created']);$cursor=(int)$r['id'];}
  setSetting('outcomes_import_cursor',(string)$cursor);
 }
 /** Sequential closed minutes only. Unknown intra-minute order is never resolved optimistically. */
 static function advance(array $snapshot,array $state,array $bars,int $until):array {
  if(!in_array($state['status'],['PENDING_ENTRY','OPEN'],true))return $state;
  $long=$snapshot['action']==='LONG';$entry=(float)$snapshot['entry_price'];$stop=(float)$snapshot['trade']['stop_loss'];
  for($t=(int)$state['cursor'];$t<$until;$t+=60){
   if(!isset($bars[$t]))throw new RuntimeException('Missing outcome minute at '.$t);
   $b=$bars[$t];foreach(['open','high','low','close','volume'] as $field)if(!is_numeric($b[$field]??null)||!is_finite((float)$b[$field]))throw new RuntimeException('Invalid outcome candle');
   if($b['high']<max($b['open'],$b['close'],$b['low'])||$b['low']>min($b['open'],$b['close'])||$b['volume']<0)throw new RuntimeException('Invalid outcome OHLCV');
   $state['cursor']=$t+60;
   // A complete no-trade interval establishes time coverage, not a price touch.
   if((float)$b['volume']===0.){$state['partial_minute']=false;continue;}
   $state['last_price']=(float)$b['close'];
   if($state['partial_minute']??false){
    $state['partial_minute']=false;
    $touch=$long?$b['low']<=$stop:$b['high']>=$stop;
    foreach($state['targets'] as $target)if($long?$b['high']>=$target['price']:$b['low']<=$target['price'])$touch=true;
    if($state['status']==='PENDING_ENTRY'&&($long?$b['low']<=$entry:$b['high']>=$entry))$touch=true;
    if($touch){$state['status']='AMBIGUOUS';$state['reason']='partial_creation_minute';$state['closed_at']=$t+60;break;}
    continue;
   }
   $newEntry=false;
   if($state['status']==='PENDING_ENTRY'){
    if($long?$b['low']>$entry:$b['high']<$entry)continue;
    $state['status']='OPEN';$state['entered_at']=$t+60;$newEntry=true;
   }
   $stopHit=$long?$b['low']<=$stop:$b['high']>=$stop;$newTargets=[];
   foreach($state['targets'] as $i=>$target)if($target['hit_at']===null&&($long?$b['high']>=$target['price']:$b['low']<=$target['price']))$newTargets[]=$i;
   // Limit fills after the candle opens cannot establish whether the high/low came before the fill.
   $entryAtOpen=$long?$b['open']<=$entry:$b['open']>=$entry;
   if(!$newEntry||$entryAtOpen){
    if($long?$b['open']<=$stop:$b['open']>=$stop){$state['status']='STOPPED';$state['closed_at']=$t+60;$state['tp1_result']??='LOSS';break;}
    foreach($state['targets'] as $i=>$target)if($target['hit_at']===null&&($long?$b['open']>=$target['price']:$b['open']<=$target['price'])){
     $state['targets'][$i]['hit_at']=$t+60;$state['targets'][$i]['evidence']=['minute'=>$t,'open'=>(float)$b['open'],'high'=>(float)$b['high'],'low'=>(float)$b['low'],'close'=>(float)$b['close']];if($i===0)$state['tp1_result']='WIN';
    }
    $newTargets=array_values(array_filter($newTargets,fn($i)=>$state['targets'][$i]['hit_at']===null));
    if($state['targets']&&end($state['targets'])['hit_at']!==null){$state['status']='ALL_TARGETS';$state['closed_at']=$t+60;break;}
   }
   if(($stopHit&&$newTargets)||($newEntry&&!$entryAtOpen&&($stopHit||$newTargets))){
    $state['status']='AMBIGUOUS';$state['reason']='intraminute_order_unknown';$state['closed_at']=$t+60;break;
   }
   foreach($newTargets as $i){$state['targets'][$i]['hit_at']=$t+60;$state['targets'][$i]['evidence']=['minute'=>$t,'open'=>(float)$b['open'],'high'=>(float)$b['high'],'low'=>(float)$b['low'],'close'=>(float)$b['close']];if($i===0)$state['tp1_result']='WIN';}
   if($stopHit){$state['status']='STOPPED';$state['closed_at']=$t+60;$state['tp1_result']??='LOSS';break;}
   if($state['targets']&&end($state['targets'])['hit_at']!==null){$state['status']='ALL_TARGETS';$state['closed_at']=$t+60;break;}
  }
  return $state;
 }
 /** Evaluate only prices already fetched by the strategy runner. No market I/O here. */
 static function advancePrice(array $snapshot,array $state,float $price,int $at):array {
  if(!is_finite($price)||$price<=0)throw new InvalidArgumentException('Invalid shared outcome price');
  if(!in_array($state['status'],['OPEN','PENDING_ENTRY'],true)||$at<=($state['sample_at']??0))return $state;
  $state['sample_at']=$at;$state['last_price']=$price;$state['data_error']=null;
  $long=$snapshot['action']==='LONG';$entry=(float)$snapshot['entry_price'];$stop=(float)$snapshot['trade']['stop_loss'];
  if($state['status']==='PENDING_ENTRY'){
   if($long?$price>$entry:$price<$entry)return $state;
   $state['status']='OPEN';$state['entered_at']=$at;$state['entry_evidence']=['price'=>$price,'observed_at'=>$at,'source'=>'strategy_price'];
  }
  if($long?$price<=$stop:$price>=$stop){
   $state['status']='STOPPED';$state['closed_at']=$at;$state['tp1_result']??='LOSS';
   $state['stop_evidence']=['price'=>$price,'observed_at'=>$at,'source'=>'strategy_price'];return $state;
  }
  foreach($state['targets'] as $i=>$target)if($target['hit_at']===null&&($long?$price>=$target['price']:$price<=$target['price'])){
   $state['targets'][$i]['hit_at']=$at;$state['targets'][$i]['evidence']=['price'=>$price,'observed_at'=>$at,'source'=>'strategy_price'];
   if($i===0)$state['tp1_result']='WIN';
  }
  if($state['targets']&&end($state['targets'])['hit_at']!==null){$state['status']='ALL_TARGETS';$state['closed_at']=$at;}
  return $state;
 }
 /** Switch unresolved legacy candle evaluations to actual saved price observations. */
 static function sharedState(array $row,array $snapshot,array $state,int $until):array {
  if(($state['evaluation_mode']??'')==='shared_alert_price_v2')return $state;
  $prior=$state;
  $state['evaluation_basis']='alert_from_issuance';$state['evaluation_mode']='shared_alert_price_v2';$state['coverage']='sampled_strategy_prices; movements between checks are not observed';
  if(in_array($state['tp1_result'],['WIN','LOSS'],true)){$state['sample_at']=(int)$row['last_check'];return $state;}
  $state['legacy_evaluation']=['status'=>$prior['status'],'reason'=>$prior['reason']??null,'last_check'=>(int)$row['last_check']];
  $state['status']='OPEN';$state['entered_at']=(int)$row['started'];
  $state['targets']=array_map(fn($v)=>['price'=>(float)$v,'hit_at'=>null],$snapshot['trade']['take_profits']);
  $state['closed_at']=null;$state['reason']=null;$state['sample_at']=0;$state['data_error']=null;
  if(is_numeric($snapshot['price_at_alert']??null))$state=self::advancePrice($snapshot,$state,(float)$snapshot['price_at_alert'],(int)$row['started']);
  // Reuse retained strategy diagnostics, never download a historical price to repair an outcome.
  $q=db()->prepare('SELECT checked,payload FROM check_logs WHERE strategy_id=? AND version=? AND checked>=? AND checked<=? ORDER BY checked,id');
  $q->execute([$row['strategy_id'],$row['version'],(int)$row['started'],$until]);
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $log){$p=json_decode($log['payload'],true);
   if(($p['exchange']??null)!==$row['exchange']||($p['analysis_provider']??$p['exchange']??null)!==$row['exchange']||!is_numeric($p['price']??null)||(float)$p['price']<=0)continue;
   $state=self::advancePrice($snapshot,$state,(float)$p['price'],(int)$log['checked']);
  }
  return $state;
 }
 static function observe(string $exchange,string $symbol,float $price,int $at):int {
  if(!is_finite($price)||$price<=0)throw new InvalidArgumentException('Invalid shared outcome price');
  $q=db()->prepare("SELECT * FROM strategy_outcomes WHERE exchange=? AND symbol=? AND started<=? AND json_extract(state,'$.status') IN ('OPEN','PENDING_ENTRY','AMBIGUOUS')");
  $q->execute([$exchange,$symbol,$at]);$count=0;
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$snapshot=json_decode($r['snapshot'],true);$state=json_decode($r['state'],true);
   if(!$snapshot['trade']||!$state['targets'])continue;
   $next=self::sharedState($r,$snapshot,$state,$at);$next=self::advancePrice($snapshot,$next,$price,$at);
   $checked=max((int)$r['last_check'],(int)($next['sample_at']??0));
   $u=db()->prepare('UPDATE strategy_outcomes SET state=?,last_check=? WHERE alert_id=? AND state=?');$u->execute([encode($next),$checked,$r['alert_id'],$r['state']]);$count+=$u->rowCount();
  }
  return $count;
 }
 static function view():array {
  $q=db()->prepare('SELECT * FROM strategy_outcomes WHERE alert_id>? AND started>=? ORDER BY alert_id DESC');$q->execute([(int)setting('outcomes_min_alert_id','0'),(int)setting('outcomes_since','0')]);$items=[];
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$items[]=['alert_id'=>(int)$r['alert_id'],'strategy_id'=>$r['strategy_id'],'version'=>$r['version'],'exchange'=>$r['exchange'],'symbol'=>$r['symbol'],'cohort'=>$r['cohort'],'started'=>(int)$r['started'],'last_check'=>(int)$r['last_check'],'snapshot'=>json_decode($r['snapshot'],true),'state'=>json_decode($r['state'],true)];
  return $items;
 }
 /** One immutable baseline per strategy: earliest confirmed alert, otherwise earliest preliminary. */
 static function uniqueStrategies(array $items):array {
  usort($items,fn($a,$b)=>[$a['started'],$a['alert_id']]<=>[$b['started'],$b['alert_id']]);$selected=[];
  foreach($items as $r){$key=$r['exchange'].':'.$r['strategy_id'];
   if(!isset($selected[$key])||($selected[$key]['cohort']!=='confirmed'&&$r['cohort']==='confirmed'))$selected[$key]=$r;
  }
  return array_values($selected);
 }
 static function report(array $items):array {
  $items=self::uniqueStrategies($items);$groups=[];
  foreach(['all','preliminary','confirmed'] as $cohort){$rows=array_values(array_filter($items,fn($r)=>$cohort==='all'||$r['cohort']===$cohort));$wins=0;$losses=0;$ambiguous=0;$pending=0;$unavailable=0;$targets=[];$ids=[];
   foreach($rows as $r){$s=$r['state'];$ids[$r['strategy_id']]=true;if($s['tp1_result']==='WIN')$wins++;elseif($s['tp1_result']==='LOSS')$losses++;
    if($s['status']==='AMBIGUOUS')$ambiguous++;if(!in_array($s['tp1_result'],['WIN','LOSS'],true))$pending++;if($s['status']==='UNAVAILABLE')$unavailable++;
    foreach($s['targets'] as $i=>$target){$targets[$i]??=['target'=>$i+1,'hit'=>0,'miss'=>0];if($target['hit_at']!==null)$targets[$i]['hit']++;elseif($s['status']==='STOPPED')$targets[$i]['miss']++;}
   }
   foreach($targets as &$target)$target['hit_rate']=$target['hit']+$target['miss']?100*$target['hit']/($target['hit']+$target['miss']):null;unset($target);
   $groups[$cohort]=['alerts'=>count($rows),'strategies'=>count($ids),'wins'=>$wins,'losses'=>$losses,'resolved'=>$wins+$losses,'win_rate'=>$wins+$losses?100*$wins/($wins+$losses):null,'ambiguous'=>$ambiguous,'pending'=>$pending,'unavailable'=>$unavailable,'targets'=>array_values($targets)];
  }
  return $groups;
 }
}
