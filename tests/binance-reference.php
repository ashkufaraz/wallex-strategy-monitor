<?php
define('TEST_DATABASE', ':memory:');require dirname(__DIR__).'/src/App.php';
$n=0;
// Re-encode identical public OHLCV into Binance's wire format, then compare against
// the independent 50-digit Decimal oracle used by indicator-reference.py.
foreach(['btcusdt','ethusdt'] as $symbol){
 $f=json_decode(file_get_contents(__DIR__.'/fixtures/'.$symbol.'-indicator-history.json'),true);$raw=$f['response'];$rows=[];
 foreach($raw['t'] as $i=>$t)$rows[]=[$t*1000,$raw['o'][$i],$raw['h'][$i],$raw['l'][$i],$raw['c'][$i],$raw['v'][$i],($t+60)*1000-1,'0',0,'0','0','0'];
 $bars=array_values(BinanceMarketDataProvider::parse($rows,60,$f['query']['from'],$f['query']['to']));
 foreach($f['reference']['values'] as $key=>$expected){[$indicator,$metric]=explode('.',$key);$c=['indicator'=>$indicator,'period'=>14,'metric'=>$metric];$actual=Indicators::value($indicator,14,$metric,array_slice($bars,-Indicators::needed($c)));if(abs($actual-$expected)>1e-8)throw new RuntimeException($symbol.' '.$key.' independent reference mismatch');$n++;}
}
$native=json_decode(file_get_contents(__DIR__.'/fixtures/binance-btcusdt-reference.json'),true);
foreach($native['candles'] as $tf=>$item)foreach($item['values'] as $indicator=>$expected){$actual=Indicators::value($indicator,$item['period'],'value',$item['ohlcv']);if(abs($actual-$expected)>1e-8)throw new RuntimeException('Native Binance '.$tf.' '.$indicator.' regression');$n++;}
echo "Binance-format and native indicator reference: $n comparisons passed.\n";
