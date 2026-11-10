<?php
require_once dirname(__DIR__).'/src/CandleAggregation.php';
define('TEST_DATABASE',':memory:');
require_once dirname(__DIR__).'/src/App.php';
function candleAssert(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
function candleMinutes():array {$a=[];for($t=0;$t<14400;$t+=60)$a[$t]=['open'=>100.,'high'=>105.,'low'=>95.,'close'=>101.,'volume'=>1.];return $a;}
foreach(['start'=>0,'end'=>14340,'middle'=>7200] as $name=>$at){$a=candleMinutes();$a[$at]=['open'=>1.,'high'=>999.,'low'=>1.,'close'=>999.,'volume'=>0.];$r=CandleAggregation::aggregate($a,60,14400);candleAssert($r[0]===['open'=>100.,'close'=>101.,'high'=>105.,'low'=>95.,'volume'=>239.],'zero volume '.$name.' ignored for OHLC, retained for coverage');}
$a=candleMinutes();for($t=0;$t<10800;$t+=60)$a[$t]['volume']=0.;$r=CandleAggregation::aggregate($a,60,14400);candleAssert($r[0]['volume']===60.&&$r[0]['open']===100.,'three consecutive zero-volume hours do not erase timestamp coverage');
unset($a[60]);candleAssert(CandleAggregation::aggregate($a,60,14400)===[],'missing zero-volume minute still rejects interval');
$a=candleMinutes();foreach($a as &$b)$b['volume']=0.;unset($b);$r=CandleAggregation::inspect($a,60,14400);candleAssert($r['bars']===[]&&str_contains($r['unavailable'][0],'no trades'),'all 240 zero-volume minutes are UNAVAILABLE');
$a=candleMinutes();$hours=[];for($t=0;$t<14400;$t+=3600)$hours[$t]=['open'=>100.,'close'=>101.,'high'=>105.,'low'=>95.,'volume'=>$t<10800?0.:60.];for($t=0;$t<10800;$t+=60)$a[$t]['volume']=0.;candleAssert(CandleAggregation::aggregate($a,60,14400)===CandleAggregation::aggregate($hours,3600,14400),'1m and 1h agree with three empty hours');
$fixtures=json_decode(file_get_contents(__DIR__.'/fixtures/four-hour-comparisons.json'),true,512,JSON_THROW_ON_ERROR);$report=[];
foreach($fixtures as $f){$m=IndicatorData::parse($f['responses']['1'],$f['symbol'],60,$f['from'],$f['to']);$h=IndicatorData::parse($f['responses']['60'],$f['symbol'],3600,$f['from'],$f['to']);
 $a=CandleAggregation::aggregate($m,60,14400);$b=CandleAggregation::aggregate($h,3600,14400);$label=$f['symbol'].' '.$f['from'].'..'.$f['to'];
 candleAssert(count($m)===240&&count($h)===4,'complete UTC source timestamps '.$label);
 candleAssert(array_keys($a)===array_keys($b)&&array_keys($a)===[$f['from']],'same 4h timestamps '.$label);
 $diff=[];foreach(['open','high','low','close','volume'] as $field){$diff[$field]=(float)$a[$f['from']][$field]-(float)$b[$f['from']][$field];candleAssert(abs($diff[$field])<=1e-9,'same '.$field.' '.$label);}
 $engine=Wallex::aggregate($m,14400);$indicator=IndicatorData::aggregate($h,3600,14400);
 candleAssert($indicator[$f['from']]['open_time']===$f['from']&&$indicator[$f['from']]['open_time']+$indicator[$f['from']]['duration']===$f['to'],'runtime indicator UTC metadata '.$label);
 foreach(['open','high','low','close','volume'] as $field)candleAssert(abs((float)$engine[$f['from']][$field]-(float)$indicator[$f['from']][$field])<=1e-9,'runtime engines same '.$field.' '.$label);
 $report[]=['symbol'=>$f['symbol'],'open_time'=>$f['from'],'close_time'=>$f['to'],'zero_minutes'=>count(array_filter($m,fn($x)=>$x['volume']==0)),'minute_4h'=>$a[$f['from']],'hour_4h'=>$b[$f['from']],'delta'=>$diff];
}
if(in_array('--report',$argv??[],true))file_put_contents(dirname(__DIR__).'/data/four-hour-comparison-report.json',json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
