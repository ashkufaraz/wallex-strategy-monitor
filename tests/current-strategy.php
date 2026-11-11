<?php
// Uses a supplied strategy snapshot and an isolated in-memory database only.
define('TEST_DATABASE',':memory:');
require dirname(__DIR__).'/src/App.php';
if(!isset($argv[1],$argv[2]))exit("Usage: php tests/current-strategy.php config.json report.json\n");
$s=Strategy::validate(json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR));
function verifySimulation(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
class CurrentStrategyMarket extends Wallex {
 public bool $confirm=true;
 public float $current=74000.;
 function price(string $symbol):float{return $this->current;}
 function minutes(string $symbol,int $from,int $to):array {
  $out=[];
  for($t=$from;$t+60<=$to;$t+=60){
   $block=intdiv($t-144000,900);
   $out[$t]=$block===0?['open'=>74900.,'high'=>75000.,'low'=>74500.,'close'=>74900.,'volume'=>1.]:
    ($block===1?['open'=>74900.,'high'=>75000.,'low'=>74300.,'close'=>$this->confirm?74400.:74900.,'volume'=>1.]:
    ['open'=>74000.,'high'=>74100.,'low'=>73900.,'close'=>74000.,'volume'=>1.]);
  }
  return $out;
 }
}
$reset=function()use($s){db()->exec('DELETE FROM strategies; DELETE FROM alerts; DELETE FROM check_logs;');db()->prepare('INSERT INTO strategies VALUES(?,?,?,?)')->execute([$s['id'],'simulation',encode($s),encode(Strategy::fresh($s,144000))]);setSetting('interval','3600');};
$state=fn()=>json_decode(db()->query('SELECT state FROM strategies')->fetchColumn(),true);
$count=fn()=>(int)db()->query('SELECT COUNT(*) FROM alerts')->fetchColumn();
$api=new CurrentStrategyMarket();$reset();$steps=[];
runCycle($api,144060);$steps[]=['input'=>'price=74000','state'=>$state()];
verifySimulation($state()['stage']==='wait_retest'&&$count()===0,'74000 < 74850 arms retest without alert');
runCycle($api,144900);$steps[]=['input'=>'15m: O=74900 H=75000 L=74500 C=74900','state'=>$state()];
verifySimulation($state()['stage']==='wait_confirmation'&&$count()===0,'retest high and low pass; confirmation still required');
$api->current=74400.;runCycle($api,145800);$steps[]=['input'=>'15m: O=74900 H=75000 L=74300 C=74400','state'=>$state()];
verifySimulation($count()===1,'bearish close below 74850 records exactly one alert');
$alert=json_decode(db()->query('SELECT payload FROM alerts')->fetchColumn(),true);
verifySimulation($alert['action']==='SHORT'&&$alert['price']==74400,'alert direction and observed price correct');
verifySimulation($alert['trade']['entry']['price']==75000&&$alert['trade']['stop_loss']==75600&&$alert['trade']['take_profits']===[74000,73600],'entry stop and both targets copied to alert');
$report=json_decode(db()->query('SELECT payload FROM check_logs ORDER BY id DESC LIMIT 1')->fetchColumn(),true);
verifySimulation($report['status']==='signal'&&$report['trace'][1]['condition']['result']===true,'diagnostic log confirms signal and passing transition');
runCycle($api,145800);verifySimulation($count()===1,'repeating the same candle does not duplicate alert');
$success=['strategy_id'=>$s['id'],'steps'=>$steps,'alert'=>$alert,'diagnostics'=>$report];
$reset();$api=new CurrentStrategyMarket();$api->confirm=false;
runCycle($api,144060);runCycle($api,144900);runCycle($api,145800);
verifySimulation($state()['stage']==='wait_confirmation'&&$count()===0,'unfulfilled confirmation produces no alert');
$api->current=75600.;runCycle($api,145801);
verifySimulation($state()['stage']===$s['initial_stage']&&$count()===0,'invalidation blocks signal and resets setup');
file_put_contents($argv[2],encode($success));
echo "Simulation passed; production databases untouched.\n";
