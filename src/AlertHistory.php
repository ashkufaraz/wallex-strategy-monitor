<?php
declare(strict_types=1);

function archiveAlert(int $id,bool $archived):void {
 if($id<1)throw new InvalidArgumentException('Invalid alert ID');
 if($archived){
  $q=db()->prepare('INSERT OR IGNORE INTO alert_archives(alert_id) SELECT id FROM alerts WHERE id=?');$q->execute([$id]);
 }else{db()->prepare('DELETE FROM alert_archives WHERE alert_id=?')->execute([$id]);}
}
function alertHistory(bool $archived):array {
 $q=db()->prepare('SELECT a.*,EXISTS(SELECT 1 FROM alert_archives x WHERE x.alert_id=a.id) AS archived FROM alerts a WHERE EXISTS(SELECT 1 FROM alert_archives x WHERE x.alert_id=a.id)=? ORDER BY a.id DESC LIMIT 100');
 $q->bindValue(1,(int)$archived,PDO::PARAM_INT);$q->execute();$items=[];
 foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$items[]=['id'=>(int)$r['id'],'created'=>(int)$r['created'],'archived'=>(bool)$r['archived'],'payload'=>json_decode($r['payload'],true)];
 return $items;
}

function deleteAlertHistory(string $action,mixed $id):int {
 if(!is_int($id)||$id<1) throw new InvalidArgumentException('Invalid alert ID');
 $sql=match($action){
  'delete_alert'=>'DELETE FROM alerts WHERE id=?',
  'clear_alerts'=>'DELETE FROM alerts WHERE id<=? AND id NOT IN (SELECT alert_id FROM alert_archives)',
  default=>throw new InvalidArgumentException('Unknown alert history action')
 };
 $q=db()->prepare($sql);$q->execute([$id]);$count=$q->rowCount();
 db()->exec('DELETE FROM alert_archives WHERE alert_id NOT IN (SELECT id FROM alerts)');return $count;
}
