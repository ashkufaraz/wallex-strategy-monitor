<?php
require dirname(__DIR__).'/src/NetworkDiagnostics.php';
function netAssert(bool $x,string $s):void{if(!$x)throw new RuntimeException('FAIL '.$s);echo "PASS $s\n";}
$spec=NetworkDiagnostics::endpoints(1440000);netAssert(count($spec)===3,'only three fixed public endpoints');
$info=['http_code'=>200,'total_time'=>0.25,'ssl_verify_result'=>0];
$p=NetworkDiagnostics::summarize('price',$spec['price'],'{"result":{"symbols":{"BTCUSDT":{"stats":{"lastPrice":"75000"}}}}}',$info,0,'');netAssert($p['schema_valid']&&$p['price']===75000.&&$p['tls']['verified']&&$p['duration_ms']===250.,'price, HTTP, TLS and duration summarized');
$r=NetworkDiagnostics::summarize('price',$spec['price'],false,['http_code'=>0,'ssl_verify_result'=>0],35,'SSL protocol error');netAssert(!$r['tls']['verified']&&$r['curl_errno']===35&&!$r['json_valid'],'TLS handshake failure is not successful verification');
$r=NetworkDiagnostics::summarize('price',$spec['price'],'<html>challenge</html>',$info,0,'');netAssert(!$r['json_valid']&&!$r['schema_valid'],'HTTP 200 HTML is not valid API JSON');
$r=NetworkDiagnostics::summarize('price',$spec['price'],'{}',$info,0,'');netAssert($r['json_valid']&&!$r['schema_valid'],'valid JSON is distinguished from valid market schema');
$d=['s'=>'ok','t'=>[],'o'=>[],'h'=>[],'l'=>[],'c'=>[],'v'=>[]];for($t=$spec['history_1m']['from'];$t<=$spec['history_1m']['to'];$t+=60){$d['t'][]=$t;foreach(['o'=>100,'h'=>101,'l'=>99,'c'=>100,'v'=>1] as $k=>$v)$d[$k][]=$v;}
$r=NetworkDiagnostics::summarize('history_1m',$spec['history_1m'],json_encode($d),$info,0,'');netAssert($r['schema_valid']&&$r['received_candles']===61&&$r['closed_candles']===60&&$r['last_data_time']===1440000,'received and closed candles plus last close distinguished');
netAssert(!function_exists('db')&&!function_exists('runCycle'),'diagnostics does not load database or scheduler');
$page=file_get_contents(dirname(__DIR__).'/public/admin-diagnostics.php');netAssert(str_contains($page,'password_verify')&&str_contains($page,'diagnostics_until')&&str_contains($page,'hash_equals')&&str_contains($page,'http_response_code(404)'),'admin page defaults off, expiring authentication and CSRF guard');
