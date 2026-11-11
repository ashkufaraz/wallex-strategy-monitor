<?php
define('TEST_DATABASE', ':memory:');
define('TEST_MARKET_CONFIG',['analysis_enabled'=>true,'enabled'=>false,'approved_strategy_versions'=>['binance-test'=>'v1']]);
require dirname(__DIR__).'/src/App.php';
$n=0;function integrationGateCheck(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
class IntegrationBinance extends BinanceMarketDataProvider {
 public float $p=100.;public int $checks=0;
 function now():int{return 1800000000;}
 function price(string $s):float{$this->checks++;return $this->p;}
 function monitoringMinutes(string $s,int $from,int $now):array{return [$from=>['open'=>100.,'high'=>101.,'low'=>99.,'close'=>100.,'volume'=>1.]];}
}
$s=json_decode(file_get_contents(dirname(__DIR__).'/example-btc-short.json'),true);$s['id']='binance-test';$s['action']='LONG';$s['invalidation']=['left'=>['price'=>true],'op'=>'lt','right'=>80];$s['trade']=['entry'=>['type'=>'MARKET'],'stop_loss'=>90,'take_profits'=>[120]];unset($s['cancellation']);
$s['initial_stage']='start';$s['stages']=[['id'=>'start','transitions'=>[['event'=>'price','when'=>['left'=>['price'=>true],'op'=>'gt','right'=>99],'signal'=>true]]]];Strategy::validate($s);
$now=1800000000;$legacy=Strategy::fresh($s,$now-100);$legacy['last_price']=999.;$legacy['custom_marker']='unchanged';
db()->prepare('INSERT INTO strategies VALUES(?,?,?,?)')->execute([$s['id'],'v1',encode($s),encode($legacy)]);
db()->prepare('INSERT INTO alerts(unique_key,created,payload) VALUES(?,?,?)')->execute(['old-alert',$now-100,'{"old":true}']);
$before=db()->query('SELECT * FROM strategies')->fetch(PDO::FETCH_ASSOC);
$api=new IntegrationBinance();runCycle($api,$now);
integrationGateCheck((int)db()->query('SELECT COUNT(*) FROM execution_signals')->fetchColumn()===1,'Binance engine signal persisted independently');
integrationGateCheck((int)db()->query('SELECT COUNT(*) FROM alerts')->fetchColumn()===1,'no entry alert before gate readiness; original alert retained');
integrationGateCheck(db()->query('SELECT * FROM strategies')->fetch(PDO::FETCH_ASSOC)===$before,'original strategy config, version and Wallex state untouched');
$signal=json_decode(db()->query('SELECT payload FROM execution_signals')->fetchColumn(),true);
integrationGateCheck((float)$signal['signal_price']===100.&&$signal['analysis_provider']==='binance_spot','signal snapshot uses Binance price, not legacy Wallex price');
integrationGateCheck($signal['status']==='WAITING_DATA'&&$signal['deadline']===$now+900,'disabled gate remains waiting with original deadline');
$state=displayedState($before,MarketArchitecture::config());integrationGateCheck((float)$state['last_price']===100.&&$state['stage']==='start','UI receives independent Binance state');
$report=json_decode(db()->query('SELECT payload FROM check_logs')->fetchColumn(),true);integrationGateCheck($report['analysis_provider']==='binance_spot','diagnostics identify actual provider');
runCycle(new IntegrationBinance(),$now+10);
integrationGateCheck((int)db()->query('SELECT COUNT(*) FROM execution_signals')->fetchColumn()===1,'pending signal not regenerated or original event replayed');
integrationGateCheck((int)db()->query('SELECT COUNT(*) FROM check_logs')->fetchColumn()===1,'pending gate does not execute strategy transition again');
runCycle(new IntegrationBinance(),$now+900);
integrationGateCheck(db()->query('SELECT status FROM execution_signals')->fetchColumn()==='EXPIRED_TIMEOUT','new worker instance does not reset deadline');
integrationGateCheck(db()->query('SELECT * FROM strategies')->fetch(PDO::FETCH_ASSOC)===$before,'legacy state remains unchanged after pending monitoring');
$s['id']='not-approved';db()->prepare('INSERT INTO strategies VALUES(?,?,?,?)')->execute([$s['id'],'v1',encode($s),encode(Strategy::fresh($s,$now))]);
runCycle(new IntegrationBinance(),$now+901,'not-approved');
integrationGateCheck((int)db()->query("SELECT COUNT(*) FROM analysis_states WHERE strategy_id='not-approved'")->fetchColumn()===0,'no silent migration without explicit version approval');
require dirname(__DIR__).'/src/BrowserRunner.php';
db()->exec("UPDATE strategies SET config=json_set(config,'$.enabled',json('false'))");
$s['id']='pending-without-enabled-strategy';$newId=ExecutionGate::create(db(),$s,'v2','price',$now,100.,null,MarketArchitecture::config(),$now);
$lock=tempnam(sys_get_temp_dir(),'gate-browser-');
try{$result=browserCheck(new IntegrationBinance(),$now+900,$lock);$q=db()->prepare('SELECT status FROM execution_signals WHERE id=?');$q->execute([$newId]);integrationGateCheck($result['status']==='gate_checked'&&$q->fetchColumn()==='EXPIRED_TIMEOUT','browser runner monitors pending deadlines even when no strategy is due');}finally{unlink($lock);}
echo "Binance integration: $n assertions passed.\n";
