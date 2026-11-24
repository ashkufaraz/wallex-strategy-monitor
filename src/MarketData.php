<?php
declare(strict_types=1);

interface MarketDataProvider {
 public function exchange():string;
 public function price(string $symbol):float;
 public function minutes(string $symbol,int $from,int $to):array;
 public function get(string $path,array $params=[]):array;
 public function withBudget(float $seconds,Closure $operation):mixed;
}

// Identity is explicit and never follows the display filter or the new-item default.
final class MarketScope {
 private static array $scope=['exchange'=>'wallex','symbol'=>'BTCUSDT','account_id'=>0];
 static function exchange(mixed $value):string {if(!is_string($value)||!in_array($value,['wallex','nobitex','binance'],true))throw new InvalidArgumentException('exchange must be wallex, nobitex or binance');return $value;}
 static function validate(array $s):array {$exchange=self::exchange(array_key_exists('exchange',$s)?$s['exchange']:'wallex');$symbol=$s['symbol']??'BTCUSDT';$account=$s['account_id']??0;if(!is_string($symbol)||!preg_match('/^[A-Z0-9]{2,24}USDT$/D',$symbol)||!is_int($account)||$account<0)throw new InvalidArgumentException('Invalid market/account identity');return ['exchange'=>$exchange,'symbol'=>$symbol,'account_id'=>$account];}
 static function current():array {return self::$scope;}
 static function key(?array $scope=null):string {$s=self::validate($scope??self::$scope);return $s['account_id'].':'.$s['exchange'].':'.$s['symbol'];}
 static function run(array $scope,Closure $fn):mixed {$old=self::$scope;self::$scope=self::validate($scope);try{return $fn();}finally{self::$scope=$old;}}
 static function settingKey(string $key):string {return self::key()==='0:wallex:BTCUSDT'?$key:$key.':'.self::key();}
 static function setting(string $key,string $default=''):string {return setting(self::settingKey($key),$default);}
 static function set(string $key,string $value):void {setSetting(self::settingKey($key),$value);}
 static function markets():array {$items=json_decode(setting('monitored_markets','[]'),true,64,JSON_THROW_ON_ERROR);$out=[self::key(['exchange'=>'wallex'])=>self::validate(['exchange'=>'wallex']),self::key(['exchange'=>'nobitex'])=>self::validate(['exchange'=>'nobitex'])];foreach(['wallex','nobitex'] as $exchange){$gold=self::validate(['exchange'=>$exchange,'symbol'=>'XAUTUSDT']);$out[self::key($gold)]=$gold;}foreach($items as $item){$s=self::validate($item);$out[self::key($s)]=$s;}return array_values($out);}
 static function add(array $scope):void {$s=self::validate($scope);$items=self::markets();$items[]=$s;$unique=[];foreach($items as $item)$unique[self::key($item)]=$item;setSetting('monitored_markets',encode(array_values($unique)));}
 static function monitoringMarkets():array {
  $out=[];foreach(['binance','nobitex'] as $exchange)foreach(['BTCUSDT','XAUTUSDT'] as $symbol){$scope=self::validate(['exchange'=>$exchange,'symbol'=>$symbol]);$out[self::key($scope)]=$scope;}
  foreach(self::markets() as $scope)if($scope['exchange']!=='wallex')$out[self::key($scope)]=$scope;
  return array_values($out);
 }
 static function label(string $exchange):string {return $exchange==='binance'?'بایننس فیوچرز':($exchange==='nobitex'?'نوبیتکس':'والکس');}
}

final class MarketData {
 public static ?Closure $factory=null;
 static function provider(string $exchange,?float $deadline=null):Wallex {MarketScope::exchange($exchange);$api=self::$factory?(self::$factory)($exchange,$deadline):($exchange==='binance'?new BinanceFuturesMarketDataProvider(null,null,$deadline):($exchange==='nobitex'?new NobitexMarketDataProvider(null,null,$deadline):new Wallex($deadline)));if($api->exchange()!==$exchange)throw new RuntimeException('Provider factory returned a different exchange');return $api;}
 static function phase(string $exchange,int $seconds):int {return $exchange==='nobitex'&&$seconds>=3600?1800%$seconds:0;}
 static function boundary(int $at,int $seconds,string $exchange):int {$phase=self::phase($exchange,$seconds);return intdiv($at-$phase,$seconds)*$seconds+$phase;}
 static function chart(string $exchange,string $symbol,int $count=64):array {
  MarketScope::validate(['exchange'=>$exchange,'symbol'=>$symbol]);$api=self::provider($exchange,microtime(true)+15);$now=time();$sec=900;$end=self::boundary($now,$sec,$exchange);$from=$end-$count*$sec;$raw=[];$base=in_array($exchange,['nobitex','binance'],true)?900:60;$chunk=$base===900?500*$base:21600;
  foreach(range($from,$end-1,$chunk) as $start){$r=self::history($api,['symbol'=>$symbol,'resolution'=>(string)intdiv($base,60),'from'=>$start,'to'=>min($end,$start+$chunk)]);array_push($raw,...ScannerData::udf($r));}
  $bars=[];$gaps=[];for($t=$from;$t<$end;$t+=$sec){try{$part=array_values(array_filter($raw,fn($b)=>$b['time']>=$t&&$b['time']<$t+$sec));$bucket=$base===$sec?ScannerData::closed($part,$sec,$t+$sec,1):ScannerData::aggregate($part,$base,$sec,$t+$sec,1);array_push($bars,...$bucket);}catch(Throwable $e){$gaps[]=['time'=>$t,'reason'=>$e->getMessage()];}}
  return ['exchange'=>$exchange,'symbol'=>$symbol,'timeframe'=>'15m','from'=>$from,'to'=>$end,'price_unit'=>'USDT','volume_unit'=>substr($symbol,0,-4),'time_unit'=>'UTC_SECONDS','bars'=>$bars,'gaps'=>$gaps];
 }
 static function history(Wallex $api,array $params):array {
  if(get_class($api)!=='Wallex'&&!$api instanceof NobitexMarketDataProvider)return $api->get('/v1/udf/history',$params);
  $exchange=$api->exchange();$key=$exchange.':udf:'.hash('sha256',encode($params));$now=time();
  db()->exec('CREATE TABLE IF NOT EXISTS market_data_cache(k TEXT PRIMARY KEY,exchange TEXT NOT NULL,expires INTEGER NOT NULL,payload TEXT NOT NULL)');
  $q=db()->prepare('SELECT payload FROM market_data_cache WHERE k=? AND expires>?');$q->execute([$key,$now]);$cached=$q->fetchColumn();if($cached!==false)return json_decode($cached,true,512,JSON_THROW_ON_ERROR);
  $r=$api->get('/v1/udf/history',$params);
  // Missing/invalid pages are retried next time; never turn a partial page into a successful cache entry.
  try{$base=(int)$params['resolution']*60;$end=min((int)$params['to'],self::boundary($now,$base,$exchange));$start=(int)$params['from'];$bars=ScannerData::udf($r);if($end>$start)ScannerData::closed($bars,$base,$end,intdiv($end-$start,$base),true,self::phase($exchange,$base));else throw new RuntimeException('No closed interval');
   db()->prepare('INSERT OR REPLACE INTO market_data_cache VALUES(?,?,?,?)')->execute([$key,$exchange,$now+60,encode($r)]);db()->prepare('DELETE FROM market_data_cache WHERE expires<=?')->execute([$now]);
  }catch(Throwable $e){}
  return $r;
 }
}
