<?php
// Explicit CLI-only, isolated in-memory database. Never opens the application's SQLite file.
if(PHP_SAPI!=='cli')exit;
define('TEST_DATABASE',':memory:');require dirname(__DIR__).'/src/App.php';
$symbol=$argv[1]??'BTCUSDT';BinanceMarketDataProvider::symbol($symbol);$api=new BinanceMarketDataProvider();$out=['provider'=>'binance_spot','symbol'=>$symbol,'checked_at'=>gmdate('c'),'persistent_database'=>false];
try{$api->withBudget(30,function()use($api,$symbol,&$out){$now=$api->serverTime();$out['server_time']=$now;$out['price']=$api->price($symbol);$out['quote']=$api->quote($symbol);
 foreach(Indicators::TIMEFRAMES as $tf=>$sec){$end=intdiv($now,$sec)*$sec;$bars=$api->candles($symbol,$tf,$end-71*$sec,$end);$values=[];foreach(['RSI','EMA','ATR','VOLUME'] as $indicator)$values[$indicator]=Indicators::value($indicator,14,'value',array_values($bars));$out['candles'][$tf]=['close_time'=>$end,'close_utc'=>gmdate('c',$end),'period'=>14,'warmup_count'=>count($bars),'last_ohlcv'=>end($bars),'ohlcv'=>array_values($bars),'values'=>$values];}
 });$out['ok']=true;}catch(Throwable $e){$out['ok']=false;$out['error']=$e->getMessage();}
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";exit($out['ok']?0:1);
