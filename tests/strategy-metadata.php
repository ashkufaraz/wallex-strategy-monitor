<?php
define('TEST_DATABASE',':memory:');
require dirname(__DIR__).'/src/App.php';
function metadataCheck(bool $ok,string $message):void {if(!$ok)throw new RuntimeException('FAIL '.$message);echo 'PASS '.$message."\n";}
$s=json_decode(file_get_contents(dirname(__DIR__).'/example-btc-short.json'),true);
metadataCheck(!isset(Strategy::validate($s)['analysis_sources']),'legacy strategy stays compatible');
$s['analysis_sources']=['chatgpt','grok','deep','gemini'];
metadataCheck(Strategy::validate($s)['analysis_sources']===$s['analysis_sources'],'multiple analysis sources survive validation');
foreach([['chatgpt','chatgpt'],['unknown'],['ChatGPT'],'chatgpt',[123],['x'=>'grok']] as $bad){
 $invalid=$s;$invalid['analysis_sources']=$bad;$rejected=false;
 try{Strategy::validate($invalid);}catch(InvalidArgumentException $e){$rejected=true;}
 metadataCheck($rejected,'invalid source metadata rejected');
}
$leaf=['left'=>['price'=>true],'op'=>'gt','right'=>100];
$s['initial_stage']='start';$s['stages']=[['id'=>'start','transitions'=>[['event'=>'price','when'=>['all'=>[$leaf,['not'=>$leaf]]],'to'=>'end']]],['id'=>'end','transitions'=>[['event'=>'price','when'=>['any'=>[$leaf,$leaf]],'signal'=>true]]]];
$state=Strategy::fresh($s,100);
$p=Diagnostics::entryProgress($s,$state,'v1');
metadataCheck($p['total']===4&&$p['passed']===0&&$p['unknown']===4,'unobserved leaves are unknown and not reported as passing');
$state['stage']='end';$state['setup']=100;
$state['completed_rules']['start:1']=['condition'=>Diagnostics::inspect(['all'=>[$leaf,['not'=>$leaf]]],['price'=>120.])];
Diagnostics::save($s['id'],'v1',['status'=>'checked','checked'=>101,'waiting'=>[['stage'=>'end','rule'=>1,'condition'=>Diagnostics::inspect(['any'=>[$leaf,$leaf]],['price'=>120.])]]]);
$p=Diagnostics::entryProgress($s,$state,'v1');
metadataCheck($p['passed']===3&&$p['total']===4&&$p['unknown']===0,'completed stage and latest entry snapshot count nested leaves with negation');
$p=Diagnostics::entryProgress($s,Strategy::fresh($s,102),'v2');
metadataCheck($p['passed']===0&&$p['unknown']===4,'old version evidence is excluded');
Diagnostics::save($s['id'],'v1',['status'=>'error','checked'=>103]);
$p=Diagnostics::entryProgress($s,$state,'v1');
metadataCheck($p['passed']===1&&$p['unknown']===2,'market error preserves completed evidence while current conditions become unknown');
