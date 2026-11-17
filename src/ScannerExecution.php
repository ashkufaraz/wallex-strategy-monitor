<?php
declare(strict_types=1);

final class ScannerExecution {
 static function quantize(float $value,float $step,bool $up=false):float {
  $units=$value/$step;$nearest=round($units);if(abs($units-$nearest)<=max(1e-10,abs($units)*PHP_FLOAT_EPSILON*4))$units=$nearest;
  return ($up?ceil($units):floor($units))*$step;
 }
 static function check(array $p,array $snapshot,array $c,float $capital):array {
  $r=self::evaluate($p,$snapshot,$c,$capital);$present=array_column($r['conditions'],'id');
  foreach($r['conditions'] as $v)if($v['status']==='FAIL'&&in_array($v['id'],['not_expired','setup_invalidation','target_before_entry'],true))$r['terminal']=true;
  $r['reason_code']=array_values(array_filter($r['conditions'],fn($v)=>in_array($v['status'],['FAIL','UNAVAILABLE'],true)))[0]['id']??$r['status'];
  if($r['status']==='MANUAL_REVIEW_AVAILABLE')$r+=['risk_budget_usdt'=>$capital*$c['risk_pct']/100,'instrument'=>$snapshot['market']['instrument'],'financing_rate_bps_day'=>$c['financing_bps_day']??0.0,'cost_assumptions'=>['entry_fee_bps'=>$c['entry_fee_bps'],'exit_fee_bps'=>$c['exit_fee_bps'],'entry_liquidity'=>'UNSPECIFIED_NOT_ASSUMED_MAKER','slippage_bps'=>$c['slippage_bps'],'slippage_scope'=>'PER_SIDE','financing_basis'=>$c['financing_bps_day']===null?'NON_FINANCED_SPOT':'EXPLICIT_CONFIGURED_RATE']];
  foreach(['market_identity','instrument_support','market_limits','quote_freshness','orderbook','spread','target_before_entry','plan_geometry','position_size','executable_depth','entry_range','risk_budget','net_rr','cost_model'] as $id)if(!in_array($id,$present,true))$r['conditions'][]=BreakRetestScanner::condition($id,$r['status']==='MANUAL_REVIEW_AVAILABLE'?'PASS':'NOT_EVALUATED');
  return $r;
 }
 private static function evaluate(array $p,array $snapshot,array $c,float $capital):array {
  $conditions=[];$now=$snapshot['observed_at'];
  $add=function(string $id,string $status,mixed $actual=null,mixed $required=null)use(&$conditions){$conditions[]=BreakRetestScanner::condition($id,$status,$actual,$required);};
  $fail=function(string $status,string $reason)use(&$conditions){return ['status'=>$status,'reason'=>$reason,'conditions'=>$conditions];};
  if($now>=$p['expires_at']){$add('not_expired','FAIL',$now,$p['expires_at']);return $fail('REJECTED','زمان اعتبار ورود پایان یافته است.');}
  for($t=$p['candle_close'];$t+900<=$now;$t+=900){
   try{$bars=ScannerData::closed($snapshot['bars']['15m']??[],900,$t+900,1);$b=$bars[0];}catch(Throwable $e){$add('target_before_entry','UNAVAILABLE',$e->getMessage());return $fail('UNAVAILABLE','بازهٔ قبل از ورود برای بررسی ابطال کامل نیست.');}
   if(($p['direction']==='LONG'&&$b['high']>=$p['targets'][0])||($p['direction']==='SHORT'&&$b['low']<=$p['targets'][0])){$add('target_before_entry','FAIL',$t,$p['targets'][0]);return $fail('REJECTED','هدف اول پیش از ورود لمس شده است.');}
   $sgn=$p['direction']==='LONG'?1:-1;$extreme=$sgn===1?$b['low']:$b['high'];if($sgn*($extreme-$p['stop_loss'])<=0||(isset($p['invalidation_price'])&&$sgn*($b['close']-$p['invalidation_price'])<=0)){$add('setup_invalidation','FAIL',$t,$p['stop_loss']);return $fail('REJECTED','فرصت پیش از ورود باطل شده است.');}
  }
  $market=$snapshot['market']??[];$book=$snapshot['book']??[];
  $financing=$c['financing_bps_day'];
  if($financing===null){if(($market['instrument']??null)==='SPOT'&&$p['direction']==='LONG')$c['financing_bps_day']=0.0;else{$add('financing_cost','UNAVAILABLE');return $fail('UNAVAILABLE','هزینهٔ تأمین مالی این ابزار مشخص نیست.');}}
  if(($snapshot['provider']??null)!=='wallex'||($market['provider']??null)!=='wallex'||($market['symbol']??null)!=='BTCUSDT'||($market['quote_asset']??null)!=='USDT'){$add('market_identity','UNAVAILABLE');return $fail('UNAVAILABLE','مشخصات بازار والکس قابل تأیید نیست.');}
  if(($market[$p['direction']==='LONG'?'supports_long':'supports_short']??false)!==true){$add('instrument_support','UNAVAILABLE',$market['instrument']??null,$p['direction']);return $fail('UNAVAILABLE','امکان جهت معامله در ابزار والکس تأیید نیست؛ فروش دارایی با بازکردن شورت یکسان نیست.');}
  $add('instrument_support','PASS',$market['instrument'],$p['direction']);
  foreach(['step_size','tick_size','min_qty','min_notional'] as $field)if(!isset($market[$field])||!is_numeric($market[$field])||!is_finite((float)$market[$field])||$market[$field]<=0){$add('market_limits','UNAVAILABLE',$field);return $fail('UNAVAILABLE','حداقل سفارش، گام حجم یا گام قیمت بازار موجود نیست.');}
  $source=$book['source_timestamp']??null;$received=$book['received_at']??null;
  $fresh=($book['provider']??null)==='wallex'&&($book['symbol']??null)==='BTCUSDT'&&($book['trust_status']??null)==='VERIFIED'&&is_int($source)&&is_int($received)&&$source<=$now&&$received<=$now&&$now-$source<=$c['quote_max_age_seconds']&&$now-$received<=$c['quote_max_age_seconds'];
  $add('quote_freshness',$fresh?'PASS':'UNAVAILABLE',['source_timestamp'=>$source,'received_at'=>$received,'trust'=>$book['trust_status']??null],$c['quote_max_age_seconds']);
  if(!$fresh)return $fail('UNAVAILABLE','تازگی منبع دفتر سفارش قابل تأیید نیست؛ زمان دریافت محلی جای timestamp منبع استفاده نمی‌شود.');
  try{$levels=WallexExecutionMarketProvider::levels($book['bids']??[],$book['asks']??[]);}catch(Throwable $e){$add('orderbook','UNAVAILABLE');return $fail('UNAVAILABLE','دفتر سفارش ناقص یا نامعتبر است.');}
  if($levels['ask']<=$levels['bid']){$add('orderbook','UNAVAILABLE');return $fail('UNAVAILABLE','دفتر سفارش قفل یا متقاطع است.');}
  $spread=10000*$levels['spread']/$levels['mid'];$add('spread',$spread<=$c['max_spread_bps']?'PASS':'FAIL',$spread,$c['max_spread_bps']);
  if($spread>$c['max_spread_bps'])return $fail('REJECTED','اسپرد از سقف تعیین‌شده بیشتر است.');
  $sign=$p['direction']==='LONG'?1:-1;$top=$p['direction']==='LONG'?$levels['ask']:$levels['bid'];
  if($sign*($top-$p['stop_loss'])<=0||(isset($p['invalidation_price'])&&$sign*($top-$p['invalidation_price'])<=0)){$add('setup_invalidation','FAIL',$top,$p['invalidation_price']??$p['stop_loss']);return $fail('REJECTED','فرصت پیش از ورود باطل شده است.');}
  if($sign*($top-$p['targets'][0])>=0){$add('target_before_entry','FAIL',$top,$p['targets'][0]);return $fail('REJECTED','هدف اول پیش از ورود لمس شده است.');}
  $range=$p['entry_range'];$entryWorst=$range[$sign===1?1:0]*(1+$sign*$c['slippage_bps']/10000);
  $stop=$p['stop_loss'];$tick=(float)$market['tick_size'];$stop=self::quantize($stop,$tick,$sign!==1);
  $targets=array_map(fn($v)=>self::quantize($v,$tick,$sign!==1),$p['targets']);
  $stopExit=$stop*(1-$sign*$c['slippage_bps']/10000);$targetExit=$targets[0]*(1-$sign*$c['slippage_bps']/10000);
  $unitRisk=$sign*($entryWorst-$stopExit)+$entryWorst*$c['entry_fee_bps']/10000+$stopExit*$c['exit_fee_bps']/10000+$entryWorst*$c['financing_bps_day']/10000*$c['max_paper_seconds']/86400;
  if($stop<=0||$unitRisk<=0||$sign*($entryWorst-$stop)<=0||$sign*($targets[0]-$entryWorst)<=0){$add('plan_geometry','FAIL');return $fail('REJECTED','ساختار قیمت ورود، حد ضرر و هدف معتبر نیست.');}
  $budget=$capital*$c['risk_pct']/100;$fixed=$c['fixed_cost_usdt'];$step=(float)$market['step_size'];
  $qty=self::quantize(min(max(0,$budget-$fixed)/$unitRisk,max(0,$capital-$fixed)/($entryWorst*(1+$c['entry_fee_bps']/10000))),$step);
  if($qty<(float)$market['min_qty']||$qty*$top<(float)$market['min_notional']){$add('position_size','FAIL',$qty,['min_qty'=>$market['min_qty'],'min_notional'=>$market['min_notional']]);return $fail('REJECTED','حجم مجاز مدیریت ریسک از حداقل سفارش والکس کمتر است.');}
  try{$estimate=WallexExecutionMarketProvider::estimate($book,$p['direction'],$qty);}catch(Throwable $e){$add('executable_depth','UNAVAILABLE');return $fail('UNAVAILABLE','عمق قابل استفاده برای حجم سفارش موجود نیست.');}
  $add('executable_depth',$estimate['depth_sufficient']?'PASS':'UNAVAILABLE',$estimate['vwap'],$qty);
  if(!$estimate['depth_sufficient'])return $fail('UNAVAILABLE','عمق مرئی برای حجم معامله کافی نیست.');
  $raw=(float)$estimate['vwap'];$entry=$raw*(1+$sign*$c['slippage_bps']/10000);
  if($qty*$raw<(float)$market['min_notional']){$add('position_size','FAIL',$qty*$raw,$market['min_notional']);return $fail('REJECTED','ارزش قابل‌اجرای سفارش از حداقل ارزش بازار کمتر است.');}
  if($sign*($entry-$stop)<=0||$sign*($targets[0]-$entry)<=0){$add('plan_geometry','FAIL');return $fail('REJECTED','قیمت ورود نسبت به حد ضرر و هدف معتبر نیست.');}
  $add('entry_range',$raw>=$range[0]&&$raw<=$range[1]?'PASS':'FAIL',$raw,$range);
  if($raw<$range[0]||$raw>$range[1])return $fail('REJECTED','قیمت قابل استفادهٔ فعلی خارج از محدوده ورود است.');
  $risk=$qty*($sign*($entry-$stopExit)+$entry*$c['entry_fee_bps']/10000+$stopExit*$c['exit_fee_bps']/10000)+$fixed;
  $reward=$qty*($sign*($targetExit-$entry)-$entry*$c['entry_fee_bps']/10000-$targetExit*$c['exit_fee_bps']/10000)-$fixed;
  // Include maximum configured financing through the time stop in the pre-alert worst case.
  $financing=$qty*$entry*$c['financing_bps_day']/10000*$c['max_paper_seconds']/86400;$reward-=$financing;$risk+=$financing;
  $rr=$risk>0?$reward/$risk:0;$add('risk_budget',$risk<=$budget+1e-8?'PASS':'FAIL',$risk,$budget);$add('net_rr',$rr>=$c['min_net_rr']?'PASS':'FAIL',$rr,$c['min_net_rr']);
  if($risk>$budget+1e-8||$rr<$c['min_net_rr'])return $fail('REJECTED','ریسک یا نسبت سود به زیان خالص پس از هزینه‌ها تأیید نشد.');
  $add('cost_model','PASS',array_intersect_key($c,array_flip(['entry_fee_bps','exit_fee_bps','slippage_bps','fixed_cost_usdt','financing_bps_day'])));
  return ['status'=>'MANUAL_REVIEW_AVAILABLE','reason'=>'الگو و کنترل‌های قیمت، عمق، هزینه و ریسک تأیید شده‌اند؛ فقط بررسی دستی و شبیه‌سازی.','conditions'=>$conditions,'entry'=>$entry,'raw_entry'=>$raw,'quantity'=>$qty,'stop_loss'=>$stop,'targets'=>$targets,'risk_usdt'=>$risk,'net_reward_usdt'=>$reward,'net_rr'=>$rr,'spread_bps'=>$spread,'quote_source_time'=>$source,'quote_received_at'=>$received,'execution_confirmed'=>false];
 }
}
