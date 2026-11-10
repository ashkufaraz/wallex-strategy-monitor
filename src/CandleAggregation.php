<?php
declare(strict_types=1);

// A zero-volume source bar proves timestamp coverage, but supplies no trade prices.
final class CandleAggregation {
 static function inspect(array $source,int $base,int $seconds):array {
  if($base<1||$seconds<$base||$seconds%$base!==0)throw new InvalidArgumentException('Invalid aggregation duration');
  $groups=[];$out=[];$unavailable=[];
  foreach($source as $t=>$bar){
   if(!is_int($t)||$t<0||$t%$base!==0)throw new RuntimeException('Invalid source timestamp');
   foreach(['open','high','low','close','volume'] as $field)if(!isset($bar[$field])||!is_numeric($bar[$field])||!is_finite((float)$bar[$field]))throw new RuntimeException('Invalid source OHLCV');
   if($bar['volume']<0||min($bar['open'],$bar['high'],$bar['low'],$bar['close'])<=0||$bar['high']<max($bar['open'],$bar['low'],$bar['close'])||$bar['low']>min($bar['open'],$bar['close']))throw new RuntimeException('Invalid source range');
   $groups[intdiv($t,$seconds)*$seconds][$t]=$bar;
  }
  ksort($groups);
  foreach($groups as $start=>$bars){
   for($t=$start;$t<$start+$seconds;$t+=$base)if(!isset($bars[$t])){$unavailable[$start]='UNAVAILABLE: missing source candle at '.$t;continue 2;}
   ksort($bars);$traded=array_filter($bars,fn($b)=>(float)$b['volume']>0);
   if(!$traded){$unavailable[$start]='UNAVAILABLE: no trades in complete interval';continue;}
   $first=reset($traded);$last=end($traded);
   $out[$start]=['open'=>$first['open'],'close'=>$last['close'],'high'=>max(array_column($traded,'high')),'low'=>min(array_column($traded,'low')),'volume'=>array_sum(array_column($bars,'volume'))];
  }
  return ['bars'=>$out,'unavailable'=>$unavailable];
 }
 static function aggregate(array $source,int $base,int $seconds):array {return self::inspect($source,$base,$seconds)['bars'];}
}
