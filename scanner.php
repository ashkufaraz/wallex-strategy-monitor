<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/src/App.php';
function scannerOutput(array $data,?string $path):void {$json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);if($path){if(file_put_contents($path,$json."\n")===false)throw new RuntimeException('Output write failed');echo $path."\n";}else echo $json."\n";}
try{
 $cmd=$argv[1]??'help';$path=$argv[2]??null;
 if($cmd==='help'){echo "php scanner.php start [config.json] | stop | tick | run | report [output.json] | export [dataset.json] | replay [output.json] | audit [output.json]\n";exit;}
 if($cmd==='start'||$cmd==='stop'){$c=$cmd==='start'&&$path?json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR):ScannerService::config();$c['enabled']=$cmd==='start';ScannerConfig::operational($c);ScannerService::save($c);scannerOutput(['enabled'=>$c['enabled'],'version'=>ScannerConfig::version($c),'real_orders'=>false],null);}
 elseif($cmd==='tick')scannerOutput(ScannerService::tickAll(),$path);
 elseif($cmd==='audit'){$c=ScannerService::config();$s=ScannerData::fetch(new Wallex(microtime(true)+90),$c,time());$state=ScannerPaper::fresh($c);$r=ScannerRunner::step($state,$s,$c);scannerOutput(['audit_utc'=>gmdate('c'),'config'=>$c,'code_version'=>ScannerReadiness::codeVersion(),'stages'=>ScannerReadiness::stages($s,$c,$state,$r),'snapshot'=>$s,'decision'=>$r['decision'],'real_orders'=>false],$path);}
 elseif($cmd==='report')scannerOutput(ScannerService::view(),$path);
 elseif($cmd==='export')scannerOutput(ScannerService::dataset(10000),$path);
 elseif($cmd==='replay')scannerOutput(ScannerService::replay(),$path);
 elseif($cmd==='run'){
  $lock=fopen(__DIR__.'/data/scanner-collector.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Collector already running');
  file_put_contents(__DIR__.'/data/scanner-collector.pid',(string)getmypid());
  try{while(true){$r=ScannerService::tickAll();echo encode(['at'=>gmdate('c'),'status'=>$r['status']??$r['decision']['status']??null,'phase'=>$r['stages']['phase']??null])."\n";sleep(5);}}
  finally{flock($lock,LOCK_UN);fclose($lock);@unlink(__DIR__.'/data/scanner-collector.pid');}
 }else throw new InvalidArgumentException('Unknown command; use help');
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
