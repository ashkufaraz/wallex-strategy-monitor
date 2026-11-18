<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/src/App.php';
$c=PatternService::config();$bc=ScannerService::config();$s=PatternData::fetch(new Wallex(microtime(true)+110),$c,$bc,time());
$state=[];$r=PatternEngine::step($state,$s,$c,$bc);
file_put_contents(dirname(__DIR__).'/data/pattern-data-audit.json',encode(['checked_at'=>time(),'snapshot'=>$s,'result'=>$r]));
foreach($r['methods'] as $id=>$m)echo encode(['method'=>$id,'data_status'=>$m['data_status'],'reason'=>$m['reason'],'health'=>$m['data_health']??null]).PHP_EOL;
