<?php
declare(strict_types=1);

function deleteAlertHistory(string $action,mixed $id):int {
 if(!is_int($id)||$id<1) throw new InvalidArgumentException('Invalid alert ID');
 $sql=match($action){
  'delete_alert'=>'DELETE FROM alerts WHERE id=?',
  'clear_alerts'=>'DELETE FROM alerts WHERE id<=?',
  default=>throw new InvalidArgumentException('Unknown alert history action')
 };
 $q=db()->prepare($sql);$q->execute([$id]);return $q->rowCount();
}
