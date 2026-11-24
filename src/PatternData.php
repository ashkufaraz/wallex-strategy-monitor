<?php
declare(strict_types=1);
final class PatternData {
 static function window(array $s,string $tf,int $n):array {
  $now=$s['observed_at'];if(isset($s['data_error'])||isset($s['fetch_errors'][$tf]))throw new RuntimeException('UNAVAILABLE: '.($s['data_error']??$s['fetch_errors'][$tf]));$sec=ScannerData::SECONDS[$tf];$phase=MarketData::phase($s['provider']??'wallex',$sec);$start=MarketData::boundary($now,$sec,$s['provider']??'wallex')-$n*$sec;
  $bars=array_values(array_filter($s['bars'][$tf]??[],function($b)use($start){if(!is_int($b['time']??null))throw new RuntimeException('UNAVAILABLE: candle timestamp');return $b['time']>=$start;}));return ScannerData::closed($bars,$sec,$now,$n,false,$phase);
 }
 static function health(array $s,array $counts):array {
  $out=[];$now=$s['observed_at'];
  foreach($counts as $tf=>$n){$sec=ScannerData::SECONDS[$tf];$phase=MarketData::phase($s['provider']??'wallex',$sec);$end=MarketData::boundary($now,$sec,$s['provider']??'wallex');$valid=[];$latest=null;$gap=null;$invalid=[];
   foreach($s['bars'][$tf]??[] as $b){if(!is_int($b['time']??null)||$b['time']+$sec>$end)continue;try{ScannerData::closed([$b],$sec,$b['time']+$sec,1,false,$phase);$valid[$b['time']]=true;$latest=max($latest??0,$b['time']+$sec);}catch(Throwable $e){$invalid[$b['time']]=['code'=>str_contains($e->getMessage(),'no trades')?'NO_TRADES':'PROCESSING_ERROR','reason'=>$e->getMessage()];}}
   $consecutive=0;for($t=$end-$sec;$t>=$end-$n*$sec;$t-=$sec){if(!isset($valid[$t])){$issue=$s['source_issues'][$tf.':'.$t]??$invalid[$t]??null;$gap=['time'=>$t,'code'=>is_array($issue)?$issue['code']:(isset($s['fetch_errors'][$tf])?($s['fetch_error_codes'][$tf]??'FETCH_ERROR'):'INCOMPLETE_RESPONSE'),'reason'=>is_array($issue)?$issue['reason']:($issue??$s['fetch_errors'][$tf]??'Expected candle absent; no-trade status not proven')];break;}$consecutive++;}
   $out[$tf]=['latest_closed_candle'=>$latest,'consecutive'=>$consecutive,'required'=>$n,'window_start'=>$end-$n*$sec,'window_end'=>$end,'last_gap'=>$gap];
  }return $out;
 }
 static function fresh(array $s,array $c,int $close):void {$now=$s['observed_at'];$received=$s['frame_received_at']['15m']??$s['received_at']??null;$seen=$s['first_seen_at']['15m']??$received;
  if(!is_int($received)||$received>$now||$now-$received>$c['receipt_max_age_seconds'])throw new RuntimeException('UNAVAILABLE: STALE_RECEIPT');
  if(!is_int($seen)||$seen<$close||$seen-$close>$c['publication_max_delay_seconds'])throw new RuntimeException('UNAVAILABLE: LATE_CANDLE_PUBLICATION');
 }
 static function completePage(array $bars,int $base,int $from,int $to):bool {try{ScannerData::closed($bars,$base,$to,intdiv($to-$from,$base),true,($from%$base));return true;}catch(Throwable $e){return false;}}
 static function fetch(Wallex $api,array $c,array $breakConfig,int $now,array $cache=[]):array {
  $scope=MarketScope::current();$exchange=$api->exchange();$symbol=$scope['symbol'];if($exchange!==$scope['exchange'])throw new RuntimeException('Provider/scope mismatch');if(($cache['provider']??$exchange)!==$exchange||($cache['symbol']??$symbol)!==$symbol)$cache=[];
  // A missing market must never be interpreted as evidence of no trades.
  if(!defined('TEST_DATABASE')&&(get_class($api)==='Wallex'||$api instanceof NobitexMarketDataProvider))$api->price($symbol);
  $counts=[];if($c['methods'][PatternConfig::BREAKOUT]['enabled'])$counts=ScannerConfig::counts($breakConfig);if($c['methods'][PatternConfig::MEAN]['enabled'])$counts['15m']=max($counts['15m']??0,PatternConfig::meanCount($c['methods'][PatternConfig::MEAN]));
  $s=['exchange'=>$exchange,'provider'=>$exchange,'symbol'=>$symbol,'observed_at'=>$now,'received_at'=>$now,'bars'=>[],'fetch_errors'=>[],'frame_received_at'=>[],'first_seen_at'=>[],'boundaries'=>[]];
  foreach(['15m','1h','4h'] as $tf){if(!isset($counts[$tf]))continue;$n=$counts[$tf];$sec=ScannerData::SECONDS[$tf];$phase=MarketData::phase($exchange,$sec);$end=MarketData::boundary($now,$sec,$exchange);
   try{if(($cache['boundaries'][$tf]??null)===$end&&count($cache['bars'][$tf]??[])===$n&&($tf!=='15m'||$now-($cache['frame_received_at'][$tf]??0)<=$c['receipt_max_age_seconds'])){foreach(['bars','frame_received_at','first_seen_at','boundaries','latest_close'] as $k)$s[$k][$tf]=$cache[$k][$tf];continue;}
    $base=in_array($exchange,['nobitex','binance'],true)?$sec:($tf==='15m'?60:3600);$chunk=in_array($exchange,['nobitex','binance'],true)?500*$base:($base===60?21600:604800);$raw=[];$empty=[];$s['pages_boundary'][$tf]=$end;
    for($from=$end-$n*$sec;$from<$end;$from+=$chunk){
     if($tf!=='15m'&&($cache['pages_boundary'][$tf]??null)===$end&&isset($cache['pages'][$tf][$from])&&self::completePage($cache['pages'][$tf][$from],$base,$from,min($end,$from+$chunk)))$part=$cache['pages'][$tf][$from];
     else{$r=MarketData::history($api,['symbol'=>$symbol,'resolution'=>(string)intdiv($base,60),'from'=>$from,'to'=>min($end,$from+$chunk)]);$s['api_evidence'][$tf][$from]=['request'=>['resolution'=>(string)intdiv($base,60),'from'=>$from,'to'=>min($end,$from+$chunk)],'response'=>$r];if(($r['s']??null)==='no_data')$empty[]=[$from,min($end,$from+$chunk)];$part=($r['s']??null)==='no_data'?[]:ScannerData::udf($r);}
     if($tf!=='15m'&&self::completePage($part,$base,$from,min($end,$from+$chunk)))$s['pages'][$tf][$from]=$part;array_push($raw,...$part);
    }
    $received=time();$s['frame_received_at'][$tf]=$received;$s['first_seen_at'][$tf]=(($cache['latest_close'][$tf]??null)===$end&&count(array_filter($cache['bars'][$tf]??[],fn($b)=>$b['time']===$end-$sec))>0)?($cache['first_seen_at'][$tf]??$received):$received;$s['latest_close'][$tf]=$end;$s['bars'][$tf]=[];
    for($t=$end-$n*$sec;$t<$end;$t+=$sec){try{$subset=array_values(array_filter($raw,fn($b)=>$b['time']>=$t&&$b['time']<$t+$sec));$bucket=$base===$sec?ScannerData::closed($subset,$base,$t+$sec,1,false,$phase):ScannerData::aggregate($subset,$base,$sec,$t+$sec,1);array_push($s['bars'][$tf],...$bucket);}catch(Throwable $e){$noData=false;foreach($empty as [$a,$z])if($t>=$a&&$t+$sec<=$z)$noData=true;$s['source_issues'][$tf.':'.$t]=['code'=>str_contains($e->getMessage(),'no trades')||$noData?'NO_TRADES':(str_contains($e->getMessage(),'missing')?'INCOMPLETE_RESPONSE':'PROCESSING_ERROR'),'reason'=>$e->getMessage(),'base_seconds'=>$base,'target_seconds'=>$sec,'no_data_response'=>$noData];}}
    if(count($s['bars'][$tf])===$n){$s['boundaries'][$tf]=$end;unset($s['pages'][$tf]);}
   }catch(Throwable $e){$s['fetch_errors'][$tf]=$e->getMessage();$s['fetch_error_codes'][$tf]=str_contains($e->getMessage(),'arrays')?'INCOMPLETE_RESPONSE':(str_contains($e->getMessage(),'candle')?'PROCESSING_ERROR':'FETCH_ERROR');$s['bars'][$tf]??=[];}
  }$s['observed_at']=max(time(),$now);$s['received_at']=$s['observed_at'];return $s;
 }
}
