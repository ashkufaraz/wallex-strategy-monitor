<?php
define('TEST_DATABASE', ':memory:');
require dirname(__DIR__).'/src/App.php';
function ok(bool $b,string $m):void{if(!$b)throw new RuntimeException('FAIL '.$m);echo "PASS $m\n";}
class FakeWallex extends Wallex {
 public float $p=74000.; public bool $fail=false; public bool $missing=false;
 function price(string $s):float {if($this->fail)throw new RuntimeException('offline');return $this->p;}
 function minutes(string $s,int $from,int $to):array {$out=[];for($t=max(0,$from);$t+60<=$to;$t+=60){$close=$t<3600?76000.:74000.;$out[$t]=['open'=>75000.,'close'=>$close,'high'=>max(75100.,$close),'low'=>min(74000.,$close),'volume'=>1.];}if($this->missing)unset($out[7140]);return $out;}
}
$s=Strategy::validate(json_decode(file_get_contents(dirname(__DIR__).'/example-btc-short.json'),true));setSetting('interval','3600');
$insert=function(array $state)use($s){db()->prepare('INSERT OR REPLACE INTO strategies VALUES(?,?,?,?)')->execute([$s['id'],'v1',encode($s),encode($state)]);};
$state=function(){return json_decode(db()->query('SELECT state FROM strategies')->fetchColumn(),true);};
$api=new FakeWallex();$insert(Strategy::fresh($s,7190));runCycle($api,7205);ok($state()['stage']==='wait_confirmation','same-time 1h before 15m, one transition each');runCycle($api,8105);ok((int)db()->query('SELECT COUNT(*) FROM alerts')->fetchColumn()===1,'signal recorded atomically');runCycle($api,8105);ok((int)db()->query('SELECT COUNT(*) FROM alerts')->fetchColumn()===1,'repeated cycle no duplicate');
$payload=json_decode(db()->query('SELECT payload FROM alerts LIMIT 1')->fetchColumn(),true);ok($payload['trade']['take_profits']===$s['trade']['take_profits']&&$payload['trade']['entry']['reference_price']===75000,'confirmed strategy persists trade details end to end');
$st=Strategy::fresh($s,8105);$st['stage']='wait_confirmation';$st['setup']=7200;$insert($st);$api->p=75450.;runCycle($api,9005);ok($state()['stage']==='wait_break','price invalidation precedes candle confirmation');ok((int)db()->query('SELECT COUNT(*) FROM alerts')->fetchColumn()===1,'invalidated setup no signal');
$api->p=74000.;$st=Strategy::fresh($s,9005);$st['stage']='wait_confirmation';$st['setup']=8000;$insert($st);$api->fail=true;runCycle($api,9065);ok($state()['error']==='offline','network failure visible');$api->fail=false;runCycle($api,9125);ok($state()['stage']==='wait_break'&&$state()['cursor']===9125,'recovery resets and skips downtime');
$st=Strategy::fresh($s,179990);$st['stage']='wait_confirmation';$st['setup']=1;$insert($st);runCycle($api,180005);ok($state()['stage']==='wait_break','expired setup does not rearm same cycle');
$st=Strategy::fresh($s,9005);$st['stage']='wait_confirmation';$st['setup']=8000;$insert($st);runCycle($api,30000);ok($state()['stage']==='wait_break'&&$state()['cursor']===30000,'long gap skips historical events');
$api->p=75450.;$insert(Strategy::fresh($s,7190));runCycle($api,7205);ok($state()['stage']==='wait_break','new setup invalidated within the same cycle');ok((int)db()->query('SELECT COUNT(*) FROM alerts')->fetchColumn()===1,'new invalid setup cannot emit an alert');
$api->p=74000.;$api->missing=true;$before=Strategy::fresh($s,7190);$insert($before);runCycle($api,7205);ok($state()['cursor']===7190&&$state()['stage']==='wait_break'&&$state()['error']!==null,'incomplete candles preserve cursor and stage');ok((int)db()->query('SELECT COUNT(*) FROM alerts')->fetchColumn()===1,'failed cycle records no partial alerts');
$api->missing=false;runCycle($api,7265);ok($state()['cursor']===7265&&$state()['error']===null,'recovery skips missed candles');
$disabled=$s;$disabled['enabled']=false;$before=$state();db()->prepare('UPDATE strategies SET config=?')->execute([encode($disabled)]);$api->fail=true;runCycle($api,7325);ok($state()===$before,'disabled strategy neither evaluated nor modified');
echo "All integration tests passed.\n";

