<?php
require dirname(__DIR__).'/deploy/HostingAccess.php';
function hostingCheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
$config=['username'=>'admin','password_hash'=>password_hash('example-password-for-tests',PASSWORD_DEFAULT)];
hostingCheck(hostingPasswordValid($config,'admin','example-password-for-tests'),'hosting password accepted');
hostingCheck(!hostingPasswordValid($config,'admin','wrong'),'wrong hosting password rejected');
hostingCheck(!hostingPasswordValid($config,'other','example-password-for-tests'),'wrong hosting username rejected');
hostingCheck(hostingCredentials(['PHP_AUTH_USER'=>'admin','PHP_AUTH_PW'=>'pass:word'])===['admin','pass:word'],'PHP auth credentials parsed');
hostingCheck(hostingCredentials(['HTTP_AUTHORIZATION'=>'Basic '.base64_encode('admin:pass:word')])===['admin','pass:word'],'Authorization header preserves colon in password');
hostingCheck(hostingCredentials(['HTTP_AUTHORIZATION'=>'Basic !!!!'])===['',''],'invalid Authorization header rejected');
hostingCheck(hostingCredentials([])===['',''],'missing Authorization header rejected');
