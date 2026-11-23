<?php
declare(strict_types=1);
define('TEST_DATABASE',':memory:');require dirname(__DIR__).'/src/App.php';require dirname(__DIR__).'/src/BrowserRunner.php';
$n=0;function outcomeCheck(bool $ok,string $label):void {global $n;if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
$at=1800000000;$owner=(int)db()->query("SELECT id FROM users WHERE role='owner'")->fetchColumn();
$s=['strategy_version'=>1,'id'=>'outcome_long','name'=>'Outcome fixture','symbol'=>'BTCUSDT','exchange'=>'wallex','enabled'=>true,'action'=>'LONG','initial_stage'=>'entry','max_setup_seconds'=>3600,'invalidation'=>['left'=>['price'=>true],'op'=>'lt','right'=>1],'stages'=>[['id'=>'entry','transitions'=>[['event'=>'price','when'=>['left'=>['price'=>true],'op'=>'gt','right'=>1],'signal'=>true]]]],'trade'=>['entry'=>['type'=>'MARKET'],'stop_loss'=>90,'take_profits'=>[110,120]]];
$saved=StrategyStore::save($s,$owner,null,$at-3600);outcomeCheck(StrategyOutcomes::created($s['id'])===$at-3600,'persist real strategy creation time');
$saved2=StrategyStore::save($s,$owner,$saved['version'],$at-1800);outcomeCheck(StrategyOutcomes::created($s['id'])===$at-3600,'editing does not reset creation time');
$candidate=['event'=>'price','time'=>$at,'episode'=>$at,'stage'=>'entry','rule'=>1,'snapshot'=>['passed'=>false,'conditions'=>[]]];
recordEntryOnlyAlert($s,$saved2['version'],$candidate,100.,$at);recordEntryOnlyAlert($s,$saved2['version'],$candidate,100.,$at+1);
$rows=StrategyOutcomes::view();outcomeCheck(count($rows)===1&&$rows[0]['cohort']==='preliminary','deduplicate preliminary episode independently');
$p=json_decode(db()->query('SELECT payload FROM alerts')->fetchColumn(),true);outcomeCheck(!isset($p['trade'])&&isset($p['evaluation_trade']),'preliminary plan stays hypothetical and non-actionable');
outcomeCheck($p['strategy_created_at']===$at-3600&&$p['event_time']===$at,'creation and signal times stay separate');
$r=$rows[0];$b=fn($o,$h,$l,$c,$v=2.)=>['open'=>$o,'high'=>$h,'low'=>$l,'close'=>$c,'volume'=>$v];
$state=StrategyOutcomes::advance($r['snapshot'],$r['state'],[$at=>$b(100,111,99,108),$at+60=>$b(108,121,107,120)],$at+120);
outcomeCheck($state['status']==='ALL_TARGETS'&&$state['targets'][0]['hit_at']===$at+60&&$state['targets'][1]['hit_at']===$at+120,'catch both targets between two-minute polls');
$stopped=StrategyOutcomes::advance($r['snapshot'],$r['state'],[$at=>$b(100,111,99,108),$at+60=>$b(108,109,89,90)],$at+120);
outcomeCheck($stopped['status']==='STOPPED'&&$stopped['tp1_result']==='WIN'&&$stopped['targets'][1]['hit_at']===null,'stop after TP1 preserves TP1 win and higher-target miss');
$loss=StrategyOutcomes::advance($r['snapshot'],$r['state'],[$at=>$b(100,105,89,90)],$at+60);
outcomeCheck($loss['tp1_result']==='LOSS'&&$loss['status']==='STOPPED','stop before TP1 is a loss');
$ambiguous=StrategyOutcomes::advance($r['snapshot'],$r['state'],[$at=>$b(100,111,89,100)],$at+60);
outcomeCheck($ambiguous['status']==='AMBIGUOUS'&&$ambiguous['tp1_result']===null,'same-minute target and stop never assume optimistic order');
$gap=StrategyOutcomes::advance($r['snapshot'],$r['state'],[$at=>$b(85,111,80,100)],$at+60);
outcomeCheck($gap['tp1_result']==='LOSS','opening gap through stop precedes later candle high');
$partial=$r['state'];$partial['partial_minute']=true;$partial=StrategyOutcomes::advance($r['snapshot'],$partial,[$at=>$b(100,111,99,105)],$at+60);
outcomeCheck($partial['status']==='AMBIGUOUS'&&$partial['reason']==='partial_creation_minute','pre-alert extremes in creation minute cannot become wins');
$empty=StrategyOutcomes::advance($r['snapshot'],$r['state'],[$at=>$b(100,200,1,1,0.)],$at+60);
outcomeCheck($empty['status']==='OPEN'&&$empty['last_price']==100.,'zero-volume placeholders never trigger targets or stops');
try{StrategyOutcomes::advance($r['snapshot'],$r['state'],[$at+60=>$b(100,111,99,110)],$at+120);$reject=false;}catch(RuntimeException $e){$reject=true;}outcomeCheck($reject,'history gap blocks evaluation instead of skipping prices');
$short=$r['snapshot'];$short['action']='SHORT';$short['trade']['stop_loss']=110;$shortState=$r['state'];$shortState['targets']=[['price'=>90.,'hit_at'=>null],['price'=>80.,'hit_at'=>null]];
$shortState=StrategyOutcomes::advance($short,$shortState,[$at=>$b(100,101,89,90),$at+60=>$b(90,91,79,80)],$at+120);
outcomeCheck($shortState['status']==='ALL_TARGETS'&&$shortState['tp1_result']==='WIN','SHORT reverses target and stop comparisons');
$limit=$r['state'];$limit['status']='PENDING_ENTRY';$limit['entered_at']=null;
$waiting=StrategyOutcomes::advance($r['snapshot'],$limit,[$at=>$b(105,109,101,106)],$at+60);outcomeCheck($waiting['status']==='PENDING_ENTRY','LIMIT stays pending until its entry is reached');
$fill=StrategyOutcomes::advance($r['snapshot'],$limit,[$at=>$b(105,111,99,108)],$at+60);outcomeCheck($fill['status']==='AMBIGUOUS','same-minute LIMIT fill and target has no invented order');
$sample=[$r+['ignored'=>true],array_replace($r,['alert_id'=>2,'cohort'=>'confirmed','state'=>$loss]),array_replace($r,['alert_id'=>3,'state'=>$ambiguous])];$sample[0]['state']=$state;$report=StrategyOutcomes::report($sample);
outcomeCheck((float)$report['all']['win_rate']===50.&&(float)$report['preliminary']['win_rate']===100.&&(float)$report['confirmed']['win_rate']===0.&&$report['all']['resolved']===2,'separate cohorts and exclude ambiguous outcomes from denominator');
db()->prepare('UPDATE strategy_outcomes SET state=? WHERE alert_id=?')->execute([encode($r['state']),$r['alert_id']]);
class OutcomeProvider extends Wallex {public int $minuteCalls=0;function minutes(string $symbol,int $from,int $to):array{$this->minuteCalls++;$out=[];for($t=$from;$t<$to;$t+=60)$out[$t]=['open'=>100.,'high'=>111.,'low'=>99.,'close'=>108.,'volume'=>2.];return $out;}}
$api=new OutcomeProvider();db()->exec('DELETE FROM strategies');$lock=sys_get_temp_dir().'/outcome-test-'.bin2hex(random_bytes(5));
try{$result=browserCheck($api,$at+120,$lock);outcomeCheck($result['status']==='outcomes_checked'&&$api->minuteCalls===1,'deleted strategy alert keeps tracking its immutable plan via scheduler');}finally{@unlink($lock);}
StrategyOutcomes::capture($r['alert_id'],$p,$at);outcomeCheck(count(StrategyOutcomes::view())===1,'re-import cannot reset evaluated state');
setSetting('outcomes_since',(string)($at+10));setSetting('outcomes_min_alert_id',(string)$r['alert_id']);
outcomeCheck(StrategyOutcomes::view()===[],'new reporting period excludes existing outcomes');
StrategyOutcomes::capture(998,$p,$at);outcomeCheck(StrategyOutcomes::view()===[],'old alerts imported later remain excluded');
StrategyOutcomes::capture(999,$p,$at+60);outcomeCheck(count(StrategyOutcomes::view())===1,'new period accepts future alerts');
StrategyOutcomes::migrate(db());outcomeCheck(setting('outcomes_since')===(string)($at+10),'period boundary persists across migrations');
echo "Strategy outcomes: $n assertions passed.\n";
