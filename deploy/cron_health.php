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
 preg_match_all('/^\d+:\s+FLOCK\s+ADVISORY\s+WRITE\s+(\d+)\s+\S+:(\d+)\s+/m',$locks,$matches,PREG_SET_ORDER);
 foreach($matches as $match){
  $pid=(int)$match[1];if((int)$match[2]!==$stat['ino'])continue;
  $exact=false;foreach(glob('/proc/'.$pid.'/fd/*')?:[] as $fd)if(@readlink($fd)===$path){$exact=true;break;}if(!$exact)continue;
  $args=explode("\0",@file_get_contents('/proc/'.$pid.'/cmdline')?:'');$cwd=@readlink('/proc/'.$pid.'/cwd');$script=null;
  foreach($args as $arg)foreach(['cron.php','worker.php'] as $name)if($arg===$root.'/'.$name||($cwd===$root&&$arg===$name))$script=$name;
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
