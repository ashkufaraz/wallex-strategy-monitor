<?php
declare(strict_types=1);

final class ScannerRunner {
 static function step(array &$state,array $snapshot,array $c):array {
  $now=$snapshot['observed_at']??0;$version=ScannerConfig::version($c);$events=[];$signal=null;
  if(!is_int($now)||$now<1)return ['observed_at'=>null,'strategy_version'=>$version,'decision'=>['status'=>'UNAVAILABLE','reason'=>'Observation timestamp missing','conditions'=>[BreakRetestScanner::condition('observation_time','UNAVAILABLE')]],'signal'=>null,'trade_events'=>[],'equity'=>null];
  if($now<=($state['last_observation']??0))return ['observed_at'=>$now,'strategy_version'=>$version,'decision'=>['status'=>$now===($state['last_observation']??0)?'DUPLICATE':'UNAVAILABLE','reason'=>'Duplicate or out-of-order observation; state not changed','reason_code'=>'observation_order','conditions'=>[BreakRetestScanner::condition('observation_order',$now===($state['last_observation']??0)?'NOT_EVALUATED':'UNAVAILABLE')]],'signal'=>null,'trade_events'=>[],'equity'=>null];
  $state['last_observation']=$now;
  try{$events=ScannerPaper::monitor($state,$snapshot,$c);}catch(Throwable $e){if($state['paper']){$state['paper']['quality']='UNAVAILABLE';$state['paper']['reason']=$e->getMessage();}return ['observed_at'=>$now,'strategy_version'=>$version,'decision'=>['status'=>'UNAVAILABLE','reason'=>$e->getMessage(),'conditions'=>[BreakRetestScanner::condition('paper_monitor','UNAVAILABLE')]],'signal'=>null,'trade_events'=>[],'equity'=>null];}
  if(isset($snapshot['data_error']))return ['observed_at'=>$now,'strategy_version'=>$version,'decision'=>['status'=>'UNAVAILABLE','reason'=>(string)$snapshot['data_error'],'reason_code'=>'data_quality','conditions'=>[BreakRetestScanner::condition('data_quality','UNAVAILABLE',$snapshot['data_error'])]],'signal'=>null,'trade_events'=>$events,'equity'=>ScannerPaper::equity($state,$snapshot,$c)];
  if(!$c['enabled']){unset($state['pattern']['setup'],$state['pattern']['candidate']);return ['observed_at'=>$now,'strategy_version'=>$version,'decision'=>['status'=>'DISABLED','reason'=>'ورود جدید متوقف است؛ معاملهٔ آزمایشی باز همچنان بررسی می‌شود.','conditions'=>[]],'signal'=>null,'trade_events'=>$events,'equity'=>ScannerPaper::equity($state,$snapshot,$c)];}
  try{
   $bars=ScannerData::context($snapshot,$c);$pattern=$state['pattern'];
   $decision=isset($pattern['candidate'])?['status'=>'PATTERN_PENDING_EXECUTION','reason'=>'الگوی ثبت‌شده منتظر کنترل اجرا است.','conditions'=>[],'candle_close'=>$pattern['candidate']['candle_close']]:BreakRetestScanner::evaluate($pattern,$bars,$c,$now);$state['pattern']=$pattern;
   if(isset($state['pattern']['candidate'])){
    $candidate=$state['pattern']['candidate'];$gate=ScannerExecution::check($candidate,$snapshot,$c,$state['balance']);$decision=array_replace($decision,$gate);$decision['candidate']=$candidate;$decision['conditions']=array_merge($candidate['conditions'],$gate['conditions']);
    if($now>=$candidate['expires_at']||($gate['terminal']??false))unset($state['pattern']['candidate']);
    if($state['paper']!==null){$decision['status']='REJECTED';$decision['reason_code']='POSITION_ALREADY_OPEN';$decision['reason']='POSITION_ALREADY_OPEN';unset($state['pattern']['candidate']);}
    elseif($gate['status']==='MANUAL_REVIEW_AVAILABLE'&&$c['enabled']){
     if($state['paper']!==null){$decision['status']='REJECTED';$decision['reason_code']='POSITION_ALREADY_OPEN';$decision['reason']='یک معاملهٔ آزمایشی باز، نامعلوم یا در انتظار ورود وجود دارد.';unset($state['pattern']['candidate']);}
     elseif($candidate['id']!==($state['last_signal']??null)){
      $candidate['stop_loss']=$gate['stop_loss'];$candidate['targets']=$gate['targets'];
      $candidate['created_at']=$now;$signal=$candidate+$gate;$signal['conditions']=$decision['conditions'];$signal['created_at']=$now;$signal['entry_model']=ScannerPlan::ENTRY_MODEL;$signal['json_strategy']=BreakRetestScanner::export($candidate,$gate['quantity']);
      $state['last_signal']=$candidate['id'];$state['paper']=['id'=>$candidate['id'],'status'=>'PENDING','candidate'=>$candidate,'eligible_at'=>$now+$c['manual_delay_seconds'],'quality'=>'WAITING_MANUAL_DELAY'];
      unset($state['pattern']['candidate']);
     }
    }
   }
  }catch(Throwable $e){$decision=['status'=>'UNAVAILABLE','reason'=>$e->getMessage(),'reason_code'=>'data_quality','conditions'=>[BreakRetestScanner::condition('data_quality','UNAVAILABLE',$e->getMessage())],'candle_close'=>is_int($now)?intdiv($now,900)*900:null];}
  $equity=ScannerPaper::equity($state,$snapshot,$c);
  return ['observed_at'=>$now,'strategy_version'=>$version,'decision'=>$decision,'signal'=>$signal,'trade_events'=>$events,'equity'=>$equity];
 }
 static function backtest(array $dataset,array $config):array {
  $c=ScannerConfig::validate($config);$state=$dataset['initial_state']??ScannerPaper::fresh($c);$comparisons=[];$trades=[];$signals=[];$decisions=[];$equity=[['time'=>0,'equity'=>$c['capital']]];$last=0;
  if(($dataset['provider']??null)!=='wallex'||($dataset['symbol']??null)!=='BTCUSDT')throw new InvalidArgumentException('Backtest requires explicit Wallex BTCUSDT provenance');
  foreach($dataset['observations']??[] as $observation){
   if(isset($observation['code_version'])&&$observation['code_version']!==ScannerReadiness::codeVersion())throw new RuntimeException('REPLAY_CODE_VERSION_MISMATCH: use the archived code revision; historical evaluation is immutable');
   $now=$observation['observed_at']??null;if(!is_int($now)||$now<=$last)throw new InvalidArgumentException('Backtest observations must have unique increasing timestamps');$last=$now;
   $snapshot=$observation+['bars'=>$dataset['bars']??[],'market'=>$dataset['market']??[],'provider'=>'wallex','symbol'=>'BTCUSDT'];
   if(isset($observation['available_candles']))foreach(ScannerData::SECONDS as $tf=>$seconds){$keys=array_fill_keys($observation['available_candles'][$tf]??[],true);$snapshot['bars'][$tf]=array_values(array_filter($snapshot['bars'][$tf]??[],fn($b)=>isset($keys[$b['time']])));}
   $runtime=$c;if(isset($observation['runtime_enabled'])&&is_bool($observation['runtime_enabled']))$runtime['enabled']=$observation['runtime_enabled'];
   $r=self::step($state,$snapshot,$runtime);$decisions[]=$r['decision']+['observed_at'=>$now];if($r['signal'])$signals[]=$r['signal'];array_push($trades,...$r['trade_events']);$equity[]=['time'=>$now,'equity'=>$r['equity']];if(isset($observation['result']))$comparisons[]=['observed_at'=>$now,'result_equal'=>$r==$observation['result'],'state_equal'=>$state==($observation['state_after']??[])];
  }
  return ['replay_comparisons'=>$comparisons,'replay_match'=>$comparisons?count(array_filter($comparisons,fn($r)=>!$r['result_equal']||!$r['state_equal']))===0:null,'final_state'=>$state,'mode'=>'BACKTEST_REPLAY','strategy_version'=>ScannerConfig::version($c),'config'=>$c,'signals'=>$signals,'decisions'=>$decisions,'trades'=>$trades,'open_paper'=>$state['paper'],'equity'=>$equity,'report'=>ScannerPaper::report($trades,$equity,$c['capital']),'notes'=>['No invented historical order books. Missing source-fresh quotes remain UNAVAILABLE.','Entries use observations after the manual delay, never a guaranteed candle-close fill.','Targets inside the partial entry candle are UNAVAILABLE; same-bar stop/target resolves stop first.']];
 }
}
