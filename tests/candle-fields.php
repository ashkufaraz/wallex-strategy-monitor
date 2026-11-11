<?php
define('TEST_DATABASE', ':memory:');
require dirname(__DIR__).'/src/App.php';
$count=0;
function verifyField(bool $ok,string $label):void{global $count;if(!$ok)throw new RuntimeException($label);$count++;}
$close=1794311100;$start=$close-900;
$bar=['open'=>74000.,'high'=>74249.,'low'=>74000.,'close'=>74249.,'volume'=>1.];
$ctx=['close'=>$close,'seconds'=>900,'bars'=>[$start=>$bar],'price'=>123.];
$rule=fn($field,$op,$value)=>['left'=>['candle'=>['timeframe'=>'15m','offset'=>1,'field'=>$field]],'op'=>$op,'right'=>$value];
$low=$rule('low','lte',74600);$high=$rule('high','gte',74300);
foreach([[$low,74000.,true],[$high,74249.,false]] as [$condition,$observed,$pass]){
 verifyField(Strategy::value($condition['left'],$ctx)===$observed,'evaluator reads requested field');
 verifyField(Strategy::check($condition,$ctx)===$pass,'independent condition result');
 $d=Diagnostics::inspect($condition,$ctx);
 verifyField($d['left']===$observed&&$d['result']===$pass,'diagnostics retain per-field observed value');
 verifyField($d['left_candle']['ohlcv']===$bar&&$d['left_candle']['close_time']===$close,'exact evaluated OHLCV and close');
}
$s=['stages'=>[['id'=>'test','transitions'=>[['event'=>'bar_close:15m','when'=>['all'=>[$low,$high]],'signal'=>true]]]],'initial_stage'=>'test'];
$preview=Diagnostics::waiting($s,['stage'=>'test'],['15m'=>[$start=>$bar]],$close+60,123.);
verifyField($preview[0]['condition']===Diagnostics::inspect(['all'=>[$low,$high]],$ctx),'preview and evaluator identical at same close');
$state=['stage'=>'test','setup'=>null];$trace=null;
Strategy::$onCheck=function($kind,$event,$condition,$context)use(&$trace){$trace=Diagnostics::inspect($condition,$context);};
verifyField(!Strategy::event($s,$state,'bar_close:15m',$close,$ctx),'high failure prevents entry');
Strategy::$onCheck=null;
verifyField($trace===$preview[0]['condition'],'main engine trace and preview use identical candle');
$old=$low;$old['left']['candle']['offset']=2;
$ctx['bars'][$start-900]=$bar;
$snapshot=Diagnostics::inspect($old,$ctx)['left_candle'];
verifyField($snapshot['open_time']===$start-900&&$snapshot['close_time']===$start,'offset timestamp belongs to requested candle');
$missing=Diagnostics::inspect($high,['close'=>$close,'seconds'=>900,'bars'=>[]]);
verifyField($missing['result']===null&&!isset($missing['left_candle']),'missing candle never fabricates OHLC');
$raw=json_decode(file_get_contents(__DIR__.'/fixtures/high-low-closed-candle.json'),true)['response'];
$minutes=[];
foreach($raw['t'] as $i=>$t){$b=[];foreach(['o'=>'open','h'=>'high','l'=>'low','c'=>'close','v'=>'volume'] as $api=>$field)$b[$field]=(float)$raw[$api][$i];$minutes[$t]=$b;}
$actual=Wallex::aggregate($minutes,900)[$start];
verifyField(count(array_filter($minutes,fn($b)=>$b['volume']>0))===1,'real captured interval has exactly one traded minute');
foreach(['open','high','low','close'] as $field)verifyField($actual[$field]===74000.12,'captured evaluated '.$field);
verifyField($actual['volume']===0.00172526,'captured interval volume');
echo "Candle field regression: $count assertions passed.\n";
