<?php
if(PHP_SAPI!=='cli')exit;
define('TEST_DATABASE',':memory:');
define('TEST_ORDER_FLOW_CONFIG',['enabled'=>true,'baseline_windows'=>2]);
define('TEST_MARKET_CONFIG',['analysis_enabled'=>true,'manual_advisory_enabled'=>true,'min_remaining_rr'=>1.,'approved_strategy_versions'=>['of-integration'=>'v1']]);
require dirname(__DIR__).'/src/App.php';
class FlowIntegrationBinance extends BinanceMarketDataProvider {
 public int $at=1800000002;public bool $offline=true;
 function now():int{return $this->at;}
 function price(string $symbol):float{return 100.;}
 function monitoringMinutes(string $symbol,int $from,int $to):array{return [];}
 function quote(string $symbol):array{if($this->offline)throw new RuntimeException('isolated unavailable quote');return ['provider'=>'binance_spot','symbol'=>$symbol,'bid'=>99.9,'ask'=>100.1,'mid'=>100.,'timestamp'=>$this->at,'observed_at'=>$this->at,'sample_id'=>'b-'.$this->at];}
}
class FlowIntegrationWallex implements ExecutionQuoteProvider {
 function quote(string $symbol,int $now):array{return ['provider'=>'wallex','symbol'=>$symbol,'bid'=>99.9,'ask'=>100.1,'bids'=>[[99.9,2.]],'asks'=>[[100.1,2.]],'mid'=>100.,'received_at'=>$now,'receipt_id'=>'receipt-'.$now,'timestamp'=>null,'trust_status'=>'UNVERIFIED'];}
}
$s=json_decode(file_get_contents(dirname(__DIR__).'/example-btc-short.json'),true);$s['id']='of-integration';$s['action']='SHORT';$s['initial_stage']='start';unset($s['cancellation']);$s['invalidation']=['left'=>['price'=>true],'op'=>'gt','right'=>120];$s['trade']=['entry'=>['type'=>'MARKET'],'quantity'=>.001,'stop_loss'=>110,'take_profits'=>[80]];$s['stages']=[['id'=>'start','transitions'=>[['event'=>'price','when'=>['left'=>['price'=>true],'op'=>'gt','right'=>99],'signal'=>true]]]];Strategy::validate($s);
$api=new FlowIntegrationBinance();$wallex=new FlowIntegrationWallex();$original=encode(Strategy::fresh($s,$api->at-100));db()->prepare('INSERT INTO strategies VALUES(?,?,?,?)')->execute([$s['id'],'v1',encode($s),$original]);
runCycle($api,$api->at);
if((int)db()->query('SELECT COUNT(*) FROM alerts')->fetchColumn()!==1||(int)db()->query('SELECT COUNT(*) FROM order_flow_jobs')->fetchColumn()!==1)throw new RuntimeException('Initial signal alert or asynchronous enqueue missing');
if(db()->query('SELECT state FROM strategies')->fetchColumn()!==$original)throw new RuntimeException('Legacy state overwritten');
$state=fn()=>json_decode(db()->query('SELECT payload FROM execution_signals')->fetchColumn(),true);
$before=$state();$calls=0;$fault=new OrderFlowMarketData(function()use(&$calls){$calls++;throw new RuntimeException('isolated auxiliary API failure');},fn()=>1800000002);
OrderFlowAdvisory::step(db(),OrderFlowConfig::get(),$api->at,$fault);
if($state()!==$before||$calls!==1)throw new RuntimeException('Auxiliary outage changed primary signal');
$config=MarketArchitecture::config();$api->offline=false;
for($i=0;$i<150;$i++){$api->at=1800000002-1500+$i*10;$w=$wallex->quote('BTCUSDT',$api->at);ExecutionGate::sample(db(),'BTCUSDT',$api->quote('BTCUSDT'),$w,$api->at,$config);}
for($i=0;$i<3;$i++){$api->at=1800000002+$i*10;ExecutionMonitor::run($api,$api->at,$config,$wallex);}
if($state()['status']!=='MANUAL_REVIEW_AVAILABLE'||(int)db()->query('SELECT COUNT(*) FROM alerts')->fetchColumn()!==2)throw new RuntimeException('Unavailable Order Flow blocked manual opportunity or duplicated alert');
$aux=json_decode(db()->query('SELECT payload FROM order_flow_results')->fetchColumn(),true);
if($aux['advisory_status']!=='UNAVAILABLE')throw new RuntimeException('Expected unavailable auxiliary record');
echo "Order Flow actual pipeline: primary alert precedes auxiliary I/O; outage preserves signal, legacy state and successful MANUAL_REVIEW_AVAILABLE with exactly two primary alerts.\n";
