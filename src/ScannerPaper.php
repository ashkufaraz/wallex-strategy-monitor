<?php
declare(strict_types=1);

final class ScannerPaper {
 static function fresh(array $c):array {return ['balance'=>$c['capital'],'pattern'=>[],'paper'=>null,'last_signal'=>null];}
 static function close(array &$state,float $rawExit,string $reason,int $at,array $c,bool $ambiguous=false):array {
  $p=$state['paper'];$sign=$p['candidate']['direction']==='LONG'?1:-1;$qty=$p['quantity'];$exit=$rawExit*(1-$sign*$c['slippage_bps']/10000);
  $exitFee=$qty*$exit*$c['exit_fee_bps']/10000;$financing=$qty*$p['entry']*$c['financing_bps_day']/10000*max(0,$at-$p['entry_at'])/86400;
  $grossAfterSlip=$sign*($exit-$p['entry'])*$qty;$slip=$qty*(abs($p['entry']-$p['raw_entry'])+abs($exit-$rawExit));$fees=$p['entry_fee']+$exitFee+$financing;
  $state['balance']+=$grossAfterSlip-$exitFee-$financing;
  $trade=array_replace($p,['status'=>'CLOSED','exit_at'=>$at,'raw_exit'=>$rawExit,'exit'=>$exit,'exit_reason'=>$reason,'ambiguous'=>$ambiguous,'exit_fee'=>$exitFee,'financing_cost'=>$financing,'slippage_cost'=>$slip,'costs'=>$fees+$slip,'gross_pnl'=>$grossAfterSlip+$slip,'net_pnl'=>$grossAfterSlip-$fees,'balance_after'=>$state['balance'],'exit_model'=>'CONSERVATIVE_CLOSED_OHLC_WITH_CONFIGURED_COSTS']);
  $state['paper']=null;return $trade;
 }
 static function monitor(array &$state,array $snapshot,array $c):array {
  $events=[];$p=$state['paper']??null;if(!$p)return $events;$now=$snapshot['observed_at'];
  if($p['status']==='PENDING'){
   if(!$c['enabled']){$events[]=array_replace($p,['status'=>'CANCELLED','reason'=>'ورود آزمایشی در انتظار، با توقف اسکنر لغو شد.']);$state['paper']=null;return $events;}
   if($now>=$p['candidate']['expires_at']){$events[]=array_replace($p,['status'=>'EXPIRED','reason'=>'ورود آزمایشی تا انقضا انجام نشد.']);$state['paper']=null;return $events;}
   if($now<$p['eligible_at'])return $events;
   try{ScannerData::context($snapshot,$c);}catch(Throwable $e){$state['paper']['quality']='UNAVAILABLE';$state['paper']['reason']=$e->getMessage();return $events;}
   $gate=ScannerExecution::check($p['candidate'],$snapshot,$c,$state['balance']);
   if($gate['status']!=='MANUAL_REVIEW_AVAILABLE'){$state['paper']['quality']=$gate['status'];$state['paper']['reason']=$gate['reason'];if(str_contains($gate['reason'],'هدف اول')){$events[]=array_replace($p,['status'=>'CANCELLED','reason'=>$gate['reason']]);$state['paper']=null;}return $events;}
   $fee=$gate['quantity']*$gate['entry']*$c['entry_fee_bps']/10000+$c['fixed_cost_usdt'];$state['balance']-=$fee;
   $state['paper']=array_replace($p,$gate,['status'=>'OPEN','entry_at'=>$now,'entry_fee'=>$fee,'last_bar'=>intdiv($now,900)*900-900,'quality'=>'AVAILABLE']);
   return $events;
  }
  $bars=[];foreach($snapshot['bars']['15m']??[] as $bar)if(isset($bar['time'])&&is_int($bar['time'])){if(isset($bars[$bar['time']])&&$bars[$bar['time']]!==$bar){$state['paper']['quality']='UNAVAILABLE';$state['paper']['reason']='Conflicting paper candle';return $events;}$bars[$bar['time']]=$bar;}
  $sign=$p['candidate']['direction']==='LONG'?1:-1;
  for($t=$p['last_bar']+900;$t+900<=$now;$t+=900){
   try{$valid=ScannerData::closed(isset($bars[$t])?[$bars[$t]]:[],900,$t+900,1);$b=$valid[0];}catch(Throwable $e){$state['paper']['quality']='UNAVAILABLE';$state['paper']['reason']=$e->getMessage();return $events;}
   $stopHit=$sign===1?$b['low']<=$p['stop_loss']:$b['high']>=$p['stop_loss'];$targetHit=$sign===1?$b['high']>=$p['targets'][0]:$b['low']<=$p['targets'][0];
   if($stopHit){$raw=$sign===1?min($p['stop_loss'],$b['open']):max($p['stop_loss'],$b['open']);$events[]=self::close($state,$raw,$targetHit?'AMBIGUOUS_STOP_FIRST':'STOP_LOSS',$t+900,$c,$targetHit||$t<$p['entry_at']);return $events;}
   if($targetHit){
    if($t<$p['entry_at']){$state['paper']['quality']='UNAVAILABLE';$state['paper']['reason']='UNAVAILABLE: target touched in partial entry candle; order after manual entry is unknown';return $events;}
    $events[]=self::close($state,$p['targets'][0],'TARGET_1',$t+900,$c);return $events;
   }
   $state['paper']['last_bar']=$t;$state['paper']['quality']='AVAILABLE';unset($state['paper']['reason']);
  }
  if($now-$p['entry_at']>=$c['max_paper_seconds']){
   $mark=self::liquidation($p,$snapshot,$c);
   if($mark!==null){$events[]=self::close($state,$mark,'TIME_STOP',$now,$c);return $events;}
   $state['paper']['quality']='UNAVAILABLE';$state['paper']['reason']='UNAVAILABLE: no verified executable time-stop quote';
  }
  return $events;
 }
 static function liquidation(array $p,array $snapshot,array $c):?float {
  $b=$snapshot['book']??[];$now=$snapshot['observed_at'];$at=$b['source_timestamp']??null;$received=$b['received_at']??null;
  if(($b['provider']??null)!=='wallex'||($b['symbol']??null)!=='BTCUSDT'||($b['trust_status']??null)!=='VERIFIED'||!is_int($at)||!is_int($received)||$now-$at<0||$now-$at>$c['quote_max_age_seconds']||$now-$received<0||$now-$received>$c['quote_max_age_seconds'])return null;
  try{$q=WallexExecutionMarketProvider::estimate($b,$p['candidate']['direction']==='LONG'?'SHORT':'LONG',$p['quantity']);return $q['depth_sufficient']?(float)$q['vwap']:null;}catch(Throwable $e){return null;}
 }
 static function equity(array $state,array $snapshot,array $c):?float {
  $p=$state['paper']??null;if(!$p||$p['status']==='PENDING')return $state['balance'];if(($p['quality']??null)==='UNAVAILABLE')return null;
  $raw=self::liquidation($p,$snapshot,$c);if($raw===null)return null;
  $sign=$p['candidate']['direction']==='LONG'?1:-1;$exit=$raw*(1-$sign*$c['slippage_bps']/10000);$fin=$p['quantity']*$p['entry']*$c['financing_bps_day']/10000*max(0,$snapshot['observed_at']-$p['entry_at'])/86400;
  return $state['balance']+$sign*($exit-$p['entry'])*$p['quantity']-$exit*$p['quantity']*$c['exit_fee_bps']/10000-$fin;
 }
 static function report(array $trades,array $equity,float $capital):array {
  $closed=array_values(array_filter($trades,fn($t)=>$t['status']==='CLOSED'));$n=count($closed);$wins=0;$positive=0.;$negative=0.;$net=0.;$costs=0.;$ambiguous=0;
  foreach($closed as $t){$v=$t['net_pnl'];$net+=$v;$costs+=$t['costs'];if($v>0){$wins++;$positive+=$v;}elseif($v<0)$negative-=$v;if($t['ambiguous'])$ambiguous++;}
  $peak=$capital;$dd=0.;$ddPct=0.;$missing=0;foreach($equity as $point){$value=is_array($point)?$point['equity']:$point;if($value===null){$missing++;continue;}$peak=max($peak,$value);$dd=max($dd,$peak-$value);$ddPct=max($ddPct,$peak>0?100*($peak-$value)/$peak:0);}
  return ['closed_trades'=>$n,'wins'=>$wins,'win_rate_pct'=>$n?100*$wins/$n:null,'net_profit_usdt'=>$net,'expectancy_usdt'=>$n?$net/$n:null,'profit_factor'=>$negative>0?$positive/$negative:null,'profit_factor_note'=>$negative>0?null:'UNDEFINED_NO_LOSS_SAMPLE','max_drawdown_usdt'=>$dd,'max_drawdown_pct'=>$ddPct,'drawdown_status'=>$missing?'PARTIAL_UNAVAILABLE_MARKS':'AVAILABLE_AT_OBSERVATION_TIMES','unavailable_equity_points'=>$missing,'total_costs_usdt'=>$costs,'ambiguous_stop_first'=>$ambiguous,'evaluation'=>'UNVALIDATED_PAPER_SAMPLE','real_orders'=>false];
 }
}
