<?php
define('TEST_DATABASE',':memory:');
define('TEST_MARKET_CONFIG',['analysis_enabled'=>true,'manual_advisory_enabled'=>true,'approved_strategy_versions'=>['manual-integration'=>'v1']]);
require dirname(__DIR__).'/src/App.php';
class AdvisoryIntegrationBinance extends BinanceMarketDataProvider {
 public int $at=1800000000;
 function now():int{return $this->at;}
 function price(string $symbol):float{return 100.;}
 function quote(string $symbol):array{throw new RuntimeException('isolated unavailable reference quote; no exchange request');}
 function monitoringMinutes(string $symbol,int $from,int $to):array{return [];}
}
$s=json_decode(file_get_contents(dirname(__DIR__).'/example-btc-short.json'),true);
$s['id']='manual-integration';$s['initial_stage']='start';$s['invalidation']=['left'=>['price'=>true],'op'=>'gt','right'=>120];unset($s['cancellation']);
$s['trade']=['entry'=>['type'=>'MARKET'],'quantity'=>.001,'stop_loss'=>110,'take_profits'=>[80]];
$s['stages']=[['id'=>'start','transitions'=>[['event'=>'price','when'=>['left'=>['price'=>true],'op'=>'gt','right'=>99],'signal'=>true]]]];
Strategy::validate($s);
$api=new AdvisoryIntegrationBinance();$original=encode(Strategy::fresh($s,$api->at-100));
db()->prepare('INSERT INTO strategies VALUES(?,?,?,?)')->execute([$s['id'],'v1',encode($s),$original]);
db()->prepare('INSERT INTO alerts(unique_key,created,payload) VALUES(?,?,?)')->execute(['historical',1,'{"historical":true}']);
runCycle($api,$api->at);
$p=json_decode(db()->query('SELECT payload FROM execution_signals')->fetchColumn(),true);
$alerts=db()->query('SELECT payload FROM alerts ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
if(count($alerts)!==2||json_decode($alerts[1],true)['type']!=='binance_signal'||$p['mode']!=='MANUAL_ADVISORY'||$p['status']!=='BLOCKED_RR_THRESHOLD_NOT_CONFIGURED')throw new RuntimeException('Real analysis pipeline did not emit initial advisory alert independently of gate blockers');
if($alerts[0]!=='{"historical":true}'||db()->query('SELECT state FROM strategies')->fetchColumn()!==$original)throw new RuntimeException('Historical alert or legacy state changed');
$deadline=$p['deadline'];$api->at+=10;runCycle($api,$api->at);
if((int)db()->query('SELECT COUNT(*) FROM alerts')->fetchColumn()!==2||(int)db()->query('SELECT COUNT(*) FROM execution_signals')->fetchColumn()!==1)throw new RuntimeException('Duplicate signal or alert');
$api->at=$deadline;runCycle($api,$api->at);
if(db()->query('SELECT status FROM execution_signals')->fetchColumn()!=='EXPIRED_TIMEOUT')throw new RuntimeException('Deadline reset during resumed cycle');
echo "Manual advisory engine integration: initial alert, blockers, deduplication, original deadline, historical alert and legacy state preserved.\n";
