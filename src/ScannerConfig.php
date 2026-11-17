<?php
declare(strict_types=1);

final class ScannerConfig {
 const STRATEGY='wallex-break-retest-v1';
 const RULES_VERSION='1.2.0';
 static function defaults():array {return [
  'enabled'=>true,'symbol'=>'BTCUSDT','capital'=>70.0,'risk_pct'=>1.0,
  'ema_fast'=>20,'ema_slow'=>50,'atr_period'=>14,'level_lookback'=>20,'level_touches'=>2,'touch_separation'=>2,
  'level_tolerance_atr'=>0.25,'break_buffer_atr'=>0.10,'retest_tolerance_atr'=>0.25,'confirm_buffer_atr'=>0.05,
  'volume_period'=>20,'break_volume_ratio'=>1.5,'confirm_volume_ratio'=>1.0,
  'setup_bars'=>8,'entry_expiry_seconds'=>1800,'manual_delay_seconds'=>60,
  'entry_band_atr'=>0.30,'structure_buffer_atr'=>0.20,'stop_atr'=>1.5,'max_stop_atr'=>3.0,
  'tp1_r'=>2.5,'tp2_r'=>4.0,'min_net_rr'=>2.0,'max_spread_bps'=>20.0,
  'tp1_exit_pct'=>100.0,'tp2_exit_pct'=>0.0,'stop_after_tp1'=>'POSITION_CLOSED',
  'entry_fee_bps'=>10.0,'exit_fee_bps'=>10.0,'slippage_bps'=>5.0,'fixed_cost_usdt'=>0.0,'financing_bps_day'=>null,
  'quote_max_age_seconds'=>10,'max_paper_seconds'=>86400
  ,'fees_verified'=>false,'fee_reference'=>'','observation_interval_seconds'=>120,'evaluation_id'=>'btc-v1-001'
 ];}
 static function validate(array $input):array {
  $default=self::defaults();foreach($input as $k=>$v)if(!array_key_exists($k,$default))throw new InvalidArgumentException('Unknown scanner setting: '.$k);
  $c=array_replace($default,$input);
  if(!is_bool($c['enabled'])||$c['symbol']!=='BTCUSDT')throw new InvalidArgumentException('Scanner v1 supports BTCUSDT only');
  if(!is_bool($c['fees_verified'])||!is_string($c['fee_reference'])||strlen($c['fee_reference'])>300||($c['fees_verified']&&trim($c['fee_reference'])==='')||!is_int($c['observation_interval_seconds'])||$c['observation_interval_seconds']<55||$c['observation_interval_seconds']>3600||!is_string($c['evaluation_id'])||!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$c['evaluation_id']))throw new InvalidArgumentException('Invalid scanner fee evidence, cadence or evaluation id');
  if(!is_numeric($c['tp1_exit_pct'])||!is_numeric($c['tp2_exit_pct'])||(float)$c['tp1_exit_pct']!==100.0||(float)$c['tp2_exit_pct']!==0.0||$c['stop_after_tp1']!=='POSITION_CLOSED')throw new InvalidArgumentException('Scanner v1 exits 100% at TP1 and 0% at informational TP2; partial exits are not implemented');
  $c['tp1_exit_pct']=100.0;$c['tp2_exit_pct']=0.0;
  $ints=['ema_fast'=>[2,100],'ema_slow'=>[3,200],'atr_period'=>[2,100],'level_lookback'=>[5,100],'level_touches'=>[1,10],'touch_separation'=>[1,20],'volume_period'=>[2,100],'setup_bars'=>[2,32],'entry_expiry_seconds'=>[120,14400],'manual_delay_seconds'=>[1,3600],'quote_max_age_seconds'=>[1,30],'max_paper_seconds'=>[900,604800]];
  foreach($ints as $k=>[$min,$max])if(!is_int($c[$k])||$c[$k]<$min||$c[$k]>$max)throw new InvalidArgumentException('Invalid scanner setting: '.$k);
  $ranges=['capital'=>[10,1000000],'risk_pct'=>[0.1,5],'level_tolerance_atr'=>[0.01,1],'break_buffer_atr'=>[0.01,2],'retest_tolerance_atr'=>[0.01,2],'confirm_buffer_atr'=>[0,1],'break_volume_ratio'=>[1,10],'confirm_volume_ratio'=>[0.1,10],'entry_band_atr'=>[0.01,2],'structure_buffer_atr'=>[0,2],'stop_atr'=>[0.1,10],'max_stop_atr'=>[0.2,20],'tp1_r'=>[1,10],'tp2_r'=>[1,20],'min_net_rr'=>[0.5,10],'max_spread_bps'=>[0.1,200],'entry_fee_bps'=>[0,100],'exit_fee_bps'=>[0,100],'slippage_bps'=>[0,100],'fixed_cost_usdt'=>[0,10],'financing_bps_day'=>[0,100]];
  foreach($ranges as $k=>[$min,$max]){if($k==='financing_bps_day'&&$c[$k]===null)continue;if((!is_int($c[$k])&&!is_float($c[$k]))||!is_finite((float)$c[$k])||$c[$k]<$min||$c[$k]>$max)throw new InvalidArgumentException('Invalid scanner setting: '.$k);$c[$k]=(float)$c[$k];}
  if($c['ema_fast']>=$c['ema_slow']||$c['tp2_r']<=$c['tp1_r']||$c['max_stop_atr']<$c['stop_atr']||$c['manual_delay_seconds']>=$c['entry_expiry_seconds'])throw new InvalidArgumentException('Inconsistent scanner periods, targets, stop or delay');
  return $c;
 }
 static function version(array $c):string {unset($c['enabled']);if(class_exists('ScannerReadiness'))$c['_code_version']=ScannerReadiness::codeVersion();return self::STRATEGY.':'.self::RULES_VERSION.':'.substr(hash('sha256',json_encode($c,JSON_THROW_ON_ERROR)),0,16);}
 static function counts(array $c):array {return ['4h'=>5*$c['ema_slow']+2,'1h'=>max(5*$c['atr_period']+2,$c['level_lookback']+4),'15m'=>max(5*$c['atr_period']+2,$c['volume_period']+4)];}
 static function operational(array $c):void {
  foreach(['ema_fast'=>20,'ema_slow'=>50,'atr_period'=>14,'volume_period'=>20,'level_lookback'=>20,'capital'=>70,'risk_pct'=>1,'manual_delay_seconds'=>60] as $key=>$v)if($c[$key]!=$v)throw new InvalidArgumentException('Operational evaluation requires '.$key.'='.$v.'; short synthetic fixtures cannot be activated');
  if($c['fees_verified']&&str_contains(strtoupper($c['fee_reference']),'SYNTHETIC'))throw new InvalidArgumentException('Synthetic fee evidence cannot activate a live period');
 }
}
