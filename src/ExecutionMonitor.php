<?php
declare(strict_types=1);

final class ExecutionMonitor {
 static function pending(PDO $db,string $id,string $version):bool {
  $q=$db->prepare("SELECT 1 FROM execution_signals WHERE strategy_id=? AND version=? AND status NOT IN ('CANCELLED_STOP_LOSS','CANCELLED_INVALIDATION','EXPIRED_TP1_REACHED','EXPIRED_TIMEOUT','MANUALLY_EXECUTED')");$q->execute([$id,$version]);return (bool)$q->fetchColumn();
 }
 static function run(BinanceMarketDataProvider $api,int $now,array $c,?ExecutionQuoteProvider $execution=null):void {
  $db=db();ExecutionGate::migrate($db);$execution??=new WallexExecutionMarketProvider(new Wallex(microtime(true)+8),$c);
  $symbols=[];foreach($db->query('SELECT config FROM strategies')->fetchAll(PDO::FETCH_COLUMN) as $raw){$s=json_decode($raw,true);if($s['enabled'])$symbols[$s['symbol']]=true;}
  $pending=$db->query("SELECT * FROM execution_signals WHERE status NOT IN ('CANCELLED_STOP_LOSS','CANCELLED_INVALIDATION','EXPIRED_TP1_REACHED','EXPIRED_TIMEOUT','MANUALLY_EXECUTED')")->fetchAll(PDO::FETCH_ASSOC);
  foreach($pending as $row)$symbols[json_decode($row['payload'],true)['symbol']]=true;
  $markets=[];
  foreach(array_keys($symbols) as $symbol){$m=['reference'=>null,'wallex'=>null,'sample'=>null,'error'=>null];
   if($c['enabled']||ExecutionGate::advisory($c))try{
    $instrument=$c['instrument'];if(!ExecutionGate::advisory($c)&&(($instrument['verified']??false)!==true||($instrument['type']??null)!=='spot'||($instrument['reference_type']??null)!=='spot'||($instrument['symbol']??null)!==$symbol))throw new RuntimeException('Matching instrument mapping not verified; basis collection disabled');
    $m['reference']=$api->quote($symbol);$m['wallex']=ExecutionGate::advisory($c)&&$execution instanceof WallexExecutionMarketProvider?$execution->snapshot($symbol,max($now,$api->now())):$execution->quote($symbol,max($now,$api->now()));$m['sample']=ExecutionGate::sample($db,$symbol,$m['reference'],$m['wallex'],max($now,$api->now()),$c);
   }catch(Throwable $e){$m['error']=$e->getMessage();}
   $m['stats']=ExecutionGate::statistics($db,$symbol,max($now,$api->now()),$c);$markets[$symbol]=$m;
  }
  foreach($pending as $row){$before=json_decode($row['payload'],true);$m=$markets[$before['symbol']];$validity=[];
   if($now<$before['deadline'])try{$validity=$api->withBudget(12,fn()=>ExecutionGate::validity($before,$api,max($now,$api->now())));}catch(Throwable $e){$validity=['error'=>$e->getMessage()];}
   if($m['error']&&!isset($validity['status']))$validity['error']=$m['error'];
   $checked=max($now,$api->now());$after=ExecutionGate::decide($before,$c,$checked,$m['reference'],$m['wallex'],$m['sample'],$m['stats'],$validity);
   $db->beginTransaction();try{$q=$db->prepare('SELECT payload FROM execution_signals WHERE id=?');$q->execute([$before['id']]);if($q->fetchColumn()===$row['payload'])ExecutionGate::persist($db,$before,$after,$checked);$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
  }
 }
}
