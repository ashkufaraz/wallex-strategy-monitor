<?php
declare(strict_types=1);

final class StrategyStore {
 static function save(array $input,int $ownerId,?string $expectedVersion,int $now):array {
  $s=Strategy::validate($input);$s['exchange']=MarketScope::exchange($s['exchange']??'wallex');$originalId=$s['id'];$cloned=false;
  db()->beginTransaction();try{
   $q=db()->prepare('SELECT s.*,o.user_id FROM strategies s LEFT JOIN strategy_owners o ON o.strategy_id=s.id WHERE s.id=?');$q->execute([$originalId]);$old=$q->fetch(PDO::FETCH_ASSOC);
   if($old){if((int)$old['user_id']!==$ownerId)throw new RuntimeException('Strategy ownership mismatch');if($old['version']!==$expectedVersion)throw new RuntimeException('Strategy version changed; reload before saving');$oldConfig=json_decode($old['config'],true);if(($oldConfig['exchange']??'wallex')!==$s['exchange']){$s['id']=substr($originalId,0,40).'_'.$s['exchange'].'_'.bin2hex(random_bytes(5));$cloned=true;}}
   $version=bin2hex(random_bytes(12));$state=Strategy::fresh($s,$now);
   db()->prepare('INSERT INTO strategies(id,version,config,state) VALUES(?,?,?,?) ON CONFLICT(id) DO UPDATE SET version=excluded.version,config=excluded.config,state=excluded.state')->execute([$s['id'],$version,encode($s),encode($state)]);
   db()->prepare('INSERT OR IGNORE INTO strategy_owners VALUES(?,?)')->execute([$s['id'],$ownerId]);db()->commit();return ['id'=>$s['id'],'version'=>$version,'exchange'=>$s['exchange'],'created_new_instance'=>$cloned,'preserved_strategy_id'=>$cloned?$originalId:null];
  }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
 }
}
