<?php
declare(strict_types=1);
final class MeanReversion {
 const RULES='1.0.0';
 static function bands(array $bars,int $period,float $factor):array {$values=array_column(array_slice($bars,-$period),'close');if(count($values)!==$period)throw new RuntimeException('UNAVAILABLE: Bollinger history');$mean=array_sum($values)/$period;$variance=array_sum(array_map(fn($v)=>($v-$mean)**2,$values))/$period;$sd=sqrt($variance);return ['middle'=>$mean,'lower'=>$mean-$factor*$sd,'upper'=>$mean+$factor*$sd,'stddev_population'=>$sd];}
 static function adx(array $bars,int $p):float {
  if(count($bars)<2*$p)throw new RuntimeException('UNAVAILABLE: ADX warmup');$tr=0.;$plus=0.;$minus=0.;$dx=[];$adx=null;
  for($i=1;$i<count($bars);$i++){$b=$bars[$i];$old=$bars[$i-1];$range=max($b['high']-$b['low'],abs($b['high']-$old['close']),abs($b['low']-$old['close']));$up=$b['high']-$old['high'];$down=$old['low']-$b['low'];$pd=$up>0&&$up>$down?$up:0.;$md=$down>0&&$down>$up?$down:0.;
   if($i<=$p){$tr+=$range;$plus+=$pd;$minus+=$md;}else{$tr=$tr-$tr/$p+$range;$plus=$plus-$plus/$p+$pd;$minus=$minus-$minus/$p+$md;}
   if($i<$p)continue;$diPlus=$tr>0?100*$plus/$tr:0.;$diMinus=$tr>0?100*$minus/$tr:0.;$v=$diPlus+$diMinus>0?100*abs($diPlus-$diMinus)/($diPlus+$diMinus):0.;
   if($adx===null){$dx[]=$v;if(count($dx)===$p)$adx=array_sum($dx)/$p;}else $adx=($adx*($p-1)+$v)/$p;
  }return (float)$adx;
 }
 static function calculate(array $bars,array $p):array {$previous=array_slice($bars,0,-1);return ['previous'=>['close'=>end($previous)['close'],'bands'=>self::bands($previous,$p['bb_period'],$p['bb_stddev']),'rsi'=>Indicators::value('RSI',$p['rsi_period'],'value',$previous)],'current'=>['close'=>end($bars)['close'],'bands'=>self::bands($bars,$p['bb_period'],$p['bb_stddev']),'rsi'=>Indicators::value('RSI',$p['rsi_period'],'value',$bars),'adx'=>self::adx($bars,$p['adx_period']),'atr'=>Indicators::value('ATR',$p['atr_period'],'value',$bars)]];}
 static function conditions(array $v,array $p,string $direction):array {
  $a=$v['previous'];$b=$v['current'];$long=$direction==='LONG';$conditions=[];$add=function($id,$actual,$op,$required)use(&$conditions){$pass=match($op){'lt'=>$actual<$required,'gt'=>$actual>$required,'gte'=>$actual>=$required,'lte'=>$actual<=$required};$conditions[]=['id'=>$id,'status'=>$pass?'PASS':'FAIL','actual'=>$actual,'operator'=>$op,'required'=>$required];};
  $add('previous_outside_band',$a['close'],$long?'lt':'gt',$a['bands'][$long?'lower':'upper']);$add('previous_rsi_extreme',$a['rsi'],$long?'lt':'gt',$p[$long?'rsi_lower':'rsi_upper']);
  $add('current_return_inside',$b['close'],$long?'gt':'lt',$b['bands'][$long?'lower':'upper']);$add('current_before_middle',$b['close'],$long?'lt':'gt',$b['bands']['middle']);$add('rsi_return',$b['rsi'],$long?'gte':'lte',$p[$long?'rsi_lower':'rsi_upper']);$add('low_trend_adx',$b['adx'],'lt',$p['adx_max']);return $conditions;
 }
 static function evaluate(array $bars,array $p):array {$v=self::calculate($bars,$p);$all=[];foreach(['LONG','BEARISH'] as $direction){$conditions=self::conditions($v,$p,$direction);$all[$direction]=$conditions;if(!array_filter($conditions,fn($c)=>$c['status']!=='PASS')){$last=end($bars);$two=array_slice($bars,-2);return ['pattern_status'=>'PATTERN_CONFIRMED','direction'=>$direction,'reference_price'=>$last['close'],'reference_target'=>$v['current']['bands']['middle'],'suggested_invalidation'=>$direction==='LONG'?min(array_column($two,'low'))-$p['invalidation_atr']*$v['current']['atr']:max(array_column($two,'high'))+$p['invalidation_atr']*$v['current']['atr'],'indicators'=>$v,'conditions'=>$conditions,'reason'=>'خروج کندل قبلی از باند و RSI افراطی، بازگشت کندل بسته‌شده به داخل باند، بازگشت RSI و ADX کم‌روند تأیید شدند.'];}}
  return ['pattern_status'=>'WAITING_PATTERN','reason'=>'همهٔ شرط‌های هیچ‌یک از دو جهت هم‌زمان برقرار نیستند.','conditions_by_direction'=>$all,'indicators'=>$v];
 }
}
