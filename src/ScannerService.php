<?php
declare(strict_types=1);
require_once __DIR__.'/ScannerConfig.php';
require_once __DIR__.'/ScannerData.php';
require_once __DIR__.'/BreakRetestScanner.php';
require_once __DIR__.'/ScannerExecution.php';
require_once __DIR__.'/ScannerPaper.php';
require_once __DIR__.'/ScannerRunner.php';

final class ScannerService {
 static function migrate(PDO $db):void {
  $db->exec("CREATE TABLE IF NOT EXISTS scanner_runs(version TEXT PRIMARY KEY,created INTEGER NOT NULL,config TEXT NOT NULL,state TEXT NOT NULL,last_checked INTEGER,last_result TEXT);
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
 static function config():array {return ScannerConfig::validate(json_decode(setting('scanner_config','{}'),true,64,JSON_THROW_ON_ERROR));}
 static function row(array $c):array {
  self::migrate(db());$v=ScannerConfig::version($c);
  db()->prepare('INSERT OR IGNORE INTO scanner_runs(version,created,config,state) VALUES(?,?,?,?)')->execute([$v,time(),encode($c),encode(ScannerPaper::fresh($c))]);
  $q=db()->prepare('SELECT * FROM scanner_runs WHERE version=?');$q->execute([$v]);return $q->fetch(PDO::FETCH_ASSOC);
 }
 static function save(array $input):array {
  $c=ScannerConfig::validate($input);$old=self::config();$row=self::row($old);$state=json_decode($row['state'],true);
  if($state['paper']!==null&&ScannerConfig::version($old)!==ScannerConfig::version($c))throw new RuntimeException('تا تعیین تکلیف معاملهٔ آزمایشی باز، فقط فعال/غیرفعال‌کردن اسکنر مجاز است. نسخهٔ قبلی و سرمایهٔ آن حفظ می‌شود.');
  setSetting('scanner_config',encode($c));self::row($c);systemLog('info','scanner.config','تنظیمات اسکنر ذخیره شد؛ سفارش واقعی ثبت نمی‌شود.',['version'=>ScannerConfig::version($c)]);return $c;
 }
 static function persist(array $c,array $snapshot,array $row):array {
  $snapshot['runtime_enabled']=$c['enabled'];
  $state=json_decode($row['state'],true);$version=ScannerConfig::version($c);$now=$snapshot['observed_at'];
  $r=ScannerRunner::step($state,$snapshot,$c);
  if(($r['decision']['reason_code']??null)==='observation_order'&&$r['decision']['status']==='DUPLICATE')return $r;
  db()->beginTransaction();
  try{
   // The state transition, signal, ownership and notification all commit together.
   $update=db()->prepare('UPDATE scanner_runs SET state=?,last_checked=?,last_result=? WHERE version=? AND state=?');$update->execute([encode($state),$now,encode($r),$version,$row['state']]);
   if(!$update->rowCount()){db()->rollBack();return ['status'=>'CONCURRENT_UPDATE_DISCARDED'];}
   $decision=$r['decision'];$key=hash('sha256',encode([$version,$decision['candle_close']??intdiv($now,900)*900,$decision['status'],$decision['reason'],$decision['candidate']['id']??null]));
   if($decision['status']!=='DUPLICATE')db()->prepare('INSERT OR IGNORE INTO scanner_checks(version,observed,status,reason,payload,dedupe) VALUES(?,?,?,?,?,?)')->execute([$version,$now,$decision['status'],$decision['reason'],encode($decision),$key]);
   if($r['signal']){
    $p=$r['signal'];$q=db()->prepare('INSERT OR IGNORE INTO scanner_signals VALUES(?,?,?,?)');$q->execute([$p['id'],$version,$now,encode($p)]);
    if($q->rowCount()){
     $owner=(int)db()->query("SELECT id FROM users WHERE email='ashkufaraz@gmail.com'")->fetchColumn();$id=$p['json_strategy']['id'];
     db()->prepare('INSERT OR IGNORE INTO strategy_owners VALUES(?,?)')->execute([$id,$owner]);
     $alert=['type'=>'scanner_manual_review','strategy_id'=>$id,'name'=>'اسکنر شکست و بازآزمایی BTCUSDT','symbol'=>'BTCUSDT','action'=>$p['direction'],'price'=>$p['entry'],'event'=>'scanner:manual_review','event_time'=>$p['candle_close'],'status'=>'MANUAL_REVIEW_AVAILABLE','scanner_signal_id'=>$p['id'],'scanner_version'=>$version,'reason'=>$p['reason'],'expires_at'=>$p['expires_at'],'analysis_sources'=>[],'trade'=>$p['json_strategy']['trade']];
     $alert['config_snapshot']=$p['config_snapshot'];$alert['rules_version']=$p['rules_version'];$alert['exit_policy']=$p['exit_policy'];$alert['cost_assumptions']=$p['cost_assumptions'];
     db()->prepare('INSERT OR IGNORE INTO alerts(unique_key,created,payload) VALUES(?,?,?)')->execute(['scanner:'.$p['id'],$now,encode($alert)]);
    }
   }
   foreach($r['trade_events'] as $trade)db()->prepare('INSERT OR REPLACE INTO scanner_trades VALUES(?,?,?,?)')->execute([$trade['id'],$version,$trade['status'],encode($trade)]);
   if($state['paper'])db()->prepare('INSERT OR REPLACE INTO scanner_trades VALUES(?,?,?,?)')->execute([$state['paper']['id'],$version,$state['paper']['status'],encode($state['paper'])]);
   db()->prepare('INSERT INTO scanner_equity(version,observed,equity) VALUES(?,?,?)')->execute([$version,$now,$r['equity']]);
   if(($r['decision']['reason_code']??null)!=='observation_order')self::storeObservation($version,$snapshot);
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
 }
 static function tick(?Wallex $api=null,?int $at=null,?string $lockPath=null):array {
  $now=$at??time();self::migrate(db());$c=self::config();$row=self::row($c);$state=json_decode($row['state'],true);
  if(!$c['enabled']&&$state['paper']===null)return ['status'=>'DISABLED'];
  $lock=fopen($lockPath??dirname(__DIR__).'/data/scanner.lock','c');if(!$lock)throw new RuntimeException('Scanner lock unavailable');if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);return ['status'=>'BUSY'];}
  try{
   if($now-(int)setting('scanner_last_attempt','0')<55)return ['status'=>'NOT_DUE'];setSetting('scanner_last_attempt',(string)$now);
   $row=self::row($c);$cache=json_decode(setting('scanner_cache','{}'),true);if(($cache['version']??null)!==ScannerConfig::version($c))$cache=null;
   if(!$c['enabled']&&($state['paper']['status']??null)==='PENDING')return self::persist($c,['observed_at'=>$now,'provider'=>'wallex','symbol'=>'BTCUSDT','bars'=>[]],$row);
   try{$snapshot=ScannerData::fetch($api??new Wallex(microtime(true)+12),$c,$now,$cache);setSetting('scanner_cache',encode(['bars'=>$snapshot['bars'],'boundaries'=>$snapshot['boundaries'],'version'=>ScannerConfig::version($c)]));return self::persist($c,$snapshot,$row);}
   catch(Throwable $e){
    $result=self::persist($c,['observed_at'=>$now,'provider'=>'wallex','symbol'=>'BTCUSDT','bars'=>[],'data_error'=>$e->getMessage()],$row);
    systemLog('warning','scanner.unavailable',$e->getMessage());return $result;
   }
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 static function view():array {
  $c=self::config();$row=self::row($c);$v=ScannerConfig::version($c);$get=function(string $sql)use($v){$q=db()->prepare($sql);$q->execute([$v]);return $q->fetchAll(PDO::FETCH_ASSOC);};
  $signals=$get('SELECT payload FROM scanner_signals WHERE version=? ORDER BY created DESC LIMIT 30');$checks=$get('SELECT observed,status,reason,payload FROM scanner_checks WHERE version=? ORDER BY id DESC LIMIT 50');
  $trades=array_map(fn($r)=>json_decode($r['payload'],true),$get('SELECT payload FROM scanner_trades WHERE version=? ORDER BY rowid'));$equity=$get('SELECT equity FROM scanner_equity WHERE version=? ORDER BY id');
  foreach($equity as &$point)$point['equity']=$point['equity']===null?null:(float)$point['equity'];unset($point);array_unshift($equity,['equity'=>$c['capital']]);
  $count=$get('SELECT status,count(*) AS count FROM scanner_checks WHERE version=? GROUP BY status');$report=ScannerPaper::report($trades,$equity,$c['capital']);
  $report['open_or_pending']=count(array_filter($trades,fn($t)=>in_array($t['status'],['OPEN','PENDING'],true)));$report['rejected_decisions']=array_sum(array_map(fn($r)=>in_array($r['status'],['REJECTED','UNAVAILABLE'],true)?(int)$r['count']:0,$count));
  $last=db()->query('SELECT id,created,version,payload FROM scanner_backtests ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
  return ['config'=>$c,'version'=>$v,'enabled'=>$c['enabled'],'last_checked'=>$row['last_checked'],'last_result'=>$row['last_result']?json_decode($row['last_result'],true):null,'state'=>json_decode($row['state'],true),'signals'=>array_map(fn($r)=>json_decode($r['payload'],true),$signals),'checks'=>array_map(fn($r)=>$r+['decision'=>json_decode($r['payload'],true)],$checks),'trades'=>array_slice(array_reverse($trades),0,30),'report'=>$report,'last_backtest'=>$last?['id'=>(int)$last['id'],'created'=>(int)$last['created'],'version'=>$last['version'],'report'=>json_decode($last['payload'],true)['report']]:null,'can_manage'=>(currentAccount()['role']??'')==='owner','real_orders'=>false];
 }
 static function replay():array {
  $c=self::config();$v=ScannerConfig::version($c);$q=db()->prepare('SELECT payload FROM scanner_observations WHERE version=? ORDER BY id DESC LIMIT 500');$q->execute([$v]);$observations=array_reverse(array_map(fn($r)=>json_decode($r['payload'],true),$q->fetchAll(PDO::FETCH_ASSOC)));
  if(!$observations)throw new RuntimeException('هنوز مشاهدهٔ ثبت‌شده‌ای برای بازپخش وجود ندارد.');
  $q=db()->prepare('SELECT timeframe,payload FROM scanner_candles WHERE version=? ORDER BY opened');$q->execute([$v]);$bars=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$bars[$r['timeframe']][]=json_decode($r['payload'],true);
  $result=ScannerRunner::backtest(['provider'=>'wallex','symbol'=>'BTCUSDT','bars'=>$bars,'observations'=>$observations],$c);
  db()->prepare('INSERT INTO scanner_backtests(created,version,payload) VALUES(?,?,?)')->execute([time(),$v,encode($result)]);$result['backtest_id']=(int)db()->lastInsertId();return $result;
 }
}
