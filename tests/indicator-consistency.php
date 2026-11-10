<?php
define('TEST_DATABASE',':memory:');
require dirname(__DIR__).'/src/App.php';
foreach(glob(__DIR__.'/fixtures/*-indicator-history.json') as $file){
 $f=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$q=$f['query'];
 $bars=array_values(IndicatorData::parse($f['response'],$q['symbol'],60,$q['from'],$q['to']));
 foreach($f['reference']['values'] as $name=>$expected){[$indicator,$metric]=explode('.',$name);$p=$f['reference']['period'];
  $selected=array_slice($bars,-($indicator==='VOLUME'?$p+1:5*$p+1));
  $actual=Indicators::value($indicator,$p,$metric,$selected);
  if($expected===null||abs($actual-$expected)>1e-8)throw new RuntimeException('Reference mismatch: '.$q['symbol'].' '.$name);
  echo 'PASS '.$q['symbol'].' '.$name.' vs independent Decimal reference; absolute error '.abs($actual-$expected)."\n";
 }
}
