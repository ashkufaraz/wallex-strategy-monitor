<?php
if(PHP_SAPI!=='cli')exit;
define('TEST_DATABASE',':memory:');require dirname(__DIR__).'/src/App.php';
try {
 if($argc<3)throw new InvalidArgumentException('Usage: php tools/scanner-backtest.php dataset.json output.json [config.json]');
 $dataset=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);$config=$argc>3?json_decode(file_get_contents($argv[3]),true,64,JSON_THROW_ON_ERROR):ScannerConfig::defaults();
 $result=ScannerRunner::backtest($dataset,$config);file_put_contents($argv[2],json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
 echo encode(['report'=>$result['report'],'signals'=>count($result['signals']),'observations'=>count($result['decisions'])])."\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
