<?php
declare(strict_types=1);
require_once __DIR__.'/Indicators.php';
final class Strategy {
 static ?Closure $signalFilter=null;
 static ?Closure $onCheck=null;
 static function trace(string $kind,string $event,array $condition,array $ctx,array $meta=[]):void {
 if(self::$onCheck!==null)(self::$onCheck)($kind,$event,$condition,$ctx,$meta);
 }
 const TIMEFRAMES=['4h'=>14400,'1h'=>3600,'15m'=>900];
 static function keys(array $a,array $required,array $optional=[]):void {foreach($required as $k) if(!array_key_exists($k,$a)) throw new InvalidArgumentException("Missing: $k"); foreach($a as $k=>$v) if(!in_array($k,array_merge($required,$optional),true)) throw new InvalidArgumentException("Unknown field: $k");}
 static function validate(array $s):array {
 self::keys($s,['strategy_version','id','name','symbol','enabled','action','initial_stage','max_setup_seconds','invalidation','stages'],['trade','cancellation','indicator_conditions']);
 if($s['strategy_version']!==1 || !is_string($s['id']) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$s['id']) || !is_string($s['symbol']) || !preg_match('/^[A-Z0-9]+USDT$/D',$s['symbol'])) throw new InvalidArgumentException('Invalid version, id or USDT symbol');
 if(!is_string($s['name']) || strlen($s['name'])<1 || strlen($s['name'])>300 || !is_bool($s['enabled']) || !in_array($s['action'],['LONG','SHORT'],true) || !is_int($s['max_setup_seconds']) || $s['max_setup_seconds']<1 || $s['max_setup_seconds']>2592000) throw new InvalidArgumentException('Invalid metadata or setup duration (1..2592000)');
 if(array_key_exists('indicator_conditions',$s)) Indicators::validate($s['indicator_conditions']);
 if(array_key_exists('trade',$s)) self::trade($s['trade'],$s['action']);
 if(array_key_exists('cancellation',$s)) {
 if(!is_array($s['cancellation'])||!array_is_list($s['cancellation'])||count($s['cancellation'])<1||count($s['cancellation'])>30) throw new InvalidArgumentException('cancellation must contain 1..30 rules');
 foreach($s['cancellation'] as $rule) {
 if(!is_array($rule)) throw new InvalidArgumentException('Invalid cancellation rule');
 self::keys($rule,['event','when','reason']);
 if(!in_array($rule['event'],['price','bar_close:15m','bar_close:1h','bar_close:4h'],true)||!is_string($rule['reason'])||trim($rule['reason'])===''||strlen($rule['reason'])>1000) throw new InvalidArgumentException('Invalid cancellation event/reason');
 self::condition($rule['when'],$rule['event']);
 }
 }
 self::condition($s['invalidation'],'price');
 if(!is_array($s['stages']) || !array_is_list($s['stages']) || count($s['stages'])<1 || count($s['stages'])>30) throw new InvalidArgumentException('Invalid stages');
 $ids=[];$signal=false;
 foreach($s['stages'] as $stage) {if(!is_array($stage)) throw new InvalidArgumentException('Invalid stage'); self::keys($stage,['id','transitions']); if(!is_string($stage['id']) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$stage['id']) || isset($ids[$stage['id']])) throw new InvalidArgumentException('Invalid/duplicate stage'); $ids[$stage['id']]=true;
 if(!is_array($stage['transitions']) || !array_is_list($stage['transitions']) || count($stage['transitions'])<1 || count($stage['transitions'])>30) throw new InvalidArgumentException('Invalid transitions');
 foreach($stage['transitions'] as $t) {if(!is_array($t)) throw new InvalidArgumentException('Invalid transition'); self::keys($t,['event','when'],['to','signal']); if(isset($t['to'])===isset($t['signal']) || (isset($t['signal']) && $t['signal']!==true) || !in_array($t['event'],['price','bar_close:15m','bar_close:1h','bar_close:4h'],true)) throw new InvalidArgumentException('Invalid transition event/target'); self::condition($t['when'],$t['event']); if(isset($t['signal'])) $signal=true;}}
 if(!is_string($s['initial_stage']) || !isset($ids[$s['initial_stage']])) throw new InvalidArgumentException('Unknown initial stage');
 foreach($s['stages'] as $stage) foreach($stage['transitions'] as $t) if(isset($t['to']) && (!is_string($t['to']) || !isset($ids[$t['to']]))) throw new InvalidArgumentException('Unknown target');
 $seen=[$s['initial_stage']=>true];$reachableSignal=false;
 do {$changed=false;foreach($s['stages'] as $stage) if(isset($seen[$stage['id']])) foreach($stage['transitions'] as $t) {if(isset($t['signal'])) $reachableSignal=true; elseif(!isset($seen[$t['to']])) {$seen[$t['to']]=true;$changed=true;}}} while($changed);
 if(!$signal || !$reachableSignal || count($seen)!==count($ids)) throw new InvalidArgumentException('Unreachable stage or no reachable signal'); return $s;
 }
 static function cancellation(array $s,string $event,array $ctx):?string {
 foreach($s['cancellation']??[] as $i=>$rule) if($rule['event']===$event){self::trace('cancellation',$event,$rule['when'],$ctx,['rule'=>$i+1,'reason'=>$rule['reason']]);if(self::check($rule['when'],$ctx)) return $rule['reason'];}
 return null;
 }
 static function trade(mixed $t,string $action):void {
 if(!is_array($t)) throw new InvalidArgumentException('trade must be an object');
 self::keys($t,['entry','stop_loss','take_profits'],['quantity','leverage','notes']);
 if(!is_array($t['entry'])) throw new InvalidArgumentException('Invalid trade.entry');
 $entry=$t['entry'];self::keys($entry,['type'],['price']);
 if(!in_array($entry['type'],['MARKET','LIMIT'],true)) throw new InvalidArgumentException('Entry type must be MARKET or LIMIT');
 if($entry['type']==='LIMIT') {if(!array_key_exists('price',$entry)) throw new InvalidArgumentException('LIMIT requires entry.price');self::positive($entry['price']);}
 elseif(array_key_exists('price',$entry)) throw new InvalidArgumentException('MARKET uses the observed signal price; omit entry.price');
 self::positive($t['stop_loss']);
 if(!is_array($t['take_profits'])||!array_is_list($t['take_profits'])||count($t['take_profits'])<1||count($t['take_profits'])>10) throw new InvalidArgumentException('Provide 1..10 take_profits');
 $previous=$entry['price']??$t['stop_loss'];
 if(isset($entry['price']) && ($action==='LONG'?$t['stop_loss']>=$previous:$t['stop_loss']<=$previous)) throw new InvalidArgumentException('Stop loss is on the wrong side of entry');
 foreach($t['take_profits'] as $target) {self::positive($target);if($action==='LONG'?$target<=$previous:$target>=$previous) throw new InvalidArgumentException('Take profits must be ordered in the trade direction beyond entry/stop');$previous=$target;}
 if(array_key_exists('quantity',$t)) self::positive($t['quantity']);
 if(array_key_exists('leverage',$t)&&(!is_int($t['leverage'])||$t['leverage']<1||$t['leverage']>125)) throw new InvalidArgumentException('Leverage must be an integer 1..125');
 if(array_key_exists('notes',$t)&&(!is_string($t['notes'])||strlen($t['notes'])>2000)) throw new InvalidArgumentException('Invalid trade notes');
 }
 static function positive(mixed $n):void {if((!is_int($n)&&!is_float($n))||!is_finite((float)$n)||$n<=0) throw new InvalidArgumentException('Trade prices and quantity must be positive finite numbers');}
 static function condition(mixed $c,string $event,int $depth=0):void {
 if(!is_array($c) || $depth>12) throw new InvalidArgumentException('Invalid/deep condition');
 foreach(['all','any'] as $key) if(array_key_exists($key,$c)) {self::keys($c,[$key]); if(!is_array($c[$key]) || !array_is_list($c[$key]) || count($c[$key])<1 || count($c[$key])>30) throw new InvalidArgumentException('Invalid condition group'); foreach($c[$key] as $item) self::condition($item,$event,$depth+1); return;}
 if(array_key_exists('not',$c)) {self::keys($c,['not']); self::condition($c['not'],$event,$depth+1);return;}
 self::keys($c,['left','op','right']); if(!in_array($c['op'],['gt','gte','lt','lte','eq','neq'],true)) throw new InvalidArgumentException('Invalid operator');self::operand($c['left'],$event);self::operand($c['right'],$event);
 }
 static function operand(mixed $x,string $event):void {
 if((is_int($x)||is_float($x)) && is_finite((float)$x)) return;
 if(!is_array($x)) throw new InvalidArgumentException('Operand must be number or source');
 if(array_key_exists('price',$x)) {self::keys($x,['price']);if($x['price']!==true || $event!=='price') throw new InvalidArgumentException('Price source only valid in price events');return;}
 self::keys($x,['candle']);$c=$x['candle'];if(!is_array($c)) throw new InvalidArgumentException('Invalid candle');self::keys($c,['timeframe','offset','field']);
 if(!in_array($c['timeframe'],array_keys(self::TIMEFRAMES),true) || $event!=='bar_close:'.$c['timeframe'] || !is_int($c['offset']) || $c['offset']<1 || $c['offset']>100 || !in_array($c['field'],['open','high','low','close','volume'],true)) throw new InvalidArgumentException('Invalid candle source (offset 1..100)');
 }
 static function value(mixed $x,array $ctx):float {if(is_int($x)||is_float($x)) return (float)$x; if(isset($x['price'])) return $ctx['price'];$c=$x['candle'];$key=$ctx['close']-$c['offset']*$ctx['seconds'];if(!isset($ctx['bars'][$key])) throw new RuntimeException('Missing candle at '.$key); return $ctx['bars'][$key][$c['field']];}
 static function check(array $c,array $ctx):bool {
 if(isset($c['all'])) {foreach($c['all'] as $x) if(!self::check($x,$ctx)) return false;return true;}
 if(isset($c['any'])) {foreach($c['any'] as $x) if(self::check($x,$ctx)) return true;return false;}
 if(isset($c['not'])) return !self::check($c['not'],$ctx);
 $a=self::value($c['left'],$ctx);$b=self::value($c['right'],$ctx);return match($c['op']) {'gt'=>$a>$b,'gte'=>$a>=$b,'lt'=>$a<$b,'lte'=>$a<=$b,'eq'=>$a===$b,'neq'=>$a!==$b};
 }
 static function fresh(array $s,int $now):array {return ['stage'=>$s['initial_stage'],'setup'=>null,'cursor'=>$now,'error'=>null,'last_check'=>null];}
 static function event(array $s,array &$state,string $event,int $time,array $ctx):bool {
 foreach($s['stages'] as $stage) if($stage['id']===$state['stage']) foreach($stage['transitions'] as $i=>$t) if($t['event']===$event) {
 self::trace('transition',$event,$t['when'],$ctx,['stage'=>$stage['id'],'rule'=>$i+1,'to'=>$t['to']??'signal']);
 if(!self::check($t['when'],$ctx))continue;
 if(isset($t['signal'])) {
 if(Indicators::enabled($s)){
  if(self::$signalFilter===null||!(self::$signalFilter)($s,$event,$time,$ctx)){
   return false;
  }
  unset($state['pending_signal']);
 }
 $state['stage']=$s['initial_stage'];$state['setup']=null;return true;}
 $state['stage']=$t['to']; if($state['stage']===$s['initial_stage']) $state['setup']=null; elseif($state['setup']===null) $state['setup']=$time;return false;
 } return false;
 }
}
