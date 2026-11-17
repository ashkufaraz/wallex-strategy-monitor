<?php
declare(strict_types=1);
// Scanner exports are immutable review contracts, never executable order instructions.
final class ScannerPlan {
 const ENTRY_MODEL='MANUAL_EXECUTABLE_AFTER_DELAY';
 static function validate(array $s):void {
  $p=$s['scanner_plan'];if(!is_array($p))throw new InvalidArgumentException('Invalid scanner_plan');
  Strategy::keys($p,['model','candle_close','created_at','eligible_at','expires_at','invalidation_price','stop_loss','entry_range','strategy_version','rules_version','config_snapshot','exit_policy']);
  if($p['model']!==self::ENTRY_MODEL||$s['enabled']!==false||$s['trade']['entry']!==['type'=>'MARKET'])throw new InvalidArgumentException('Scanner review exports must stay disabled and use the manual executable-price model');
  foreach(['candle_close','created_at','eligible_at','expires_at'] as $k)if(!is_int($p[$k]))throw new InvalidArgumentException('Scanner plan requires absolute UTC seconds');
  if($p['eligible_at']<$p['created_at']+($p['config_snapshot']['manual_delay_seconds']??PHP_INT_MAX)||$p['expires_at']<=$p['eligible_at'])throw new InvalidArgumentException('Invalid scanner plan chronology');
  Strategy::positive($p['invalidation_price']);Strategy::positive($p['stop_loss']);
  $expected=['left'=>['price'=>true],'op'=>$s['action']==='LONG'?'lte':'gte','right'=>$p['invalidation_price']];
  if($s['invalidation']!=$expected||$s['trade']['stop_loss']!=$p['stop_loss']||!is_array($p['entry_range'])||!array_is_list($p['entry_range'])||count($p['entry_range'])!==2||$p['entry_range'][0]>$p['entry_range'][1])throw new InvalidArgumentException('Scanner setup invalidation / trade stop mismatch');
 }
 static function review(array $s,array &$state,int $now,float $price):array {
  self::validate($s);$p=$s['scanner_plan'];if(isset($state['scanner_terminal']))return ['status'=>'REJECTED','reason_code'=>$state['scanner_terminal']];
  $sign=$s['action']==='LONG'?1:-1;$reason=$now>=$p['expires_at']?'ENTRY_EXPIRED':($sign*($price-$p['invalidation_price'])<=0?'SETUP_INVALIDATED':($sign*($price-$s['trade']['take_profits'][0])>=0?'TARGET_BEFORE_ENTRY':null));
  if($reason){$state['scanner_terminal']=$reason;return ['status'=>'REJECTED','reason_code'=>$reason];}
  if($now<$p['eligible_at'])return ['status'=>'PENDING','reason_code'=>'WAITING_MANUAL_DELAY'];
  if($price<$p['entry_range'][0]||$price>$p['entry_range'][1])return ['status'=>'REJECTED','reason_code'=>'ENTRY_OUTSIDE_RANGE'];
  return ['status'=>'REVIEW_ONLY','reason_code'=>'SCANNER_SNAPSHOT_REVALIDATION_REQUIRED'];
 }
}
