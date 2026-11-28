<?php
// Temporary authenticated maintenance probe, uploaded and removed by cron_health.py.
if(!hash_equals('__TOKEN__',$_SERVER['HTTP_X_PROBE_TOKEN']??'')){http_response_code(404);exit;}
header('Content-Type: application/json');
$root=__DIR__;$db=new PDO('sqlite:'.$root.'/data/app.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$heartbeat=(int)$db->query("SELECT v FROM settings WHERE k='heartbeat'")->fetchColumn();
$path=realpath($root.'/data/worker.lock');$handle=fopen($root.'/data/worker.lock','c');$free=flock($handle,LOCK_EX|LOCK_NB);
$result=['now'=>time(),'heartbeat'=>$heartbeat,'heartbeat_age'=>time()-$heartbeat,'lock_free'=>$free,'holders'=>[]];
if($free)flock($handle,LOCK_UN);
else {
 $stat=fstat($handle);$locks=@file_get_contents('/proc/locks')?:'';
 // An orphaned child can retain an inherited flock even after its original PID exits.
 foreach(glob('/proc/[0-9]*',GLOB_ONLYDIR)?:[] as $proc){
  $pid=(int)basename($proc);if($pid===getmypid()||!function_exists('posix_geteuid')||@fileowner($proc)!==posix_geteuid())continue;
  $exact=false;foreach(glob($proc.'/fd/*')?:[] as $fd)if(@readlink($fd)===$path&&str_contains(@file_get_contents($proc.'/fdinfo/'.basename($fd))?:'','FLOCK')){$exact=true;break;}if(!$exact)continue;
  $args=explode("\0",@file_get_contents('/proc/'.$pid.'/cmdline')?:'');$cwd=@readlink('/proc/'.$pid.'/cwd');$script=null;
  foreach($args as $arg)foreach(['cron.php','worker.php'] as $name)if($arg===$root.'/'.$name||($cwd===$root&&$arg===$name))$script=$name;
  $exe=preg_replace('/ \(deleted\)$/','',@readlink($proc.'/exe')?:'');
  if($exe===$root.'/config/candle-proxy/xray')$script='inherited-xray-lock';
  $elapsed=function_exists('shell_exec')?(int)trim((string)shell_exec('ps -o etimes= -p '.$pid)):0;
  $own=function_exists('posix_geteuid')&&@fileowner('/proc/'.$pid)===posix_geteuid();
  $holder=['pid'=>$pid,'script'=>$script,'elapsed_seconds'=>$elapsed,'same_owner'=>$own,'exact_lock_file'=>$exact];
  if('__RECOVER__'==='true'&&$script&&$own&&$elapsed>300&&time()-$heartbeat>600){
   $holder['terminated']=function_exists('posix_kill')?posix_kill($pid,15):false;
  }
  $result['holders'][]=$holder;
 }
}
fclose($handle);echo json_encode($result,JSON_THROW_ON_ERROR);
