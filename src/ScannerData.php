<?php
declare(strict_types=1);

final class ScannerData {
 const SECONDS=['15m'=>900,'1h'=>3600,'4h'=>14400];
 static function closed(array $source,int $seconds,int $now,int $count,bool $allowZero=false):array {
  $bars=[];$end=intdiv($now,$seconds)*$seconds;
  foreach($source as $bar){
   if(!is_array($bar)||!isset($bar['time'])||!is_int($bar['time'])||$bar['time']<0||$bar['time']%$seconds)throw new RuntimeException('UNAVAILABLE: invalid candle timestamp');
   $t=$bar['time'];if($t+$seconds>$end)continue;
   foreach(['open','high','low','close','volume'] as $field)if(!isset($bar[$field])||!is_numeric($bar[$field])||!is_finite((float)$bar[$field]))throw new RuntimeException('UNAVAILABLE: invalid OHLCV');
   if(min($bar['open'],$bar['high'],$bar['low'],$bar['close'])<=0||$bar['volume']<0||$bar['high']<max($bar['open'],$bar['low'],$bar['close'])||$bar['low']>min($bar['open'],$bar['high'],$bar['close']))throw new RuntimeException('UNAVAILABLE: invalid OHLCV range');
   $normalized=['time'=>$t];foreach(['open','high','low','close','volume'] as $field)$normalized[$field]=(float)$bar[$field];
   if(isset($bars[$t])&&$bars[$t]!==$normalized)throw new RuntimeException('UNAVAILABLE: conflicting duplicate candle');$bars[$t]=$normalized;
  }
  $out=[];for($t=$end-$count*$seconds;$t<$end;$t+=$seconds){
   if(!isset($bars[$t]))throw new RuntimeException('UNAVAILABLE: missing or stale '. $seconds.'s candle at '.gmdate('c',$t));
   if(!$allowZero&&$bars[$t]['volume']<=0)throw new RuntimeException('UNAVAILABLE: no trades in '.$seconds.'s interval at '.gmdate('c',$t));
   $out[]=$bars[$t];
  }return $out;
 }
 static function aggregate(array $source,int $base,int $target,int $now,int $count):array {
  $end=intdiv($now,$target)*$target;$ratio=intdiv($target,$base);$bars=self::closed($source,$base,$end,$count*$ratio,true);$out=[];
  foreach(array_chunk($bars,$ratio) as $group){$traded=array_values(array_filter($group,fn($b)=>$b['volume']>0));if(!$traded)throw new RuntimeException('UNAVAILABLE: no trades in '.$target.'s interval at '.gmdate('c',$group[0]['time']));
   $out[]=['time'=>$group[0]['time'],'open'=>$traded[0]['open'],'high'=>max(array_column($traded,'high')),'low'=>min(array_column($traded,'low')),'close'=>end($traded)['close'],'volume'=>array_sum(array_column($traded,'volume'))];
  }return $out;
 }
 static function context(array $snapshot,array $c):array {
  $now=$snapshot['observed_at']??null;if(!is_int($now)||$now<1)throw new RuntimeException('UNAVAILABLE: observation timestamp missing');$out=[];
  foreach(ScannerConfig::counts($c) as $tf=>$n)$out[$tf]=self::closed($snapshot['bars'][$tf]??[],self::SECONDS[$tf],$now,$n);
  return $out;
 }
 static function udf(array $data):array {
  if(($data['s']??null)!=='ok'||!is_array($data['t']??null)||!array_is_list($data['t']))throw new RuntimeException('UNAVAILABLE: Wallex candle response');
  foreach(['o','h','l','c','v'] as $f)if(!is_array($data[$f]??null)||!array_is_list($data[$f])||count($data[$f])!==count($data['t']))throw new RuntimeException('UNAVAILABLE: incomplete Wallex candle arrays');
  $out=[];foreach($data['t'] as $i=>$t){if(!is_int($t)||$t<0||$t>=100000000000)throw new RuntimeException('UNAVAILABLE: candle timestamp must be UTC seconds, not milliseconds');$out[]=['time'=>$t,'open'=>$data['o'][$i],'high'=>$data['h'][$i],'low'=>$data['l'][$i],'close'=>$data['c'][$i],'volume'=>$data['v'][$i]];}return $out;
 }
 static function fetch(Wallex $api,array $c,int $now,?array $cached=null):array {
  $snapshot=['observed_at'=>$now,'received_at'=>$now,'symbol'=>'BTCUSDT','provider'=>'wallex','bars'=>[],'fetch_errors'=>[],'source_issues'=>[]];
  try{
  $m=$api->markets()['BTCUSDT']??throw new RuntimeException('UNAVAILABLE: BTCUSDT market metadata');
  $step=$m['stepSize']??null;$tick=$m['tickSize']??null;$proof=['source'=>'/v1/markets','raw_stepSize'=>$step,'raw_tickSize'=>$tick];
  if(is_int($step)&&is_int($tick)){
   $modern=$api->get('/hector/web/v1/markets')['result']['markets']??[];$match=array_values(array_filter($modern,fn($x)=>($x['symbol']??null)==='BTCUSDT'))[0]??[];
   if(($match['amount_precision']??null)!==$step||($match['price_precision']??null)!==$tick||$step<0||$step>18||$tick<0||$tick>18||($match['is_spot']??null)!==true)throw new RuntimeException('UNAVAILABLE: market precision corroboration failed');
   $proof['corroboration_source']='/hector/web/v1/markets';$proof['amount_precision']=$step;$proof['price_precision']=$tick;$step=10**(-$step);$tick=10**(-$tick);
  }
  $snapshot['market']=['limits_evidence'=>$proof,'symbol'=>$m['symbol']??null,'quote_asset'=>$m['quoteAsset']??null,'step_size'=>$step,'tick_size'=>$tick,'min_qty'=>$m['minQty']??null,'min_notional'=>$m['minNotional']??null,'instrument'=>'SPOT','supports_long'=>true,'supports_short'=>false,'provider'=>'wallex'];
  }catch(Throwable $e){$snapshot['fetch_errors']['market']=$e->getMessage();}
  try{$snapshot['book']=(new WallexExecutionMarketProvider($api,[]))->snapshot('BTCUSDT',time());}catch(Throwable $e){$snapshot['fetch_errors']['book']=$e->getMessage();}
  foreach(ScannerConfig::counts($c) as $tf=>$count){$seconds=self::SECONDS[$tf];$end=intdiv($now,$seconds)*$seconds;
   try{
   if(isset($cached['bars'][$tf])&&($cached['boundaries'][$tf]??null)===$end)$snapshot['bars'][$tf]=$cached['bars'][$tf];
   else {
    // Wallex currently maps resolution=15 to minutes and 240 to hours.
    // Request the verified base granularity, then aggregate complete UTC buckets.
    $base=$tf==='15m'?60:3600;
    $raw=[];$chunk=$base===60?21600:604800;
    for($start=$end-$count*$seconds;$start<$end;$start+=$chunk){$response=$api->get('/v1/udf/history',['symbol'=>'BTCUSDT','resolution'=>$base===60?'1':'60','from'=>$start,'to'=>min($end,$start+$chunk)]);$part=($response['s']??null)==='no_data'?[]:self::udf($response);array_push($raw,...$part);}
    $snapshot['bars'][$tf]=[];
    for($t=$end-$count*$seconds;$t<$end;$t+=$seconds){try{
     $subset=array_values(array_filter($raw,fn($b)=>$b['time']>=$t&&$b['time']<$t+$seconds));
     $bucket=$seconds===$base?self::closed($subset,$base,$t+$seconds,1):self::aggregate($subset,$base,$seconds,$t+$seconds,1);
     array_push($snapshot['bars'][$tf],...$bucket);
    }catch(Throwable $e){$snapshot['source_issues'][$tf.':'.$t]=['code'=>str_contains($e->getMessage(),'no trades')?'NO_TRADES':(str_contains($e->getMessage(),'missing')?'MISSING':'MALFORMED'),'reason'=>$e->getMessage()];}}
   }
   if(count($snapshot['bars'][$tf])===$count)$snapshot['boundaries'][$tf]=$end;
   }catch(Throwable $e){$snapshot['fetch_errors'][$tf]=$e->getMessage();$snapshot['bars'][$tf]??=[];}
  }
  $snapshot['observed_at']=max($now,time());$snapshot['received_at']=$snapshot['observed_at'];return $snapshot;
 }
}
