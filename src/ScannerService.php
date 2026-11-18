<?php
declare(strict_types=1);
require_once __DIR__.'/ScannerConfig.php';
require_once __DIR__.'/ScannerData.php';
require_once __DIR__.'/BreakRetestScanner.php';
require_once __DIR__.'/ScannerExecution.php';
require_once __DIR__.'/ScannerPaper.php';
require_once __DIR__.'/ScannerRunner.php';
require_once __DIR__.'/ScannerReadiness.php';
require_once __DIR__.'/PatternService.php';

final class ScannerService {
 static function migrate(PDO $db):void {
  $db->exec("CREATE TABLE IF NOT EXISTS scanner_runs(version TEXT PRIMARY KEY,created INTEGER NOT NULL,config TEXT NOT NULL,state TEXT NOT NULL,last_checked INTEGER,last_result TEXT);
   CREATE TABLE IF NOT EXISTS scanner_totals(version TEXT PRIMARY KEY,payload TEXT NOT NULL);
   CREATE TABLE IF NOT EXISTS scanner_checks(id INTEGER PRIMARY KEY AUTOINCREMENT,version TEXT NOT NULL,observed INTEGER NOT NULL,status TEXT NOT NULL,reason TEXT NOT NULL,payload TEXT NOT NULL,dedupe TEXT UNIQUE NOT NULL);
   CREATE TABLE IF NOT EXISTS scanner_signals(id TEXT PRIMARY KEY,version TEXT NOT NULL,created INTEGER NOT NULL,payload TEXT NOT NULL);
   CREATE TABLE IF NOT EXISTS scanner_trades(id TEXT PRIMARY KEY,version TEXT NOT NULL,status TEXT NOT NULL,payload TEXT NOT NULL);
   CREATE TABLE IF NOT EXISTS scanner_equity(id INTEGER PRIMARY KEY AUTOINCREMENT,version TEXT NOT NULL,observed INTEGER NOT NULL,equity REAL);
   CREATE TABLE IF NOT EXISTS scanner_candles(version TEXT NOT NULL,timeframe TEXT NOT NULL,opened INTEGER NOT NULL,payload TEXT NOT NULL,PRIMARY KEY(version,timeframe,opened));
   CREATE TABLE IF NOT EXISTS scanner_observations(id INTEGER PRIMARY KEY AUTOINCREMENT,version TEXT NOT NULL,observed INTEGER NOT NULL,payload TEXT NOT NULL);
   CREATE TABLE IF NOT EXISTS scanner_backtests(id INTEGER PRIMARY KEY AUTOINCREMENT,created INTEGER NOT NULL,version TEXT NOT NULL,payload TEXT NOT NULL);
   CREATE INDEX IF NOT EXISTS scanner_checks_version ON scanner_checks(version,id);
   CREATE INDEX IF NOT EXISTS scanner_observations_version ON scanner_observations(version,id);
   CREATE INDEX IF NOT EXISTS scanner_observations_time ON scanner_observations(version,observed);");
 }
 static function config():array {$c=ScannerConfig::validate(json_decode(MarketScope::setting('scanner_config','{}'),true,64,JSON_THROW_ON_ERROR));if(!defined('TEST_DATABASE'))ScannerConfig::operational($c);return $c;}
 static function row(array $c):array {
  self::migrate(db());$v=ScannerConfig::version($c);
  db()->prepare('INSERT OR IGNORE INTO scanner_runs(version,created,config,state) VALUES(?,?,?,?)')->execute([$v,time(),encode($c),encode(ScannerPaper::fresh($c))]);
  if(!defined('TEST_DATABASE')&&self::capitalConflict($c,$v)!==null)throw new RuntimeException('POSITION_ALREADY_OPEN: this account/exchange capital is reserved by another evaluation');
  $q=db()->prepare('SELECT * FROM scanner_runs WHERE version=?');$q->execute([$v]);return $q->fetch(PDO::FETCH_ASSOC);
 }
 static function capitalConflict(array $c,string $version):?string {self::migrate(db());foreach(db()->query('SELECT version,config,state FROM scanner_runs')->fetchAll(PDO::FETCH_ASSOC) as $other){$oc=json_decode($other['config'],true);if($other['version']!==$version&&($oc['exchange']??'wallex')===($c['exchange']??'wallex')&&($oc['account_id']??0)===($c['account_id']??0)&&(json_decode($other['state'],true)['paper']??null)!==null)return $other['version'];}return null;}
 static function lockPath():string {$s=MarketScope::current();return dirname(__DIR__).'/data/scanner-'.$s['account_id'].'-'.$s['exchange'].'.lock';}
 static function save(array $input):array {
  $lock=fopen(self::lockPath(),'c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Scanner is busy; retry configuration after the current observation');try{
  $c=ScannerConfig::validate($input);if(!defined('TEST_DATABASE'))ScannerConfig::operational($c);$old=self::config();$row=self::row($old);$state=json_decode($row['state'],true);
  if($state['paper']!==null&&ScannerConfig::version($old)!==ScannerConfig::version($c))throw new RuntimeException('تا تعیین تکلیف معاملهٔ آزمایشی باز، فقط فعال/غیرفعال‌کردن اسکنر مجاز است. نسخهٔ قبلی و سرمایهٔ آن حفظ می‌شود.');
  MarketScope::set('scanner_config',encode($c));self::row($c);systemLog('info','scanner.config','تنظیمات اسکنر ذخیره شد؛ سفارش واقعی ثبت نمی‌شود.',['version'=>ScannerConfig::version($c)]);return $c;
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 static function persist(array $c,array $snapshot,array $row):array {
  if(!defined('TEST_DATABASE')&&ScannerConfig::version($c)!==ScannerConfig::version(self::config()))return ['status'=>'CONFIGURATION_CHANGED_DISCARDED'];
  $snapshot['runtime_enabled']=$c['enabled'];
  $state=json_decode($row['state'],true);$version=ScannerConfig::version($c);$now=$snapshot['observed_at'];
  $before=$state;$r=ScannerRunner::step($state,$snapshot,$c);
  if(($r['decision']['reason_code']??null)==='observation_order'&&$r['decision']['status']==='DUPLICATE')return $r;
  $stages=ScannerReadiness::stages($snapshot,$c,$state,$r);$r['stages']=$stages;
  db()->beginTransaction();
  try{
   // The state transition, signal, ownership and notification all commit together.
   $update=db()->prepare('UPDATE scanner_runs SET state=?,last_checked=?,last_result=? WHERE version=? AND state=?');$update->execute([encode($state),$now,encode($r),$version,$row['state']]);
   if(!$update->rowCount()){db()->rollBack();return ['status'=>'CONCURRENT_UPDATE_DISCARDED'];}
   $q=db()->prepare('SELECT payload FROM scanner_totals WHERE version=?');$q->execute([$version]);$total=json_decode($q->fetchColumn()?:'[]',true);$total=ScannerReadiness::tally($total,$snapshot,$stages,$r,$c['observation_interval_seconds']);db()->prepare('INSERT OR REPLACE INTO scanner_totals VALUES(?,?)')->execute([$version,encode($total)]);
   $decision=$r['decision'];if(isset($decision['candidate']))PatternService::recordExecution($decision['candidate'],$decision,$now);$key=hash('sha256',encode([$version,$decision['candle_close']??intdiv($now,900)*900,$decision['status'],$decision['reason'],$decision['candidate']['id']??null]));
   if($decision['status']!=='DUPLICATE')db()->prepare('INSERT OR IGNORE INTO scanner_checks(version,observed,status,reason,payload,dedupe) VALUES(?,?,?,?,?,?)')->execute([$version,$now,$decision['status'],$decision['reason'],encode($decision),$key]);
   if($r['signal']){
    $p=$r['signal'];$q=db()->prepare('INSERT OR IGNORE INTO scanner_signals VALUES(?,?,?,?)');$q->execute([$p['id'],$version,$now,encode($p)]);
    if($q->rowCount()){
     $owner=(int)db()->query("SELECT id FROM users WHERE email='ashkufaraz@gmail.com'")->fetchColumn();$owner=($c['account_id']??0)?:$owner;$id=$p['json_strategy']['id'];
     db()->prepare('INSERT OR IGNORE INTO strategy_owners VALUES(?,?)')->execute([$id,$owner]);
     $alert=['exchange'=>$c['exchange']??'wallex','account_id'=>$c['account_id']??0,'type'=>'scanner_manual_review','strategy_id'=>$id,'name'=>MarketScope::label($c['exchange']??'wallex').' '.$c['symbol'].' '.PatternConfig::name(PatternConfig::BREAKOUT),'symbol'=>$c['symbol'],'action'=>$p['direction'],'price'=>$p['entry'],'event'=>'scanner:manual_review','event_time'=>$p['candle_close'],'status'=>'MANUAL_REVIEW_AVAILABLE','scanner_signal_id'=>$p['id'],'scanner_version'=>$version,'reason'=>$p['reason'],'expires_at'=>$p['expires_at'],'analysis_sources'=>[],'trade'=>$p['json_strategy']['trade']];
     $alert['scanner_plan']=$p['json_strategy']['scanner_plan'];$alert['invalidation_price']=$p['invalidation_price'];$alert['entry_model']=$p['entry_model'];$alert['config_snapshot']=$p['config_snapshot'];$alert['rules_version']=$p['rules_version'];$alert['exit_policy']=$p['exit_policy'];$alert['cost_assumptions']=$p['cost_assumptions'];
     db()->prepare('INSERT OR IGNORE INTO alerts(unique_key,created,payload) VALUES(?,?,?)')->execute(['scanner:opportunity:'.PatternConfig::opportunity($p['symbol'],PatternConfig::BREAKOUT,$p['direction'],$p['candle_close'],$c['exchange']??'wallex',$c['account_id']??0),$now,encode($alert)]);
    }
   }
   foreach($r['trade_events'] as $trade)db()->prepare('INSERT OR REPLACE INTO scanner_trades VALUES(?,?,?,?)')->execute([$trade['id'],$version,$trade['status'],encode($trade)]);
   if($state['paper'])db()->prepare('INSERT OR REPLACE INTO scanner_trades VALUES(?,?,?,?)')->execute([$state['paper']['id'],$version,$state['paper']['status'],encode($state['paper'])]);
   db()->prepare('INSERT INTO scanner_equity(version,observed,equity) VALUES(?,?,?)')->execute([$version,$now,$r['equity']]);
   if(($r['decision']['reason_code']??null)!=='observation_order')self::storeObservation($version,$snapshot+['run_id'=>$version,'execution_id'=>bin2hex(random_bytes(12)),'observation_id'=>hash('sha256',$version.':'.$now),'code_version'=>ScannerReadiness::codeVersion(),'rules_version'=>ScannerConfig::RULES_VERSION,'config_snapshot'=>$c,'received_at'=>$snapshot['received_at']??$now,'source_timestamp'=>$snapshot['book']['source_timestamp']??null,'stages'=>$stages,'result'=>array_diff_key($r,['stages'=>true]),'state_before'=>$before,'state_after'=>$state]);
   db()->commit();
  }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
  if($r['signal'])systemLog('success','scanner.manual_review','فرصت اسکنر برای بررسی دستی ثبت شد؛ فقط معامله آزمایشی.',['signal_id'=>$r['signal']['id'],'version'=>$version]);
  return $r;
 }
 static function storeObservation(string $v,array $snapshot):void {
  $find=db()->prepare('SELECT payload FROM scanner_candles WHERE version=? AND timeframe=? AND opened=?');$put=db()->prepare('INSERT OR IGNORE INTO scanner_candles VALUES(?,?,?,?)');
  foreach($snapshot['bars']??[] as $tf=>$bars)foreach($bars as $bar){if(!is_int($bar['time']??null)||$bar['time']+ScannerData::SECONDS[$tf]>$snapshot['observed_at'])continue;
   $find->execute([$v,$tf,$bar['time']]);$old=$find->fetchColumn();$serialized=encode($bar);if($old!==false&&json_decode($old,true)!=$bar)throw new RuntimeException('UNAVAILABLE: previously stored closed candle revised by source');
   $put->execute([$v,$tf,$bar['time'],$serialized]);
  }
  $slim=$snapshot;unset($slim['bars'],$slim['boundaries']);$slim['available_candles']=[];
  foreach($snapshot['bars']??[] as $tf=>$bars)$slim['available_candles'][$tf]=array_values(array_map(fn($b)=>$b['time'],array_filter($bars,fn($b)=>is_int($b['time']??null)&&$b['time']+ScannerData::SECONDS[$tf]<=$snapshot['observed_at'])));
  db()->prepare('INSERT INTO scanner_observations(version,observed,payload) SELECT ?,?,? WHERE NOT EXISTS(SELECT 1 FROM scanner_observations WHERE version=? AND observed=?)')->execute([$v,$snapshot['observed_at'],encode($slim),$v,$snapshot['observed_at']]);
  db()->prepare('DELETE FROM scanner_observations WHERE version=? AND id NOT IN (SELECT id FROM scanner_observations WHERE version=? ORDER BY id DESC LIMIT 10000)')->execute([$v,$v]);
  db()->prepare('DELETE FROM scanner_observations WHERE version=? AND observed<?')->execute([$v,$snapshot['observed_at']-7*86400]);
  while((int)db()->query('SELECT coalesce(sum(length(payload)),0) FROM scanner_observations')->fetchColumn()>67108864)db()->exec('DELETE FROM scanner_observations WHERE id IN (SELECT id FROM scanner_observations ORDER BY id LIMIT 100)');
  $oldest=(int)(db()->query('SELECT min(observed) FROM scanner_observations')->fetchColumn()?:$snapshot['observed_at']);db()->prepare('DELETE FROM scanner_candles WHERE opened<?')->execute([$oldest-ScannerConfig::counts($snapshot['config_snapshot'])['4h']*14400-14400]);
 }
 static function tick(?Wallex $api=null,?int $at=null,?string $lockPath=null):array {
  if(!defined('TEST_DATABASE')){try{PatternService::tick($api,$at);}catch(Throwable $e){systemLog('warning','pattern.unavailable',$e->getMessage());}}
  $now=$at??time();self::migrate(db());$c=self::config();$row=self::row($c);$state=json_decode($row['state'],true);
  if(!$c['enabled']&&$state['paper']===null)return ['status'=>'DISABLED'];
  $lock=fopen($lockPath??self::lockPath(),'c');if(!$lock)throw new RuntimeException('Scanner lock unavailable');if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);return ['status'=>'BUSY'];}
  try{
   $c=self::config();$row=self::row($c);$state=json_decode($row['state'],true);
   if($now-(int)MarketScope::setting('scanner_last_attempt','0')<$c['observation_interval_seconds'])return ['status'=>'NOT_DUE'];MarketScope::set('scanner_last_attempt',(string)$now);
   $row=self::row($c);$cache=json_decode(MarketScope::setting('scanner_cache','{}'),true);if(($cache['version']??null)!==ScannerConfig::version($c))$cache=null;
   if(!$c['enabled']&&($state['paper']['status']??null)==='PENDING')return self::persist($c,['observed_at'=>$now,'provider'=>$c['exchange']??'wallex','symbol'=>$c['symbol'],'bars'=>[]],$row);
   try{$snapshot=ScannerData::fetch($api??MarketData::provider($c['exchange']??'wallex',microtime(true)+18),$c,$now,$cache);MarketScope::set('scanner_cache',encode(['bars'=>$snapshot['bars'],'boundaries'=>$snapshot['boundaries']??[],'version'=>ScannerConfig::version($c)]));return self::persist($c,$snapshot,$row);}
   catch(Throwable $e){
    $result=self::persist($c,['observed_at'=>$now,'provider'=>$c['exchange']??'wallex','symbol'=>$c['symbol'],'bars'=>[],'data_error'=>$e->getMessage()],$row);
    systemLog('warning','scanner.unavailable',$e->getMessage());return $result;
   }
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 static function tickAll(?int $at=null,?Closure $factory=null):array {$out=[];foreach(MarketScope::markets() as $scope){try{$out[MarketScope::key($scope)]=MarketScope::run($scope,fn()=>self::tick($factory?$factory($scope['exchange']):MarketData::provider($scope['exchange'],microtime(true)+8),$at));}catch(Throwable $e){$out[MarketScope::key($scope)]=['status'=>'UNAVAILABLE','error'=>$e->getMessage()];}}return $out;}
 static function viewAll():array {$out=[];foreach(MarketScope::markets() as $scope){try{$out[MarketScope::key($scope)]=MarketScope::run($scope,fn()=>self::view());}catch(Throwable $e){$out[MarketScope::key($scope)]=$scope+['error'=>$e->getMessage()];}}return ['markets'=>$out];}
 static function view():array {
  $c=self::config();$row=self::row($c);$v=ScannerConfig::version($c);$get=function(string $sql)use($v){$q=db()->prepare($sql);$q->execute([$v]);return $q->fetchAll(PDO::FETCH_ASSOC);};
  $signals=$get('SELECT payload FROM scanner_signals WHERE version=? ORDER BY created DESC LIMIT 30');$checks=$get('SELECT observed,status,reason,payload FROM scanner_checks WHERE version=? ORDER BY id DESC LIMIT 50');
  $trades=array_map(fn($r)=>json_decode($r['payload'],true),$get('SELECT payload FROM scanner_trades WHERE version=? ORDER BY rowid'));$equity=$get('SELECT equity FROM scanner_equity WHERE version=? ORDER BY id');
  foreach($equity as &$point)$point['equity']=$point['equity']===null?null:(float)$point['equity'];unset($point);array_unshift($equity,['equity'=>$c['capital']]);
  $count=$get('SELECT status,count(*) AS count FROM scanner_checks WHERE version=? GROUP BY status');$report=ScannerPaper::report($trades,$equity,$c['capital']);
  $report['open_or_pending']=count(array_filter($trades,fn($t)=>in_array($t['status'],['OPEN','PENDING'],true)));$report['rejected_decisions']=array_sum(array_map(fn($r)=>in_array($r['status'],['REJECTED','UNAVAILABLE'],true)?(int)$r['count']:0,$count));
  $q=db()->prepare('SELECT observed,payload FROM scanner_observations WHERE version=? ORDER BY id');$q->execute([$v]);$obs=[];while($o=$q->fetch(PDO::FETCH_ASSOC)){$p=json_decode($o['payload'],true);$decision=$p['result']['decision']??[];$candidate=$decision['candidate']??null;$obs[]=['observed'=>$o['observed'],'payload'=>encode(['result'=>['decision'=>array_intersect_key($decision,array_flip(['status','reason','reason_code','terminal']))+['candidate'=>$candidate?['id'=>$candidate['id']]:null],'signal'=>isset($p['result']['signal'])?['id'=>$p['result']['signal']['id']]:null],'stages'=>['indicators'=>['status'=>$p['stages']['indicators']['status']??null],'manual_entry'=>['reasons'=>$p['stages']['manual_entry']['reasons']??[]]]])];}$q=db()->prepare('SELECT payload FROM scanner_totals WHERE version=?');$q->execute([$v]);$total=json_decode($q->fetchColumn()?:'null',true);$report=array_replace($report,ScannerReadiness::period($c,$row,json_decode($row['state'],true),$obs,$trades,$equity,$total));
  $q=db()->prepare('SELECT id,created,version,payload FROM scanner_backtests WHERE version=? ORDER BY id DESC LIMIT 1');$q->execute([$v]);$last=$q->fetch(PDO::FETCH_ASSOC);
  return ['exchange'=>$c['exchange']??'wallex','symbol'=>$c['symbol'],'account_id'=>$c['account_id']??0,'market_key'=>MarketScope::key(),'config'=>$c,'version'=>$v,'enabled'=>$c['enabled'],'last_checked'=>$row['last_checked'],'last_result'=>$row['last_result']?json_decode($row['last_result'],true):null,'state'=>json_decode($row['state'],true),'signals'=>array_map(fn($r)=>json_decode($r['payload'],true),$signals),'checks'=>array_map(fn($r)=>$r+['decision'=>json_decode($r['payload'],true)],$checks),'trades'=>array_slice(array_reverse($trades),0,30),'report'=>$report,'last_backtest'=>$last?['id'=>(int)$last['id'],'created'=>(int)$last['created'],'version'=>$last['version'],'report'=>json_decode($last['payload'],true)['report']]:null,'can_manage'=>(currentAccount()['role']??'')==='owner','real_orders'=>false];
 }
 static function dataset(int $limit=500):array {
  $limit=max(1,min(10000,$limit));
  $c=self::config();$v=ScannerConfig::version($c);$q=db()->prepare('SELECT payload FROM scanner_observations WHERE version=? ORDER BY id DESC LIMIT '.$limit);$q->execute([$v]);$observations=array_reverse(array_map(fn($r)=>json_decode($r['payload'],true),$q->fetchAll(PDO::FETCH_ASSOC)));
  if(!$observations)throw new RuntimeException('هنوز مشاهدهٔ ثبت‌شده‌ای برای بازپخش وجود ندارد.');
  $q=db()->prepare('SELECT timeframe,payload FROM scanner_candles WHERE version=? ORDER BY opened');$q->execute([$v]);$bars=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$bars[$r['timeframe']][]=json_decode($r['payload'],true);
  return ['exchange'=>$c['exchange']??'wallex','provider'=>$c['exchange']??'wallex','symbol'=>$c['symbol'],'bars'=>$bars,'observations'=>$observations,'initial_state'=>$observations[0]['state_before']??ScannerPaper::fresh($c),'config'=>$observations[0]['config_snapshot']??$c];
 }
 static function replay():array {
  $data=self::dataset();$c=$data['config'];$v=ScannerConfig::version($c);$result=ScannerRunner::backtest($data,$c);
  db()->prepare('INSERT INTO scanner_backtests(created,version,payload) VALUES(?,?,?)')->execute([time(),$v,encode($result)]);$result['backtest_id']=(int)db()->lastInsertId();db()->exec('DELETE FROM scanner_backtests WHERE id NOT IN (SELECT id FROM scanner_backtests ORDER BY id DESC LIMIT 20)');return $result;
 }
}
