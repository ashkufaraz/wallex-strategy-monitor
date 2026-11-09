<?php
define('TEST_DATABASE', ':memory:');
require dirname(__DIR__).'/src/App.php';
function diagnosticCheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
$leaf=['left'=>['price'=>true],'op'=>'gt','right'=>100];
$tree=Diagnostics::inspect(['all'=>[$leaf,['not'=>['left'=>2,'op'=>'eq','right'=>2]]]],['price'=>120.]);
diagnosticCheck($tree['result']===false&&$tree['children'][0]['left']===120.&&$tree['children'][0]['right']===100.,'nested conditions show values and true/false results');
$missing=['left'=>['candle'=>['timeframe'=>'4h','offset'=>1,'field'=>'close']],'op'=>'gt','right'=>100];
$unknown=Diagnostics::inspect($missing,['close'=>14400,'seconds'=>14400,'bars'=>[]]);
diagnosticCheck($unknown['result']===null&&isset($unknown['error']),'missing data is unknown rather than false');
$or=Diagnostics::inspect(['any'=>[['left'=>1,'op'=>'eq','right'=>1],$missing]],['close'=>14400,'seconds'=>14400,'bars'=>[]]);
diagnosticCheck($or['result']===true&&$or['children'][1]['result']===null,'diagnostic children do not alter short circuit semantics');
class DiagnosticWallex extends Wallex {
 public bool $fail=false;public float $p=74000.;public ?Closure $concurrent=null;
 function price(string $s):float{if($this->fail)throw new RuntimeException('offline');if($this->concurrent)($this->concurrent)();return $this->p;}
 function minutes(string $s,int $from,int $to):array{return [];}
}
$s=json_decode(file_get_contents(dirname(__DIR__).'/example-btc-short.json'),true);unset($s['cancellation']);
$s['stages']=[['id'=>'start','transitions'=>[['event'=>'price','when'=>$leaf,'signal'=>true]]]];$s['initial_stage']='start';Strategy::validate($s);
$put=function($config,$now){db()->prepare('INSERT OR REPLACE INTO strategies VALUES(?,?,?,?)')->execute([$config['id'],'v1',encode($config),encode(Strategy::fresh($config,$now))]);};
$latest=fn()=>json_decode(db()->query('SELECT payload FROM check_logs ORDER BY id DESC LIMIT 1')->fetchColumn(),true);
$api=new DiagnosticWallex();$put($s,1000);runCycle($api,1001);$r=$latest();
diagnosticCheck($r['status']==='signal'&&$r['price']===74000&&$r['checked']===1001,'successful cycle persisted with price/time/status');
diagnosticCheck($r['trace'][1]['kind']==='transition'&&$r['trace'][1]['condition']['result']===true,'executed transition traced');
diagnosticCheck($r['trade']===$s['trade']&&$r['action']==='SHORT','trade plan and direction snapshotted');
diagnosticCheck($r['waiting'][0]['condition']['result']===true,'waiting previews separate from actual checks');
$st=json_decode(db()->query('SELECT state FROM strategies')->fetchColumn(),true);
diagnosticCheck($st['last_price']===74000&&$st['price_at']===1001,'state retains observed price timestamp');
for($i=1002;$i<=1035;$i++)runCycle($api,$i);
diagnosticCheck((int)db()->query('SELECT COUNT(*) FROM check_logs')->fetchColumn()===30,'check history bounded at 30 per strategy');
$api->fail=true;runCycle($api,1036);$r=$latest();
diagnosticCheck($r['status']==='error'&&$r['price']===null&&$r['error']==='offline','failed fetch logged without stale price');
diagnosticCheck(Strategy::$onCheck===null,'trace observer cleared after error');
$api->fail=false;$api->concurrent=function(){db()->exec("UPDATE strategies SET version='v2'");};$count=(int)db()->query('SELECT COUNT(*) FROM check_logs')->fetchColumn();runCycle($api,1037);
diagnosticCheck((int)db()->query('SELECT COUNT(*) FROM check_logs')->fetchColumn()===$count,'concurrent edit discards uncommitted diagnostics');
$s['enabled']=false;$put($s,1040);$before=$count;runCycle($api,1041);
diagnosticCheck((int)db()->query('SELECT COUNT(*) FROM check_logs')->fetchColumn()===$before,'disabled strategy creates no misleading check log');
$s['enabled']=true;$put($s,1042);db()->exec("UPDATE strategies SET state=json_set(state,'$.cancelled_at',1042)");runCycle($api,1043);
diagnosticCheck((int)db()->query('SELECT COUNT(*) FROM check_logs')->fetchColumn()===$before,'cancelled strategy creates no new check log');
$s['stages'][0]['transitions'][0]=['event'=>'bar_close:4h','when'=>$missing,'signal'=>true];
$preview=Diagnostics::waiting($s,['stage'=>'start'],['4h'=>[0=>['close'=>120.]]],15000,74000.);
diagnosticCheck($preview[0]['candle_close']===14400&&$preview[0]['condition']['result']===true,'waiting 4h preview uses latest closed UTC boundary');
