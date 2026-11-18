<?php
declare(strict_types=1);
class Wallex implements MarketDataProvider {
 function exchange():string {return 'wallex';}
 private array $cache=[];
 function __construct(private ?float $deadline=null) {}
 function withBudget(float $seconds,Closure $operation):mixed {
  $previous=$this->deadline;$this->deadline=min($previous??INF,microtime(true)+$seconds);
  try{return $operation();}finally{$this->deadline=$previous;}
 }
 protected function fetchResponse(string $url,int $remaining):string {
  $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>min(8,$remaining),CURLOPT_TIMEOUT=>min(20,$remaining),CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_ENCODING=>'',CURLOPT_USERAGENT=>'WallexStrategyAlerts/1.0']);
  $raw=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
  if($raw===false||$status!==200)throw new RuntimeException('Wallex HTTP '.$status.' '.$error);
  return $raw;
 }
 function get(string $path,array $params=[]):array {
  $url='https://api.wallex.ir'.$path.($params?'?'.http_build_query($params):'');
  $deadline=min($this->deadline??INF,microtime(true)+20);
  for($attempt=0;$attempt<2;$attempt++){
   $remaining=(int)floor($deadline-microtime(true));if($remaining<1)throw new RuntimeException('Market request time budget exceeded; retry on next check');
   $raw=$this->fetchResponse($url,$remaining);
   try{$d=json_decode($raw,true,512,JSON_THROW_ON_ERROR);}
   catch(JsonException $e){if($attempt===0)continue;throw new RuntimeException('Invalid market JSON at '.$path.': '.$e->getMessage(),0,$e);}
   if(!is_array($d))throw new RuntimeException('Invalid API response');return $d;
  }
  throw new RuntimeException('Invalid API response');
 }
 function invalidateMinuteCache():void {foreach(array_keys($this->cache) as $key)if($key!=='markets')unset($this->cache[$key]);}
 function markets():array {return $this->cache['markets']??=$this->get('/v1/markets')['result']['symbols']??throw new RuntimeException('Markets missing');}
 function price(string $symbol):float {$m=$this->markets()[$symbol]??throw new RuntimeException('Market not found: '.$symbol);if(($m['quoteAsset']??null)!=='USDT') throw new RuntimeException('Market must be USDT');$v=$m['stats']['lastPrice']??null;if(!is_numeric($v)||!is_finite((float)$v)||(float)$v<=0) throw new RuntimeException('Invalid market price');return (float)$v;}
 function minutes(string $symbol,int $from,int $to):array {$key="$symbol:$from:$to";if(isset($this->cache[$key])) return $this->cache[$key];$out=[];
 for($start=$from;$start<$to;$start+=21600) {$end=min($to,$start+21600);$d=MarketData::history($this,['symbol'=>$symbol,'resolution'=>'1','from'=>$start,'to'=>$end]);if(($d['s']??'')!=='ok') throw new RuntimeException('Candle API returned no data');$times=$d['t']??[];if(!is_array($times)||!array_is_list($times)||!count($times)) throw new RuntimeException('Empty candles');foreach(['o','h','l','c','v'] as $f) if(!isset($d[$f])||!is_array($d[$f])||!array_is_list($d[$f])||count($d[$f])!==count($times)) throw new RuntimeException('Unequal candle arrays');
 foreach($times as $i=>$t) {if(!is_numeric($t)||!is_finite((float)$t)||(float)(int)$t!==(float)$t||(int)$t%60!==0) throw new RuntimeException('Invalid minute timestamp');$t=(int)$t;if($t<$from||$t+60>$to) continue;$bar=[];foreach(['o'=>'open','h'=>'high','l'=>'low','c'=>'close','v'=>'volume'] as $short=>$field) {if(!is_numeric($d[$short][$i])||!is_finite((float)$d[$short][$i])) throw new RuntimeException('Invalid OHLC value');$bar[$field]=(float)$d[$short][$i];}if(min($bar['open'],$bar['high'],$bar['low'],$bar['close'])<=0||$bar['volume']<0||$bar['high']<max($bar['open'],$bar['close'],$bar['low'])||$bar['low']>min($bar['open'],$bar['close'])) throw new RuntimeException('Invalid OHLC range');if(isset($out[$t])&&$out[$t]!==$bar) throw new RuntimeException('Conflicting candles');$out[$t]=$bar;}}
 ksort($out);return $this->cache[$key]=$out;
 }
 static function aggregate(array $minutes,int $seconds):array {return CandleAggregation::aggregate($minutes,60,$seconds);}
}
