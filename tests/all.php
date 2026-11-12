<?php
// Run suites in separate processes so their storage and helper functions stay isolated.
$suites=['run.php','integration.php','wallex.php','trade.php','history.php','cancellation.php','four-hour.php','diagnostics.php','hosting.php','browser-runner.php','indicators.php','indicator-integration.php','indicator-consistency.php','candle-aggregation.php','network-diagnostics.php'];
$suites[]='candle-fields.php';
$suites[]='multi-market.php';
$suites[]='execution-gate.php';
$suites[]='binance-integration.php';
$suites[]='binance-reference.php';
$suites[]='execution-monitor.php';
$suites[]='wallex-orderbook.php';
$suites[]='manual-advisory.php';
$suites[]='manual-advisory-integration.php';
$suites[]='order-flow.php';
$suites[]='order-flow-jobs.php';
$suites[]='order-flow-integration.php';
foreach($suites as $suite){
 passthru(escapeshellarg(PHP_BINARY).' -d error_reporting=32767 '.escapeshellarg(__DIR__.'/'.$suite),$code);
 if($code!==0)exit($code);
}
echo "All PHP suites passed.\n";
