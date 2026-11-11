<?php
declare(strict_types=1);

final class MarketArchitecture {
 static function defaults():array{return ['analysis_enabled'=>false,'enabled'=>false,'manual_advisory_enabled'=>false,'approved_strategy_versions'=>[],
  'max_wait_seconds'=>900,'basis_window_seconds'=>3600,'calibration_history_days'=>7,'basis_sample_interval_seconds'=>10,
  'confirmation_samples'=>3,'min_samples_60m'=>30,'min_samples_7d'=>100,'min_remaining_rr'=>null,
  'max_quote_age_seconds'=>10,'mad_multiplier'=>3.,'historical_percentile'=>95.,
  'fees_pct'=>null,'slippage_pct'=>null,'instrument'=>null,'orderbook'=>null];}
 static function validate(array $input):array {
  if(array_diff(array_keys($input),array_keys(self::defaults())))throw new InvalidArgumentException('Unknown execution gate configuration');
  $c=array_replace(self::defaults(),$input);
  foreach(['analysis_enabled','enabled','manual_advisory_enabled'] as $k)if(!is_bool($c[$k]))throw new InvalidArgumentException($k.' must be boolean');
  foreach(['max_wait_seconds','basis_window_seconds','calibration_history_days','basis_sample_interval_seconds','confirmation_samples','min_samples_60m','min_samples_7d','max_quote_age_seconds'] as $k)if(!is_int($c[$k])||$c[$k]<1)throw new InvalidArgumentException('Invalid '.$k);
  if($c['max_wait_seconds']>900||$c['calibration_history_days']>7||$c['basis_sample_interval_seconds']<$c['max_quote_age_seconds'])throw new InvalidArgumentException('Unsafe deadline, retention or sampling configuration');
  foreach(['min_remaining_rr','fees_pct','slippage_pct','mad_multiplier','historical_percentile'] as $k)if($c[$k]!==null&&(!is_numeric($c[$k])||!is_finite((float)$c[$k])||$c[$k]<0))throw new InvalidArgumentException('Invalid '.$k);
  if($c['historical_percentile']<=0||$c['historical_percentile']>100||$c['mad_multiplier']<=0)throw new InvalidArgumentException('Invalid statistical configuration');
  if(!is_array($c['approved_strategy_versions']))throw new InvalidArgumentException('Explicit strategy version approvals required');
  foreach($c['approved_strategy_versions'] as $id=>$version)if(!is_string($id)||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$id)||!is_string($version)||!preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$version))throw new InvalidArgumentException('Invalid strategy version approval');
  if($c['instrument']!==null){$i=$c['instrument'];if(!is_array($i)||array_diff(array_keys($i),['verified','type','reference_type','symbol','evidence'])||!is_bool($i['verified']??null)||!in_array($i['type']??'', ['spot','margin','futures'],true)||!is_string($i['evidence']??null))throw new InvalidArgumentException('Invalid instrument mapping');}
  if($c['orderbook']!==null){$b=$c['orderbook'];if(!is_array($b)||array_diff(array_keys($b),['verified','path','bids','asks','timestamp','timestamp_unit','evidence'])||($b['verified']??false)!==true||!preg_match('~^/v[0-9]+/[a-zA-Z0-9/_-]+$~D',$b['path']??'')||!str_starts_with($b['evidence']??'','https://')||!in_array($b['timestamp_unit']??'', ['seconds','milliseconds'],true))throw new InvalidArgumentException('Verified public order-book mapping required');foreach(['bids','asks','timestamp'] as $k)if(!is_string($b[$k]??null)||!preg_match('/^[A-Za-z0-9_.]+$/D',$b[$k]))throw new InvalidArgumentException('Invalid order book field mapping');}
  if($c['manual_advisory_enabled']&&$c['confirmation_samples']<3)throw new InvalidArgumentException('Manual advisory requires at least three independent receipts');
  return $c;
 }
 static function config():array {
  if(defined('TEST_MARKET_CONFIG'))return self::validate(TEST_MARKET_CONFIG);
  $path=dirname(__DIR__).'/config/execution-gate.json';
  return self::validate(is_file($path)?json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR):[]);
 }
 static function approved(array $c,string $id,string $version):bool{return ($c['approved_strategy_versions'][$id]??null)===$version;}
}
