<?php
define('TEST_DATABASE',':memory:');
require dirname(__DIR__).'/src/App.php';
function continuityCheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$source=[0=>['open'=>100.,'high'=>105.,'low'=>95.,'close'=>102.,'volume'=>3.],3600=>['open'=>999.,'high'=>999.,'low'=>999.,'close'=>999.,'volume'=>0.],7200=>['open'=>102.,'high'=>104.,'low'=>100.,'close'=>101.,'volume'=>1.]];
$r=IndicatorData::continuous($source,3600,3600,0,10800);
continuityCheck($r[3600]['close']===102.&&$r[3600]['high']===102.&&$r[3600]['volume']===0.,'empty historical interval carries last traded close, never untraded source price or fake volume');
continuityCheck(isset($r[3600]['continuity'])&&!isset($r[0]['continuity']),'carry-forward intervals are explicitly tagged');
continuityCheck(count(CandleAggregation::aggregate($source,3600,3600))===2,'entry candle aggregation still excludes empty intervals');
$r=IndicatorData::continuous([3600=>$source[3600]],3600,3600,3600,7200,98.);
continuityCheck($r[3600]['close']===98.,'a verified earlier trade can seed the first empty interval');
$bad=false;try{IndicatorData::continuous([3600=>$source[3600]],3600,3600,3600,7200);}catch(RuntimeException $e){$bad=true;}
continuityCheck($bad,'no previous real trade remains unavailable');
unset($source[3600]);$bad=false;try{IndicatorData::continuous($source,3600,3600,0,10800);}catch(RuntimeException $e){$bad=true;}
continuityCheck($bad,'missing timestamps are never filled as if they were zero-volume candles');
