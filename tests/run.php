<?php
require dirname(__DIR__).'/src/App.php';
function expect(bool $x,string $label):void {if(!$x)throw new RuntimeException('FAIL: '.$label);echo "PASS: $label\n";}
$s=Strategy::validate(json_decode(file_get_contents(dirname(__DIR__).'/example-btc-short.json'),true));
$st=Strategy::fresh($s,10000);
$bars=[7200=>['open'=>75100.,'close'=>75060.],10800=>['open'=>75060.,'close'=>75000.]];
expect(!Strategy::event($s,$st,'bar_close:1h',14400,['close'=>14400,'seconds'=>3600,'bars'=>$bars]),'break has no signal');expect($st['stage']==='wait_pullback'&&$st['setup']===14400,'break arms setup');
$ctx=['close'=>15300,'seconds'=>900,'bars'=>[14400=>['high'=>75100.,'low'=>74900.,'open'=>75080.,'close'=>75000.]]];
expect(!Strategy::event($s,$st,'bar_close:15m',15300,$ctx),'pullback cannot also confirm');expect($st['stage']==='wait_confirmation','pullback changes stage');
$ctx=['close'=>16200,'seconds'=>900,'bars'=>[15300=>['high'=>75080.,'low'=>74900.,'open'=>75000.,'close'=>74950.]]];
expect(Strategy::event($s,$st,'bar_close:15m',16200,$ctx),'next bearish candle signals');expect($st['stage']===$s['initial_stage']&&$st['setup']===null,'signal resets setup');
expect(Strategy::check($s['invalidation'],['price'=>75450.]),'invalidation inclusive');
$bad=$s;$bad['stages'][0]['transitions'][0]['when']['all'][0]['left']['candle']['offset']=0;
try{Strategy::validate($bad);expect(false,'offset rejected');}catch(InvalidArgumentException $e){expect(true,'offset zero rejected');}
$bad=$s;$bad['execute']='system';try{Strategy::validate($bad);expect(false,'unknown field rejected');}catch(InvalidArgumentException $e){expect(true,'unknown field rejected');}
try{Strategy::check(['left'=>['candle'=>['offset'=>2,'field'=>'close']],'op'=>'gt','right'=>1],$ctx);expect(false,'missing data');}catch(RuntimeException $e){expect(true,'missing candle blocks evaluation');}
$minutes=[];for($t=0;$t<900;$t+=60)$minutes[$t]=['open'=>10.,'high'=>15.,'low'=>8.,'close'=>12.,'volume'=>2.];$bars=Wallex::aggregate($minutes,900);expect(count($bars)===1&&$bars[0]['volume']===30.,'15 minute aggregation');unset($minutes[60]);expect(Wallex::aggregate($minutes,900)===[],'incomplete aggregate rejected');
echo "All engine tests passed.\n";
