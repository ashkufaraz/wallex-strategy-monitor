<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
define('TEST_DATABASE',$argv[1]);require dirname(__DIR__).'/src/App.php';require __DIR__.'/scanner-fixture.php';
[$c,$d,$t]=scannerFixture();$mode=$argv[2];
$snapshot=fn($i)=>$d['observations'][$i]+['provider'=>'wallex','symbol'=>'BTCUSDT','bars'=>$d['bars'],'market'=>$d['market']];
if($mode==='seed'){setSetting('scanner_config',encode($c));foreach([0,1] as $i)ScannerService::persist($c,$snapshot($i),ScannerService::row($c));echo "SEEDED\n";}
elseif($mode==='race'||$mode==='entryrace'){$row=ScannerService::row($c);file_put_contents($argv[3].'.ready.'.$argv[4],'ready');$start=microtime(true);while(!is_file($argv[3])){if(microtime(true)-$start>12)throw new RuntimeException('Barrier timeout');usleep(5000);}$r=ScannerService::persist($c,$snapshot($mode==='entryrace'?3:2),$row);echo encode(['discarded'=>($r['status']??null)==='CONCURRENT_UPDATE_DISCARDED'])."\n";}
elseif($mode==='confirm'){ScannerService::persist($c,$snapshot(2),ScannerService::row($c));echo "CONFIRMED\n";}
elseif($mode==='wallet'){$state=json_decode(ScannerService::row($c)['state'],true);$w=ScannerPaper::wallet($state);echo encode($w+['status'=>$state['paper']['status'],'accounted'=>$state['balance']+$state['paper']['entry_fee'],'reserve_matches'=>abs($w['reserved_principal_usdt']-$state['paper']['quantity']*$state['paper']['entry'])<1e-10,'trades'=>(int)db()->query('SELECT count(*) FROM scanner_trades')->fetchColumn()])."\n";}
elseif($mode==='restart'){foreach([2,3,3] as $i)ScannerService::persist($c,$snapshot($i),ScannerService::row($c));echo "RESTARTED\n";}
elseif($mode==='replay'){setSetting('scanner_config',encode($c));$r=ScannerService::replay();echo encode(['signals'=>count($r['signals']),'observations'=>count($r['decisions']),'open_status'=>$r['open_paper']['status']??null])."\n";}
elseif($mode==='summary'){$r=db()->query('SELECT payload FROM scanner_signals')->fetchColumn();$p=json_decode($r,true);$before=$p['config_snapshot'];$c['entry_fee_bps']=90.;setSetting('scanner_config',encode($c));$after=json_decode(db()->query('SELECT payload FROM scanner_signals')->fetchColumn(),true)['config_snapshot'];echo encode(['signals'=>(int)db()->query('SELECT count(*) FROM scanner_signals')->fetchColumn(),'alerts'=>(int)db()->query("SELECT count(*) FROM alerts WHERE unique_key LIKE 'scanner:%'")->fetchColumn(),'trades'=>(int)db()->query('SELECT count(*) FROM scanner_trades')->fetchColumn(),'config_unchanged'=>$before===$after,'recorded_entry_fee_bps'=>$after['entry_fee_bps'],'paper_status'=>db()->query('SELECT status FROM scanner_trades')->fetchColumn()])."\n";}
else throw new RuntimeException('Unknown worker mode');
