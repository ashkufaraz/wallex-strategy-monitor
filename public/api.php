<?php
require dirname(__DIR__).'/src/App.php';
require dirname(__DIR__).'/src/AlertHistory.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
$host=$_SERVER['HTTP_HOST']??'';
if(!in_array($host,['127.0.0.1:8080','localhost:8080','127.0.0.1:5078','localhost:5078'],true)) {http_response_code(403);exit('{"error":"Local access only"}');}
session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Strict','use_strict_mode'=>true]);$_SESSION['csrf']??=bin2hex(random_bytes(24));
try {
 if($_SERVER['REQUEST_METHOD']==='GET'&&isset($_GET['mobile_feed'])){
  $userId=MobileAlerts::authenticate(db(),$_SERVER['HTTP_AUTHORIZATION']??'',time());
  $after=isset($_GET['after'])?filter_var($_GET['after'],FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]):null;
  if($after===false)throw new InvalidArgumentException('Invalid cursor');
  echo encode(MobileAlerts::feed(db(),$userId,$after));exit;
 }
 if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_GET['mobile_action'])){
  $header=$_SERVER['HTTP_AUTHORIZATION']??'';$userId=MobileAlerts::authenticate(db(),$header,time());
  if((int)($_SERVER['CONTENT_LENGTH']??0)>10000)throw new InvalidArgumentException('Request too large');
  $body=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);
  if($_GET['mobile_action']==='ack')MobileAlerts::acknowledge(db(),$userId,$body['ids']??[],time());
  elseif($_GET['mobile_action']==='revoke')MobileAlerts::revoke(db(),$header);
  else throw new InvalidArgumentException('Invalid mobile action');
  echo encode(['ok'=>true]);exit;
 }
 if($_SERVER['REQUEST_METHOD']==='GET'&&isset($_GET['comments_before'])){echo encode(['comments'=>publicComments(max(1,(int)$_GET['comments_before']))]);exit;}
 if($_SERVER['REQUEST_METHOD']==='GET'&&isset($_GET['details'])) {
 $id=$_GET['details'];if(!is_string($id)||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$id))throw new InvalidArgumentException('Invalid strategy ID');
 $q=db()->prepare('SELECT version FROM strategies WHERE id=?');$q->execute([$id]);$version=$q->fetchColumn();
 if($version===false){http_response_code(404);throw new RuntimeException('Strategy not found');}
 $q=db()->prepare('SELECT id,version,payload FROM check_logs WHERE strategy_id=? ORDER BY id DESC LIMIT 30');$q->execute([$id]);
 $logs=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$logs[]=['id'=>(int)$r['id'],'version'=>$r['version'],'report'=>json_decode($r['payload'],true)];
 $architecture=MarketArchitecture::config();
 echo encode(['strategy_id'=>$id,'version'=>$version,'logs'=>$logs,'execution_signals'=>ExecutionGate::details(db(),$id),'order_flow'=>OrderFlowAdvisory::safeDetails(db(),$id,$version,OrderFlowConfig::safe()),'architecture'=>['analysis_enabled'=>$architecture['analysis_enabled'],'enabled'=>$architecture['enabled'],'manual_advisory_enabled'=>$architecture['manual_advisory_enabled'],'confirmation_samples'=>$architecture['confirmation_samples'],'max_quote_age_seconds'=>$architecture['max_quote_age_seconds'],'version_approved'=>MarketArchitecture::approved($architecture,$id,$version)]]);exit;
 }
 if($_SERVER['REQUEST_METHOD']==='GET') {$rows=db()->query('SELECT s.*,o.user_id AS author_id,u.name AS author_name FROM strategies s LEFT JOIN strategy_owners o ON o.strategy_id=s.id LEFT JOIN users u ON u.id=o.user_id ORDER BY s.rowid DESC')->fetchAll(PDO::FETCH_ASSOC);$items=[];foreach($rows as $r) {$config=json_decode($r['config'],true);$state=displayedState($r,MarketArchitecture::config());$items[]=['can_manage'=>ownsStrategy($r['id']),'author_name'=>$r['author_name'],'config'=>json_decode($r['config'],true),'state'=>$state,'entry_progress'=>Diagnostics::entryProgress($config,$state,$r['version']),'last_signal'=>strategyLastSignal($r),'version'=>$r['version']];}$alerts=alertHistory(false);$archivedAlerts=alertHistory(true);echo encode(['order_flow_enabled'=>OrderFlowConfig::safe()['enabled'],'order_flow_browser_enabled'=>OrderFlowConfig::safe()['browser_processing_enabled'],'browser_checks'=>defined('HOSTING_BROWSER_CHECKS')&&HOSTING_BROWSER_CHECKS,'csrf'=>$_SESSION['csrf'],'interval'=>(int)setting('interval','60'),'heartbeat'=>(int)setting('heartbeat','0'),'last_cycle'=>(int)setting('last_cycle','0'),'user'=>currentAccount(),'can_manage_settings'=>(currentAccount()['role']??'')==='owner','comments'=>publicComments(),'strategies'=>$items,'alerts'=>$alerts,'archived_alerts'=>$archivedAlerts,'logs'=>(currentAccount()['role']??'')==='owner'?systemLogs():[]]);exit;}
 if($_SERVER['REQUEST_METHOD']!=='POST') throw new RuntimeException('POST required');
 if(!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??'')) {http_response_code(403);throw new RuntimeException('Invalid request token');}
 if((int)($_SERVER['CONTENT_LENGTH']??0)>100000) throw new RuntimeException('Request too large');$input=json_decode(file_get_contents('php://input'),true,64,JSON_THROW_ON_ERROR);$action=$input['action']??'';
 if(in_array($action,['login','register','logout','activate_owner'],true)){echo encode(accountAction($action,$input));exit;}
 if($action==='mobile_register'){$user=requireAccount();rateLimit('mobile_register',10,3600);echo encode(MobileAlerts::register(db(),$user['id'],(string)($input['name']??'Android'),time()));exit;}
 if($action==='notification_received'){
  if(!is_int($input['id']??null))throw new InvalidArgumentException('Invalid alert ID');
  requireAlertOwner($input['id']);SmsFallback::acknowledge(db(),$input['id'],time());echo encode(['ok'=>true]);exit;
 }
 if($action==='test_sms'){
  requireSiteOwner();rateLimit('sms_test',1,300);
  $config=SmsFallback::config();if(empty($config['enabled']))throw new RuntimeException('SMS disabled');
  $sent=SmsFallback::send($config,'پیامک آزمایشی پایش خودکار استراتژی والکس. ارسال پیامک فعال است.');
  systemLog('success','sms.test','پیامک آزمایشی توسط کاوه‌نگار پذیرفته شد.',$sent);echo encode(['ok'=>true]+$sent);exit;
 }
 if($action==='comment'){addPublicComment($input);echo encode(['ok'=>true]);exit;}
 if($action==='delete_comment'){requireSiteOwner();if(!is_int($input['id']??null))throw new InvalidArgumentException('Invalid comment ID');db()->prepare('DELETE FROM comments WHERE id=?')->execute([$input['id']]);echo encode(['ok'=>true]);exit;}
 if(in_array($action,['save','validate','toggle','delete'],true))requireAccount();
 if(in_array($action,['toggle','delete'],true))requireStrategyOwner((string)($input['id']??''));
 if($action==='order_flow_visibility')requireStrategyOwner((string)($input['strategy_id']??''));
 if($action==='acknowledge_execution'){
  requireAccount();ExecutionGate::migrate(db());$q=db()->prepare('SELECT strategy_id FROM execution_signals WHERE id=?');$q->execute([$input['signal_id']??'']);requireStrategyOwner((string)$q->fetchColumn());
 }
 if(in_array($action,['archive_alert','restore_alert','delete_alert'],true)){if(!is_int($input['id']??null))throw new InvalidArgumentException('Invalid alert ID');requireAlertOwner($input['id']);}
 if($action==='clear_alerts')requireAccount();
 if($action==='settings')requireSiteOwner();
 if($action==='order_flow_tick'){session_write_close();$c=OrderFlowConfig::safe();if(!$c['enabled']||!$c['browser_processing_enabled']){echo encode(['status'=>'disabled']);exit;}OrderFlowAdvisory::discover(db(),$c,time());echo encode(OrderFlowAdvisory::step(db(),$c,time()));exit;}
 if($action==='order_flow_visibility'){$c=OrderFlowConfig::safe();$id=$input['strategy_id']??null;$visible=$input['visible']??null;if(!$c['enabled']||!is_string($id)||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$id)||!is_bool($visible))throw new InvalidArgumentException('Invalid Order Flow visibility setting');OrderFlowAdvisory::migrate(db());db()->prepare('INSERT OR REPLACE INTO order_flow_visibility VALUES(?,?)')->execute([$id,(int)$visible]);echo encode(['ok'=>true]);exit;}
 if($action==='acknowledge_execution') {if(!MarketArchitecture::config()['analysis_enabled'])throw new RuntimeException('Execution architecture disabled');if(!is_string($input['signal_id']??null)||!preg_match('/^[a-f0-9]{64}$/D',$input['signal_id']))throw new InvalidArgumentException('Invalid signal ID');ExecutionGate::acknowledge(db(),$input['signal_id'],time());systemLog('success','execution.acknowledged','Execution signal acknowledged',['signal_id'=>$input['signal_id']]);echo encode(['ok'=>true]);exit;}
 if($action==='validate') {Strategy::validate(json_decode($input['json'],true,64,JSON_THROW_ON_ERROR));echo encode(['ok'=>true]);exit;}
 if($action==='tick') {if(!defined('HOSTING_BROWSER_CHECKS')||!HOSTING_BROWSER_CHECKS)throw new RuntimeException('Browser checking is unavailable');session_write_close();require_once dirname(__DIR__).'/src/BrowserRunner.php';echo encode(browserCheck());exit;}
 if($action==='save') {$s=Strategy::validate(json_decode($input['json'],true,64,JSON_THROW_ON_ERROR));$s['enabled']=true;$owner=requireAccount();$q=db()->prepare('SELECT user_id FROM strategy_owners WHERE strategy_id=?');$q->execute([$s['id']]);$previousOwner=$q->fetchColumn();if($previousOwner!==false)requireStrategyOwner($s['id']);$api=new Wallex();$api->price($s['symbol']);db()->beginTransaction();$q=db()->prepare('SELECT version FROM strategies WHERE id=?');$q->execute([$s['id']]);$existing=$q->fetchColumn();if($existing!==false)requireStrategyOwner($s['id']);if($existing!==false && ($input['expected_version']??null)!==$existing) throw new RuntimeException('این شناسه وجود دارد یا نسخه تغییر کرده است؛ ابتدا ویرایش را انتخاب کنید.');db()->prepare('INSERT INTO strategies(id,version,config,state) VALUES(?,?,?,?) ON CONFLICT(id) DO UPDATE SET version=excluded.version,config=excluded.config,state=excluded.state')->execute([$s['id'],bin2hex(random_bytes(12)),encode($s),encode(Strategy::fresh($s,time()))]);db()->prepare('INSERT OR IGNORE INTO strategy_owners(strategy_id,user_id) VALUES(?,?)')->execute([$s['id'],$owner['id']]);db()->commit();}
 elseif($action==='toggle'||$action==='delete') {db()->beginTransaction();$q=db()->prepare('SELECT * FROM strategies WHERE id=?');$q->execute([$input['id']]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!$r || $r['version']!==($input['expected_version']??'')) throw new RuntimeException('نسخه تغییر کرده است؛ صفحه را تازه کنید.');if($action==='delete') db()->prepare('DELETE FROM strategies WHERE id=?')->execute([$input['id']]);else {$s=json_decode($r['config'],true);$s['enabled']=isset(json_decode($r['state'],true)['cancelled_at'])?true:!$s['enabled'];db()->prepare('UPDATE strategies SET version=?,config=?,state=? WHERE id=?')->execute([bin2hex(random_bytes(12)),encode($s),encode(Strategy::fresh($s,time())),$input['id']]);}db()->commit();}
 elseif($action==='archive_alert'||$action==='restore_alert') {if(!is_int($input['id']??null))throw new InvalidArgumentException('Invalid alert ID');archiveAlert($input['id'],$action==='archive_alert');}
 elseif($action==='delete_alert'||$action==='clear_alerts') {deleteAlertHistory($action,$input['id']??null,requireAccount()['id']);}
 elseif($action==='settings') {$n=$input['interval']??null;if(!is_int($n)||$n<5||$n>3600) throw new RuntimeException('فاصله باید عدد صحیح بین ۵ تا ۳۶۰۰ ثانیه باشد.');setSetting('interval',(string)$n);}
 else throw new RuntimeException('Unknown action');systemLog('success','api.'.$action,'عملیات با موفقیت انجام شد',['strategy_id'=>$input['id']??$s['id']??null,'interval'=>$input['interval']??null]);echo encode(['ok'=>true]);
} catch(Throwable $e) {if(db()->inTransaction()) db()->rollBack();systemLog('error','api.'.($action??'read'),$e->getMessage(),['strategy_id'=>$input['id']??null]);if(http_response_code()<400) http_response_code(400);echo encode(['error'=>$e->getMessage()]);}
