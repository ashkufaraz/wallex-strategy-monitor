<?php
define('TEST_DATABASE', ':memory:');
require dirname(__DIR__).'/src/App.php';
$count=0;
function gateCheck(bool $ok,string $label):void{global $count;if(!$ok)throw new RuntimeException('FAIL '.$label);$count++;echo "PASS $label\n";}
function gateThrows(Closure $f,string $label):void{try{$f();}catch(Throwable $e){gateCheck(true,$label);return;}gateCheck(false,$label);}
function near(float $a,float $b):bool{return abs($a-$b)<1e-9;}
$now=1800000000;
$calls=[];
$transport=function($url)use(&$calls,&$now){$calls[]=$url;$path=parse_url($url,PHP_URL_PATH);parse_str(parse_url($url,PHP_URL_QUERY)??'',$q);
 $d=match($path){'/api/v3/time'=>['serverTime'=>$now*1000],'/api/v3/ticker/price'=>['symbol'=>$q['symbol'],'price'=>'100'],'/api/v3/ticker/bookTicker'=>['symbol'=>$q['symbol'],'bidPrice'=>'99','askPrice'=>'101','bidQty'=>'5','askQty'=>'5'],'/api/v3/depth'=>['lastUpdateId'=>1,'bids'=>[['99','5']],'asks'=>[['101','5']]],default=>[]};
 if($path==='/api/v3/klines'){$sec=Indicators::TIMEFRAMES[$q['interval']];for($t=(int)$q['startTime']/1000;$t<=(int)$q['endTime']/1000;$t+=$sec)$d[]=[(int)$t*1000,'100','101','99','100','1',((int)$t+$sec)*1000-1,'100',1,'1','100','0'];}
 return ['status'=>200,'headers'=>[],'body'=>encode($d)];};
$api=new BinanceMarketDataProvider($transport,fn()=>$now);
foreach(Indicators::TIMEFRAMES as $tf=>$sec){$end=intdiv($now,$sec)*$sec;$bars=$api->candles('BTCUSDT',$tf,$end-2*$sec,$end);gateCheck(count($bars)===2&&array_key_last($bars)===$end-$sec,'native closed UTC '.$tf);}
$row=[$now*1000,'74000','74249','74000','74249','1',($now+60)*1000-1,'1',1,'1','1','0'];
$parsed=BinanceMarketDataProvider::parse([$row],60,$now,$now+60);
gateCheck($parsed[$now]['high']===74249.&&$parsed[$now]['low']===74000.,'Binance OHLCV mapping');
gateCheck(BinanceMarketDataProvider::parse([$row],60,$now,$now)===[],'open candle excluded from closed parse');
foreach(['duplicate','unordered','fraction','malformed'] as $case){$r=$row;$rows=[$row];if($case==='duplicate')$rows[]=$row;if($case==='unordered'){$r[0]-=60000;$r[6]-=60000;$rows[]=$r;}if($case==='fraction')$rows[0][0]++;if($case==='malformed')$rows[0][2]='NaN';gateThrows(fn()=>BinanceMarketDataProvider::parse($rows,60,$now,$now+120),'reject '.$case);}
gateThrows(fn()=>$api->candles('BTCUSDT','1m',$now,$now+60),'future closed interval rejected');
gateThrows(fn()=>$api->get('/api/v3/order'),'order endpoint impossible');
gateCheck($api->price('BTCUSDT')===100.,'Binance current price');
gateCheck($api->quote('BTCUSDT')['bid']===99.&&count($api->depth('BTCUSDT')['bids'])===1,'best quote and depth');
$indicatorData=new IndicatorData($api);
foreach(['EMA'=>100.,'RSI'=>50.,'ATR'=>2.,'VOLUME'=>1.] as $indicator=>$expected){$c=['indicator'=>$indicator,'period'=>14,'metric'=>'value'];$history=$indicatorData->history('BTCUSDT','15m',Indicators::needed($c),$now,$now);gateCheck(near(Indicators::value($indicator,14,'value',array_values($history)),$expected),'Binance '.$indicator.' independent constant-series reference');}
gateCheck((int)db()->query("SELECT COUNT(*) FROM indicator_cache WHERE k LIKE 'binance_spot:%'")->fetchColumn()>0,'provider-isolated indicator cache');
ExecutionGate::migrate(db());
$config=MarketArchitecture::validate(['analysis_enabled'=>true,'enabled'=>true,'min_remaining_rr'=>1.,'instrument'=>['verified'=>true,'type'=>'spot','reference_type'=>'spot','symbol'=>'BTCUSDT','evidence'=>'https://example.test/audited-spot-mapping']]);
$quote=fn($at,$mid=100.)=>['provider'=>'test','symbol'=>'BTCUSDT','bid'=>$mid-.1,'ask'=>$mid+.1,'mid'=>$mid,'timestamp'=>$at,'observed_at'=>$at,'sample_id'=>'quote-'.$at.'-'.$mid];
gateCheck(near(ExecutionGate::median([5.,1.,3.]),3.)&&near(ExecutionGate::median([1.,3.]),2.),'rolling median odd and even');
gateCheck(near(ExecutionGate::percentile([0.,1.,2.],95),1.9),'empirical interpolated percentile');
$stats=ExecutionGate::statistics(db(),'BTCUSDT',$now,$config);gateCheck(!$stats['calibrated'],'insufficient calibration fails closed');
// Generate real paired samples, including a seven-day-old record to test safe pruning.
ExecutionGate::sample(db(),'BTCUSDT',$quote($now-8*86400),$quote($now-8*86400,101.),$now-8*86400,$config);
for($i=0;$i<150;$i++){$at=$now-1500+$i*10;ExecutionGate::sample(db(),'BTCUSDT',$quote($at),$quote($at,101.),$at,$config);}
$stats=ExecutionGate::statistics(db(),'BTCUSDT',$now,$config);
gateCheck($stats['calibrated']&&$stats['calibration_count']===120,'seven-day retained empirical deviations use preceding rolling medians');
gateCheck(near($stats['median'],1.)&&near($stats['mad'],0.)&&near($stats['tolerance'],0.),'signed basis, MAD and reproducible tolerance');
$negative=ExecutionGate::sample(db(),'BTCUSDT',$quote($now),$quote($now,99.),$now,$config);
gateCheck(near($negative['basis'],-1.),'negative signed basis is preserved');
$varied=$config;$varied['instrument']['symbol']='ETHUSDT';
for($i=0;$i<150;$i++){$at=$now-1500+$i*10;$a=$quote($at);$a['symbol']='ETHUSDT';$b=$quote($at,100.+$i%3);$b['symbol']='ETHUSDT';ExecutionGate::sample(db(),'ETHUSDT',$a,$b,$at,$varied);}
$dispersion=ExecutionGate::statistics(db(),'ETHUSDT',$now,$varied);
gateCheck(near($dispersion['median'],1.)&&near($dispersion['mad'],1.)&&near($dispersion['robust_sigma'],1.4826)&&near($dispersion['tolerance'],4.4478),'nonzero MAD dispersion and tolerance');
gateCheck((int)db()->query('SELECT MIN(at) FROM basis_samples')->fetchColumn()>$now-7*86400,'older-than-seven-day samples pruned');
gateCheck(ExecutionGate::sample(db(),'BTCUSDT',$quote($now-10),$quote($now-10,101.),$now,$config)===null,'same exchange quote cannot be another sample');
$s=json_decode(file_get_contents(dirname(__DIR__).'/example-btc-short.json'),true);$s['id']='gate-test';$s['action']='LONG';$s['trade']=['entry'=>['type'=>'MARKET'],'stop_loss'=>90,'take_profits'=>[120]];$s['invalidation']=['left'=>['price'=>true],'op'=>'lt','right'=>80];$s['cancellation']=[];
$id=ExecutionGate::create(db(),$s,'v1','price',$now,100.,null,$config,$now);$raw=db()->query('SELECT payload FROM execution_signals')->fetchColumn();$p=json_decode($raw,true);
ExecutionGate::create(db(),$s,'v1','price',$now,100.,null,$config,$now);ExecutionGate::create(db(),$s,'v1','price',$now+1,100.,null,$config,$now+1);
gateCheck((int)db()->query('SELECT COUNT(*) FROM execution_signals')->fetchColumn()===1,'idempotent signal and one pending per strategy version');
gateCheck($p['deadline']===$now+900&&$p['signal_time']===$now,'original signal deadline persisted');
$readyStats=['calibrated'=>true,'median'=>0.,'tolerance'=>.2,'calibration_count'=>100,'window_count'=>30];
$step=function($p,$at,$q=null,$c=null,$validity=[])use($quote,$config,$readyStats){return ExecutionGate::decide($p,$c??$config,$at,$quote($at),$q??$quote($at),['id'=>'sample-'.$at,'at'=>$at,'basis'=>0.],$readyStats,$validity);};
for($i=0;$i<3;$i++)$p=$step($p,$now+$i*10);
gateCheck($p['status']==='READY_FOR_MANUAL_ENTRY'&&count($p['confirmations'])===3,'three distinct consecutive normal samples ready');
$repeat=$step($p,$now+20);gateCheck(count($repeat['confirmations'])===3,'same sample not counted twice');
$stillReady=$step($p,$now+21);gateCheck($stillReady['confirmed_at']===$now+20,'continuous readiness preserves exact original confirmation time');
$abnormal=$step($p,$now+30,$quote($now+30,110.));gateCheck($abnormal['status']==='WAITING_BASIS'&&$abnormal['confirmations']===[],'one abnormal sample resets confirmations');
foreach(['reference','wallex'] as $source){$r=$quote($now+30);$w=$quote($now+30);if($source==='reference')$r=$quote($now-1);else $w=$quote($now-1);$a=ExecutionGate::decide($p,$config,$now+30,$r,$w,null,$readyStats,[]);gateCheck($a['status']==='WAITING_DATA'&&$a['confirmations']===[],'stale '.$source.' quote blocks and resets');}
$c=$config;$c['min_remaining_rr']=null;gateCheck($step($p,$now+30,null,$c)['status']==='BLOCKED_RR_THRESHOLD_NOT_CONFIGURED','unset RR threshold blocks');
$c=$config;$c['min_remaining_rr']=10.;gateCheck($step($p,$now+30,null,$c)['status']==='WAITING_BASIS','low RR blocks');
$c=$config;$c['instrument']['type']='futures';gateCheck($step($p,$now+30,null,$c)['status']==='BLOCKED_INSTRUMENT_MISMATCH','instrument mismatch blocks');
$short=$p;$short['action']='SHORT';$short['stop']=110.;$short['tp1']=80.;$short['take_profits']=[80.];
gateCheck(!ExecutionGate::instrument($short,$config),'SPOT SHORT never assumed supported');
$rr=ExecutionGate::rr($short,$quote($now),$quote($now),$config);gateCheck(near($rr['entry'],99.9)&&near($rr['gross_rr'],19.9/10.1),'SHORT best bid and RR');
$rr=ExecutionGate::rr($p,$quote($now),$quote($now),$config);gateCheck(near($rr['entry'],100.1)&&near($rr['gross_rr'],19.9/10.1),'LONG best ask and RR');
$converted=ExecutionGate::rr($p,$quote($now),$quote($now,101.),$config);gateCheck(near($converted['stop'],90.9)&&near($converted['tp1'],121.2),'mid conversion ratio for original levels');
$bad=$p;$bad['stop']=null;gateThrows(fn()=>ExecutionGate::rr($bad,$quote($now),$quote($now),$config),'missing stop rejected');
$bad=$p;$bad['tp1']=90.;gateThrows(fn()=>ExecutionGate::rr($bad,$quote($now),$quote($now),$config),'negative reward rejected');
$c=$config;$c['fees_pct']=.1;$net=ExecutionGate::rr($p,$quote($now),$quote($now),$c);gateCheck($net['net_rr']<$net['gross_rr'],'configured fees reduce RR without invented fees');
gateCheck($rr['net_rr']===null,'unconfigured fees display gross RR only');
foreach(['CANCELLED_STOP_LOSS','EXPIRED_TP1_REACHED','CANCELLED_INVALIDATION'] as $state)gateCheck($step($p,$now+30,null,null,['status'=>$state,'reason'=>'observed'])['status']===$state,$state.' blocks READY');
gateCheck($step($p,$now+900)['status']==='EXPIRED_TIMEOUT','fifteen-minute timeout');
$restored=json_decode($raw,true);gateCheck($step($restored,$now+900)['status']==='EXPIRED_TIMEOUT','restart cannot extend deadline');
db()->beginTransaction();ExecutionGate::persist(db(),$restored,$p,$now+20);db()->commit();
$stored=json_decode(db()->query('SELECT payload FROM execution_signals')->fetchColumn(),true);
gateCheck((int)db()->query('SELECT COUNT(*) FROM alerts')->fetchColumn()===1,'one final READY notification persisted');
db()->beginTransaction();ExecutionGate::persist(db(),$stored,$abnormal,$now+30);db()->commit();
gateCheck((int)db()->query("SELECT COUNT(*) FROM execution_events WHERE status='READY_REVOKED'")->fetchColumn()===1,'READY deterioration persisted as revoked');
// Restore the notification marker as the real lifecycle does and exercise re-readiness.
$p['notified_at']=$stored['notified_at'];db()->beginTransaction();ExecutionGate::persist(db(),$abnormal,$p,$now+40);db()->commit();
gateCheck((int)db()->query('SELECT COUNT(*) FROM alerts')->fetchColumn()===1,'re-readiness never duplicates final notification');
ExecutionGate::acknowledge(db(),$id,$now+20);
gateCheck(db()->query('SELECT status FROM execution_signals')->fetchColumn()==='MANUALLY_EXECUTED','manual acknowledgement only persists user action');
gateThrows(fn()=>ExecutionGate::acknowledge(db(),$id,$now+21),'duplicate manual acknowledgement rejected');
gateThrows(fn()=>(new WallexExecutionMarketProvider(new Wallex(),$config))->quote('BTCUSDT',$now),'unverified Wallex orderbook unavailable');
class TestExecutionWallex extends Wallex {public array $data=[];function get(string $p,array $params=[]):array{return $this->data;}}
$bookApi=new TestExecutionWallex();$bookConfig=$config;$bookConfig['orderbook']=['verified'=>true,'path'=>'/v1/depth','bids'=>'result.bid','asks'=>'result.ask','timestamp'=>'result.time','timestamp_unit'=>'milliseconds','evidence'=>'https://developers.wallex.ir/docs/basic-services-depth'];
$row=fn($p,$q)=>['price'=>$p,'quantity'=>$q,'sum'=>$p*$q];
$bookConfig=MarketArchitecture::validate($bookConfig);$bookApi->data=['success'=>true,'result'=>['bid'=>[$row(99,2),$row(98,1)],'ask'=>[$row(101,2),$row(102,1)],'time'=>$now*1000]];
$book=new WallexExecutionMarketProvider($bookApi,$bookConfig,fn()=>$now);$bookQuote=$book->quote('BTCUSDT',$now);
gateCheck($bookQuote['bid']===99.&&$bookQuote['ask']===101.&&$bookQuote['timestamp']===null&&$bookQuote['spread']===2.&&$bookQuote['trust_status']==='UNVERIFIED','official object order book normalized without inventing a source timestamp');
$bookApi->data['result']['time']=($now-11)*1000;gateThrows(fn()=>ExecutionGate::fresh($book->quote('BTCUSDT',$now),$now,$config),'unverified timestamp extension cannot make Wallex quote fresh');
gateThrows(fn()=>WallexExecutionMarketProvider::levels([],[]),'missing orderbook never substitutes last price');
foreach([429,418] as $status){db()->exec('DELETE FROM market_backoff');$requests=0;$limited=new BinanceMarketDataProvider(function($u)use($status,&$requests){$requests++;return ['status'=>$status,'headers'=>['retry-after'=>'120'],'body'=>'{}'];},fn()=>$now);
 gateThrows(fn()=>$limited->serverTime(),'HTTP '.$status.' stops immediately');gateThrows(fn()=>$limited->serverTime(),'HTTP '.$status.' persisted backoff');gateCheck($requests===1,'no aggressive retry '.$status);}
db()->exec('DELETE FROM market_backoff');
class ValidityBinance extends BinanceMarketDataProvider {
 public float $current=100.;public array $monitor=[];
 function price(string $s):float{return $this->current;}
 function monitoringMinutes(string $s,int $from,int $now):array{return $this->monitor;}
}
$v=new ValidityBinance();$base=json_decode($raw,true);
$v->monitor=[$now=>['high'=>121.,'low'=>99.]];gateCheck(ExecutionGate::validity($base,$v,$now+30)['status']==='EXPIRED_TP1_REACHED','in-progress post-signal minute catches reversed TP1 touch');
$v->monitor=[$now=>['high'=>101.,'low'=>89.]];gateCheck(ExecutionGate::validity($base,$v,$now+30)['status']==='CANCELLED_STOP_LOSS','post-signal low catches reversed stop touch');
$partial=$base;$partial['signal_time']=$now+10;gateThrows(fn()=>ExecutionGate::validity($partial,$v,$now+30),'pre-signal partial-minute touch is not attributed to post-signal trades');
$v->monitor=[];$v->current=79.;$base['stop']=null;$base['tp1']=null;gateCheck(ExecutionGate::validity($base,$v,$now+30)['status']==='CANCELLED_INVALIDATION','original Binance invalidation re-evaluated');
gateCheck(count(array_filter($calls,fn($u)=>!preg_match('~/api/v3/(time|ticker/price|ticker/bookTicker|depth|klines)(\?|$)~',$u)))===0,'every request is a public read-only market-data endpoint');
gateCheck(!MarketArchitecture::config()['analysis_enabled'],'new architecture remains disabled by default');
echo "Execution Gate: $count assertions passed.\n";
