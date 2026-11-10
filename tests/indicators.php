<?php
define('TEST_DATABASE',':memory:');
require dirname(__DIR__).'/src/App.php';
function checkIndicator(bool $ok,string $name):void{if(!$ok)throw new RuntimeException('FAIL '.$name);echo "PASS $name\n";}
function near(float $a,float $b,string $name):void{checkIndicator(abs($a-$b)<1e-8,$name);}
function bars(array $closes,array $volumes=[]):array{return array_map(fn($c,$i)=>['open'=>(float)$c,'high'=>$c+1.,'low'=>$c-1.,'close'=>(float)$c,'volume'=>(float)($volumes[$i]??1)],$closes,array_keys($closes));}
near(Indicators::value('EMA',3,'value',bars([1,2,3,4,5])),4,'EMA seeded by SMA, alpha=0.5');
near(Indicators::value('EMA',3,'close_minus_ema_pct',bars([1,2,3,4,5])),25,'close minus EMA percent');
$wilder=[44.34,44.09,44.15,43.61,44.33,44.83,45.10,45.42,45.84,46.08,45.89,46.03,45.61,46.28,46.28];
near(Indicators::value('RSI',14,'value',bars($wilder)),70.464135021097,'RSI Wilder published seed example');
near(Indicators::value('RSI',3,'value',bars([10,11,12,13,14])),100,'RSI no losses');
near(Indicators::value('RSI',3,'value',bars([14,13,12,11,10])),0,'RSI no gains');
near(Indicators::value('RSI',3,'value',bars([10,10,10,10,10])),50,'RSI flat market defined as 50');
$atr=bars([10,11,13,14,18]);$atr[3]['high']=16.;$atr[3]['low']=12.;
near(Indicators::value('ATR',3,'value',$atr),11/3,'ATR true ranges 2,3,4,5 with Wilder recurrence');
near(Indicators::value('ATR',3,'atr_pct',$atr),100*(11/3)/18,'ATR percent uses last close');
$vol=bars([10,10,10,10],[10,20,30,120]);
near(Indicators::value('VOLUME',3,'value',$vol),120,'last closed candle volume');
near(Indicators::value('VOLUME',3,'average_previous',$vol),20,'reference volume excludes evaluated candle');
near(Indicators::value('VOLUME',3,'ratio_to_previous_avg',$vol),6,'volume ratio uses previous N candles');
$failed=false;try{Indicators::value('VOLUME',3,'ratio_to_previous_avg',bars([10,10,10,10],[0,0,0,1]));}catch(RuntimeException){$failed=true;}checkIndicator($failed,'zero volume reference unavailable');
$s=json_decode(file_get_contents(dirname(__DIR__).'/example-btc-short.json'),true);
$condition=['id'=>'rsi','indicator'=>'RSI','timeframe'=>'15m','period'=>14,'candle'=>'last_closed','metric'=>'value','operator'=>'between','value'=>[50,68]];
$block=['enabled'=>true,'logic'=>'all','conditions'=>[$condition]];
checkIndicator(Strategy::validate($s)===$s,'legacy schema unchanged');
$s['indicator_conditions']=['enabled'=>false];checkIndicator(!Indicators::enabled(Strategy::validate($s)),'disabled minimal block accepted');
$s['indicator_conditions']=$block;checkIndicator(Indicators::enabled(Strategy::validate($s)),'valid indicator block accepted');
foreach(['period zero','period negative','period too large','period float','RSI below zero','RSI above 100','wrong timeframe','open candle','wrong metric','unknown indicator','reversed range','range scalar','NaN','infinity','string threshold','logic any','unknown field','duplicate id','too many','disabled invalid'] as $case){
 $b=$block;
 switch($case){
  case 'period zero':$b['conditions'][0]['period']=0;break;
  case 'period negative':$b['conditions'][0]['period']=-1;break;
  case 'period too large':$b['conditions'][0]['period']=201;break;
  case 'period float':$b['conditions'][0]['period']=14.;break;
  case 'RSI below zero':$b['conditions'][0]['value']=[-1,50];break;
  case 'RSI above 100':$b['conditions'][0]['value']=[50,101];break;
  case 'wrong timeframe':$b['conditions'][0]['timeframe']='5m';break;
  case 'open candle':$b['conditions'][0]['candle']='current';break;
  case 'wrong metric':$b['conditions'][0]['metric']='atr_pct';break;
  case 'unknown indicator':$b['conditions'][0]['indicator']='eval()';break;
  case 'reversed range':$b['conditions'][0]['value']=[68,50];break;
  case 'range scalar':$b['conditions'][0]['value']=50;break;
  case 'NaN':$b['conditions'][0]['value']=[NAN,50];break;
  case 'infinity':$b['conditions'][0]['value']=[50,INF];break;
  case 'string threshold':$b['conditions'][0]['value']=['50',68];break;
  case 'logic any':$b['logic']='any';break;
  case 'unknown field':$b['conditions'][0]['script']='alert()';break;
  case 'duplicate id':$b['conditions'][]=$condition;break;
  case 'too many':$b['conditions']=array_fill(0,31,$condition);break;
  case 'disabled invalid':$b['enabled']=false;$b['conditions'][0]['period']=0;break;
 }
 $s['indicator_conditions']=$b;$failed=false;try{Strategy::validate($s);}catch(InvalidArgumentException){$failed=true;}checkIndicator($failed,$case.' rejected at validation');
}
foreach(['gt','gte','lt','lte','eq','neq','between'] as $op){$c=$condition;$c['operator']=$op;$c['value']=$op==='between'?[50,68]:60;checkIndicator(is_bool(Indicators::passes(60.,$c)),'operator '.$op);}
checkIndicator(Indicators::passes(50.,$condition)&&Indicators::passes(68.,$condition),'between includes both boundaries');
$raw=['s'=>'ok','t'=>[0,60,120],'o'=>['10','11','12'],'h'=>[15,15,15],'l'=>[8,8,8],'c'=>[11,12,13],'v'=>[1,2,3]];
$parsed=IndicatorData::parse($raw,'BTCUSDT',60,0,120);checkIndicator(count($parsed)===2&&!isset($parsed[120])&&$parsed[60]['duration']===60,'open candle excluded, numeric strings accepted');
foreach(['duplicate','unordered','gap','NaN','negative volume','bad OHLC','fractional timestamp','unequal arrays','wrong market','wrong resolution'] as $case){
 $d=$raw;
 switch($case){
  case 'duplicate':$d['t']=[0,0,60];break;
  case 'unordered':$d['t']=[60,0,120];break;
  case 'gap':$d['t']=[0,60,180];break;
  case 'NaN':$d['c'][0]=NAN;break;
  case 'negative volume':$d['v'][0]=-1;break;
  case 'bad OHLC':$d['l'][0]=20;break;
  case 'fractional timestamp':$d['t'][0]=0.5;break;
  case 'unequal arrays':$d['v']=[];break;
  case 'wrong market':$d['symbol']='ETHUSDT';break;
  case 'wrong resolution':$d['t']=[0,3600,7200];break;
 }
 $failed=false;try{IndicatorData::parse($d,'BTCUSDT',60,0,10000);}catch(RuntimeException){$failed=true;}checkIndicator($failed,$case.' data rejected');
}
$minute=[];for($t=0;$t<900;$t+=60)$minute[$t]=['open'=>10+$t/60,'close'=>11+$t/60,'high'=>12+$t/60,'low'=>9+$t/60,'volume'=>2];
$m=IndicatorData::aggregate($minute,60,900)[0];checkIndicator($m['open']===10&&$m['close']===25&&$m['high']===26&&$m['low']===9&&$m['volume']===30,'15m aggregates 15 complete 1m bars');
unset($minute[420]);checkIndicator(IndicatorData::aggregate($minute,60,900)===[],'missing minute prevents 15m candle');
$hour=[];for($t=0;$t<14400;$t+=3600)$hour[$t]=['open'=>10+$t/3600,'close'=>11+$t/3600,'high'=>12+$t/3600,'low'=>9+$t/3600,'volume'=>3];
$h=IndicatorData::aggregate($hour,3600,14400)[0];checkIndicator($h['open']===10&&$h['close']===14&&$h['volume']===12&&$h['duration']===14400,'4h aggregates four consecutive UTC hours');
unset($hour[3600]);checkIndicator(IndicatorData::aggregate($hour,3600,14400)===[],'missing hour prevents 4h candle');

foreach([1,2,3,5,14,20,50,200] as $period){
 $flat=bars(array_fill(0,5*$period+1,123.5));
 near(Indicators::value('EMA',$period,'value',$flat),123.5,'EMA constant series period '.$period);
 $linear=range(10,10+5*$period);$seed=array_sum(array_slice($linear,0,$period))/$period;$alpha=2/($period+1);$tail=array_slice($linear,$period);$expected=$seed*(1-$alpha)**count($tail);
 foreach($tail as $i=>$v)$expected+=$alpha*$v*(1-$alpha)**(count($tail)-$i-1);
 near(Indicators::value('EMA',$period,'value',bars($linear)),$expected,'EMA weighted-sum reference period '.$period);
}
$spike=bars([100,100,100,100,100]);$spike[4]['high']=1000.;$spike[4]['low']=1.;
near(Indicators::value('ATR',3,'value',$spike),(2*2+999)/3,'ATR severe volatility spike uses true range and Wilder smoothing');
near(Indicators::value('ATR',3,'atr_pct',$spike),100*((2*2+999)/3)/100,'ATR spike percentage normalized to close');
$jump=bars([100,100,100,100,1000]);
near(Indicators::value('ATR',3,'value',$jump),(2*2+901)/3,'ATR gap accounts for previous close');
