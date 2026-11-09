<?php
// Run suites in separate processes so their storage and helper functions stay isolated.
$suites=['run.php','integration.php','wallex.php','trade.php','history.php','cancellation.php','four-hour.php','diagnostics.php','hosting.php'];
foreach($suites as $suite){
 passthru(escapeshellarg(PHP_BINARY).' -d error_reporting=32767 '.escapeshellarg(__DIR__.'/'.$suite),$code);
 if($code!==0)exit($code);
}
echo "All PHP suites passed.\n";
