<?php
declare(strict_types=1);
define('TEST_DATABASE',':memory:');require dirname(__DIR__).'/src/App.php';
class ChartFixture extends Wallex {
 public int $calls=0;
 function __construct(private string $market){parent::__construct();}
 function exchange():string{return $this->market;}
 function get(string $path,array $p=[]):array {
  $this->calls++;$r=['s'=>'ok','t'=>[],'o'=>[],'h'=>[],'l'=>[],'c'=>[],'v'=>[]];
  for($t=$p['from'];$t<$p['to'];$t+=(int)$p['resolution']*60){$r['t'][]=$t;$r['o'][]=100;$r['h'][]=102;$r['l'][]=99;$r['c'][]=101;$r['v'][]=2;}
  return $r;
 }
}
foreach(['nobitex','binance'] as $exchange)foreach([1,64,501] as $count){
 $api=new ChartFixture($exchange);MarketData::$factory=fn()=>$api;$result=MarketData::chart($exchange,'BTCUSDT',$count);
 if(count($result['bars'])!==$count||$result['gaps']||$api->calls!==(int)ceil($count/500))throw new RuntimeException('Chart pagination failed');
 echo "PASS $exchange chart $count candles with bounded pages\n";
}
MarketData::$factory=null;
