<?php
// Explicit CLI action only: each invocation sends one paid test SMS.
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/src/SmsFallback.php';
try {
 $sent=SmsFallback::send(SmsFallback::config(),'پیامک آزمایشی پایش خودکار استراتژی والکس. ارسال پیامک فعال است.');
 echo json_encode($sent,JSON_UNESCAPED_UNICODE)."\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
