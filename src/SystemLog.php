<?php
declare(strict_types=1);

function systemLogTable():void {
 db()->exec('CREATE TABLE IF NOT EXISTS system_logs(id INTEGER PRIMARY KEY AUTOINCREMENT,created INTEGER NOT NULL,level TEXT NOT NULL,event TEXT NOT NULL,message TEXT NOT NULL,context TEXT NOT NULL)');
}
function systemLog(string $level,string $event,string $message,array $context=[]):void {
 static $writing=false;
 if($writing)return;
 $writing=true;
 try {
  systemLogTable();
  db()->prepare('INSERT INTO system_logs(created,level,event,message,context) VALUES(?,?,?,?,?)')->execute([time(),$level,$event,$message,encode($context)]);
  db()->exec('DELETE FROM system_logs WHERE id <= (SELECT MAX(id)-10000 FROM system_logs)');
 }catch(Throwable $e){error_log('System log failed: '.$e->getMessage());}
 finally{$writing=false;}
}
function systemLogs():array {
 systemLogTable();
 $rows=db()->query('SELECT * FROM system_logs ORDER BY id DESC LIMIT 300')->fetchAll(PDO::FETCH_ASSOC);
 foreach($rows as &$row){$row['created']=(int)$row['created'];$row['context']=json_decode($row['context'],true);}
 return $rows;
}
set_error_handler(function(int $severity,string $message,string $file,int $line):bool {
 if(!(error_reporting() & $severity))return false;
 systemLog(in_array($severity,[E_WARNING,E_USER_WARNING],true)?'warning':'error','php.runtime',$message,['file'=>basename($file),'line'=>$line]);
 return false;
});
register_shutdown_function(function():void {
 $error=error_get_last();
 if($error&&in_array($error['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR],true))systemLog('error','php.fatal',$error['message'],['file'=>basename($error['file']),'line'=>$error['line']]);
});
