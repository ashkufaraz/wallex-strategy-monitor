<?php
define('TEST_DATABASE',':memory:');require dirname(__DIR__).'/src/App.php';
$n=0;function advisoryCheck(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";$n++;}
function advisoryReject(Closure $fn,string $label):void{try{$fn();}catch(Throwable $e){advisoryCheck(true,$label);return;}throw new RuntimeException($label);}
$now=1800000000;
$c=MarketArchitecture::validate(['analysis_enabled'=>true,'manual_advisory_enabled'=>true,'min_remaining_rr'=>1.]);
ExecutionGate::migrate(db());
class AdvisoryBinance extends BinanceMarketDataProvider {
 public int $at=1800000000;public float $value=100.;public bool $offline=false;public array $bars=[];
 function now():int{return $this->at;}
 function price(string $symbol):float{if($this->offline)throw new RuntimeException('offline');return $this->value;}
 function quote(string $symbol):array{if($this->offline)throw new RuntimeException('offline');return ['provider'=>'binance_spot','symbol'=>$symbol,'bid'=>99.9,'ask'=>100.1,'mid'=>100.,'timestamp'=>$this->at,'observed_at'=>$this->at,'sample_id'=>'b-'.$this->at];}
 function monitoringMinutes(string $symbol,int $from,int $to):array{return $this->bars;}
}
class AdvisoryWallex implements ExecutionQuoteProvider {
 public int $at=1800000000;public int $requests=0;public bool $bad=false;public bool $offline=false;public float $quantity=2.;public float $shift=0.;
 function quote(string $symbol,int $now):array{
  $this->requests++;if($this->offline)throw new RuntimeException('offline');
  $bid=99.9+$this->shift;$ask=100.1+$this->shift;
  return ['provider'=>'wallex','symbol'=>$symbol,'bid'=>$bid,'ask'=>$ask,'mid'=>($bid+$ask)/2,'spread'=>$ask-$bid,'bids'=>$this->bad?[]:[[$bid,$this->quantity]],'asks'=>[[$ask,$this->quantity]],'timestamp'=>null,'received_at'=>$this->at,'receipt_id'=>'receipt-'.$this->requests,'sample_id'=>'unchanged-content','trust_status'=>'UNVERIFIED'];
 }
}
$api=new AdvisoryBinance();$wallex=new AdvisoryWallex();
// Calibrate only this isolated advisory series. Identical content is allowed:
// these are independent simulated receipts, not verified exchange-time events.
for($i=0;$i<150;$i++){$at=$now-1500+$i*10;$api->at=$wallex->at=$at;ExecutionGate::sample(db(),'BTCUSDT',$api->quote('BTCUSDT'),$wallex->quote('BTCUSDT',$at),$at,$c);}
$s=json_decode(file_get_contents(dirname(__DIR__).'/example-btc-short.json'),true);
$s['id']='manual-test';$s['action']='SHORT';$s['trade']=['entry'=>['type'=>'MARKET'],'quantity'=>.5,'stop_loss'=>110.,'take_profits'=>[80.,70.,60.]];$s['invalidation']=['left'=>['price'=>true],'op'=>'gt','right'=>120.];$s['cancellation']=[];
$id=ExecutionGate::create(db(),$s,'v1','price',$now,100.,null,$c,$now);
$state=function()use($id){$q=db()->prepare('SELECT payload FROM execution_signals WHERE id=?');$q->execute([$id]);return json_decode($q->fetchColumn(),true);};
$count=fn()=>(int)db()->query('SELECT COUNT(*) FROM alerts')->fetchColumn();
advisoryCheck($count()===1&&json_decode(db()->query('SELECT payload FROM alerts')->fetchColumn(),true)['type']==='binance_signal','first Binance alert is immediate before any Wallex quote or readiness');
ExecutionGate::create(db(),$s,'v1','price',$now,100.,null,$c,$now+10);
advisoryCheck($count()===1&&$state()['deadline']===$now+900,'duplicate signal creation neither alerts again nor extends deadline');
for($i=0;$i<3;$i++){$api->at=$wallex->at=$now+$i*10;ExecutionMonitor::run($api,$api->at,$c,$wallex);advisoryCheck($state()['status']===($i<2?'WAITING_BASIS':'MANUAL_REVIEW_AVAILABLE'),'independent advisory confirmation '.($i+1).' of three');}
$p=$state();
advisoryCheck($p['quotes']['wallex']['trust_status']==='UNVERIFIED'&&$p['quotes']['wallex']['timestamp']===null,'advisory never upgrades timestamp or trust');
advisoryCheck($p['rr']['entry']===99.9&&$p['estimate']['vwap']===99.9&&!$p['estimate']['execution_confirmed'],'SHORT uses bid VWAP only as unverified public-book estimate');
advisoryCheck($count()===2&&$p['status']!=='READY_FOR_MANUAL_ENTRY','second independent alert uses manual review, never verified READY');
advisoryReject(fn()=>ExecutionGate::fresh($p['quotes']['wallex'],$now+20,$c),'strict quote validation still rejects advisory book');
advisoryReject(fn()=>ExecutionGate::acknowledge(db(),$id,$now+20),'advisory cannot use verified READY acknowledgement endpoint');
$disabled=$c;$disabled['manual_advisory_enabled']=false;$disabled['enabled']=true;
$blocked=ExecutionGate::decide($p,$disabled,$now+20,null,null,null,[],[]);
advisoryCheck($blocked['status']==='WAITING_DATA','disabling advisory does not convert its existing signal to verified path');
$api->at=$wallex->at=$now+30;ExecutionMonitor::run($api,$api->at,$c,$wallex);
advisoryCheck($count()===2,'repeated successful monitoring never duplicates either alert');
$wallex->bad=true;$api->at=$wallex->at=$now+40;ExecutionMonitor::run($api,$api->at,$c,$wallex);
advisoryCheck($state()['status']==='WAITING_DATA'&&!$state()['confirmations']&&isset($state()['revoked_at']),'bad book revokes availability and resets confirmations');
$wallex->bad=false;$wallex->quantity=.1;$api->at=$wallex->at=$now+50;ExecutionMonitor::run($api,$api->at,$c,$wallex);
advisoryCheck($state()['status']==='WAITING_DATA'&&!$state()['estimate']['depth_sufficient'],'insufficient visible depth prevents advisory');
$wallex->quantity=2.;$api->at=$wallex->at=$now+60;$r=$api->quote('BTCUSDT');$w=$wallex->quote('BTCUSDT',$api->at);$sample=ExecutionGate::sample(db(),'BTCUSDT',$r,$w,$api->at,$c);$stats=ExecutionGate::statistics(db(),'BTCUSDT',$api->at,$c);
advisoryCheck(ExecutionGate::sample(db(),'BTCUSDT',$r,$w,$api->at,$c)===null,'replayed pair is not an independent receipt');
$replay=$w;$replay['received_at']=$api->at+10;$later=$r;$later['timestamp']=$later['observed_at']=$api->at+10;$later['sample_id']='different-reference';
advisoryCheck(ExecutionGate::sample(db(),'BTCUSDT',$later,$replay,$api->at+10,$c)===null,'same receipt cannot count twice even with another reference and forged later receipt time');
$noCalibration=$stats;$noCalibration['calibrated']=false;
advisoryCheck(ExecutionGate::decide($p,$c,$api->at,$r,$w,$sample,$noCalibration,[])['status']==='WAITING_CALIBRATION','insufficient calibration blocks opportunity');
$poor=$c;$poor['min_remaining_rr']=10.;
advisoryCheck(ExecutionGate::decide($p,$poor,$api->at,$r,$w,$sample,$stats,[])['status']==='WAITING_BASIS','poor remaining RR blocks opportunity');
$partialCosts=$c;$partialCosts['fees_pct']=.1;
advisoryCheck(ExecutionGate::decide($p,$partialCosts,$api->at,$r,$w,$sample,$stats,[])['rr']['net_rr']===null,'incomplete fees and slippage are described as gross, not net');
$unknownRR=$c;$unknownRR['min_remaining_rr']=null;
advisoryCheck(ExecutionGate::decide($p,$unknownRR,$api->at,$r,$w,$sample,$stats,[])['status']==='BLOCKED_RR_THRESHOLD_NOT_CONFIGURED','no guessed RR threshold');
$noQuantity=$p;unset($noQuantity['strategy']['trade']['quantity']);
advisoryCheck(ExecutionGate::decide($noQuantity,$c,$api->at,$r,$w,$sample,$stats,[])['status']==='WAITING_DATA','unknown size never claims a size-specific VWAP opportunity');
$old=$w;$old['received_at']=$now;
advisoryReject(fn()=>ExecutionGate::sample(db(),'BTCUSDT',$r,$old,$api->at,$c),'stale local receipt is rejected despite missing exchange timestamp being allowed');
$mismatch=$w;$mismatch['symbol']='ETHUSDT';
advisoryReject(fn()=>ExecutionGate::sample(db(),'BTCUSDT',$r,$mismatch,$api->at,$c),'mismatched market pair rejected');
$jump=$w;$jump['bid']=109.9;$jump['ask']=110.1;$jump['bids']=[[109.9,2.]];$jump['asks']=[[110.1,2.]];
advisoryCheck(ExecutionGate::decide($p,$c,$api->at,$r,$jump,$sample,$stats,[])['status']==='WAITING_BASIS','abnormal basis remains blocking');
$api->offline=true;$api->at=$wallex->at=$now+70;ExecutionMonitor::run($api,$api->at,$c,$wallex);
advisoryCheck($state()['status']==='WAITING_DATA'&&!$state()['confirmations'],'API outage fails closed');
$api->offline=false;
foreach([111.=>'CANCELLED_STOP_LOSS',79.=>'EXPIRED_TP1_REACHED'] as $value=>$status){$api->value=(float)$value;$api->at=$now+80;$v=ExecutionGate::validity($p,$api,$api->at);advisoryCheck(ExecutionGate::decide($p,$c,$api->at,null,null,null,[],$v)['status']===$status,'original Binance level invalidates: '.$status);}
$api->value=100.;$invalidate=$p;$invalidate['invalidation']=['left'=>['price'=>true],'op'=>'gt','right'=>99.];$v=ExecutionGate::validity($invalidate,$api,$api->at);
advisoryCheck(ExecutionGate::decide($invalidate,$c,$api->at,null,null,null,[],$v)['status']==='CANCELLED_INVALIDATION','original strategy invalidation preserved');
$cancel=$p;$cancel['cancellation']=[['event'=>'price','when'=>['left'=>['price'=>true],'op'=>'gt','right'=>99.],'reason'=>'test cancellation']];
advisoryCheck(ExecutionGate::validity($cancel,$api,$api->at)['status']==='CANCELLED_INVALIDATION','explicit strategy cancellation preserved');
$api->at=$wallex->at=$now+900;ExecutionMonitor::run($api,$api->at,$c,$wallex);
advisoryCheck($state()['status']==='EXPIRED_TIMEOUT'&&$state()['deadline']===$now+900&&$count()===2,'900 second timeout is persistent and never emits another entry alert');
// Persistence across actual SQLite connection reopening, wholly separate temporary DB.
$file=tempnam(sys_get_temp_dir(),'manual-advisory-');
try{
 $disk=new PDO('sqlite:'.$file);$disk->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$disk->exec('CREATE TABLE alerts(unique_key TEXT UNIQUE,created INTEGER,payload TEXT)');ExecutionGate::migrate($disk);
 $diskId=ExecutionGate::create($disk,$s,'disk','price',$now,100.,null,$c,$now);
 $before=json_decode($disk->query('SELECT payload FROM execution_signals')->fetchColumn(),true);$after=$p;$after['id']=$diskId;$after['version']='disk';unset($after['advisory_notified_at']);
 ExecutionGate::persist($disk,$before,$after,$now+20);$disk=null;
 $disk=new PDO('sqlite:'.$file);$raw=json_decode($disk->query('SELECT payload FROM execution_signals')->fetchColumn(),true);
 advisoryCheck($raw['deadline']===$now+900&&$raw['mode']==='MANUAL_ADVISORY'&&$raw['status']==='MANUAL_REVIEW_AVAILABLE'&&count($raw['confirmations'])===3,'SQLite reopening preserves opportunity, confirmations, mode and original deadline');
 ExecutionGate::persist($disk,$raw,$raw,$now+30);
 advisoryCheck((int)$disk->query('SELECT COUNT(*) FROM alerts')->fetchColumn()===2,'notification markers survive SQLite reopening without duplicate advisory alert');
 $expired=ExecutionGate::decide($raw,$c,$now+900,null,null,null,[],[]);ExecutionGate::persist($disk,$raw,$expired,$now+900);$disk=null;$disk=new PDO('sqlite:'.$file);
 advisoryCheck($disk->query('SELECT status FROM execution_signals')->fetchColumn()==='EXPIRED_TIMEOUT','terminal expiry survives a second SQLite connection restart');
 $disk=null;
}finally{unlink($file);}

advisoryCheck(!MarketArchitecture::config()['manual_advisory_enabled']&&!MarketArchitecture::config()['enabled'],'actual flags remain off');
advisoryReject(fn()=>MarketArchitecture::validate(['manual_advisory_enabled'=>true,'confirmation_samples'=>2]),'fewer than three advisory receipts not configurable');
advisoryCheck(!str_contains(file_get_contents(dirname(__DIR__).'/src/ExecutionMonitor.php'),'->post(')&&!str_contains(file_get_contents(dirname(__DIR__).'/src/ExecutionGate.php'),'curl_'),'gate and monitor contain no order transport');
echo "Manual advisory: $n assertions passed.\n";
