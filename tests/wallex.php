<?php
require dirname(__DIR__).'/src/App.php';
function verify(bool $value,string $label):void {if(!$value)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
class FixtureWallex extends Wallex {
 public array $response=[];
 public function get(string $path,array $params=[]):array {return $this->response;}
}
$valid=['s'=>'ok','t'=>[0,60,120],'o'=>[10,11,12],'h'=>[15,15,15],'l'=>[8,8,8],'c'=>[11,12,13],'v'=>[1,2,3]];
$api=new FixtureWallex();$api->response=$valid;
$bars=$api->minutes('BTCUSDT',0,120);
verify(count($bars)===2&&!isset($bars[120]),'open minute excluded');
foreach(['fractional timestamp','non-array OHLC','invalid range','negative volume','unequal arrays','no data'] as $case){
 $api=new FixtureWallex();$api->response=$valid;
 switch($case){
 case 'fractional timestamp':$api->response['t'][1]=60.5;break;
 case 'non-array OHLC':$api->response['o']='bad';break;
 case 'invalid range':$api->response['h'][0]=9;break;
 case 'negative volume':$api->response['v'][0]=-1;break;
 case 'unequal arrays':array_pop($api->response['c']);break;
 case 'no data':$api->response=['s'=>'no_data'];break;
 }
 $rejected=false;try{$api->minutes('BTCUSDT',0,180);}catch(RuntimeException $e){$rejected=true;}
 verify($rejected,$case.' rejected as a data error');
}
echo "All adapter tests passed.\n";
