<?php
declare(strict_types=1);
final class PatternData {
 static function window(array $s,string $tf,int $n):array {
  $now=$s['observed_at'];if(isset($s['data_error'])||isset($s['fetch_errors'][$tf]))throw new RuntimeException('UNAVAILABLE: '.($s['data_error']??$s['fetch_errors'][$tf]));$sec=ScannerData::SECONDS[$tf];$start=intdiv($now,$sec)*$sec-$n*$sec;
  $bars=array_values(array_filter($s['bars'][$tf]??[],function($b)use($start){if(!is_int($b['time']??null))throw new RuntimeException('UNAVAILABLE: candle timestamp');return $b['time']>=$start;}));return ScannerData::closed($bars,$sec,$now,$n);
 }
 static function fresh(array $s,array $c,int $close):void {$now=$s['observed_at'];$received=$s['frame_received_at']['15m']??$s['received_at']??null;$seen=$s['first_seen_at']['15m']??$received;
  if(!is_int($received)||$received>$now||$now-$received>$c['receipt_max_age_seconds'])throw new RuntimeException('UNAVAILABLE: STALE_RECEIPT');
  if(!is_int($seen)||$seen<$close||$seen-$close>$c['publication_max_delay_seconds'])throw new RuntimeException('UNAVAILABLE: LATE_CANDLE_PUBLICATION');
 }
 static function fetch(Wallex $api,array $c,array $breakConfig,int $now,array $cache=[]):array {
  $counts=[];if($c['methods'][PatternConfig::BREAKOUT]['enabled'])$counts=ScannerConfig::counts($breakConfig);if($c['methods'][PatternConfig::MEAN]['enabled'])$counts['15m']=max($counts['15m']??0,PatternConfig::meanCount($c['methods'][PatternConfig::MEAN]));
  $s=['provider'=>'wallex','symbol'=>'BTCUSDT','observed_at'=>$now,'received_at'=>$now,'bars'=>[],'fetch_errors'=>[],'frame_received_at'=>[],'first_seen_at'=>[],'boundaries'=>[]];
  foreach(['15m','1h','4h'] as $tf){if(!isset($counts[$tf]))continue;$n=$counts[$tf];$sec=ScannerData::SECONDS[$tf];$end=intdiv($now,$sec)*$sec;
   try{if(($cache['boundaries'][$tf]??null)===$end&&count($cache['bars'][$tf]??[])===$n&&($tf!=='15m'||$now-($cache['frame_received_at'][$tf]??0)<=$c['receipt_max_age_seconds'])){foreach(['bars','frame_received_at','first_seen_at','boundaries','latest_close'] as $k)$s[$k][$tf]=$cache[$k][$tf];continue;}
    $base=$tf==='15m'?60:3600;$chunk=$base===60?21600:604800;$raw=[];$s['pages_boundary'][$tf]=$end;
    for($from=$end-$n*$sec;$from<$end;$from+=$chunk){
     if($tf!=='15m'&&($cache['pages_boundary'][$tf]??null)===$end&&isset($cache['pages'][$tf][$from]))$part=$cache['pages'][$tf][$from];
     else{$r=$api->get('/v1/udf/history',['symbol'=>'BTCUSDT','resolution'=>$base===60?'1':'60','from'=>$from,'to'=>min($end,$from+$chunk)]);$part=($r['s']??null)==='no_data'?[]:ScannerData::udf($r);}
     if($tf!=='15m')$s['pages'][$tf][$from]=$part;array_push($raw,...$part);
    }
    $received=time();$s['frame_received_at'][$tf]=$received;$s['first_seen_at'][$tf]=(($cache['latest_close'][$tf]??null)===$end&&count(array_filter($cache['bars'][$tf]??[],fn($b)=>$b['time']===$end-$sec))>0)?($cache['first_seen_at'][$tf]??$received):$received;$s['latest_close'][$tf]=$end;$s['bars'][$tf]=[];
    for($t=$end-$n*$sec;$t<$end;$t+=$sec){try{$subset=array_values(array_filter($raw,fn($b)=>$b['time']>=$t&&$b['time']<$t+$sec));$bucket=$base===$sec?ScannerData::closed($subset,$base,$t+$sec,1):ScannerData::aggregate($subset,$base,$sec,$t+$sec,1);array_push($s['bars'][$tf],...$bucket);}catch(Throwable $e){$s['source_issues'][$tf.':'.$t]=$e->getMessage();}}
    if(count($s['bars'][$tf])===$n){$s['boundaries'][$tf]=$end;unset($s['pages'][$tf]);}
   }catch(Throwable $e){$s['fetch_errors'][$tf]=$e->getMessage();$s['bars'][$tf]??=[];}
  }$s['observed_at']=max(time(),$now);$s['received_at']=$s['observed_at'];return $s;
 }
}
