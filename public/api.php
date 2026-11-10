<?php
require dirname(__DIR__).'/src/App.php';
require dirname(__DIR__).'/src/AlertHistory.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
$host=$_SERVER['HTTP_HOST']??'';
if(!in_array($host,['127.0.0.1:8080','localhost:8080','127.0.0.1:5078','localhost:5078'],true)) {http_response_code(403);exit('{"error":"Local access only"}');}
session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Strict']);$_SESSION['csrf']??=bin2hex(random_bytes(24));
try {
 if($_SERVER['REQUEST_METHOD']==='GET'&&isset($_GET['details'])) {
 $id=$_GET['details'];if(!is_string($id)||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$id))throw new InvalidArgumentException('Invalid strategy ID');
 $q=db()->prepare('SELECT version FROM strategies WHERE id=?');$q->execute([$id]);$version=$q->fetchColumn();
 if($version===false){http_response_code(404);throw new RuntimeException('Strategy not found');}
 $q=db()->prepare('SELECT id,version,payload FROM check_logs WHERE strategy_id=? ORDER BY id DESC LIMIT 30');$q->execute([$id]);
 $logs=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$logs[]=['id'=>(int)$r['id'],'version'=>$r['version'],'report'=>json_decode($r['payload'],true)];
 echo encode(['strategy_id'=>$id,'version'=>$version,'logs'=>$logs]);exit;
 }
 if($_SERVER['REQUEST_METHOD']==='GET') {$rows=db()->query('SELECT * FROM strategies')->fetchAll(PDO::FETCH_ASSOC);$items=[];foreach($rows as $r) $items[]=['config'=>json_decode($r['config'],true),'state'=>json_decode($r['state'],true),'version'=>$r['version']];$alerts=[];foreach(db()->query('SELECT * FROM alerts ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC) as $r) $alerts[]=['id'=>(int)$r['id'],'created'=>(int)$r['created'],'payload'=>json_decode($r['payload'],true)];echo encode(['browser_checks'=>defined('HOSTING_BROWSER_CHECKS')&&HOSTING_BROWSER_CHECKS,'csrf'=>$_SESSION['csrf'],'interval'=>(int)setting('interval','60'),'heartbeat'=>(int)setting('heartbeat','0'),'last_cycle'=>(int)setting('last_cycle','0'),'strategies'=>$items,'alerts'=>$alerts]);exit;}
 if($_SERVER['REQUEST_METHOD']!=='POST') throw new RuntimeException('POST required');
 if(!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??'')) {http_response_code(403);throw new RuntimeException('Invalid request token');}
 if((int)($_SERVER['CONTENT_LENGTH']??0)>100000) throw new RuntimeException('Request too large');$input=json_decode(file_get_contents('php://input'),true,64,JSON_THROW_ON_ERROR);$action=$input['action']??'';
 if($action==='validate') {Strategy::validate(json_decode($input['json'],true,64,JSON_THROW_ON_ERROR));echo encode(['ok'=>true]);exit;}
 if($action==='tick') {if(!defined('HOSTING_BROWSER_CHECKS')||!HOSTING_BROWSER_CHECKS)throw new RuntimeException('Browser checking is unavailable');session_write_close();require_once dirname(__DIR__).'/src/BrowserRunner.php';echo encode(browserCheck());exit;}
 if($action==='save') {$s=Strategy::validate(json_decode($input['json'],true,64,JSON_THROW_ON_ERROR));$api=new Wallex();$api->price($s['symbol']);db()->beginTransaction();$q=db()->prepare('SELECT version FROM strategies WHERE id=?');$q->execute([$s['id']]);$existing=$q->fetchColumn();if($existing!==false && ($input['expected_version']??null)!==$existing) throw new RuntimeException('این شناسه وجود دارد یا نسخه تغییر کرده است؛ ابتدا ویرایش را انتخاب کنید.');db()->prepare('INSERT INTO strategies(id,version,config,state) VALUES(?,?,?,?) ON CONFLICT(id) DO UPDATE SET version=excluded.version,config=excluded.config,state=excluded.state')->execute([$s['id'],bin2hex(random_bytes(12)),encode($s),encode(Strategy::fresh($s,time()))]);db()->commit();}
 elseif($action==='toggle'||$action==='delete') {db()->beginTransaction();$q=db()->prepare('SELECT * FROM strategies WHERE id=?');$q->execute([$input['id']]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!$r || $r['version']!==($input['expected_version']??'')) throw new RuntimeException('نسخه تغییر کرده است؛ صفحه را تازه کنید.');if($action==='delete') db()->prepare('DELETE FROM strategies WHERE id=?')->execute([$input['id']]);else {$s=json_decode($r['config'],true);$s['enabled']=isset(json_decode($r['state'],true)['cancelled_at'])?true:!$s['enabled'];db()->prepare('UPDATE strategies SET version=?,config=?,state=? WHERE id=?')->execute([bin2hex(random_bytes(12)),encode($s),encode(Strategy::fresh($s,time())),$input['id']]);}db()->commit();}
 elseif($action==='delete_alert'||$action==='clear_alerts') {deleteAlertHistory($action,$input['id']??null);}
 elseif($action==='settings') {$n=$input['interval']??null;if(!is_int($n)||$n<5||$n>3600) throw new RuntimeException('فاصله باید عدد صحیح بین ۵ تا ۳۶۰۰ ثانیه باشد.');setSetting('interval',(string)$n);}
 else throw new RuntimeException('Unknown action');echo encode(['ok'=>true]);
} catch(Throwable $e) {if(db()->inTransaction()) db()->rollBack();if(http_response_code()<400) http_response_code(400);echo encode(['error'=>$e->getMessage()]);}
