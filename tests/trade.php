<?php
define('TEST_DATABASE', ':memory:');
require dirname(__DIR__).'/src/App.php';
function checkTrade(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
$sample=json_decode(file_get_contents(dirname(__DIR__).'/example-btc-short.json'),true);
checkTrade(Strategy::validate($sample)===$sample,'limit short accepted');
$legacy=$sample;unset($legacy['trade']);checkTrade(Strategy::validate($legacy)===$legacy,'legacy strategy accepted');
$long=$sample;$long['action']='LONG';$long['trade']['stop_loss']=74000;$long['trade']['take_profits']=[75500,76000];checkTrade(Strategy::validate($long)===$long,'limit long accepted');
$market=$sample;$market['trade']['entry']=['type'=>'MARKET'];checkTrade(Strategy::validate($market)===$market,'market accepted without fixed entry');
$cases=[
 'missing limit price'=>fn(&$s)=>$s['trade']['entry']=['type'=>'LIMIT'],
 'market fixed price'=>fn(&$s)=>$s['trade']['entry']['type']='MARKET',
 'negative price'=>fn(&$s)=>$s['trade']['entry']['price']=-1,
 'string price'=>fn(&$s)=>$s['trade']['stop_loss']='75450',
 'wrong stop side'=>fn(&$s)=>$s['trade']['stop_loss']=74900,
 'wrong target side'=>fn(&$s)=>$s['trade']['take_profits']=[75100],
 'unordered targets'=>fn(&$s)=>$s['trade']['take_profits']=[74000,74500],
 'empty targets'=>fn(&$s)=>$s['trade']['take_profits']=[],
 'zero quantity'=>fn(&$s)=>$s['trade']['quantity']=0,
 'fractional leverage'=>fn(&$s)=>$s['trade']['leverage']=1.5,
 'unknown field'=>fn(&$s)=>$s['trade']['execute']=true,
 'nonfinite price'=>fn(&$s)=>$s['trade']['stop_loss']=INF,
 'null trade'=>fn(&$s)=>$s['trade']=null
];
foreach($cases as $label=>$change){$s=$sample;$change($s);$rejected=false;try{Strategy::validate($s);}catch(InvalidArgumentException $e){$rejected=true;}checkTrade($rejected,$label.' rejected');}
recordAlert($sample,'v1','price',100,74950);
$saved=json_decode(db()->query('SELECT payload FROM alerts WHERE id=1')->fetchColumn(),true);
checkTrade($saved['trade']['entry']['reference_price']===75000&&$saved['price']===74950,'fixed entry distinct from observed price');
checkTrade($saved['trade']['stop_loss']===$sample['trade']['stop_loss']&&$saved['trade']['take_profits']===$sample['trade']['take_profits']&&$saved['action']==='SHORT','full trade snapshot saved');
$sample['trade']['stop_loss']=76000;
checkTrade(json_decode(db()->query('SELECT payload FROM alerts WHERE id=1')->fetchColumn(),true)===$saved,'stored history immutable after config changes');
recordAlert($market,'v2','price',200,74950);
$savedMarket=json_decode(db()->query('SELECT payload FROM alerts WHERE id=2')->fetchColumn(),true);
checkTrade($savedMarket['trade']['entry']['reference_price']===74950,'market entry snapshots observed price');
recordAlert($legacy,'v3','price',300,74950);
checkTrade(!isset(json_decode(db()->query('SELECT payload FROM alerts WHERE id=3')->fetchColumn(),true)['trade']),'legacy alerts remain supported');
