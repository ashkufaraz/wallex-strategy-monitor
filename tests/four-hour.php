<?php
define('TEST_DATABASE', ':memory:');
require dirname(__DIR__).'/src/App.php';
function fourCheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
$s=json_decode(file_get_contents(dirname(__DIR__).'/example-btc-short.json'),true);
$condition=fn($tf)=>['left'=>['candle'=>['timeframe'=>$tf,'offset'=>1,'field'=>'close']],'op'=>'gt','right'=>1];
$s['stages']=[['id'=>'start','transitions'=>[['event'=>'bar_close:4h','when'=>$condition('4h'),'to'=>'hour']]],['id'=>'hour','transitions'=>[['event'=>'bar_close:1h','when'=>$condition('1h'),'to'=>'quarter']]],['id'=>'quarter','transitions'=>[['event'=>'bar_close:15m','when'=>$condition('15m'),'signal'=>true]]]];
$s['initial_stage']='start';unset($s['cancellation']);
fourCheck(Strategy::validate($s)===$s,'4h transitions accepted');
$bad=$s;$bad['stages'][0]['transitions'][0]['when']=$condition('1h');$rejected=false;try{Strategy::validate($bad);}catch(InvalidArgumentException $e){$rejected=true;}fourCheck($rejected,'cross-timeframe source rejected');
$offset=$condition('4h');$offset['left']['candle']['offset']=100;
fourCheck(lookback($offset)===1440000,'4h offset 100 lookback uses correct duration');
$minutes=[];for($t=0;$t<14400;$t+=60)$minutes[$t]=['open'=>10.,'high'=>15.,'low'=>8.,'close'=>12.,'volume'=>2.];
$minutes[0]['open']=9.;$minutes[14340]['close']=13.;
$bars=Wallex::aggregate($minutes,14400);
fourCheck(count($bars)===1&&$bars[0]===['open'=>9.,'close'=>13.,'high'=>15.,'low'=>8.,'volume'=>480.],'4h OHLCV aggregated from 240 minute candles');
unset($minutes[60]);fourCheck(Wallex::aggregate($minutes,14400)===[],'incomplete 4h candle rejected');
class FourHourWallex extends Wallex {
 public bool $missing=false;public array $ranges=[];
 function price(string $s):float{return 74000.;}
 function minutes(string $s,int $from,int $to):array{$this->ranges[]=[$from,$to];$out=[];for($t=$from;$t+60<=$to;$t+=60)$out[$t]=['open'=>10.,'high'=>15.,'low'=>8.,'close'=>12.,'volume'=>2.];if($this->missing)unset($out[60]);return $out;}
}
$insert=function($config,$now){db()->exec('DELETE FROM alerts');db()->prepare('INSERT OR REPLACE INTO strategies VALUES(?,?,?,?)')->execute([$config['id'],'v1',encode($config),encode(Strategy::fresh($config,$now))]);};
$state=fn()=>json_decode(db()->query('SELECT state FROM strategies')->fetchColumn(),true);
$api=new FourHourWallex();$insert($s,14390);runCycle($api,14405);
fourCheck((int)db()->query('SELECT COUNT(*) FROM alerts')->fetchColumn()===1,'simultaneous close order is 4h then 1h then 15m');
runCycle($api,14405);fourCheck((int)db()->query('SELECT COUNT(*) FROM alerts')->fetchColumn()===1,'4h boundary not reprocessed');
$insert($s,14390);$api->missing=true;runCycle($api,14405);
fourCheck($state()['cursor']===14390&&$state()['error']!==null,'missing 4h minute preserves state and blocks signals');
$api->missing=false;
$s['cancellation']=[['event'=>'bar_close:4h','when'=>$condition('4h'),'reason'=>'Four hour cancellation']];Strategy::validate($s);$insert($s,14390);runCycle($api,14405);
fourCheck(($state()['cancelled_at']??null)===14400,'4h cancellation runs before entry');
$p=json_decode(db()->query('SELECT payload FROM alerts')->fetchColumn(),true);fourCheck($p['type']==='cancelled'&&$p['reason']==='Four hour cancellation','4h cancellation alert recorded');
$s['stages'][0]['transitions'][0]['when']['left']['candle']['offset']=2;unset($s['cancellation']);$insert($s,28790);runCycle($api,28805);$range=end($api->ranges);
fourCheck($range[0]===0&&(int)db()->query('SELECT COUNT(*) FROM alerts')->fetchColumn()===1,'4h offset two loads aligned previous candles');
$legacy=json_decode(file_get_contents(dirname(__DIR__).'/example-btc-short.json'),true);
fourCheck(!isset(strategyTimeframes($legacy)['4h']),'legacy strategies do not require 4h candles');
