<?php
declare(strict_types=1);
define('TEST_DATABASE', ':memory:');
require dirname(__DIR__).'/src/App.php';

function marketAssert(bool $value,string $message):void {
 if(!$value)throw new RuntimeException($message);
 echo "PASS $message\n";
}

// Exercise the actual Wallex symbol routing and shared indicator cache offline.
final class MultiMarketWallex extends Wallex {
 public array $prices=['BTCUSDT'=>74000.,'ETHUSDT'=>2500.];
 public array $historySymbols=[];
 protected function fetchResponse(string $url,int $remaining):string {
  $path=parse_url($url,PHP_URL_PATH);parse_str(parse_url($url,PHP_URL_QUERY)??'', $params);
  if($path==='/v1/markets') {
   $symbols=[];
   foreach($this->prices as $symbol=>$price)$symbols[$symbol]=['quoteAsset'=>'USDT','stats'=>['lastPrice'=>$price]];
   return encode(['result'=>['symbols'=>$symbols]]);
  }
  if($path!=='/v1/udf/history')throw new RuntimeException('Unexpected endpoint');
  $symbol=$params['symbol'];$this->historySymbols[]=$symbol;
  $price=$this->prices[$symbol]??throw new RuntimeException('Unexpected symbol');
  $step=$params['resolution']==='1'?60:3600;
  $out=['s'=>'ok','symbol'=>$symbol,'t'=>[],'o'=>[],'h'=>[],'l'=>[],'c'=>[],'v'=>[]];
  for($at=(int)$params['from'];$at<(int)$params['to'];$at+=$step) {
   $out['t'][]=$at;$out['o'][]=$price;$out['h'][]=$price+1;
   $out['l'][]=$price-1;$out['c'][]=$price;$out['v'][]=$symbol==='BTCUSDT'?10:20;
  }
  return encode($out);
 }
}

$now=1440900;
foreach(['BTCUSDT'=>74000.,'ETHUSDT'=>2500.] as $symbol=>$price) {
 $s=['strategy_version'=>1,'id'=>strtolower($symbol),'name'=>$symbol,'symbol'=>$symbol,
  'enabled'=>true,'action'=>'LONG','initial_stage'=>'entry','max_setup_seconds'=>3600,
  'invalidation'=>['left'=>['price'=>true],'op'=>'lt','right'=>1],
  'stages'=>[['id'=>'entry','transitions'=>[['event'=>'price','when'=>['left'=>['price'=>true],'op'=>'gt','right'=>1],'signal'=>true]]]],
  'trade'=>['entry'=>['type'=>'LIMIT','price'=>$price],'stop_loss'=>$price-100,'take_profits'=>[$price+200]],
  'cancellation'=>[['event'=>'price','when'=>['left'=>['price'=>true],'op'=>'gte','right'=>$price+500],'reason'=>'Market-specific cancellation']],
  'indicator_conditions'=>['enabled'=>true,'logic'=>'all','conditions'=>[]]];
 foreach(['EMA'=>['value',[$price-1,$price+1]],'RSI'=>['value',[40,60]],'ATR'=>['value',[0,10]],'VOLUME'=>['ratio_to_previous_avg',[.9,1.1]]] as $indicator=>[$metric,$range]) {
  $s['indicator_conditions']['conditions'][]=['id'=>strtolower($indicator),'indicator'=>$indicator,'timeframe'=>'15m',
   'period'=>2,'candle'=>'last_closed','metric'=>$metric,'operator'=>'between','value'=>$range];
 }
 $s=Strategy::validate($s);
 db()->prepare('INSERT INTO strategies VALUES(?,?,?,?)')->execute([$s['id'],'v1',encode($s),encode(Strategy::fresh($s,$now-1))]);
}
$api=new MultiMarketWallex();runCycle($api,$now);
$alerts=db()->query('SELECT payload FROM alerts ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
marketAssert(count($alerts)===2,'BTC and ETH both produce their own signal');
foreach($alerts as $raw) {
 $p=json_decode($raw,true);$expected=$api->prices[$p['symbol']];
 marketAssert((float)$p['price']===$expected && (float)$p['trade']['entry']['reference_price']===$expected,$p['symbol'].' price and trade use the correct market');
 foreach($p['indicators']['conditions'] as $condition) {
  marketAssert($condition['symbol']===$p['symbol'] && $condition['status']==='PASS',$p['symbol'].' '.$condition['indicator'].' uses its own data');
  if($condition['indicator']==='EMA')marketAssert(abs($condition['observed']-$expected)<1e-8,$p['symbol'].' EMA cache is not shared across symbols');
 }
}
marketAssert(count(array_unique($api->historySymbols))===2,'history requests include both requested symbols');
$next=new MultiMarketWallex();$next->prices['ETHUSDT']=3100.;runCycle($next,$now+60);
$rows=db()->query('SELECT id,state FROM strategies')->fetchAll(PDO::FETCH_KEY_PAIR);
marketAssert(isset(json_decode($rows['ethusdt'],true)['cancelled_at']),'ETH cancellation follows ETH price');
marketAssert(!isset(json_decode($rows['btcusdt'],true)['cancelled_at']),'ETH cancellation does not cancel BTC');
