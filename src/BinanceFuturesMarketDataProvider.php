<?php
declare(strict_types=1);

/** Explicit USD-M Futures strategy provider; legacy Binance Spot analysis stays separate. */
class BinanceFuturesMarketDataProvider extends BinanceMarketDataProvider
{
 function exchange():string {return 'binance';}
 function limits(string $symbol):array {
  self::symbol($symbol);$data=$this->get('/api/v3/exchangeInfo');$found=null;foreach($data['symbols']??[] as $row)if(($row['symbol']??null)===$symbol){$found=$row;break;}
  if(!$found||($found['status']??null)!=='TRADING'||($found['quoteAsset']??null)!=='USDT'||($found['contractType']??null)!=='PERPETUAL')throw new RuntimeException('UNSUPPORTED_MARKET: Binance USD-M '.$symbol);
  $filters=array_column($found['filters']??[],null,'filterType');$step=$filters['LOT_SIZE']['stepSize']??null;$tick=$filters['PRICE_FILTER']['tickSize']??null;$min=$filters['LOT_SIZE']['minQty']??null;$notional=$filters['MIN_NOTIONAL']['notional']??null;
  foreach([$step,$tick,$min,$notional] as $v)if(!is_numeric($v)||!is_finite((float)$v)||(float)$v<=0)throw new RuntimeException('Binance Futures market filters missing');
  return ['provider'=>'binance','exchange'=>'binance','symbol'=>$symbol,'quote_asset'=>'USDT','instrument'=>'FUTURES_USDM','supports_long'=>true,'supports_short'=>true,'step_size'=>(float)$step,'tick_size'=>(float)$tick,'min_qty'=>(float)$min,'min_notional'=>(float)$notional,'limits_evidence'=>['source'=>'/fapi/v1/exchangeInfo','filters'=>$filters]];
 }
 function book(string $symbol,int $now):array {
  $data=$this->depth($symbol);$levels=WallexExecutionMarketProvider::levels($data['bids'],$data['asks']);$raw=$data['T']??null;$source=is_int($raw)&&$raw>=1000000000000&&$raw<100000000000000?intdiv($raw,1000):null;
  return $levels+['provider'=>'binance','exchange'=>'binance','symbol'=>$symbol,'received_at'=>$now,'source_timestamp'=>$source,'timestamp'=>$source,'source_timestamp_raw'=>$raw,'trust_status'=>$source!==null&&$source<=$now&&$now-$source<=10?'VERIFIED':'UNVERIFIED','trust_reason'=>'Futures depth transaction time T, milliseconds; local receipt is not substituted'];
 }
 protected function baseUrl():string {return 'https://fapi.binance.com';}
 protected function endpoint(string $path):string {
  return ['/api/v3/klines'=>'/fapi/v1/klines','/api/v3/ticker/price'=>'/fapi/v2/ticker/price',
   '/api/v3/exchangeInfo'=>'/fapi/v1/exchangeInfo','/api/v3/time'=>'/fapi/v1/time','/api/v3/ticker/bookTicker'=>'/fapi/v1/ticker/bookTicker','/api/v3/depth'=>'/fapi/v1/depth'][$path]
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
