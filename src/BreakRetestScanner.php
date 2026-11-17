<?php
declare(strict_types=1);

final class BreakRetestScanner {
 static function condition(string $id,string $status,mixed $actual=null,mixed $required=null):array {return compact('id','status','actual','required');}
 static function evaluate(array &$state,array $bars,array $c,int $now):array {
  $f=$bars['15m'];$last=end($f);$close=$last['time']+900;$conditions=[];
  if($close<=($state['last_close']??0))return ['status'=>'DUPLICATE','reason'=>'کندل جدیدی بسته نشده است.','conditions'=>[],'candle_close'=>$close];
  if(isset($state['setup'])&&$close>($state['last_close']??0)+900){unset($state['setup']);$state['last_close']=$close;return ['status'=>'UNAVAILABLE','reason'=>'میان بررسی‌های ستاپ کندل بسته‌شده‌ای از دست رفته است؛ ستاپ کنار گذاشته شد.','conditions'=>[self::condition('continuous_observation','UNAVAILABLE')],'candle_close'=>$close];}
  $state['last_close']=$close;
  $h=$bars['4h'];$fast=Indicators::value('EMA',$c['ema_fast'],'value',$h);$slow=Indicators::value('EMA',$c['ema_slow'],'value',$h);$oldFast=Indicators::value('EMA',$c['ema_fast'],'value',array_slice($h,0,-1));$hc=end($h)['close'];
  $direction=$fast>$slow&&$fast>$oldFast&&$hc>$fast?'LONG':($fast<$slow&&$fast<$oldFast&&$hc<$fast?'SHORT':null);
  $conditions[]=self::condition('trend_4h',$direction?'PASS':'FAIL',['direction'=>$direction,'ema_fast'=>$fast,'ema_slow'=>$slow,'previous_fast'=>$oldFast,'close'=>$hc],'EMA fast/slow + slope + close');
  $atr=Indicators::value('ATR',$c['atr_period'],'value',$f);if($atr<=0)throw new RuntimeException('UNAVAILABLE: ATR is zero');
  $average=array_sum(array_column(array_slice($f,-1-$c['volume_period'],$c['volume_period']),'volume'))/$c['volume_period'];$volume=$last['volume']/$average;
  $setup=$state['setup']??null;
  $result=['status'=>'REJECTED','reason'=>'هنوز شکست معتبر تشکیل نشده است.','conditions'=>&$conditions,'candle_close'=>$close];
  if($setup&&($close>$setup['expires']||$direction!==$setup['direction'])){unset($state['setup']);$conditions[]=self::condition('setup_valid','FAIL',$direction,$setup['direction']);$result['reason']=$close>$setup['expires']?'ستاپ منقضی شد.':'روند با ستاپ هم‌جهت نیست.';return $result;}
  if(!$direction){$result['reason']='روند ۴ ساعته تأیید نیست.';return $result;}
  $sign=$direction==='LONG'?1:-1;
  if(!$setup){
   // A level uses only hours fully closed before this 15m candle OPENED.
   $hours=array_values(array_filter($bars['1h'],fn($b)=>$b['time']+3600<=$last['time']));$hours=array_slice($hours,-$c['level_lookback']);
   if(count($hours)!==$c['level_lookback'])throw new RuntimeException('UNAVAILABLE: insufficient pre-breakout 1h history');
   $atrHour=Indicators::value('ATR',$c['atr_period'],'value',$bars['1h']);
   $field=$direction==='LONG'?'high':'low';$level=$direction==='LONG'?max(array_column($hours,$field)):min(array_column($hours,$field));
   $touches=0;$lastTouch=-10000;foreach($hours as $i=>$b)if(abs($b[$field]-$level)<=$atrHour*$c['level_tolerance_atr']&&$i-$lastTouch>=$c['touch_separation']){$touches++;$lastTouch=$i;}
   $previous=$f[count($f)-2];$threshold=$level+$sign*$atr*$c['break_buffer_atr'];
   $validTouches=$touches>=$c['level_touches'];$cross=$sign*($last['close']-$threshold)>0&&$sign*($previous['close']-$threshold)<=0&&$sign*($last['close']-$last['open'])>0;
   $conditions[]=self::condition('level_1h',$validTouches?'PASS':'FAIL',['level'=>$level,'touches'=>$touches],$c['level_touches']);
   $conditions[]=self::condition('breakout_15m',$cross?'PASS':'FAIL',$last['close'],$threshold);
   $conditions[]=self::condition('break_volume',$volume>=$c['break_volume_ratio']?'PASS':'FAIL',$volume,$c['break_volume_ratio']);
   if(!$validTouches||!$cross||$volume<$c['break_volume_ratio'])return $result;
   $state['setup']=['stage'=>'RETEST','direction'=>$direction,'level'=>$level,'atr'=>$atr,'break_at'=>$close,'break_low'=>$last['low'],'break_high'=>$last['high'],'expires'=>$close+900*$c['setup_bars'],'conditions'=>$conditions];
   $result['status']='WAITING_RETEST';$result['reason']='شکست بسته‌شده تأیید شد؛ منتظر بازآزمایی روی کندل بعدی.';return $result;
  }
  $level=$setup['level'];$anchorAtr=$setup['atr'];
  if($sign*($last['close']-$level)<-$anchorAtr*$c['retest_tolerance_atr']){unset($state['setup']);$result['reason']='قیمت بسته‌شده سطح ستاپ را باطل کرد.';$conditions[]=self::condition('invalidation','FAIL',$last['close'],$level);return $result;}
  $conditions=array_merge($setup['conditions'],$conditions);
  if($setup['stage']==='RETEST'){
   $extreme=$direction==='LONG'?$last['low']:$last['high'];$touch=abs($extreme-$level)<=$anchorAtr*$c['retest_tolerance_atr']&&$sign*($last['close']-$level)>=0;
   $conditions[]=self::condition('retest_15m',$touch?'PASS':'FAIL',$extreme,[$level-$anchorAtr*$c['retest_tolerance_atr'],$level+$anchorAtr*$c['retest_tolerance_atr']]);
   $result['status']='WAITING_RETEST';$result['reason']='منتظر بازآزمایی معتبر.';
   if($touch){$state['setup']=$setup+['retest_at'=>$close,'retest_low'=>$last['low'],'retest_high'=>$last['high']];$state['setup']['stage']='CONFIRM';$state['setup']['conditions']=$conditions;$result['status']='WAITING_CONFIRMATION';$result['reason']='بازآزمایی ثبت شد؛ تأیید باید روی کندل بسته‌شدهٔ بعدی رخ دهد.';}
   return $result;
  }
  $threshold=($direction==='LONG'?$setup['retest_high']:$setup['retest_low'])+$sign*$atr*$c['confirm_buffer_atr'];
  $confirm=$sign*($last['close']-$threshold)>0&&$sign*($last['close']-$last['open'])>0;
  $conditions[]=self::condition('confirmation_15m',$confirm?'PASS':'FAIL',$last['close'],$threshold);$conditions[]=self::condition('confirmation_volume',$volume>=$c['confirm_volume_ratio']?'PASS':'FAIL',$volume,$c['confirm_volume_ratio']);
  if(!$confirm||$volume<$c['confirm_volume_ratio']){$result['status']='WAITING_CONFIRMATION';$result['reason']='تأیید کندل و حجم هنوز برقرار نیست.';return $result;}
  $structure=$direction==='LONG'?min($setup['break_low'],$setup['retest_low'],$last['low']):max($setup['break_high'],$setup['retest_high'],$last['high']);
  $stop=$direction==='LONG'?min($structure-$atr*$c['structure_buffer_atr'],$last['close']-$atr*$c['stop_atr']):max($structure+$atr*$c['structure_buffer_atr'],$last['close']+$atr*$c['stop_atr']);
  $risk=abs($last['close']-$stop);$stopValid=$risk<=$atr*$c['max_stop_atr'];$conditions[]=self::condition('structural_atr_stop',$stopValid?'PASS':'FAIL',$risk/$atr,$c['max_stop_atr']);
  if(!$stopValid){unset($state['setup']);$result['reason']='فاصلهٔ حد ضرر ساختاری از سقف ATR بیشتر است.';return $result;}
  $version=ScannerConfig::version($c);$id=substr(hash('sha256',$version.':'.$direction.':'.$setup['break_at'].':'.$setup['retest_at'].':'.$close),0,32);
  $candidate=['id'=>$id,'direction'=>$direction,'symbol'=>'BTCUSDT','strategy_version'=>$version,'candle_close'=>$close,'candle_utc'=>gmdate('Y-m-d\TH:i:s\Z',$close),'break_at'=>$setup['break_at'],'retest_at'=>$setup['retest_at'],'level'=>$level,'atr'=>$atr,'reference_price'=>$last['close'],'entry_range'=>[$last['close']-$atr*$c['entry_band_atr'],$last['close']+$atr*$c['entry_band_atr']],'stop_loss'=>$stop,'targets'=>[$last['close']+$sign*$risk*$c['tp1_r'],$last['close']+$sign*$risk*$c['tp2_r']],'expires_at'=>$close+$c['entry_expiry_seconds'],'conditions'=>$conditions,'reason'=>'روند ۴ساعته، سطح ۱ساعته و شکست، بازآزمایی و تأیید روی سه کندل بسته‌شدهٔ متمایز ۱۵دقیقه.'];
  $state['candidate']=$candidate;unset($state['setup']);$result['candidate']=$candidate;$result['status']='PATTERN_CONFIRMED';$result['reason']=$candidate['reason'];return $result;
 }
 static function export(array $p,float $quantity):array {
  $long=$p['direction']==='LONG';$entry=$p['entry_range'][$long?1:0];
  $s=['strategy_version'=>1,'id'=>'scan_'.$p['id'],'name'=>'اسکنر شکست و بازآزمایی BTCUSDT','symbol'=>'BTCUSDT','enabled'=>false,'action'=>$p['direction'],'initial_stage'=>'manual_review','max_setup_seconds'=>max(1,$p['expires_at']-$p['candle_close']),
   'invalidation'=>['left'=>['price'=>true],'op'=>$long?'lte':'gte','right'=>$p['stop_loss']],
   'stages'=>[['id'=>'manual_review','transitions'=>[['event'=>'price','when'=>['all'=>[['left'=>['price'=>true],'op'=>'gte','right'=>$p['entry_range'][0]],['left'=>['price'=>true],'op'=>'lte','right'=>$p['entry_range'][1]]]],'signal'=>true]]]],
   'trade'=>['entry'=>['type'=>'LIMIT','price'=>$entry],'stop_loss'=>$p['stop_loss'],'take_profits'=>$p['targets'],'quantity'=>$quantity,'notes'=>'MANUAL_REVIEW_AVAILABLE · '.$p['strategy_version'].' · انقضا UTC: '.gmdate('c',$p['expires_at']).' · طرح آزمایشی؛ ثبت سفارش واقعی ندارد.']];
  // Compatibility export is an immutable manual-entry plan, NOT a dynamic JSON reimplementation of the scanner.
  return Strategy::validate($s);
 }
}
