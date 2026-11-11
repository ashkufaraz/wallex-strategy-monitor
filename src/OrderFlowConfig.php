<?php
declare(strict_types=1);

final class OrderFlowConfig {
 static function defaults():array{return ['enabled'=>false,'browser_processing_enabled'=>false,'windows'=>[60,300],'baseline_windows'=>10,'book_band_bps'=>5.,'depth_limit'=>1000,'max_pages'=>12,'max_age_seconds'=>90,'max_signal_book_lag_seconds'=>15,'refresh_seconds'=>60,'request_timeout_ms'=>1500,'step_interval_seconds'=>3,'max_weight_per_minute'=>120,'taker_threshold'=>.15,'book_threshold'=>.15,'relative_volume_high'=>1.5,'relative_volume_low'=>.7,'strategy_enabled'=>[]];}
 static function validate(array $input):array {
  if(array_diff(array_keys($input),array_keys(self::defaults())))throw new InvalidArgumentException('Unknown Order Flow setting');
  $c=array_replace(self::defaults(),$input);
  if(!is_bool($c['enabled'])||!is_bool($c['browser_processing_enabled'])||!is_array($c['windows'])||!array_is_list($c['windows'])||!$c['windows']||array_diff($c['windows'],[60,300])||count(array_unique($c['windows']))!==count($c['windows']))throw new InvalidArgumentException('Order Flow supports unique 60/300 second windows');
  foreach($c['windows'] as $window)if(!is_int($window))throw new InvalidArgumentException('Window seconds must be integers');
  foreach(['baseline_windows'=>[2,30],'depth_limit'=>[100,1000],'max_pages'=>[1,20],'max_age_seconds'=>[15,180],'max_signal_book_lag_seconds'=>[1,30],'refresh_seconds'=>[60,3600],'request_timeout_ms'=>[200,2000],'step_interval_seconds'=>[3,60],'max_weight_per_minute'=>[50,300]] as $k=>[$min,$max])if(!is_int($c[$k])||$c[$k]<$min||$c[$k]>$max)throw new InvalidArgumentException('Invalid Order Flow '.$k);
  foreach(['book_band_bps'=>[.1,100.],'taker_threshold'=>[.01,.99],'book_threshold'=>[.01,.99],'relative_volume_high'=>[1.,10.],'relative_volume_low'=>[.01,1.]] as $k=>[$min,$max])if(!is_numeric($c[$k])||!is_finite((float)$c[$k])||$c[$k]<$min||$c[$k]>$max)throw new InvalidArgumentException('Invalid Order Flow '.$k);
  if($c['relative_volume_low']>=$c['relative_volume_high']||!is_array($c['strategy_enabled']))throw new InvalidArgumentException('Invalid Order Flow thresholds or strategies');
  foreach($c['strategy_enabled'] as $id=>$enabled)if(!is_string($id)||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$id)||!is_bool($enabled))throw new InvalidArgumentException('Invalid Order Flow strategy override');
  return $c;
 }
 static function get():array {
  if(defined('TEST_ORDER_FLOW_CONFIG'))return self::validate(TEST_ORDER_FLOW_CONFIG);
  $file=dirname(__DIR__).'/config/order-flow.json';return self::validate(is_file($file)?json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR):[]);
 }
 // Auxiliary config errors must never break primary polling/notifications.
 static function safe():array{try{return self::get();}catch(Throwable $e){return self::defaults();}}
 static function forStrategy(array $c,string $id):bool{return $c['enabled']&&($c['strategy_enabled'][$id]??true);}
}
