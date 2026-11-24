<?php
define('TEST_DATABASE',':memory:');require dirname(__DIR__).'/src/App.php';require __DIR__.'/scanner-fixture.php';
function scanCheck(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message."\n";}
[$c,$dataset,$base]=scannerFixture();$result=ScannerRunner::backtest($dataset,$c);
$minutes=[];for($i=0;$i<15;$i++)$minutes[]=['time'=>$base+$i*60,'open'=>100.0,'high'=>101.0,'low'=>99.0,'close'=>100.5,'volume'=>$i===0?0.0:1.0];$minutes[0]['high']=1000.;$agg=ScannerData::aggregate($minutes,60,900,$base+900,1);
scanCheck(count($agg)===1&&$agg[0]['high']===101.0&&$agg[0]['volume']===14.0,'UTC aggregation ignores OHLC of no-trade base slots instead of carrying price');
$missingBase=$minutes;unset($missingBase[5]);try{ScannerData::aggregate(array_values($missingBase),60,900,$base+900,1);scanCheck(false,'missing base must reject');}catch(RuntimeException $e){scanCheck(str_contains($e->getMessage(),'missing'),'missing constituent interval cannot be aggregated');}
scanCheck(count($result['signals'])===1,'one breakout/retest/confirmation signal across distinct closed candles');
$signal=$result['signals'][0];scanCheck($signal['status']==='MANUAL_REVIEW_AVAILABLE'&&$signal['candle_close']===$base+1800,'manual-review stage, UTC candle anchor and version preserved');
scanCheck(Strategy::validate($signal['json_strategy'])['enabled']===false,'existing JSON schema compatible export remains disabled');
scanCheck(count($result['trades'])===1&&$result['trades'][0]['status']==='CLOSED','paper entry and target exit use shared runner');
$trade=$result['trades'][0];scanCheck($trade['entry_at']===$base+1860&&abs($trade['raw_entry']-101.8)<1e-8,'manual delay fills later observed depth instead of candle close');
scanCheck($trade['net_pnl']<$trade['gross_pnl']&&$trade['costs']>0&&$trade['quantity']*$trade['entry']<=$c['capital'],'fees, slippage and capital cap charged');
scanCheck($result['report']['closed_trades']===1&&(float)$result['report']['win_rate_pct']===100.0&&$result['report']['expectancy_usdt']>0&&$result['report']['evaluation']==='UNVALIDATED_PAPER_SAMPLE','sample metrics contain no profitability claim');
$dup=$dataset;array_splice($dup['observations'],1,0,[array_replace($dup['observations'][0],['observed_at'=>$base+10,'book'=>array_replace($dup['observations'][0]['book'],['source_timestamp'=>$base+10,'received_at'=>$base+10])])]);$r=ScannerRunner::backtest($dup,$c);
scanCheck(count($r['signals'])===1&&$r['decisions'][1]['status']==='DUPLICATE','re-fetching a closed candle never advances the pattern');
$future=$dataset;foreach($future['bars']['15m'] as &$b)if($b['time']>=$base+3600)$b['close']=$b['high']=100000.0;unset($b);$r=ScannerRunner::backtest($future,$c);scanCheck($r['signals'][0]['id']===$signal['id'],'future candle values do not alter earlier signal');
$missing=$dataset;$missing['bars']['15m']=array_values(array_filter($missing['bars']['15m'],fn($b)=>$b['time']!==$base-1800));$r=ScannerRunner::backtest($missing,$c);scanCheck(!$r['signals']&&$r['decisions'][0]['status']==='UNAVAILABLE','missing interval blocks scanning without filling');
$zero=$dataset;foreach($zero['bars']['1h'] as &$b)if($b['time']===$base-3600)$b['volume']=0;unset($b);$r=ScannerRunner::backtest($zero,$c);scanCheck(!$r['signals']&&str_contains($r['decisions'][0]['reason'],'no trades'),'no-trade interval remains unavailable');
$stale=$dataset;foreach($stale['observations'] as &$o)$o['book']['source_timestamp']-=100;unset($o);$r=ScannerRunner::backtest($stale,$c);scanCheck(!$r['signals']&&$r['decisions'][2]['status']==='UNAVAILABLE','stale source book is not executable evidence');
$unverified=$dataset;foreach($unverified['observations'] as &$o){$o['book']['source_timestamp']=null;$o['book']['trust_status']='UNVERIFIED';}unset($o);$r=ScannerRunner::backtest($unverified,$c);scanCheck(!$r['signals']&&$r['report']['closed_trades']===0,'real REST receipt time cannot replace missing source timestamp');
$snapshot=$dataset['observations'][2]+['bars'=>$dataset['bars'],'market'=>$dataset['market'],'provider'=>'wallex'];$candidate=$signal;
$wide=$snapshot;$wide['book']['bids'][0][0]=90;$gate=ScannerExecution::check($candidate,$wide,$c,70);scanCheck($gate['status']==='REJECTED','excess spread rejects before alert');
$thin=$snapshot;$thin['book']['asks'][0][1]=0.00001;$gate=ScannerExecution::check($candidate,$thin,$c,70);scanCheck($gate['status']==='UNAVAILABLE','insufficient visible executable depth is unavailable');
$expensive=$c;$expensive['entry_fee_bps']=$expensive['exit_fee_bps']=100.0;scanCheck(ScannerExecution::check($candidate,$snapshot,$expensive,70)['status']==='REJECTED','net RR includes configurable costs');
$short=$candidate;$short['direction']='SHORT';scanCheck(ScannerExecution::check($short,$snapshot,$c,70)['status']==='UNAVAILABLE','spot book never proves a short instrument exists');
$state=ScannerPaper::fresh($c);$state['paper']=array_replace($trade,['status'=>'OPEN','candidate'=>$signal,'entry_at'=>$base+1800,'last_bar'=>$base+1800]);$state['balance']=70-$trade['entry_fee'];
$both=$dataset['observations'][5]+['bars'=>['15m'=>[['time'=>$base+2700,'open'=>102.0,'high'=>200.0,'low'=>90.0,'close'=>110.0,'volume'=>10.0]]]];
$events=ScannerPaper::monitor($state,$both,$c);scanCheck($events[0]['net_pnl']<0&&$events[0]['exit_reason']==='AMBIGUOUS_STOP_FIRST','same-bar stop and target use adverse order');
$state=ScannerPaper::fresh($c);$state['paper']=array_replace($trade,['status'=>'OPEN','candidate'=>$signal,'entry_at'=>$base+1860,'last_bar'=>$base+900]);$state['balance']=70-$trade['entry_fee'];
$partial=$dataset['observations'][4]+['bars'=>['15m'=>[['time'=>$base+1800,'open'=>102.0,'high'=>200.0,'low'=>100.0,'close'=>110.0,'volume'=>10.0]]]];
scanCheck(ScannerPaper::monitor($state,$partial,$c)===[]&&$state['paper']['quality']==='UNAVAILABLE','target inside partial entry candle is not booked as a win');
$report=ScannerPaper::report([['status'=>'CLOSED','net_pnl'=>10,'costs'=>2,'ambiguous'=>false],['status'=>'CLOSED','net_pnl'=>-5,'costs'=>1,'ambiguous'=>true]],[['equity'=>70],['equity'=>80],['equity'=>60],['equity'=>null]],70);
scanCheck($report['closed_trades']===2&&$report['win_rate_pct']==50.0&&$report['net_profit_usdt']==5.0&&$report['expectancy_usdt']==2.5&&$report['profit_factor']==2.0&&$report['max_drawdown_usdt']==20.0&&$report['max_drawdown_pct']==25.0&&$report['drawdown_status']==='PARTIAL_UNAVAILABLE_MARKS','report formulas and missing equity coverage are explicit');
setSetting('scanner_config',encode($c));foreach($dataset['observations'] as $o){$snap=$o+['bars'=>$dataset['bars'],'market'=>$dataset['market'],'provider'=>'wallex','symbol'=>'BTCUSDT'];ScannerService::persist($c,$snap,ScannerService::row($c));}
scanCheck((int)db()->query('SELECT count(*) FROM scanner_signals')->fetchColumn()===1&&(int)db()->query("SELECT count(*) FROM alerts WHERE unique_key LIKE 'scanner:%'")->fetchColumn()===1,'persisted restart state and notification transaction deduplicate signals');
scanCheck((int)db()->query('SELECT count(*) FROM alert_owners')->fetchColumn()===1,'scanner notifications preserve owner-scoped management and mobile delivery');
$replay=ScannerService::replay();scanCheck($replay['signals'][0]['id']===$signal['id']&&abs($replay['trades'][0]['net_pnl']-$trade['net_pnl'])<1e-9,'recorded live paper replay uses identical strategy and fill logic');
$state=ScannerPaper::fresh($c);$r=ScannerRunner::step($state,['observed_at'=>null],$c);scanCheck($r['decision']['status']==='UNAVAILABLE','missing observation timestamp is explicit unavailable');
$state=ScannerPaper::fresh($c);$state['paper']=['id'=>$signal['id'],'status'=>'PENDING','candidate'=>$signal,'eligible_at'=>$base+1860];$bad=$dataset['observations'][3]+['provider'=>'wallex','market'=>$dataset['market'],'bars'=>[]];ScannerPaper::monitor($state,$bad,$c);scanCheck($state['paper']['status']==='PENDING'&&$state['paper']['quality']==='UNAVAILABLE','manual delayed entry requires complete fresh candle data again');
$paused=$c;$paused['enabled']=false;$events=ScannerPaper::monitor($state,$bad,$paused);scanCheck($state['paper']===null&&$events[0]['status']==='CANCELLED','pausing cancels queued entry without opening a position');
class ScannerBaseApi extends Wallex {
 public array $calls=[];
 function get(string $path,array $params=[]):array{$this->calls[]=[$path,$params];if($path==='/v1/depth')return ['success'=>true,'result'=>['bid'=>[['price'=>100,'quantity'=>1,'sum'=>100]],'ask'=>[['price'=>101,'quantity'=>1,'sum'=>101]]]];
  $step=$params['resolution']==='1'?60:3600;$r=['s'=>'ok','t'=>[],'o'=>[],'h'=>[],'l'=>[],'c'=>[],'v'=>[]];for($t=$params['from'];$t<=$params['to'];$t+=$step){$r['t'][]=$t;$r['o'][]=100.;$r['h'][]=101.;$r['l'][]=99.;$r['c'][]=100.;$r['v'][]=1.;}return $r;}
 function markets():array{return ['BTCUSDT'=>['symbol'=>'BTCUSDT','quoteAsset'=>'USDT','stepSize'=>.001,'tickSize'=>.01,'minQty'=>.001,'minNotional'=>1.]];}
}
$api=new ScannerBaseApi();$fetched=ScannerData::fetch($api,ScannerConfig::defaults(),$base);scanCheck(count($fetched['bars']['4h'])===252&&count($fetched['bars']['15m'])===72&&$fetched['bars']['4h'][0]['volume']===4.0&&$fetched['bars']['15m'][0]['volume']===15.0,'live adapter pages base candles and aggregates exact 4h/15m UTC bars');
scanCheck($fetched['book']['source_timestamp']===null&&$fetched['book']['trust_status']==='UNVERIFIED','live adapter never upgrades REST receipt to verified source time');
$revisionState=ScannerPaper::fresh($c);$revisionSnapshot=$dataset['observations'][0]+['bars'=>$dataset['bars'],'market'=>$dataset['market']];ScannerRunner::step($revisionState,$revisionSnapshot,$c);
$changed=$revisionSnapshot;$changed['observed_at']++;$changed['bars']['4h'][19]['close']+=.01;$blocked=ScannerRunner::step($revisionState,$changed,$c);$changed['observed_at']++;$recovered=ScannerRunner::step($revisionState,$changed,$c);
scanCheck(($blocked['decision']['reason_code']??'')==='CANDLE_REVISION_RESET'&&$blocked['signal']===null,'historical revision discards dependent setup without emitting a signal');
scanCheck(($recovered['decision']['reason_code']??'')!=='CANDLE_REVISION_RESET','unchanged revised data does not permanently block subsequent checks');
$archived=db()->query('SELECT payload FROM scanner_candles ORDER BY opened LIMIT 1')->fetchColumn();
$revisionSnapshot=$dataset['observations'][5]+['bars'=>$dataset['bars'],'market'=>$dataset['market'],'provider'=>'wallex','symbol'=>'BTCUSDT'];$revisionSnapshot['observed_at']++;$revisionSnapshot['bars']['4h'][19]['close']+=.01;
ScannerService::persist($c,$revisionSnapshot,ScannerService::row($c));$revisionSnapshot['observed_at']++;ScannerService::persist($c,$revisionSnapshot,ScannerService::row($c));
$revisionReplay=ScannerService::replay();scanCheck($revisionReplay['replay_match']===true,'replay binds each observation to its exact candle variant after provider revisions');
scanCheck($archived===db()->query('SELECT payload FROM scanner_candles ORDER BY opened LIMIT 1')->fetchColumn(),'original stored candle history remains immutable');
echo "Scanner strategy, strict data, execution gates, paper and replay tests passed.\n";
