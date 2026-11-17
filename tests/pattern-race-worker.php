<?php
define('TEST_DATABASE',$argv[1]);require dirname(__DIR__).'/src/App.php';
$f=json_decode(file_get_contents(dirname(__DIR__).'/data/pattern-reference.json'),true);$t=$f['anchor'];$mode=$argv[2];PatternService::migrate();
if($mode==='seed'){echo "SEEDED\n";exit;}
if($mode==='race'){file_put_contents($argv[3].'.ready.'.$argv[4],'ready');$deadline=microtime(true)+12;while(!is_file($argv[3])){if(microtime(true)>$deadline)throw new RuntimeException('Barrier');usleep(5000);}}
if($mode==='race'||$mode==='restart'){$r=PatternService::persist(['provider'=>'wallex','symbol'=>'BTCUSDT','observed_at'=>$t+($mode==='restart'?1:0),'received_at'=>$t+($mode==='restart'?1:0),'bars'=>['15m'=>$f['bars']['LONG']]],PatternConfig::defaults(),ScannerConfig::defaults());echo encode(['signals'=>count($r['signals']??[]),'status'=>$r['status']??'COMMITTED'])."\n";}
elseif($mode==='summary'){echo encode(['events'=>(int)db()->query('SELECT count(*) FROM pattern_events')->fetchColumn(),'alerts'=>(int)db()->query('SELECT count(*) FROM alerts')->fetchColumn(),'signals'=>(int)db()->query('SELECT count(*) FROM pattern_signals')->fetchColumn(),'no_manual'=>(int)db()->query("SELECT count(*) FROM alerts WHERE json_extract(payload,'$.status')='MANUAL_REVIEW_AVAILABLE'")->fetchColumn()===0])."\n";}
