<?php
declare(strict_types=1);
ini_set('serialize_precision','-1');
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
$file=dirname(__DIR__).'/config/diagnostics-admin.php';
if(!is_file($file)){http_response_code(404);exit;}
$config=require $file;
if(!is_array($config)||!isset($config['password_hash'],$config['expires'])||time()>$config['expires']){http_response_code(404);exit;}
if(($_SERVER['HTTPS']??'')!=='on'&&!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)){http_response_code(403);exit;}
session_name('wallex_admin_diagnostics');session_start(['cookie_httponly'=>true,'cookie_secure'=>(($_SERVER['HTTPS']??'')==='on'),'cookie_samesite'=>'Strict','use_strict_mode'=>true]);
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['password'])){
 usleep(250000);
 if(is_string($_POST['password'])&&password_verify($_POST['password'],$config['password_hash'])){session_regenerate_id(true);$_SESSION['diagnostics_until']=min(time()+900,$config['expires']);$_SESSION['diagnostics_csrf']=bin2hex(random_bytes(24));}
}
if(($_SESSION['diagnostics_until']??0)<time()){
 header("Content-Security-Policy: default-src 'none'; form-action 'self'; frame-ancestors 'none'");
 echo '<!doctype html><meta charset="utf-8"><title>Admin network diagnostics</title><h1>Admin diagnostics</h1><form method="post"><label>Temporary admin password <input name="password" type="password" autocomplete="off" required></label><button>Sign in</button></form>';exit;
}
$csrf=$_SESSION['diagnostics_csrf'];
if(isset($_GET['ping'])){session_write_close();header('X-Diagnostics-PHP: reached');header('Content-Type: application/json');echo '{"php":"reached","read_only":true}';exit;}
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['run'])){
 if(!is_string($_POST['csrf']??null)||!hash_equals($csrf,$_POST['csrf'])){http_response_code(403);exit;}
 if(time()-(int)($_SESSION['diagnostics_last']??0)<20){http_response_code(429);exit;}
 $_SESSION['diagnostics_last']=time();session_write_close();require dirname(__DIR__).'/src/NetworkDiagnostics.php';
 $report=NetworkDiagnostics::run();
 // Optional private report for the administrator's FTPS retrieval; contains only public endpoints.
 if(!empty($config['save_private_report']))file_put_contents(dirname(__DIR__).'/config/diagnostics-last.json',json_encode($report,JSON_THROW_ON_ERROR));
 header('Content-Type: application/json');echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);exit;
}
$nonce=bin2hex(random_bytes(16));header("Content-Security-Policy: default-src 'none'; script-src 'nonce-$nonce'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'");
?><!doctype html><meta charset="utf-8"><title>Admin network diagnostics</title>
<h1>Read-only network diagnostics</h1><p>No strategy evaluation, database access or alerts. Three fixed public Wallex requests only. Access expires after 15 minutes.</p>
<button id="ping" autofocus>Test browser → PHP (read only)</button><pre id="result"></pre>
<form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf,ENT_QUOTES)?>"><label>Read-only check: type RUN and press Enter <input name="confirmation" aria-label="Read-only check confirmation" pattern="RUN" required autocomplete="off"></label><button name="run" value="1">Test PHP → Wallex price and OHLCV</button></form>
<script nonce="<?=$nonce?>">document.getElementById('ping').onclick=async()=>{let start=performance.now();try{let r=await fetch(location.pathname+'?ping=1',{cache:'no-store'});document.getElementById('result').textContent=JSON.stringify({url:location.origin+location.pathname,http_status:r.status,duration_ms:performance.now()-start,php_response:r.headers.get('X-Diagnostics-PHP')},null,2)}catch(e){document.getElementById('result').textContent=JSON.stringify({url:location.origin+location.pathname,http_status:null,duration_ms:performance.now()-start,error:e.message},null,2)}};</script>
