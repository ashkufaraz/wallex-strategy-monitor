<?php
declare(strict_types=1);

function accountSchema(PDO $db):void {
 $db->exec("CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY AUTOINCREMENT,email TEXT UNIQUE NOT NULL,name TEXT NOT NULL,password_hash TEXT,role TEXT NOT NULL DEFAULT 'member',created INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS strategy_owners(strategy_id TEXT PRIMARY KEY,user_id INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS alert_owners(alert_id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS request_limits(scope TEXT NOT NULL,identity TEXT NOT NULL,window INTEGER NOT NULL,count INTEGER NOT NULL,PRIMARY KEY(scope,identity));
 CREATE TABLE IF NOT EXISTS comments(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL,body TEXT NOT NULL,created INTEGER NOT NULL);
 CREATE TRIGGER IF NOT EXISTS alert_owner_snapshot AFTER INSERT ON alerts BEGIN INSERT OR IGNORE INTO alert_owners(alert_id,user_id) SELECT NEW.id,user_id FROM strategy_owners WHERE strategy_id=json_extract(NEW.payload,'$.strategy_id'); END;");
 $db->prepare("INSERT OR IGNORE INTO users(email,name,password_hash,role,created) VALUES(?,?,NULL,'owner',?)")->execute(['ashkufaraz@gmail.com','سیدمجتبی اشکوفراز',time()]);
 $id=(int)$db->query("SELECT id FROM users WHERE email='ashkufaraz@gmail.com'")->fetchColumn();
 // Existing strategies belong to the reserved owner, never to the first visitor.
 $db->prepare('INSERT OR IGNORE INTO strategy_owners SELECT id,? FROM strategies')->execute([$id]);
 $db->prepare('INSERT OR IGNORE INTO alert_owners SELECT a.id,COALESCE(o.user_id,?) FROM alerts a LEFT JOIN strategy_owners o ON o.strategy_id=json_extract(a.payload,\'$.strategy_id\')')->execute([$id]);
}
function currentAccount():?array {
 $id=$_SESSION['user_id']??null;
 if(!$id||time()-(int)($_SESSION['auth_at']??0)>86400)return null;
 $q=db()->prepare('SELECT id,name,email,role FROM users WHERE id=? AND password_hash IS NOT NULL');$q->execute([$id]);$user=$q->fetch(PDO::FETCH_ASSOC);
 if(!$user)return null;$user['id']=(int)$user['id'];return $user;
}
function requireAccount():array {
 $user=currentAccount();if(!$user){http_response_code(401);throw new RuntimeException('برای این کار ابتدا وارد حساب شوید.');}return $user;
}
function requireSiteOwner():array {
 $user=requireAccount();if($user['role']!=='owner'){http_response_code(403);throw new RuntimeException('این تنظیم فقط در اختیار مدیر سایت است.');}return $user;
}
function ownsStrategy(string $id,?array $user=null):bool {
 $user??=currentAccount();if(!$user)return false;
 $q=db()->prepare('SELECT user_id FROM strategy_owners WHERE strategy_id=?');$q->execute([$id]);return (int)$q->fetchColumn()===$user['id'];
}
function requireStrategyOwner(string $id):array {
 $user=requireAccount();if(!ownsStrategy($id,$user)){http_response_code(403);throw new RuntimeException('فقط ثبت‌کنندهٔ استراتژی می‌تواند آن را مدیریت کند.');}return $user;
}
function ownsAlert(int $id,?array $user=null):bool {
 $user??=currentAccount();if(!$user)return false;
 $q=db()->prepare('SELECT user_id FROM alert_owners WHERE alert_id=?');$q->execute([$id]);return (int)$q->fetchColumn()===$user['id'];
}
function requireAlertOwner(int $id):void {
 $user=requireAccount();if(!ownsAlert($id,$user)){http_response_code(403);throw new RuntimeException('این هشدار متعلق به استراتژی شما نیست.');}
}
function rateLimit(string $scope,int $limit,int $seconds,string $extra=''):void {
 $key=hash('sha256',($_SERVER['REMOTE_ADDR']??'local').'|'.$extra);$now=time();
 db()->prepare('INSERT INTO request_limits(scope,identity,window,count) VALUES(?,?,?,1) ON CONFLICT(scope,identity) DO UPDATE SET count=CASE WHEN window<=? THEN 1 ELSE count+1 END,window=CASE WHEN window<=? THEN excluded.window ELSE window END')->execute([$scope,$key,$now,$now-$seconds,$now-$seconds]);
 $q=db()->prepare('SELECT count FROM request_limits WHERE scope=? AND identity=?');$q->execute([$scope,$key]);
 if((int)$q->fetchColumn()>$limit){http_response_code(429);throw new RuntimeException('تعداد درخواست‌ها زیاد است؛ کمی بعد دوباره تلاش کنید.');}
 db()->prepare('DELETE FROM request_limits WHERE window<?')->execute([$now-86400]);
}
function accountCredentials(array $input,bool $new):array {
 $email=is_string($input['email']??null)?strtolower(trim($input['email'])):'';
 $password=$input['password']??null;
 if(strlen($email)>254||!filter_var($email,FILTER_VALIDATE_EMAIL)||!is_string($password)||strlen($password)>72||strlen($password)<($new?10:1)||str_contains($password,"\0"))throw new InvalidArgumentException('ایمیل معتبر و رمز عبور ۱۰ تا ۷۲ نویسه‌ای وارد کنید.');
 return [$email,$password];
}
function signedIn(int $id):void {
 session_regenerate_id(true);$_SESSION['user_id']=$id;$_SESSION['auth_at']=time();$_SESSION['csrf']=bin2hex(random_bytes(24));
}
function accountAction(string $action,array $input):array {
 if($action==='logout'){$_SESSION=[];session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(24));return ['ok'=>true,'csrf'=>$_SESSION['csrf']];}
 rateLimit('account',20,900);
 [$email,$password]=accountCredentials($input,$action!=='login');
 $q=db()->prepare('SELECT * FROM users WHERE email=?');$q->execute([$email]);$existing=$q->fetch(PDO::FETCH_ASSOC);
 if($action==='login'){
  $hash=$existing['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
  if(!password_verify($password,$hash)||!$existing||empty($existing['password_hash'])){http_response_code(401);throw new RuntimeException('ایمیل یا رمز عبور صحیح نیست.');}
  if(password_needs_rehash($hash,PASSWORD_DEFAULT))db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$existing['id']]);
  signedIn((int)$existing['id']);
 }elseif($action==='register'){
  rateLimit('register',5,3600);
  $name=is_string($input['name']??null)?trim($input['name']):'';
  if($name===''||strlen($name)>160)throw new InvalidArgumentException('نام را وارد کنید (حداکثر ۸۰ حرف).');
  if($existing)throw new InvalidArgumentException('این حساب قابل ثبت مجدد نیست؛ وارد شوید.');
  db()->prepare('INSERT INTO users(email,name,password_hash,created) VALUES(?,?,?,?)')->execute([$email,$name,password_hash($password,PASSWORD_DEFAULT),time()]);signedIn((int)db()->lastInsertId());
 }elseif($action==='activate_owner'){
  $path=dirname(__DIR__).'/config/owner-bootstrap.php';$config=is_file($path)?require $path:[];
  $token=$input['token']??null;
  if(!$existing||$existing['role']!=='owner'||$existing['password_hash']!==null||!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token)||!isset($config['token_hash'])||!hash_equals($config['token_hash'],hash('sha256',$token))){http_response_code(403);throw new RuntimeException('لینک فعال‌سازی معتبر نیست یا قبلاً استفاده شده است.');}
  $q=db()->prepare('UPDATE users SET password_hash=? WHERE id=? AND password_hash IS NULL');$q->execute([password_hash($password,PASSWORD_DEFAULT),$existing['id']]);
  if(!$q->rowCount())throw new RuntimeException('این حساب قبلاً فعال شده است.');signedIn((int)$existing['id']);
 }else throw new InvalidArgumentException('Unknown account action');
 return ['ok'=>true,'csrf'=>$_SESSION['csrf'],'user'=>currentAccount()];
}
function publicComments(?int $before=null):array {
 $q=db()->prepare('SELECT id,name,body,created FROM comments WHERE id<? ORDER BY id DESC LIMIT 50');$q->bindValue(1,$before??PHP_INT_MAX,PDO::PARAM_INT);$q->execute();return $q->fetchAll(PDO::FETCH_ASSOC);
}
function addPublicComment(array $input):void {
 rateLimit('comment',5,600);
 $name=is_string($input['name']??null)?trim($input['name']):'';$body=is_string($input['body']??null)?trim($input['body']):'';
 if($name===''||strlen($name)>160||$body===''||strlen($body)>6000)throw new InvalidArgumentException('نام و متن نظر را وارد کنید؛ متن حداکثر ۲۰۰۰ حرف باشد.');
 db()->prepare('INSERT INTO comments(name,body,created) VALUES(?,?,?)')->execute([$name,$body,time()]);
}
