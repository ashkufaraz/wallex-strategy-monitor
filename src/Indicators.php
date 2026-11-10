<?php
declare(strict_types=1);

final class Indicators {
 const TIMEFRAMES=['1m'=>60,'15m'=>900,'1h'=>3600,'4h'=>14400];
 const METRICS=['RSI'=>['value'],'EMA'=>['value','close_minus_ema_pct'],'ATR'=>['value','atr_pct'],'VOLUME'=>['value','average_previous','ratio_to_previous_avg']];
 static function enabled(array $s):bool{return ($s['indicator_conditions']['enabled']??false)===true;}
 static function validate(mixed $block):void {
  if(!is_array($block))throw new InvalidArgumentException('indicator_conditions must be an object');
  Strategy::keys($block,['enabled'],['logic','conditions']);
  if(!is_bool($block['enabled']))throw new InvalidArgumentException('indicator_conditions.enabled must be boolean');
  if(!array_key_exists('conditions',$block)){
   if($block['enabled'])throw new InvalidArgumentException('indicator_conditions.conditions is required');
   if(isset($block['logic'])&&$block['logic']!=='all')throw new InvalidArgumentException('indicator_conditions.logic must be all');
   return;
  }
  if(($block['logic']??null)!=='all')throw new InvalidArgumentException('indicator_conditions.logic must be all');
  if(!is_array($block['conditions'])||!array_is_list($block['conditions'])||count($block['conditions'])<1||count($block['conditions'])>30)throw new InvalidArgumentException('indicator_conditions requires 1..30 conditions');
  $ids=[];
  foreach($block['conditions'] as $c){
   if(!is_array($c))throw new InvalidArgumentException('Invalid indicator condition');
   Strategy::keys($c,['id','indicator','timeframe','period','candle','metric','operator','value']);
   if(!is_string($c['id'])||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$c['id'])||isset($ids[$c['id']]))throw new InvalidArgumentException('Invalid/duplicate indicator condition id');
   $ids[$c['id']]=true;
   if(!is_string($c['indicator'])||!isset(self::METRICS[$c['indicator']])||!in_array($c['metric'],self::METRICS[$c['indicator']],true))throw new InvalidArgumentException('Invalid indicator/metric combination');
   if(!is_string($c['timeframe'])||!isset(self::TIMEFRAMES[$c['timeframe']])||$c['candle']!=='last_closed')throw new InvalidArgumentException('Indicator requires timeframe 1m/15m/1h/4h and candle last_closed');
   if(!is_int($c['period'])||$c['period']<1||$c['period']>200)throw new InvalidArgumentException('Indicator period must be integer 1..200');
   if(!in_array($c['operator'],['gt','gte','lt','lte','eq','neq','between'],true))throw new InvalidArgumentException('Invalid indicator operator');
   $values=$c['operator']==='between'?$c['value']:[$c['value']];
   if(!is_array($values)||!array_is_list($values)||count($values)!==($c['operator']==='between'?2:1))throw new InvalidArgumentException('between requires two ordered finite numbers');
   foreach($values as $v)if((!is_int($v)&&!is_float($v))||!is_finite((float)$v)||($c['indicator']==='RSI'&&($v<0||$v>100)))throw new InvalidArgumentException('Invalid indicator threshold (RSI must be 0..100)');
   if(count($values)===2&&$values[0]>$values[1])throw new InvalidArgumentException('between bounds must be ordered');
  }
 }
 static function needed(array $c):int{return $c['indicator']==='VOLUME'?$c['period']+1:5*$c['period']+1;}
 static function value(string $indicator,int $period,string $metric,array $bars):float {
  $n=count($bars);$close=array_column($bars,'close');$last=$close[$n-1]??0;
  if($period<1||$n<($indicator==='EMA'?$period:$period+1)||$last<=0)throw new RuntimeException('Insufficient indicator history');
  if($indicator==='EMA'){
   $v=array_sum(array_slice($close,0,$period))/$period;$alpha=2/($period+1);
   for($i=$period;$i<$n;$i++)$v=$alpha*$close[$i]+(1-$alpha)*$v;
   $v=$metric==='close_minus_ema_pct'?100*($last-$v)/$v:$v;
  }elseif($indicator==='RSI'){
   $gain=0.;$loss=0.;
   for($i=1;$i<$n;$i++){$delta=$close[$i]-$close[$i-1];$up=max(0.,$delta);$down=max(0.,-$delta);
    if($i<=$period){$gain+=$up/$period;$loss+=$down/$period;}else{$gain=($gain*($period-1)+$up)/$period;$loss=($loss*($period-1)+$down)/$period;}}
   $v=$loss==0.?($gain==0.?50.:100.):($gain==0.?0.:100-100/(1+$gain/$loss));
  }elseif($indicator==='ATR'){
   $v=0.;for($i=1;$i<$n;$i++){$b=$bars[$i];$tr=max($b['high']-$b['low'],abs($b['high']-$close[$i-1]),abs($b['low']-$close[$i-1]));$v=$i<=$period?$v+$tr/$period:($v*($period-1)+$tr)/$period;}
   if($metric==='atr_pct')$v=100*$v/$last;
  }elseif($indicator==='VOLUME'){
   $volume=$bars[$n-1]['volume'];$average=array_sum(array_column(array_slice($bars,$n-1-$period,$period),'volume'))/$period;
   if($metric==='ratio_to_previous_avg'&&$average<=0)throw new RuntimeException('Previous volume average is zero');
   $v=match($metric){'value'=>$volume,'average_previous'=>$average,'ratio_to_previous_avg'=>$volume/$average};
  }else throw new RuntimeException('Unknown indicator');
  if(!is_finite((float)$v))throw new RuntimeException('Non-finite indicator result');return (float)$v;
 }
 static function passes(float $v,array $c):bool {return match($c['operator']){
  'gt'=>$v>$c['value'],'gte'=>$v>=$c['value'],'lt'=>$v<$c['value'],'lte'=>$v<=$c['value'],'eq'=>$v==(float)$c['value'],'neq'=>$v!=(float)$c['value'],'between'=>$v>=$c['value'][0]&&$v<=$c['value'][1]};}
}
