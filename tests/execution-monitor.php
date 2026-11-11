<?php
define('TEST_DATABASE',':memory:');require dirname(__DIR__).'/src/App.php';
$n=0;function monitorCheck(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
$now=1800000000;
$config=MarketArchitecture::validate(['analysis_enabled'=>true,'enabled'=>true,'min_remaining_rr'=>1.,'instrument'=>['verified'=>true,'type'=>'spot','reference_type'=>'spot','symbol'=>'BTCUSDT','evidence'=>'https://example.test/test-only-mapping'],'orderbook'=>['verified'=>true,'path'=>'/v1/test-book','bids'=>'bids','asks'=>'asks','timestamp'=>'time','timestamp_unit'=>'seconds','evidence'=>'https://example.test/test-only-book']]);
class MonitorBinance extends BinanceMarketDataProvider {
 public int $at=1800000000;public float $current=100.;public bool $offline=false;
 function now():int{return $this->at;}
 function price(string $s):float{if($this->offline)throw new RuntimeException('Binance offline');return $this->current;}
 function quote(string $s):array{if($this->offline)throw new RuntimeException('Binance offline');return ['provider'=>'binance_spot','symbol'=>$s,'bid'=>99.9,'ask'=>100.1,'mid'=>100.,'timestamp'=>$this->at,'observed_at'=>$this->at,'sample_id'=>'b-'.$this->at];}
 function monitoringMinutes(string $s,int $from,int $now):array{return [$from=>['high'=>101.,'low'=>99.]];}
}
// Synthetic verified quotes exercise the lifecycle, not the real REST provider.
// They do not claim that the live Wallex endpoint has a source timestamp.
class MonitorWallex implements ExecutionQuoteProvider {public int $at=1800000000;public float $mid=100.;function quote(string $symbol,int $now):array{return ['provider'=>'wallex','symbol'=>$symbol,'bid'=>$this->mid-.1,'ask'=>$this->mid+.1,'mid'=>$this->mid,'timestamp'=>$this->at,'observed_at'=>$this->at,'sample_id'=>'w-'.$this->at,'trust_status'=>'VERIFIED'];}}
ExecutionGate::migrate(db());$api=new MonitorBinance();$wallex=new MonitorWallex();$provider=$wallex;
for($i=0;$i<150;$i++){$at=$now-1500+$i*10;$r=['provider'=>'binance_spot','symbol'=>'BTCUSDT','bid'=>99.9,'ask'=>100.1,'timestamp'=>$at,'observed_at'=>$at,'sample_id'=>'r-'.$at];$w=$r;$w['provider']='wallex';$w['trust_status']='VERIFIED';$w['sample_id']='w-'.$at;ExecutionGate::sample(db(),'BTCUSDT',$r,$w,$at,$config);}
$s=json_decode(file_get_contents(dirname(__DIR__).'/example-btc-short.json'),true);$s['id']='monitor';$s['action']='LONG';$s['trade']=['entry'=>['type'=>'MARKET'],'stop_loss'=>90,'take_profits'=>[120]];$s['invalidation']=['left'=>['price'=>true],'op'=>'lt','right'=>80];unset($s['cancellation']);
$id=ExecutionGate::create(db(),$s,'v1','price',$now,100.,null,$config,$now);
$state=fn()=>json_decode(db()->query('SELECT payload FROM execution_signals')->fetchColumn(),true);
for($i=0;$i<3;$i++){$api->at=$wallex->at=$now+$i*10;ExecutionMonitor::run($api,$api->at,$config,$provider);}
monitorCheck($state()['status']==='READY_FOR_MANUAL_ENTRY','monitor service reaches READY through persisted calibration and three fresh pairs');
monitorCheck((int)db()->query('SELECT COUNT(*) FROM alerts')->fetchColumn()===1,'monitor persists one final notification');
$api->at=$wallex->at=$now+30;$wallex->mid=110.;ExecutionMonitor::run($api,$api->at,$config,$provider);
monitorCheck($state()['status']==='WAITING_BASIS'&&isset($state()['revoked_at']),'live pair deterioration revokes READY');
$wallex->mid=100.;for($i=4;$i<7;$i++){$api->at=$wallex->at=$now+$i*10;ExecutionMonitor::run($api,$api->at,$config,$provider);}
monitorCheck($state()['status']==='READY_FOR_MANUAL_ENTRY'&&(int)db()->query('SELECT COUNT(*) FROM alerts')->fetchColumn()===1,'recovery can restore READY without duplicate alert');
$api->at=$wallex->at=$now+70;$api->offline=true;ExecutionMonitor::run($api,$api->at,$config,$provider);
monitorCheck($state()['status']==='WAITING_DATA'&&$state()['confirmations']===[],'API outage fails closed and clears confirmations');
$api->offline=false;$api->at=$wallex->at=$now+80;ExecutionMonitor::run($api,$api->at,$config,$provider);
monitorCheck($state()['status']==='WAITING_BASIS'&&count($state()['confirmations'])===1,'API recovery starts fresh confirmation sequence');
$api->current=89.;$api->at=$wallex->at=$now+90;ExecutionMonitor::run($api,$api->at,$config,$provider);
monitorCheck($state()['status']==='CANCELLED_STOP_LOSS','stop touch cancels pending signal in full service');
$api->current=100.;$api->at=$wallex->at=$now+100;ExecutionMonitor::run($api,$api->at,$config,$provider);
monitorCheck($state()['status']==='CANCELLED_STOP_LOSS','terminal signal never revives automatically');
class DelayedMonitorBinance extends MonitorBinance {function quote(string $s):array{$this->at+=5;return parent::quote($s);}}
$delayed=new DelayedMonitorBinance();$delayedId=ExecutionGate::create(db(),$s,'v2','price',$now+200,100.,null,$config,$now+200);
for($i=0;$i<3;$i++){$delayed->at=$wallex->at=$now+200+$i*10;ExecutionMonitor::run($delayed,$delayed->at,$config,$provider);}
$q=db()->prepare('SELECT payload FROM execution_signals WHERE id=?');$q->execute([$delayedId]);$d=json_decode($q->fetchColumn(),true);
monitorCheck($d['status']==='READY_FOR_MANUAL_ENTRY'&&$d['checked_at']===$now+225,'network latency uses actual observation time rather than stale scheduler start');
echo "Execution monitor service: $n assertions passed.\n";
