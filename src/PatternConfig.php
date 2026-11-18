<?php
declare(strict_types=1);
final class PatternConfig {
 const BREAKOUT='wallex-break-retest-v1';
 const MEAN='mean_reversion_v1';
 static function defaults():array {return ['enabled'=>true,'interval_seconds'=>120,'receipt_max_age_seconds'=>180,'publication_max_delay_seconds'=>300,'methods'=>[self::BREAKOUT=>['enabled'=>true],self::MEAN=>['enabled'=>true,'bb_period'=>20,'bb_stddev'=>2.0,'rsi_period'=>14,'rsi_lower'=>30.0,'rsi_upper'=>70.0,'adx_period'=>14,'adx_max'=>20.0,'atr_period'=>14,'invalidation_atr'=>0.25,'expiry_seconds'=>900]]];}
 static function validate(array $input):array {
  $d=self::defaults();Strategy::keys($input,[],array_keys($d));$c=array_replace($d,$input);if(!is_bool($c['enabled'])||!is_array($c['methods']))throw new InvalidArgumentException('Invalid pattern settings');
  foreach(['interval_seconds'=>[55,300],'receipt_max_age_seconds'=>[30,300],'publication_max_delay_seconds'=>[30,899]] as $k=>[$a,$b])if(!is_int($c[$k])||$c[$k]<$a||$c[$k]>$b)throw new InvalidArgumentException('Invalid '.$k);
  Strategy::keys($c['methods'],[],array_keys($d['methods']));$methods=[];foreach($d['methods'] as $id=>$defaults){$p=$c['methods'][$id]??[];if(!is_array($p))throw new InvalidArgumentException('Invalid method');Strategy::keys($p,[],array_keys($defaults));$p=array_replace($defaults,$p);if(!is_bool($p['enabled']))throw new InvalidArgumentException('Invalid method enabled');
   if($id===self::MEAN){foreach(['bb_period','rsi_period','adx_period','atr_period'] as $k)if(!is_int($p[$k])||$p[$k]<2||$p[$k]>100)throw new InvalidArgumentException('Invalid '.$k);
    foreach(['bb_stddev'=>[0.5,4],'rsi_lower'=>[1,49],'rsi_upper'=>[51,99],'adx_max'=>[1,50],'invalidation_atr'=>[0,3]] as $k=>[$lo,$hi])if((!is_int($p[$k])&&!is_float($p[$k]))||!is_finite((float)$p[$k])||$p[$k]<$lo||$p[$k]>$hi)throw new InvalidArgumentException('Invalid '.$k);
    if(!is_int($p['expiry_seconds'])||$p['expiry_seconds']<120||$p['expiry_seconds']>3600)throw new InvalidArgumentException('Invalid expiry_seconds');
   }$methods[$id]=$p;
  }$c['methods']=$methods;return $c;
 }
 static function meanCount(array $p):int {return max($p['bb_period']+1,5*$p['rsi_period']+2,7*$p['adx_period'],5*$p['atr_period']+2);}
 static function name(string $id):string {return $id===self::MEAN?'بازگشت به میانگین':'شکست و بازآزمایی';}
 static function opportunity(string $symbol,string $id,string $direction,int $close,string $exchange='wallex',int $accountId=0):string {return hash('sha256',implode('|',[$accountId,MarketScope::exchange($exchange),$symbol,$id,$direction==='SHORT'?'BEARISH':$direction,$close]));}
}
