<?php
declare(strict_types=1);
require_once __DIR__.'/IndicatorData.php';

// Fixed public upstreams only. Never loads App.php, opens SQLite, or executes a tick.
final class NetworkDiagnostics {
 static function endpoints(int $now):array {
  $end=intdiv($now,60)*60;$hour=intdiv($now,3600)*3600;
  return ['price'=>['url'=>'https://api.wallex.ir/v1/markets'],
   'history_1m'=>['url'=>'https://api.wallex.ir/v1/udf/history?'.http_build_query(['symbol'=>'BTCUSDT','resolution'=>'1','from'=>$end-3600,'to'=>$end]),'from'=>$end-3600,'to'=>$end,'base'=>60],
   'history_1h'=>['url'=>'https://api.wallex.ir/v1/udf/history?'.http_build_query(['symbol'=>'BTCUSDT','resolution'=>'60','from'=>$hour-14400,'to'=>$hour]),'from'=>$hour-14400,'to'=>$hour,'base'=>3600]];
 }
 static function summarize(string $kind,array $spec,string|false $raw,array $info,int $errno,string $error):array {
  $r=['url'=>$spec['url'],'http_status'=>(int)($info['http_code']??0),'duration_ms'=>round(1000*($info['total_time']??0),2),'curl_errno'=>$errno,'curl_error'=>substr($error,0,300),
   'tls'=>['peer_verification_enabled'=>true,'hostname_verification_enabled'=>true,'verify_result'=>$info['ssl_verify_result']??null,'verified'=>$errno===0&&($info['http_code']??0)>0&&($info['ssl_verify_result']??-1)===0],
   'json_valid'=>false,'schema_valid'=>false,'received_candles'=>null,'closed_candles'=>null,'last_data_time'=>null];
  if($raw===false)return $r;
  try{$d=json_decode($raw,true,512,JSON_THROW_ON_ERROR);$r['json_valid']=true;
   if(!is_array($d))throw new RuntimeException('JSON root is not an object');
   if($kind==='price'){$p=$d['result']['symbols']['BTCUSDT']['stats']['lastPrice']??null;if(!is_numeric($p)||!is_finite((float)$p)||(float)$p<=0)throw new RuntimeException('Missing valid BTCUSDT price');$r['schema_valid']=true;$r['price']=(float)$p;}
   else {$bars=IndicatorData::parse($d,'BTCUSDT',$spec['base'],$spec['from'],$spec['to']);$r['received_candles']=count($d['t']);$r['closed_candles']=count($bars);$r['last_data_time']=$bars?array_key_last($bars)+$spec['base']:null;
    $r['schema_valid']=count($bars)===intdiv($spec['to']-$spec['from'],$spec['base']);if(!$r['schema_valid'])$r['validation_error']='Missing closed candles';}
  }catch(Throwable $e){$r['validation_error']=$e instanceof JsonException?'Invalid JSON':$e->getMessage();}
  return $r;
 }
 static function run():array {
  $start=microtime(true);$result=['server_time'=>time(),'read_only'=>true,'requests'=>[]];
  foreach(self::endpoints(time()) as $kind=>$spec){$remaining=(int)floor(14-(microtime(true)-$start));if($remaining<1){$result['requests'][$kind]=['url'=>$spec['url'],'error'=>'Diagnostic budget exhausted'];continue;}
   $ch=curl_init($spec['url']);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>min(4,$remaining),CURLOPT_TIMEOUT=>min(5,$remaining),CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_ENCODING=>'',CURLOPT_USERAGENT=>'WallexReadOnlyDiagnostics/1.0']);
   $raw=curl_exec($ch);$result['requests'][$kind]=self::summarize($kind,$spec,$raw,curl_getinfo($ch),curl_errno($ch),curl_error($ch));curl_close($ch);
  }
  return $result;
 }
}
