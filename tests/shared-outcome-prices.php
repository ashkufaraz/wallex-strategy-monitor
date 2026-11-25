<?php
declare(strict_types=1);
define('TEST_DATABASE',':memory:');require dirname(__DIR__).'/src/BrowserRunner.php';
function sharedCheck(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$at=time()+600;db();
$s=['strategy_version'=>1,'id'=>'shared-price','name'=>'Shared price','symbol'=>'BTCUSDT','exchange'=>'wallex','enabled'=>true,'action'=>'SHORT','initial_stage'=>'entry','max_setup_seconds'=>3600,'invalidation'=>['left'=>['price'=>true],'op'=>'gt','right'=>1000],'stages'=>[['id'=>'entry','transitions'=>[['event'=>'price','when'=>['left'=>['price'=>true],'op'=>'gt','right'=>999],'signal'=>true]]]],'trade'=>['entry'=>['type'=>'MARKET'],'stop_loss'=>110,'take_profits'=>[90,80]]];
$capture=function(array $config,int $created) {
 $p=['type'=>'signal','strategy_id'=>$config['id'],'strategy_version'=>'v1','name'=>$config['name'],'symbol'=>$config['symbol'],'exchange'=>$config['exchange'],'action'=>$config['action'],'trade'=>$config['trade'],'price'=>100];
 db()->prepare('INSERT INTO alerts(unique_key,created,payload) VALUES(?,?,?)')->execute([bin2hex(random_bytes(10)),$created,encode($p)]);$id=(int)db()->lastInsertId();StrategyOutcomes::capture($id,$p,$created);return $id;
};
$get=fn($id)=>array_values(array_filter(StrategyOutcomes::view(),fn($r)=>$r['alert_id']===$id))[0];
$market=$capture($s,$at);$other=$s;$other['id']='other-strategy';$otherId=$capture($other,$at);
$foreign=$s;$foreign['exchange']='nobitex';$foreignId=$capture($foreign,$at);
$limit=$s;$limit['id']='limit-short';$limit['trade']['entry']=['type'=>'LIMIT','price'=>105];$limitId=$capture($limit,$at);
$long=$s;$long['id']='long';$long['action']='LONG';$long['trade']['stop_loss']=90;$long['trade']['take_profits']=[110,120];$longId=$capture($long,$at);
sharedCheck($get($limitId)['state']['status']==='PENDING_ENTRY','limit cannot be treated as filled just because an alert exists');
db()->prepare('INSERT INTO strategies VALUES(?,?,?,?)')->execute([$s['id'],'v1',encode(Strategy::validate($s)),encode(Strategy::fresh($s,$at))]);
class SharedPriceProvider extends Wallex {
 public int $priceCalls=0,$minuteCalls=0;public float $quote=89;public bool $offline=false;
 function price(string $symbol):float {$this->priceCalls++;if($this->offline)throw new RuntimeException('offline');return $this->quote;}
 function minutes(string $symbol,int $from,int $to):array {$this->minuteCalls++;throw new RuntimeException('strategy candle unavailable');}
}
$api=new SharedPriceProvider();runCycle($api,$at+60,$s['id']);
sharedCheck($api->priceCalls===1&&$api->minuteCalls===0,'strategy and outcomes share one price request with no outcome candle request');
sharedCheck($get($market)['state']['tp1_result']==='WIN'&&$get($otherId)['state']['tp1_result']==='WIN','one market quote updates all matching strategies including ones not selected in this cycle');
sharedCheck($get($foreignId)['state']['tp1_result']===null,'same symbol on another exchange never receives the shared quote');
sharedCheck($get($limitId)['state']['status']==='PENDING_ENTRY','price below short target cannot count as win before limit entry');
sharedCheck($get($longId)['state']['tp1_result']==='LOSS','same quote correctly uses opposite stop direction for long');
StrategyOutcomes::observe('wallex','BTCUSDT',105,$at+120);
sharedCheck($get($limitId)['state']['entered_at']===$at+120,'observed limit price establishes entry in sequence');
StrategyOutcomes::observe('wallex','BTCUSDT',90,$at+180);
sharedCheck($get($limitId)['state']['tp1_result']==='WIN'&&$get($limitId)['state']['targets'][0]['evidence']['price']===90,'target reached after observed fill records exact shared quote evidence');
StrategyOutcomes::observe('wallex','BTCUSDT',111,$at+240);
sharedCheck($get($market)['state']['status']==='STOPPED'&&$get($market)['state']['tp1_result']==='WIN','stop after first target preserves first-target win');
$legacyId=$capture($s,$at+250);$legacy=$get($legacyId)['state'];unset($legacy['evaluation_mode']);$legacy['status']='AMBIGUOUS';$legacy['reason']='partial_creation_minute';$legacy['closed_at']=$at+300;
db()->prepare('UPDATE strategy_outcomes SET state=? WHERE alert_id=?')->execute([encode($legacy),$legacyId]);
StrategyOutcomes::observe('wallex','BTCUSDT',89,$at+360);
sharedCheck($get($legacyId)['state']['tp1_result']==='WIN'&&$get($legacyId)['last_check']===$at+360,'legacy partial-minute ambiguity resumes from observed strategy prices');
$pendingId=$capture($limit,$at+400);$pending=$get($pendingId)['state'];unset($pending['evaluation_mode']);$pending['status']='AMBIGUOUS';$pending['reason']='partial_creation_minute';
db()->prepare('UPDATE strategy_outcomes SET state=? WHERE alert_id=?')->execute([encode($pending),$pendingId]);
StrategyOutcomes::observe('wallex','BTCUSDT',89,$at+420);
sharedCheck($get($pendingId)['state']['status']==='PENDING_ENTRY'&&$get($pendingId)['state']['tp1_result']===null,'legacy ambiguous limit entry is not converted into an invented win');
$errorId=$capture($s,$at+500);$candleStrategy=$s;$candleStrategy['stages'][0]['transitions'][0]['event']='bar_close:15m';
db()->prepare('UPDATE strategies SET config=?,state=? WHERE id=?')->execute([encode($candleStrategy),encode(Strategy::fresh($candleStrategy,$at+500)),$s['id']]);
runCycle($api,$at+560,$s['id']);
sharedCheck($api->minuteCalls===1&&$get($errorId)['state']['tp1_result']==='WIN','outcome updates from successful ticker even when subsequent strategy candle request fails');
$before=$get($pendingId)['last_check'];$api->offline=true;runCycle($api,$at+620,$s['id']);
sharedCheck($get($pendingId)['last_check']===$before,'failed ticker cannot manufacture a new outcome observation');
StrategyOutcomes::observe('wallex','BTCUSDT',106,$at+200);
sharedCheck($get($pendingId)['state']['entered_at']===null,'out-of-order quote cannot backfill entry before alert creation');
$replayId=$capture($limit,$at+700);$replay=$get($replayId)['state'];unset($replay['evaluation_mode']);$replay['status']='AMBIGUOUS';$replay['reason']='partial_creation_minute';
db()->prepare('UPDATE strategy_outcomes SET state=? WHERE alert_id=?')->execute([encode($replay),$replayId]);
foreach([[$at+710,111,'nobitex','v1'],[$at+715,111,'wallex','old-version'],[$at+720,105,'wallex','v1'],[$at+730,89,'wallex','v1']] as [$time,$quote,$exchange,$version]){
 db()->prepare('INSERT INTO check_logs(strategy_id,version,checked,payload) VALUES(?,?,?,?)')->execute([$limit['id'],$version,$time,encode(['exchange'=>$exchange,'price'=>$quote])]);
}
StrategyOutcomes::observe('wallex','BTCUSDT',95,$at+740);
sharedCheck($get($replayId)['state']['entered_at']===$at+720&&$get($replayId)['state']['tp1_result']==='WIN','migration replays retained same-version same-exchange fill and target observations in time order');
$resolved=$get($replayId)['state'];unset($resolved['evaluation_mode']);
db()->prepare('UPDATE strategy_outcomes SET state=? WHERE alert_id=?')->execute([encode($resolved),$replayId]);
StrategyOutcomes::observe('wallex','BTCUSDT',111,$at+750);
sharedCheck($get($replayId)['state']['tp1_result']==='WIN'&&$get($replayId)['state']['status']==='STOPPED','changing evaluation mode cannot erase an already established first-target win');
$source=file_get_contents(dirname(__DIR__).'/src/StrategyOutcomes.php');
sharedCheck(!str_contains($source,'->minutes(')&&!str_contains($source,'->price(')&&!str_contains($source,'MarketData::provider('),'outcome evaluator has no independent market-data access');
