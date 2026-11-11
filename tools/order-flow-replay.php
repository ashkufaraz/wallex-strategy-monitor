<?php
// Offline historical replay only. No HTTP or persistent SQLite is used.
if(PHP_SAPI!=='cli')exit;
define('TEST_DATABASE',':memory:');require dirname(__DIR__).'/src/App.php';
try{
 $file=$argv[1]??dirname(__DIR__).'/tests/fixtures/order-flow-binance-history.json';$f=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
 if(($f['complete']??false)!==true)throw new RuntimeException('Historical interval is not complete');
 $end=$f['trade_window_end'];$c=OrderFlowConfig::validate(['windows'=>[60,300],'baseline_windows'=>2]);
 $bars=BinanceMarketDataProvider::parse($f['klines'],60,$end-900,$end);
 $out=OrderFlowAdvisory::calculate($f['rows'],$bars,$f['book']??null,$c,'LONG',$end);
 echo encode(['offline'=>true,'symbol'=>$f['symbol'],'end'=>$end,'historical'=>true,'result'=>$out])."\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
