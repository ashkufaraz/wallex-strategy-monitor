<?php
declare(strict_types=1);

final class Diagnostics {
 static function inspect(array $condition,array $ctx):array {
  foreach(['all','any','not'] as $group) if(isset($condition[$group])) {
   $children=array_map(fn($c)=>self::inspect($c,$ctx),$group==='not'?[$condition[$group]]:$condition[$group]);
   $results=array_column($children,'result');
   // Unknown data remains unknown rather than being reported as a failed rule.
   $result=$group==='not'?($results[0]===null?null:!$results[0]):($group==='all'?(in_array(false,$results,true)?false:(in_array(null,$results,true)?null:true)):(in_array(true,$results,true)?true:(in_array(null,$results,true)?null:false)));
   return ['group'=>$group,'result'=>$result,'children'=>$children];
  }
  $out=['left_source'=>$condition['left'],'op'=>$condition['op'],'right_source'=>$condition['right']];
  try{$out['left']=Strategy::value($condition['left'],$ctx);$out['right']=Strategy::value($condition['right'],$ctx);$out['result']=Strategy::check($condition,$ctx);}
  catch(Throwable $e){$out['result']=null;$out['error']=$e->getMessage();}
  return $out;
 }
 static function waiting(array $s,array $state,array $bars,int $now,?float $price):array {
  $out=[];
  foreach($s['stages'] as $stage)if($stage['id']===$state['stage'])foreach($stage['transitions'] as $i=>$rule){
   $tf=substr($rule['event'],10);$seconds=Strategy::TIMEFRAMES[$tf]??null;
   $ctx=$seconds?['close'=>intdiv($now,$seconds)*$seconds,'seconds'=>$seconds,'bars'=>$bars[$tf]??[]]:($price===null?[]:['price'=>$price]);
   if(!$seconds&&$price===null){$condition=['result'=>null,'error'=>'No current price'];}
   else $condition=self::inspect($rule['when'],$ctx);
   $out[]=['stage'=>$stage['id'],'rule'=>$i+1,'event'=>$rule['event'],'candle_close'=>$ctx['close']??null,'condition'=>$condition];
  }
  return $out;
 }
 static function save(string $id,string $version,array $report):void {
  db()->prepare('INSERT INTO check_logs(strategy_id,version,checked,payload) VALUES(?,?,?,?)')->execute([$id,$version,$report['checked'],encode($report)]);
  db()->prepare('DELETE FROM check_logs WHERE strategy_id=? AND id NOT IN (SELECT id FROM check_logs WHERE strategy_id=? ORDER BY id DESC LIMIT 30)')->execute([$id,$id]);
 }
}
