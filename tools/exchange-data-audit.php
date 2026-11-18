<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/src/App.php';
// Read-only market requests and in-memory evaluation; never persist signals or send alerts.
$out=['checked_at'=>time(),'markets'=>[]];
foreach(MarketScope::markets() as $scope){
 $key=MarketScope::key($scope);$out['markets'][$key]=MarketScope::run($scope,function()use($scope){
  try{$api=MarketData::provider($scope['exchange'],microtime(true)+150);$price=$api->price($scope['symbol']);$c=PatternService::config();$bc=ScannerService::config();$s=PatternData::fetch($api,$c,$bc,time());$state=[];$r=PatternEngine::step($state,$s,$c,$bc);
   file_put_contents(dirname(__DIR__).'/data/exchange-audit-'.str_replace(':','-',MarketScope::key()).'.json',encode(['scope'=>$scope,'snapshot'=>$s,'result'=>$r]));
   $methods=[];foreach($r['methods'] as $id=>$m)$methods[$id]=array_intersect_key($m,array_flip(['data_status','pattern_status','reason','data_health','conditions']));
   return $scope+['supported'=>true,'price'=>$price,'price_unit'=>'USDT','volume_unit'=>substr($scope['symbol'],0,-4),'time_unit'=>'UNIX_SECONDS','methods'=>$methods,'fetch_errors'=>$s['fetch_errors']??[]];
  }catch(Throwable $e){return $scope+['supported'=>null,'error'=>$e->getMessage()];}
 });
 file_put_contents(dirname(__DIR__).'/reports/exchange-live-coverage.json',encode($out));echo $key.' '.encode($out['markets'][$key]).PHP_EOL;
}
