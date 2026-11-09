<?php
define('TEST_DATABASE',':memory:');
require dirname(__DIR__).'/src/BrowserRunner.php';
function verify($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
class BrowserFake extends Wallex {
 public bool $fail=false;
 function price(string $s):float{if($this->fail)throw new RuntimeException('offline');return 74000.;}
 function minutes(string $s,int $from,int $to):array{return [];}
}
$s=json_decode(file_get_contents(dirname(__DIR__).'/example-btc-short.json'),true);
unset($s['cancellation']);$s['initial_stage']='start';$s['stages']=[['id'=>'start','transitions'=>[['event'=>'price','when'=>['left'=>['price'=>true],'op'=>'gt','right'=>1],'signal'=>true]]]];
foreach(['a','b','disabled','cancelled'] as $id){$s['id']=$id;$s['enabled']=$id!=='disabled';$st=Strategy::fresh($s,1000);if($id==='cancelled')$st['cancelled_at']=1000;db()->prepare('INSERT INTO strategies VALUES(?,?,?,?)')->execute([$id,'v1',encode(Strategy::validate($s)),encode($st)]);}
$path=tempnam(sys_get_temp_dir(),'browser-lock');$api=new BrowserFake();
try{
 $run=fn($now)=>browserCheck($api,$now,$path);
 verify($run(1001)['strategy_id']==='a','one due strategy per request');
 verify((int)db()->query('SELECT COUNT(*) FROM check_logs')->fetchColumn()===1,'other strategy untouched');
 verify($run(1002)['strategy_id']==='b','next request checks next strategy');
 verify($run(1003)['status']==='idle','interval prevents duplicate checks');
 verify((int)db()->query('SELECT COUNT(*) FROM alerts')->fetchColumn()===2,'signals recorded once');
 $lock=fopen($path,'c');flock($lock,LOCK_EX);verify($run(1100)['status']==='busy','concurrent request blocked');flock($lock,LOCK_UN);fclose($lock);
 $api->fail=true;verify($run(1100)['strategy_id']==='a','failed fetch completes bounded attempt');
 $state=json_decode(db()->query("SELECT state FROM strategies WHERE id='a'")->fetchColumn(),true);verify($state['error']==='offline','network failure visible in strategy');
 verify($run(1101)['strategy_id']==='b','failed strategy does not starve others');
 verify($run(1102)['status']==='idle','failed checks are rate limited');
}finally{unlink($path);}

class JsonRetryWallex extends Wallex {
 public int $calls=0; public bool $alwaysBad=false;
 protected function fetchResponse(string $url,int $remaining):string {++$this->calls;return $this->calls===1||$this->alwaysBad?"{\"value\":\"bad\x00\"}":'{"value":74000}';}
}
$retry=new JsonRetryWallex();verify($retry->get('/test')['value']===74000&&$retry->calls===2,'malformed JSON retried without accepting corrupted data');
$retry=new JsonRetryWallex();$retry->alwaysBad=true;try{$retry->get('/test');throw new RuntimeException('Expected JSON error');}catch(RuntimeException $e){verify(str_contains($e->getMessage(),'Invalid market JSON at /test')&&$retry->calls===2,'persistent malformed response has contextual bounded error');}
$expired=new JsonRetryWallex(microtime(true)-1);try{$expired->get('/test');throw new RuntimeException('Expected budget error');}catch(RuntimeException $e){verify($expired->calls===0&&str_contains($e->getMessage(),'time budget'),'expired request budget prevents network access');}
