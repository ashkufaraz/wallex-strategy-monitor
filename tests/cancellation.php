<?php
define('TEST_DATABASE', ':memory:');
require dirname(__DIR__).'/src/App.php';
function cancellationCheck(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
class CancellationWallex extends Wallex {
 public float $p=74000.;public int $calls=0;public bool $fail=false;
 function price(string $s):float {$this->calls++;if($this->fail)throw new RuntimeException('offline');return $this->p;}
 function minutes(string $s,int $from,int $to):array {$bars=[];for($t=max(0,$from);$t+60<=$to;$t+=60)$bars[$t]=['open'=>75000.,'high'=>76000.,'low'=>73000.,'close'=>74000.,'volume'=>1.];return $bars;}
}
$base=json_decode(file_get_contents(dirname(__DIR__).'/example-btc-short.json'),true);
$s=$base;$s['cancellation']=[['event'=>'price','when'=>['left'=>['price'=>true],'op'=>'lt','right'=>74500],'reason'=>'Price cancellation']];
cancellationCheck(Strategy::validate($s)===$s,'price cancellation valid');
foreach(['empty','blank reason','unknown field','wrong source'] as $case){$bad=$s;switch($case){case 'empty':$bad['cancellation']=[];break;case 'blank reason':$bad['cancellation'][0]['reason']=' ';break;case 'unknown field':$bad['cancellation'][0]['foo']=true;break;case 'wrong source':$bad['cancellation'][0]['event']='bar_close:1h';break;}$rejected=false;try{Strategy::validate($bad);}catch(InvalidArgumentException $e){$rejected=true;}cancellationCheck($rejected,$case.' rejected');}
$insert=function($config,$state){db()->prepare('INSERT OR REPLACE INTO strategies VALUES(?,?,?,?)')->execute([$config['id'],'v1',encode($config),encode($state)]);};
$state=function()use($s){$q=db()->prepare('SELECT state FROM strategies WHERE id=?');$q->execute([$s['id']]);return json_decode($q->fetchColumn(),true);};
$api=new CancellationWallex();$insert($s,Strategy::fresh($s,100));runCycle($api,101);
cancellationCheck($state()['cancelled_at']===101&&$state()['setup']===null,'cancellation applies in initial stage');
$payload=json_decode(db()->query('SELECT payload FROM alerts')->fetchColumn(),true);
cancellationCheck($payload['type']==='cancelled'&&$payload['reason']==='Price cancellation'&&!isset($payload['trade']),'cancellation alert stores reason without entry instructions');
$calls=$api->calls;$before=$state();runCycle($api,5000);
cancellationCheck($api->calls===$calls&&$state()===$before,'cancelled strategy remains stopped after a gap');
cancellationCheck((int)db()->query('SELECT COUNT(*) FROM alerts')->fetchColumn()===1,'no duplicate cancellation alert');
initializeWorkerStates(5000);cancellationCheck($state()===$before,'worker restart preserves cancellation');
$other=$base;$other['id']='other';$insert($other,Strategy::fresh($other,5000));runCycle($api,5001);
cancellationCheck($api->calls===$calls+1,'other strategies continue');
$insert($s,Strategy::fresh($s,5000));$api->fail=true;runCycle($api,5001);cancellationCheck(!isset($state()['cancelled_at'])&&$state()['error']==='offline','network error is not cancellation');
$api->fail=false;runCycle($api,5002);cancellationCheck(isset($state()['cancelled_at']),'price cancellation checked on recovery');
$candle=$s;$candle['cancellation']=[['event'=>'bar_close:15m','when'=>['left'=>['candle'=>['timeframe'=>'15m','offset'=>1,'field'=>'close']],'op'=>'lt','right'=>74500],'reason'=>'Candle cancellation']];
Strategy::validate($candle);$st=Strategy::fresh($candle,7190);$st['stage']='wait_confirmation';$st['setup']=7000;$insert($candle,$st);runCycle($api,7205);
cancellationCheck($state()['cancelled_at']===7200&&$state()['cancel_reason']==='Candle cancellation','candle cancellation wins over simultaneous entry confirmation');
$api->p=76000.;$insert($candle,Strategy::fresh($candle,7190));runCycle($api,7205);
cancellationCheck(isset($state()['cancelled_at']),'candle cancellation checked even when setup invalidation holds');
$api->p=75000.;$insert($s,Strategy::fresh($s,8000));runCycle($api,8001);
cancellationCheck(!isset($state()['cancelled_at']),'fresh explicit restart can run when cancellation no longer holds');
