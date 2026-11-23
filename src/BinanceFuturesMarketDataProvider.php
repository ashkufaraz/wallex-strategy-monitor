<?php
declare(strict_types=1);

/** Explicit USD-M Futures strategy provider; legacy Binance Spot analysis stays separate. */
class BinanceFuturesMarketDataProvider extends BinanceMarketDataProvider
{
 function exchange():string {return 'binance';}
 protected function baseUrl():string {return 'https://fapi.binance.com';}
 protected function endpoint(string $path):string {
  return ['/api/v3/klines'=>'/fapi/v1/klines','/api/v3/ticker/price'=>'/fapi/v2/ticker/price',
   '/api/v3/time'=>'/fapi/v1/time','/api/v3/ticker/bookTicker'=>'/fapi/v1/ticker/bookTicker','/api/v3/depth'=>'/fapi/v1/depth'][$path]
   ?? throw new InvalidArgumentException('Unsupported Futures endpoint');
 }
 protected function httpResponse(string $url,int $remaining):array {
  $path=dirname(__DIR__).'/config/candle-downloader.local.php';$config=is_file($path)?require $path:[];
  $proxy=getenv('CANDLE_BINANCE_PROXY');
  if($proxy===false&&($config['managed_proxy']??false)===true){
   require_once __DIR__.'/CandleProxy.php';$r=CandleProxy::get($url,null,$remaining);
   return ['status'=>$r['status'],'headers'=>$r['headers']??[],'body'=>$r['body']];
  }
  $proxy=$proxy===false?($config['binance_proxy']??''):$proxy;
  $headers=[];$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_PROXY=>$proxy,
   CURLOPT_CONNECTTIMEOUT=>min(5,$remaining),CURLOPT_TIMEOUT=>$remaining,CURLOPT_FOLLOWLOCATION=>false,
   CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HEADERFUNCTION=>static function($ch,$line)use(&$headers){
    if(str_contains($line,':')){[$k,$v]=explode(':',$line,2);$headers[strtolower(trim($k))]=trim($v);}return strlen($line);}]);
  try{$body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);if($body===false)throw new RuntimeException('Binance Futures proxy transport failed');}
  finally{curl_close($ch);}return ['body'=>$body,'status'=>$status,'headers'=>$headers];
 }
 function get(string $path,array $params=[]):array {
  if($path!=='/v1/udf/history')return parent::get($path,$params);
  $tf=array_search((int)($params['resolution']??0)*60,Indicators::TIMEFRAMES,true);
  if($tf===false)throw new InvalidArgumentException('Unsupported Futures UDF resolution');
  $end=min((int)$params['to'],MarketData::boundary($this->now(),Indicators::TIMEFRAMES[$tf],'binance'));
  $bars=$this->candles($params['symbol'],$tf,(int)$params['from'],$end);
  $out=['s'=>'ok','t'=>[],'o'=>[],'h'=>[],'l'=>[],'c'=>[],'v'=>[]];
  foreach($bars as $t=>$bar){$out['t'][]=$t;foreach(['o'=>'open','h'=>'high','l'=>'low','c'=>'close','v'=>'volume'] as $k=>$f)$out[$k][]=$bar[$f];}
  return $out;
 }
}
