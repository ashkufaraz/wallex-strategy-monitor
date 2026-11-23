<?php
declare(strict_types=1);
define('TEST_DATABASE',':memory:');require dirname(__DIR__).'/src/App.php';
$now=1800000000;$requests=[];$n=0;
function futureCheck(bool $ok,string $label):void {global $n;if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
$api=new BinanceFuturesMarketDataProvider(function($url)use(&$requests,$now){$requests[]=$url;$path=parse_url($url,PHP_URL_PATH);parse_str(parse_url($url,PHP_URL_QUERY)??'',$p);
 if($path==='/fapi/v1/time')$body=['serverTime'=>$now*1000];
 elseif($path==='/fapi/v2/ticker/price')$body=['symbol'=>$p['symbol'],'price'=>'100'];
 elseif($path==='/fapi/v1/klines'){$step=Indicators::TIMEFRAMES[$p['interval']];$body=[];for($t=(int)($p['startTime']/1000);$t<=(int)($p['endTime']/1000);$t+=$step)$body[]=[$t*1000,'100','101','99','100','2',($t+$step)*1000-1,'200',1,'1','100','0'];}
 else throw new RuntimeException('Unexpected Futures route');return ['status'=>200,'headers'=>[],'body'=>encode($body)];},fn()=>$now);
futureCheck($api->exchange()==='binance'&&$api->price('BTCUSDT')===100.,'Futures ticker uses explicit Binance identity');
$bars=$api->candles('BTCUSDT','15m',$now-3*900,$now);futureCheck(count($bars)===3&&end($bars)['provider']==='binance','native Futures candles retain provenance');
$s=['strategy_version'=>1,'id'=>'futures_entry','name'=>'Futures entry','exchange'=>'binance','symbol'=>'BTCUSDT','enabled'=>true,'action'=>'LONG','initial_stage'=>'entry','max_setup_seconds'=>3600,'invalidation'=>['left'=>['price'=>true],'op'=>'lt','right'=>1],'stages'=>[['id'=>'entry','transitions'=>[['event'=>'price','when'=>['left'=>['price'=>true],'op'=>'gt','right'=>1],'signal'=>true]]]],'indicator_conditions'=>['enabled'=>true,'logic'=>'all','conditions'=>[['id'=>'ema','indicator'=>'EMA','timeframe'=>'15m','period'=>2,'candle'=>'last_closed','metric'=>'value','operator'=>'gt','value'=>1]]],'trade'=>['entry'=>['type'=>'MARKET'],'stop_loss'=>90,'take_profits'=>[110,120]]];
$owner=(int)db()->query("SELECT id FROM users WHERE role='owner'")->fetchColumn();StrategyStore::save($s,$owner,null,$now-120);MarketData::$factory=fn($exchange)=>$api;
runCycle($api,$now);$p=json_decode(db()->query('SELECT payload FROM alerts')->fetchColumn(),true);
futureCheck($p['exchange']==='binance'&&(float)$p['price']===100.&&$p['indicators']['passed'],'saved Binance strategy triggers on its own Futures indicators');
futureCheck(count(StrategyOutcomes::view())===1&&StrategyOutcomes::view()[0]['cohort']==='confirmed','Futures entry starts independent outcome tracking');
futureCheck(count(array_filter($requests,fn($url)=>!str_starts_with($url,'https://fapi.binance.com/')))===0,'no Futures request falls back to Spot or another exchange');
futureCheck(MarketScope::label('binance')!=='والکس','Futures alert labels do not identify as Wallex');
try{$api->get('/api/v3/order');$rejected=false;}catch(RuntimeException $e){$rejected=true;}futureCheck($rejected,'private order endpoint remains forbidden');
echo "Binance Futures: $n assertions passed.\n";
