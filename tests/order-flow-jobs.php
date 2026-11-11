<?php
if(PHP_SAPI!=='cli')exit;
define('TEST_DATABASE',':memory:');require dirname(__DIR__).'/src/App.php';
$n=0;function jobCheck(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";$n++;}
$end=1800000000;
function jobCase(string $mode,int $end):array {
 $db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->exec('CREATE TABLE alerts(unique_key TEXT UNIQUE,created INTEGER,payload TEXT)');ExecutionGate::migrate($db);OrderFlowAdvisory::migrate($db);
 $c=OrderFlowConfig::validate(['enabled'=>true,'baseline_windows'=>2,'max_pages'=>$mode==='cap'?1:12]);
 $signal=['id'=>'signal-'.$mode,'strategy_id'=>'s-'.$mode,'version'=>'v1','symbol'=>'BTCUSDT','action'=>$mode==='contradictory'?'SHORT':'LONG','signal_time'=>$end+2];
 $db->prepare('INSERT INTO execution_signals VALUES(?,?,?,?,?,?,?)')->execute([$signal['id'],$signal['strategy_id'],'v1','WAITING_BASIS',$end+2,$end+902,encode($signal+['status'=>'WAITING_BASIS'])]);
 OrderFlowAdvisory::queue($db,$signal,'SIGNAL',$end+2,$c,$end+2);
 $rows=[];$volume=[];for($i=0;$i<1001;$i++){$t=($end-300)*1000+$i*299;$rows[]=['a'=>$i+1000,'p'=>'100','q'=>'1','f'=>$i+1000,'l'=>$i+1000,'T'=>$t,'m'=>$i%5===0];$minute=intdiv($t,60000)*60;$volume[$minute]=($volume[$minute]??0)+1;}
 $klines=[];for($t=$end-900;$t<$end;$t+=60)$klines[]=[$t*1000,'100','101','99','100',(string)($t>=$end-300?($volume[$t]??0):200.2),($t+60)*1000-1,'0',1,'0','0','0'];
 $clock=$end+($mode==='late-book'?20:2);$calls=[];
 $api=new OrderFlowMarketData(function($url)use(&$clock,&$calls,$rows,$klines,$mode){$calls[]=$url;$path=parse_url($url,PHP_URL_PATH);parse_str(parse_url($url,PHP_URL_QUERY)??'',$params);
  if($mode==='rate-limit')return ['status'=>429,'headers'=>['retry-after'=>'120'],'body'=>'{}'];
  $data=match($path){'/api/v3/time'=>['serverTime'=>$clock*1000],'/api/v3/depth'=>['lastUpdateId'=>42,'bids'=>[['99.99','8'],['99.9','1']],'asks'=>[['100.01','2'],['100.1','1']]],'/api/v3/klines'=>$klines,'/api/v3/aggTrades'=>array_slice($rows,isset($params['fromId'])?(int)$params['fromId']-1000:0,1000)};
  if($mode==='settled-later'&&$path==='/api/v3/time')$data['serverTime']-=4000;
  if($path==='/api/v3/aggTrades'&&isset($params['fromId'])){
   if($mode==='gap')$data[0]['a']++;
   if($mode==='duplicate')$data[0]=$rows[999];
  }
  if($mode==='delayed'&&$path==='/api/v3/aggTrades')$clock+=100;
  return ['status'=>200,'headers'=>[],'body'=>encode($data)];
 },function()use(&$clock){return $clock;});
 for($i=0;$i<8;$i++){$at=$clock;OrderFlowAdvisory::step($db,$c,$at,$api);$clock+=3;if($db->query('SELECT COUNT(*) FROM order_flow_results')->fetchColumn())break;}
 $result=json_decode($db->query('SELECT payload FROM order_flow_results')->fetchColumn(),true);
 return [$result,$calls,$db,$api,$c,$clock];
}
[$ok,$calls,$db]=jobCase('complete',$end);
jobCheck($ok['advisory_status']==='SUPPORTIVE'&&$ok['trade_count']===1001,'1001 trades paginate to a complete consistent five-minute interval');
$pages=array_values(array_filter($calls,fn($u)=>str_contains($u,'aggTrades')));
jobCheck(count($pages)===2&&str_contains($pages[1],'fromId=2000')&&!str_contains($pages[1],'startTime'),'continuation uses inclusive fromId last ID plus one and no ambiguous time combination');
[$settled,$settleCalls]=jobCase('settled-later',$end);
jobCheck($settled['advisory_status']==='SUPPORTIVE'&&count(array_filter($settleCalls,fn($u)=>str_contains($u,'/time')))===3,'just-closed window gets at most two settling retries without blocking primary processing');
foreach(['gap','duplicate','cap','delayed','late-book'] as $mode){[$r]=jobCase($mode,$end);jobCheck($r['advisory_status']==='UNAVAILABLE','job fails closed: '.$mode);}
[$contradictory,$ignore,$contradictoryDb]=jobCase('contradictory',$end);
jobCheck($contradictory['advisory_status']==='CONTRADICTORY'&&$contradictoryDb->query('SELECT status FROM execution_signals')->fetchColumn()==='WAITING_BASIS','persisted contradictory analysis never cancels or changes waiting signal');
[$limited,$calls,$db,$api,$c,$clock]=jobCase('rate-limit',$end);$count=count($calls);
jobCheck($limited['advisory_status']==='UNAVAILABLE','rate-limited auxiliary request never returns a positive/negative result');
OrderFlowAdvisory::step($db,$c,$clock,$api);
jobCheck(count($calls)===$count,'Retry-After backoff survives between worker invocations');
$next=(int)$db->query("SELECT v FROM order_flow_meta WHERE k='next_request'")->fetchColumn();jobCheck($next===$end+122,'official Retry-After seconds honored');
$signal=['id'=>'eth-signal','strategy_id'=>'eth-strategy','version'=>'eth-v','symbol'=>'ETHUSDT','action'=>'SHORT','signal_time'=>$end+2];OrderFlowAdvisory::queue($db,$signal,'SIGNAL',$end+2,$c,$clock);
jobCheck(OrderFlowAdvisory::details($db,'eth-strategy','eth-v',$c)['results']===[],'BTC results are never returned for ETH strategy');
jobCheck(OrderFlowAdvisory::details($db,'s-rate-limit','v1',$c)['results'][0]['signal_id']==='signal-rate-limit','signal-specific result association survives failure');
// Deliberately simulate advisory outputs alongside already-created main states.
// This module has no write path to either execution_signals or alerts.
foreach(['SIGNAL_CREATED','WAITING_BASIS','READY_FOR_MANUAL_ENTRY','MANUAL_REVIEW_AVAILABLE'] as $status){
 $payload=encode(['id'=>'main-'.$status,'status'=>$status]);$db->prepare('INSERT INTO execution_signals VALUES(?,?,?,?,?,?,?)')->execute(['main-'.$status,'s-'.$status,'v1',$status,$end,$end+900,$payload]);
 $before=$db->query('SELECT * FROM execution_signals')->fetchAll(PDO::FETCH_ASSOC);OrderFlowAdvisory::step($db,$c,$clock,$api);jobCheck($db->query('SELECT * FROM execution_signals')->fetchAll(PDO::FETCH_ASSOC)===$before,'advisory cannot alter main '.$status);
}
jobCheck((int)$db->query('SELECT COUNT(*) FROM alerts')->fetchColumn()===0,'auxiliary jobs produce no alerts or orders');
echo "Order Flow bounded jobs: $n assertions passed.\n";
