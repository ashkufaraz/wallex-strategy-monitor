<?php
declare(strict_types=1);

final class Diagnostics {
 static function entryProgress(array $s,array $state,string $version):array {
  $q=db()->prepare('SELECT payload FROM check_logs WHERE strategy_id=? AND version=? ORDER BY id DESC LIMIT 1');$q->execute([$s['id'],$version]);
  $raw=$q->fetchColumn();$latest=$raw===false?null:json_decode($raw,true);
  $counts=['passed'=>0,'total'=>0,'unknown'=>0,'checked'=>$latest['checked']??null];
  $walk=function(array $definition,?array $snapshot,bool $negated=false)use(&$walk,&$counts):void {
   foreach(['all','any','not'] as $group)if(isset($definition[$group])){
    $children=$group==='not'?[$definition[$group]]:$definition[$group];
    foreach($children as $i=>$child)$walk($child,$snapshot['children'][$i]??null,$group==='not'?!$negated:$negated);
    return;
   }
   $counts['total']++;$result=$snapshot['result']??null;
   if($result===null)$counts['unknown']++;
   elseif($negated?!$result:$result)$counts['passed']++;
  };
  foreach($s['stages'] as $stage)foreach($stage['transitions'] as $i=>$rule){
   $found=null;
   if(($latest['status']??'error')!=='error')foreach($latest['waiting']??[] as $entry)if($entry['stage']===$stage['id']&&$entry['rule']===$i+1){$found=$entry;break;}
   if($found===null&&isset($state['setup'])&&!isset($state['cancelled_at']))$found=$state['completed_rules'][$stage['id'].':'.($i+1)]??null;
   $walk($rule['when'],$found['condition']??null);
  }
  return $counts;
 }
 // Snapshot exactly the bar used by Strategy::value; never substitute market price.
 static function candle(mixed $operand,array $ctx):?array {
  if(!is_array($operand)||!isset($operand['candle'],$ctx['close'],$ctx['seconds']))return null;
  $c=$operand['candle'];$start=$ctx['close']-$c['offset']*$ctx['seconds'];
  if(!isset($ctx['bars'][$start]))return null;
  return ['field'=>$c['field'],'timeframe'=>$c['timeframe'],'offset'=>$c['offset'],
   'open_time'=>$start,'close_time'=>$start+$ctx['seconds'],'provider'=>$ctx['bars'][$start]['provider']??'wallex',
   'ohlcv'=>array_intersect_key($ctx['bars'][$start],array_flip(['open','high','low','close','volume']))];
 }
 static function inspect(array $condition,array $ctx):array {
  foreach(['all','any','not'] as $group) if(isset($condition[$group])) {
   $children=array_map(fn($c)=>self::inspect($c,$ctx),$group==='not'?[$condition[$group]]:$condition[$group]);
   $results=array_column($children,'result');
   // Unknown data remains unknown rather than being reported as a failed rule.
   $result=$group==='not'?($results[0]===null?null:!$results[0]):($group==='all'?(in_array(false,$results,true)?false:(in_array(null,$results,true)?null:true)):(in_array(true,$results,true)?true:(in_array(null,$results,true)?null:false)));
   return ['group'=>$group,'result'=>$result,'children'=>$children];
  }
  $out=['left_source'=>$condition['left'],'op'=>$condition['op'],'right_source'=>$condition['right']];
  foreach(['left','right'] as $side){$snapshot=self::candle($condition[$side],$ctx);if($snapshot!==null)$out[$side.'_candle']=$snapshot;}
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
  $status=$report['status']??'checked';
  $level=$status==='error'?'error':(in_array($status,['cancelled','blocked','indicator_blocked'],true)?'warning':($status==='signal'?'success':'info'));
  systemLog($level,'strategy.'.$status,$report['error']??('بررسی استراتژی: '.$status),['strategy_id'=>$id,'version'=>$version,'report'=>$report]);
  db()->prepare('INSERT INTO check_logs(strategy_id,version,checked,payload) VALUES(?,?,?,?)')->execute([$id,$version,$report['checked'],encode($report)]);
  db()->prepare('DELETE FROM check_logs WHERE strategy_id=? AND id NOT IN (SELECT id FROM check_logs WHERE strategy_id=? ORDER BY id DESC LIMIT 30)')->execute([$id,$id]);
 }
}
