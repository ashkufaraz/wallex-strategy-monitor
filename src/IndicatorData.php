<?php
declare(strict_types=1);
require_once __DIR__.'/Indicators.php';
require_once __DIR__.'/CandleAggregation.php';

// Dedicated validated history for optional indicators; legacy candle fetching stays unchanged.
final class IndicatorData {
 private array $memory=[];
 function __construct(private Wallex $api){}
 // Validate completeness BEFORE caching: a delayed tail must never poison the cache.
 private function fetchPart(array $params,string $symbol,int $base,int $start,int $until):array {
  for($attempt=0;$attempt<2;$attempt++){
   try{
    $bars=self::parse($this->api->get('/v1/udf/history',$params),$symbol,$base,$start,$until);
    for($t=$start;$t<$until;$t+=$base)if(!isset($bars[$t]))throw new RuntimeException('Delayed or missing closed candle at '.$t);
    return $bars;
   }catch(Throwable $e){if($attempt===1)throw $e;usleep(200000);}
  }
  throw new RuntimeException('Indicator history unavailable');
 }
 private function cached(string $key,int $now,Closure $compute):mixed {
  if(isset($this->memory[$key]))return $this->memory[$key];
  db()->exec('CREATE TABLE IF NOT EXISTS indicator_cache(k TEXT PRIMARY KEY, expires INTEGER NOT NULL, payload TEXT NOT NULL)');
  $q=db()->prepare('SELECT payload FROM indicator_cache WHERE k=? AND expires>?');$q->execute([$key,$now]);$raw=$q->fetchColumn();
  if($raw!==false)return $this->memory[$key]=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
  $value=$compute();
  db()->prepare('INSERT OR REPLACE INTO indicator_cache VALUES(?,?,?)')->execute([$key,$now+60,encode($value)]);
  db()->prepare('DELETE FROM indicator_cache WHERE expires<=? OR k IN (SELECT k FROM indicator_cache ORDER BY expires DESC LIMIT -1 OFFSET 256)')->execute([$now]);
  return $this->memory[$key]=$value;
 }
 static function parse(array $d,string $symbol,int $seconds,int $from,int $end):array {
  if(($d['s']??null)!=='ok'||(isset($d['symbol'])&&$d['symbol']!==$symbol))throw new RuntimeException('Missing history or wrong market');
  if(!isset($d['t'])||!is_array($d['t'])||!array_is_list($d['t'])||!count($d['t']))throw new RuntimeException('Empty indicator history');
  foreach(['o','h','l','c','v'] as $f)if(!isset($d[$f])||!is_array($d[$f])||!array_is_list($d[$f])||count($d[$f])!==count($d['t']))throw new RuntimeException('Unequal indicator OHLCV arrays');
  $out=[];$previous=null;
  foreach($d['t'] as $i=>$t){
   if(!is_numeric($t)||!is_finite((float)$t)||(float)(int)$t!==(float)$t||(int)$t%$seconds!==0)throw new RuntimeException('Invalid indicator candle timestamp/resolution');
   $t=(int)$t;if($previous!==null&&$t-$previous!==$seconds)throw new RuntimeException('Duplicate, unordered or gapped indicator candles');$previous=$t;
   $bar=[];foreach(['o'=>'open','h'=>'high','l'=>'low','c'=>'close','v'=>'volume'] as $short=>$field){$v=$d[$short][$i];if(!is_numeric($v)||!is_finite((float)$v))throw new RuntimeException('Invalid indicator OHLCV value');$bar[$field]=(float)$v;}
   if(min($bar['open'],$bar['high'],$bar['low'],$bar['close'])<=0||$bar['volume']<0||$bar['high']<max($bar['open'],$bar['close'],$bar['low'])||$bar['low']>min($bar['open'],$bar['close']))throw new RuntimeException('Invalid indicator OHLCV range');
   if($t>=$from&&$t+$seconds<=$end)$out[$t]=$bar+['open_time'=>$t,'duration'=>$seconds];
  }
  return $out;
 }
 static function aggregate(array $source,int $base,int $seconds):array {
  $out=CandleAggregation::aggregate($source,$base,$seconds);
  foreach($out as $t=>&$bar)$bar+=['open_time'=>$t,'duration'=>$seconds];unset($bar);
  return $out;
 }

 function history(string $symbol,string $tf,int $count,int $asOf,int $now):array {
  $seconds=Indicators::TIMEFRAMES[$tf];$end=intdiv($asOf,$seconds)*$seconds;$from=$end-$count*$seconds;
  if($from<0)throw new RuntimeException('Insufficient history before Unix epoch');
  $base=$seconds>=3600?3600:60;
  // Reuse a larger history fetched by another strategy with a different period.
  $prefix="h:v2:$symbol:$tf:$end:";
  db()->exec('CREATE TABLE IF NOT EXISTS indicator_cache(k TEXT PRIMARY KEY, expires INTEGER NOT NULL, payload TEXT NOT NULL)');
  $q=db()->prepare('SELECT payload FROM indicator_cache WHERE k LIKE ? AND expires>?');$q->execute([$prefix.'%',$now]);
  foreach($q->fetchAll(PDO::FETCH_COLUMN) as $raw){$candidate=json_decode($raw,true,512,JSON_THROW_ON_ERROR);$valid=true;
   for($t=$from;$t<$end;$t+=$seconds)if(!isset($candidate[$t])){$valid=false;break;}
   if($valid)return array_filter($candidate,fn($t)=>$t>=$from&&$t<$end,ARRAY_FILTER_USE_KEY);
  }
  return $this->cached("h:v2:$symbol:$tf:$end:$count",$now,function()use($symbol,$base,$seconds,$from,$end,$now){
   $source=[];
   for($start=$from;$start<$end;$start+=$base*300){$until=min($end,$start+$base*300);
    $part=$this->cached("raw:v2:$symbol:$base:$start:$until",$now,fn()=>$this->fetchPart(['symbol'=>$symbol,'resolution'=>$base===60?'1':'60','from'=>$start,'to'=>$until],$symbol,$base,$start,$until));
    $source+=$part;
   }
   $bars=self::aggregate($source,$base,$seconds);
   for($t=$from;$t<$end;$t+=$seconds)if(!isset($bars[$t]))throw new RuntimeException(CandleAggregation::inspect($source,$base,$seconds)['unavailable'][$t]??('Missing complete '.$seconds.'s candle at '.$t));
   ksort($bars);return $bars;
  });
 }
 function evaluate(array $s,int $asOf,int $now):array {
  return $this->api->withBudget(12,fn()=>$this->evaluateWithinBudget($s,$asOf,$now));
 }
 private function evaluateWithinBudget(array $s,int $asOf,int $now):array {
  $conditions=$s['indicator_conditions']['conditions'];$needs=[];$histories=[];$errors=[];
  foreach($conditions as $c)$needs[$c['timeframe']]=max($needs[$c['timeframe']]??0,Indicators::needed($c));
  foreach($needs as $tf=>$count)try{$histories[$tf]=$this->history($s['symbol'],$tf,$count,$asOf,$now);}catch(Throwable $e){$errors[$tf]=$e->getMessage();}
  $results=[];
  foreach($conditions as $c){$seconds=Indicators::TIMEFRAMES[$c['timeframe']];$end=intdiv($asOf,$seconds)*$seconds;
   $row=$c+['symbol'=>$s['symbol'],'candle_open'=>$end-$seconds,'candle_close'=>$end,'duration'=>$seconds,'as_of'=>$asOf,'observed'=>null,'status'=>'UNAVAILABLE'];
   try{
    if(isset($errors[$c['timeframe']]))throw new RuntimeException($errors[$c['timeframe']]);
    $bars=array_slice(array_values($histories[$c['timeframe']]),-Indicators::needed($c));
    $key=implode(':',['v2',$s['symbol'],$c['timeframe'],$c['indicator'],$c['period'],$c['metric'],$end]);
    $v=$this->cached($key,$now,fn()=>Indicators::value($c['indicator'],$c['period'],$c['metric'],$bars));
    $row['observed']=$v;$row['status']=Indicators::passes($v,$c)?'PASS':'FAIL';
   }catch(Throwable $e){$row['error']=$e->getMessage();}
   $results[]=$row;
  }
  return ['logic'=>'all','as_of'=>$asOf,'passed'=>count(array_filter($results,fn($r)=>$r['status']!=='PASS'))===0,'conditions'=>$results];
 }
}
