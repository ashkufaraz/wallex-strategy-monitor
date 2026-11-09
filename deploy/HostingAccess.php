<?php
declare(strict_types=1);

function hostingCredentials(array $server):array {
 if(isset($server['PHP_AUTH_USER'],$server['PHP_AUTH_PW']))return [$server['PHP_AUTH_USER'],$server['PHP_AUTH_PW']];
 $header=$server['HTTP_AUTHORIZATION']??$server['REDIRECT_HTTP_AUTHORIZATION']??'';
 if(!str_starts_with($header,'Basic '))return ['',''];
 $decoded=base64_decode(substr($header,6),true);
 return $decoded!==false&&str_contains($decoded,':')?explode(':',$decoded,2):['',''];
}
function hostingPasswordValid(array $config,string $username,string $password):bool {
 return hash_equals($config['username'],$username)&&password_verify($password,$config['password_hash']);
}
function requireHostingAccess():void {
 $file=dirname(__DIR__).'/config/hosting.php';
 if(!is_file($file)){http_response_code(503);exit('Hosting configuration is missing.');}
 $config=require $file;
 $host=strtolower($_SERVER['HTTP_HOST']??'');
 if(!in_array($host,$config['hosts'],true)){http_response_code(403);exit('Host not allowed.');}
 if(($_SERVER['HTTPS']??'')!=='on'&&($_SERVER['HTTPS']??'')!=='1'){
  header('Location: https://'.$host.($_SERVER['REQUEST_URI']??'/'),true,308);exit;
 }
 if(($config['public_access']??false)===true)return;
 [$username,$password]=hostingCredentials($_SERVER);
 $throttle=dirname(__DIR__).'/data/auth-'.hash('sha256',$_SERVER['REMOTE_ADDR']??'unknown').'.json';
 $handle=fopen($throttle,'c+');
 if(!$handle||!flock($handle,LOCK_EX)){http_response_code(503);exit('Authentication unavailable.');}
 $raw=stream_get_contents($handle);$attempts=json_decode($raw?:'{}',true)?:[];
 $now=time();if($now-($attempts['since']??0)>=300)$attempts=['since'=>$now,'count'=>0];
 if(($attempts['count']??0)>=10){flock($handle,LOCK_UN);fclose($handle);header('Retry-After: 300');http_response_code(429);exit('Too many login attempts. Try again in five minutes.');}
 $valid=hostingPasswordValid($config,$username,$password);
 if($valid)$attempts=['since'=>$now,'count'=>0];elseif($username!==''||$password!=='')$attempts['count']=($attempts['count']??0)+1;
 rewind($handle);ftruncate($handle,0);fwrite($handle,json_encode($attempts,JSON_THROW_ON_ERROR));flock($handle,LOCK_UN);fclose($handle);
 if(!$valid){header('WWW-Authenticate: Basic realm="Wallex private panel", charset="UTF-8"');http_response_code(401);exit('Authentication required.');}
}
