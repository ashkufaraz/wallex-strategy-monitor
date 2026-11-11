<?php
if(PHP_SAPI!=='cli')exit;
// Diagnostics never opens the application's persistent SQLite database.
define('TEST_DATABASE',':memory:');require dirname(__DIR__).'/src/App.php';
$symbol=$argv[1]??'BTCUSDT';$action=$argv[2]??'LONG';$quantity=$argv[3]??null;
$out=['read_only'=>true,'persistent_database'=>false,'checked_at_utc'=>gmdate('c')];
try{
 if($quantity!==null&&(!is_numeric($quantity)||!is_finite((float)$quantity)||(float)$quantity<=0))throw new InvalidArgumentException('Quantity must be a positive base-asset number');
 $api=new Wallex(microtime(true)+15);$provider=new WallexExecutionMarketProvider($api,MarketArchitecture::defaults());
 $out['snapshot']=$provider->snapshot($symbol,time());
 if($quantity!==null)$out['estimate']=WallexExecutionMarketProvider::estimate($out['snapshot'],$action,(float)$quantity);
 $out['ok']=true;
}catch(Throwable $e){$out['ok']=false;$out['error']=$e->getMessage();}
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
exit($out['ok']?0:1);
