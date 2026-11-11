<?php
if(PHP_SAPI!=='cli')exit;
define('TEST_DATABASE',':memory:');require dirname(__DIR__).'/src/App.php';
$n=0;function flowCheck(bool $ok,string $name):void{global $n;if(!$ok)throw new RuntimeException($name);$n++;echo "PASS $name\n";}
function flowReject(Closure $fn,string $name):void{try{$fn();}catch(Throwable $e){flowCheck(true,$name);return;}throw new RuntimeException($name);}
function flowFixture(int $end,bool $buy=true):array {
 $rows=[];$id=1000;
 for($t=$end-300;$t<$end;$t+=60)foreach([[8.,!$buy],[2.,$buy]] as $i=>[$qty,$maker]){$rows[]=['a'=>$id,'p'=>'100','q'=>(string)$qty,'f'=>$id,'l'=>$id,'T'=>$t*1000+10000+$i*1000,'m'=>$maker];$id++;}
 $bars=[];for($t=$end-900;$t<$end;$t+=60)$bars[$t]=['open'=>100.,'high'=>101.,'low'=>99.,'close'=>100.,'volume'=>10.];
 $depth=['lastUpdateId'=>123,'bids'=>[['99.99',$buy?'8':'2'],['99.9','1']],'asks'=>[['100.01',$buy?'2':'8'],['100.1','1']]];
 return [$rows,$bars,$depth];
}
$c=OrderFlowConfig::validate(['enabled'=>true,'baseline_windows'=>2]);$end=1800000000;
[$rows,$bars,$depth]=flowFixture($end);
$r=OrderFlowAdvisory::calculate($rows,$bars,$depth,$c,'LONG',$end);$one=$r['indicator_values']['60'];$five=$r['indicator_values']['300'];
flowCheck($r['advisory_status']==='SUPPORTIVE','buy pressure and bid imbalance support LONG');
flowCheck(abs($one['delta']-6.)<1e-10&&abs($five['delta']-30.)<1e-10,'Delta uses base quantities over exact one/five minute windows');
flowCheck($five['aggressive_buy_volume']===40.&&$five['aggressive_sell_volume']===10.,'m false is buyer taker; m true is seller taker');
flowCheck($five['cvd']===30.&&array_column($five['cvd_curve'],'value')===[0.,6.,12.,18.,24.,30.],'CVD cumulative curve resets to zero at requested window start');
flowCheck(abs($five['order_book']['imbalance']-.6)<1e-12,'book imbalance uses equal symmetric price band, not arbitrary equal row counts');
flowCheck($one['relative_volume']===1.&&$five['relative_volume']===1.&&$one['baseline_windows']===2,'relative volume compares preceding non-overlapping equal-duration windows');
flowCheck(OrderFlowAdvisory::calculate($rows,$bars,$depth,$c,'SHORT',$end)['advisory_status']==='CONTRADICTORY','same buy evidence contradicts SHORT');
[$sell,$sbars,$sdepth]=flowFixture($end,false);
flowCheck(OrderFlowAdvisory::calculate($sell,$sbars,$sdepth,$c,'SHORT',$end)['advisory_status']==='SUPPORTIVE','sell pressure and ask imbalance support SHORT');
flowCheck(OrderFlowAdvisory::calculate($rows,$bars,$sdepth,$c,'LONG',$end)['advisory_status']==='MIXED','Delta and CVD do not outvote a conflicting book as independent votes');
$neutral=$depth;$neutral['bids'][0][1]='2';$neutral['asks'][0][1]='2';
flowCheck(OrderFlowAdvisory::calculate($rows,$bars,$neutral,$c,'LONG',$end)['advisory_status']==='MIXED','trade-pressure family alone never gives supportive or contradictory outcome');
$high=$bars;foreach($high as $t=>&$bar)if($t<$end-300)$bar['volume']=2.;unset($bar);
flowCheck(OrderFlowAdvisory::calculate($rows,$high,$depth,$c,'LONG',$end)['indicator_values']['300']['volume_label']==='HIGH','relative volume threshold labels high activity without probability claim');
$missing=$bars;unset($missing[$end-60]);flowReject(fn()=>OrderFlowAdvisory::calculate($rows,$missing,$depth,$c,'LONG',$end),'missing current minute rejected');
$missing=$bars;unset($missing[$end-900]);flowReject(fn()=>OrderFlowAdvisory::calculate($rows,$missing,$depth,$c,'LONG',$end),'missing historical baseline minute rejected');
$incomplete=$rows;array_shift($incomplete);flowReject(fn()=>OrderFlowAdvisory::calculate($incomplete,$bars,$depth,$c,'LONG',$end),'missing first aggregate detected by independent candle-volume reconciliation');
$gap=$rows;unset($gap[2]);flowReject(fn()=>OrderFlowAdvisory::calculate(array_values($gap),$bars,$depth,$c,'LONG',$end),'aggregate ID gap rejected');
$dup=$rows;$dup[2]=$dup[1];flowReject(fn()=>OrderFlowAdvisory::calculate($dup,$bars,$depth,$c,'LONG',$end),'duplicate aggregate rejected rather than double counted');
$bad=$rows;$bad[0]['m']='false';flowReject(fn()=>OrderFlowAdvisory::calculate($bad,$bars,$depth,$c,'LONG',$end),'string maker flag never interpreted as boolean');
$bad=$rows;$bad[0]['T']=($end-300)*1000-1;flowReject(fn()=>OrderFlowAdvisory::calculate($bad,$bars,$depth,$c,'LONG',$end),'start boundary is inclusive and outside data rejected');
$bad=$rows;$bad[count($bad)-1]['T']=$end*1000;flowReject(fn()=>OrderFlowAdvisory::calculate($bad,$bars,$depth,$c,'LONG',$end),'end boundary is exclusive');
$bad=$depth;array_pop($bad['bids']);flowReject(fn()=>OrderFlowAdvisory::calculate($rows,$bars,$bad,$c,'LONG',$end),'partial band coverage is unavailable, never extrapolated');
$bad=$depth;$bad['asks'][0][0]='99.98';flowReject(fn()=>OrderFlowAdvisory::calculate($rows,$bars,$bad,$c,'LONG',$end),'crossed book rejected');
$bad=$bars;foreach($bad as $t=>&$bar)if($t<$end-300)$bar['volume']=0.;unset($bar);flowReject(fn()=>OrderFlowAdvisory::calculate($rows,$bad,$depth,$c,'LONG',$end),'zero historical baseline does not produce fabricated relative volume');
flowReject(fn()=>OrderFlowConfig::validate(['windows'=>[120]]),'unsupported timeframe rejected');
flowReject(fn()=>OrderFlowConfig::validate(['enabled'=>1]),'flag must be explicit boolean');
flowCheck(!OrderFlowConfig::get()['enabled'],'real default remains off');
flowCheck(!OrderFlowConfig::get()['browser_processing_enabled'],'browser auxiliary requests also require a separate opt-in');

ExecutionGate::migrate(db());$s=json_decode(file_get_contents(dirname(__DIR__).'/example-btc-short.json'),true);$s['id']='flow-test';$s['action']='LONG';$s['trade']=['entry'=>['type'=>'MARKET'],'quantity'=>.01,'stop_loss'=>90,'take_profits'=>[120]];
$gate=MarketArchitecture::validate(['analysis_enabled'=>true,'manual_advisory_enabled'=>true]);$at=$end+2;
$signalId=ExecutionGate::create(db(),$s,'v1','price',$at,100.,null,$gate,$at);
$before=db()->query('SELECT payload FROM execution_signals')->fetchColumn();$alerts=db()->query('SELECT * FROM alerts')->fetchAll(PDO::FETCH_ASSOC);
OrderFlowAdvisory::discover(db(),$c,$at);
flowCheck(count($alerts)===1&&json_decode($alerts[0]['payload'],true)['type']==='binance_signal','primary signal alert is persisted before any auxiliary HTTP');
flowCheck((int)db()->query('SELECT COUNT(*) FROM order_flow_jobs')->fetchColumn()===1,'signal queues work even while gate has not reached manual availability');
$clock=$at;$calls=[];
$api=new OrderFlowMarketData(function($url,$timeout)use(&$clock,&$calls,$end,$rows,$bars,$depth){$calls[]=$url;$path=parse_url($url,PHP_URL_PATH);parse_str(parse_url($url,PHP_URL_QUERY)??'',$params);
 $data=match($path){'/api/v3/time'=>['serverTime'=>$clock*1000],'/api/v3/depth'=>$depth,'/api/v3/aggTrades'=>$rows,'/api/v3/klines'=>array_map(fn($t,$b)=>[$t*1000,'100','101','99','100',(string)$b['volume'],($t+60)*1000-1,'0',1,'0','0','0'],array_keys($bars),array_values($bars)),default=>throw new RuntimeException('Non-public endpoint')};return ['status'=>200,'headers'=>[],'body'=>encode($data)];},function()use(&$clock){return $clock;});
for($i=0;$i<4;$i++){$clock=$at+$i*3;$count=count($calls);$step=OrderFlowAdvisory::step(db(),$c,$clock,$api);flowCheck(count($calls)-$count<=1,'bounded step sends at most one public GET '.$i);}
$result=json_decode(db()->query('SELECT payload FROM order_flow_results')->fetchColumn(),true);
flowCheck($result['advisory_status']==='SUPPORTIVE'&&$result['kind']==='SIGNAL'&&$result['signal_id']===$signalId,'complete signal-time auxiliary snapshot stored');
flowCheck($result['book_event_timestamp']===null&&$result['depth_received_at']===$at+3,'receipt time is not misrepresented as book event timestamp');
flowCheck(db()->query('SELECT payload FROM execution_signals')->fetchColumn()===$before&&db()->query('SELECT * FROM alerts')->fetchAll(PDO::FETCH_ASSOC)===$alerts,'auxiliary processing changes neither primary status/payload nor notifications');
$originalResult=db()->query('SELECT payload FROM order_flow_results')->fetchColumn();OrderFlowAdvisory::discover(db(),$c,$at+20);
flowCheck((int)db()->query('SELECT COUNT(*) FROM order_flow_jobs')->fetchColumn()===1&&db()->query('SELECT payload FROM order_flow_results')->fetchColumn()===$originalResult,'rediscovery never overwrites signal snapshot or duplicates job');
OrderFlowAdvisory::discover(db(),$c,$at+61);
flowCheck((int)db()->query("SELECT COUNT(*) FROM order_flow_jobs WHERE kind='LATEST'")->fetchColumn()===1,'later live analysis has separate job from signal-time snapshot');
$details=OrderFlowAdvisory::details(db(),$s['id'],'v1',$c);
flowCheck(count($details['results'])===1&&$details['results'][0]['kind']==='SIGNAL','detail response keeps historical role explicit');
flowCheck(OrderFlowAdvisory::details(db(),$s['id'],'other-version',$c)['results']===[],'strategy versions never mix');
$off=$c;$off['strategy_enabled'][$s['id']]=false;
flowCheck(!OrderFlowAdvisory::details(db(),$s['id'],'v1',$off)['enabled'],'per-strategy switch is independent of JSON v1');
$disabled=OrderFlowConfig::defaults();$callCount=count($calls);
flowCheck(OrderFlowAdvisory::step(db(),$disabled,$at+100,$api)['status']==='disabled'&&count($calls)===$callCount,'disabled worker performs no network request');
$fault=new OrderFlowMarketData(fn()=>throw new RuntimeException('timeout'));
OrderFlowAdvisory::step(db(),$c,$at+65,$fault);
$latest=json_decode(db()->query("SELECT payload FROM order_flow_results WHERE kind='LATEST'")->fetchColumn(),true);
flowCheck($latest['advisory_status']==='UNAVAILABLE'&&db()->query('SELECT payload FROM execution_signals')->fetchColumn()===$before,'API failure records unavailable without cancelling/waiting/changing primary status');
flowReject(fn()=>$api->get('/api/v3/order',[],$c),'order endpoint cannot be requested');
flowCheck(!str_contains(file_get_contents(dirname(__DIR__).'/src/ExecutionGate.php'),'OrderFlow')&&!str_contains(file_get_contents(dirname(__DIR__).'/src/ExecutionMonitor.php'),'OrderFlow'),'Gate and Basis do not depend on auxiliary module');
$s['id']='independent-mode';$legacyGate=MarketArchitecture::validate(['analysis_enabled'=>true,'enabled'=>false,'manual_advisory_enabled'=>false]);
$independentId=ExecutionGate::create(db(),$s,'independent','price',$at+100,100.,null,$legacyGate,$at+100);OrderFlowAdvisory::discover(db(),$c,$at+100);
$q=db()->prepare('SELECT COUNT(*) FROM order_flow_jobs WHERE signal_id=?');$q->execute([$independentId]);
flowCheck((int)$q->fetchColumn()===1,'Order Flow queues with both verified Gate and Manual Advisory disabled');
$broken=new PDO('sqlite::memory:');$broken->exec('CREATE TABLE order_flow_results(x INTEGER)');
flowCheck(OrderFlowAdvisory::safeDetails($broken,'test','v1',$c)['storage_available']===false,'auxiliary storage failure is contained and cannot break primary detail polling');
echo "Order Flow: $n assertions passed.\n";
