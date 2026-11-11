<?php
define('TEST_DATABASE',':memory:');require dirname(__DIR__).'/src/App.php';
$checks=0;
function bookCheck(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$message);$checks++;echo "PASS $message\n";}
function bookReject(Closure $f,string $message):void{try{$f();}catch(Throwable $e){bookCheck(true,$message);return;}bookCheck(false,$message);}
function bookNear(float $a,float $b):bool{return abs($a-$b)<1e-10;}
class ObjectBookApi extends Wallex {
 public array $data=[];public array $calls=[];
 function get(string $path,array $params=[]):array{$this->calls[]=[$path,$params];return $this->data;}
}
$row=fn($p,$q)=>['price'=>(string)$p,'quantity'=>(string)$q,'sum'=>(string)($p*$q)];
$data=['success'=>true,'result'=>['bid'=>[$row(99,2),$row(100,1),$row(98,5)],'ask'=>[$row(102,3),$row(103,2),$row(101,1)]]];
$now=1800000000;$api=new ObjectBookApi();$api->data=$data;$config=MarketArchitecture::defaults();$provider=new WallexExecutionMarketProvider($api,$config,fn()=>$now);
$book=$provider->snapshot('BTCUSDT',$now);
bookCheck($book['bid']===100.&&$book['ask']===101.,'best prices are extrema of valid unsorted object rows');
bookCheck($book['bids']===[[100.,1.],[99.,2.],[98.,5.]]&&$book['asks']===[[101.,1.],[102.,3.],[103.,2.]],'object rows normalized and sorted for execution');
bookCheck($book['visible_bid_quantity']===8.&&$book['visible_ask_quantity']===6.&&$book['quantity_unit']==='base_asset','quantity is base volume, never sum');
bookCheck($book['spread']===1.&&$book['mid']===100.5,'best-price spread and mid');
bookCheck($book['timestamp']===null&&$book['source_timestamp']===null&&$book['source_sequence']===null&&$book['received_at']===$now,'source time absent and local received_at separate');
bookCheck($book['trust_status']==='UNVERIFIED'&&!isset($book['observed_at']),'receipt alone never verifies quote freshness');
bookCheck($api->calls===[['/v1/depth',['symbol'=>'BTCUSDT']]],'only documented public GET is requested');
$long=WallexExecutionMarketProvider::estimate($book,'LONG',2.);
bookCheck($long['depth_sufficient']&&bookNear($long['vwap'],101.5)&&$long['estimated_quote_amount']===203.,'LONG walks asks and uses partial final level for VWAP');
$short=WallexExecutionMarketProvider::estimate($book,'SHORT',3.);
bookCheck($short['depth_sufficient']&&bookNear($short['vwap'],298./3.)&&$short['filled_base_quantity']===3.,'SHORT walks bids with quantity weighting');
$small=WallexExecutionMarketProvider::estimate($book,'LONG',.5);
bookCheck($small['vwap']===101.&&$small['levels_used'][0]['base_quantity']===.5,'sub-level quantity estimates at best ask');
$exact=WallexExecutionMarketProvider::estimate($book,'LONG',6.);
bookCheck($exact['depth_sufficient']&&bookNear($exact['vwap'],613./6.)&&$exact['unfilled_base_quantity']===0.,'exact total visible depth is supported');
foreach([['LONG',7.,6.],['SHORT',9.,8.]] as [$action,$qty,$visible]){
 $estimate=WallexExecutionMarketProvider::estimate($book,$action,$qty);
 bookCheck(!$estimate['depth_sufficient']&&$estimate['vwap']===null&&$estimate['filled_base_quantity']===$visible&&$estimate['unfilled_base_quantity']===1.&&!$estimate['execution_confirmed'],'insufficient '.$action.' depth never approved or extrapolated');
}
bookCheck(!$long['execution_confirmed']&&!$short['execution_confirmed']&&!$long['fees_included'],'sufficient visible depth is still an indicative estimate, not execution confirmation');
foreach([0.,-1.,INF,NAN] as $qty)bookReject(fn()=>WallexExecutionMarketProvider::estimate($book,'LONG',$qty),'invalid requested base quantity rejected');
bookReject(fn()=>WallexExecutionMarketProvider::estimate($book,'BUY',1.),'unsupported action rejected');
$badCases=[];
$bad=$data;unset($bad['result']['ask']);$badCases['missing side']=$bad;
$bad=$data;$bad['result']['bid']=[];$badCases['empty side']=$bad;
$bad=$data;$bad['result']['ask']=null;$badCases['null side']=$bad;
$bad=$data;unset($bad['success']);$badCases['missing success']=$bad;
$bad=$data;$bad['success']=false;$badCases['failed response']=$bad;
$bad=$data;$bad['result']['bid']=['a'=>$row(99,2)];$badCases['non-list side']=$bad;
foreach(['price','quantity','sum'] as $key){$bad=$data;unset($bad['result']['bid'][0][$key]);$badCases['missing '.$key]=$bad;}
foreach([['price','NaN'],['price',0],['quantity',-1],['quantity',0],['sum',-1],['sum',9999]] as [$key,$value]){$bad=$data;$bad['result']['bid'][0][$key]=$value;$badCases['invalid '.$key.' '.strval($value)]=$bad;}
$bad=$data;$bad['result']['ask'][0]['sum']='407';$badCases['cumulative sum instead of row notional']=$bad;
$bad=$data;$bad['result']['bid'][]=$row(99,2);$badCases['ambiguous duplicate price']=$bad;
$bad=$data;$bad['result']['ask'][2]=$row(99,1);$badCases['crossed book']=$bad;
$bad=$data;$bad['result']['ask'][2]=$row(100,1);$badCases['locked book']=$bad;
$bad=$data;$bad['result']['bid'][0]=['price'=>'1e308','quantity'=>'1e308','sum'=>'1e308'];$badCases['overflow notional']=$bad;
foreach($badCases as $label=>$bad)bookReject(fn()=>WallexExecutionMarketProvider::normalize($bad),$label.' rejected');
$api->data=$data+['headers'=>['Date'=>gmdate('D, d M Y H:i:s \G\M\T',$now)]];
$api->data['result']['timestamp']=$now;$api->data['result']['sequence']=123;
$again=$provider->snapshot('BTCUSDT',$now+5);
bookCheck($again['timestamp']===null&&$again['source_sequence']===null&&$again['trust_status']==='UNVERIFIED','HTTP Date and undocumented timestamp/sequence extensions are not event evidence');
bookCheck($again['received_at']===$now+5&&$again['sample_id']===$book['sample_id'],'later receipt does not turn the same book into a new source sample');
$c=MarketArchitecture::validate(['analysis_enabled'=>true,'enabled'=>true,'min_remaining_rr'=>1.,'instrument'=>['verified'=>true,'type'=>'spot','reference_type'=>'spot','symbol'=>'BTCUSDT','evidence'=>'https://example.test/fixture-only']]);
$ref=['provider'=>'binance_spot','symbol'=>'BTCUSDT','bid'=>99.,'ask'=>101.,'timestamp'=>$now,'observed_at'=>$now,'sample_id'=>'ref'];
bookReject(fn()=>ExecutionGate::fresh($book,$now,$c),'Gate rejects actual UNVERIFIED REST snapshot');
$forged=$book;$forged['timestamp']=$now;$forged['observed_at']=$now;
bookReject(fn()=>ExecutionGate::fresh($forged,$now,$c),'copying receipt or HTTP Date to timestamp cannot bypass UNVERIFIED guard');
$signal=['status'=>'WAITING_BASIS','deadline'=>$now+900,'symbol'=>'BTCUSDT','action'=>'LONG','stop'=>90.,'tp1'=>120.,'take_profits'=>[120.],'confirmations'=>[['id'=>'a','at'=>$now-20],['id'=>'b','at'=>$now-10],['id'=>'c','at'=>$now]]];
$decision=ExecutionGate::decide($signal,$c,$now,$ref,$forged,null,['calibrated'=>true,'median'=>0.,'tolerance'=>10.],[]);
bookCheck($decision['status']==='WAITING_DATA'&&str_contains($decision['reason'],'UNVERIFIED')&&$decision['confirmations']===[],'three prior confirmations cannot make unverified quote READY');
ExecutionGate::migrate(db());bookReject(fn()=>ExecutionGate::sample(db(),'BTCUSDT',$ref,$forged,$now,$c),'unverified book cannot enter calibration');
bookCheck((int)db()->query('SELECT COUNT(*) FROM basis_samples')->fetchColumn()===0,'invalid quote persists no paired sample');
$signal['action']='SHORT';$c['instrument']['type']='margin';
bookCheck(!ExecutionGate::instrument($signal,$c),'SHORT margin is blocked even when a fixture configuration claims verified');
$captured=json_decode(file_get_contents(dirname(__DIR__).'/docs/wallex-market-api-evidence.json'),true);
foreach($captured['requests'] as $r)if($r['name']==='depth-btcusdt')$real=WallexExecutionMarketProvider::normalize($r['response_sample']);
bookCheck($real['bid']===74182.38&&$real['ask']===74328.45&&count($real['bids'])===50&&count($real['asks'])===50,'captured real official response normalizes all 100 object levels');
bookCheck(!MarketArchitecture::config()['enabled']&&!MarketArchitecture::config()['analysis_enabled'],'actual default feature flags remain disabled');
bookCheck(count(array_filter($api->calls,fn($call)=>$call!==['/v1/depth',['symbol'=>'BTCUSDT']]))===0,'all adapter test requests are public order-book GET, never order creation');
echo "Wallex object order book: $checks assertions passed.\n";
