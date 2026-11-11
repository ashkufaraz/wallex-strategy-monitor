<?php
declare(strict_types=1);

// Public market-data only. Inherits the existing scheduler contract, never its HTTP routing.
class BinanceMarketDataProvider extends Wallex {
 private array $responses=[];private array $responseTimes=[];private ?float $budget=null;
 function __construct(private ?Closure $transport=null,private ?Closure $clock=null,?float $deadline=null){parent::__construct();$this->budget=$deadline;}
 function now():int{return $this->clock?(int)($this->clock)():time();}
 function withBudget(float $seconds,Closure $operation):mixed{$old=$this->budget;$this->budget=min($old??INF,microtime(true)+$seconds);try{return $operation();}finally{$this->budget=$old;}}
 function invalidateMinuteCache():void{$this->responses=[];}
 static function number(mixed $v):float{if(!is_numeric($v)||!is_finite((float)$v))throw new RuntimeException('Invalid Binance number');return (float)$v;}
 function get(string $path,array $params=[]):array {
  try{return $this->request($path,$params);}catch(Throwable $e){
   db()->exec('CREATE TABLE IF NOT EXISTS market_failures(id INTEGER PRIMARY KEY AUTOINCREMENT,provider TEXT NOT NULL,at INTEGER NOT NULL,path TEXT NOT NULL,error TEXT NOT NULL)');
   db()->prepare('INSERT INTO market_failures(provider,at,path,error) VALUES(?,?,?,?)')->execute(['binance_spot',$this->now(),$path,$e->getMessage()]);
   db()->exec('DELETE FROM market_failures WHERE id NOT IN (SELECT id FROM market_failures ORDER BY id DESC LIMIT 200)');throw $e;
  }
 }
 private function request(string $path,array $params):array {
  if(!in_array($path,['/api/v3/klines','/api/v3/ticker/price','/api/v3/ticker/bookTicker','/api/v3/depth','/api/v3/time'],true))throw new RuntimeException('Only public Binance market-data endpoints are allowed');
  db()->exec('CREATE TABLE IF NOT EXISTS market_backoff(provider TEXT PRIMARY KEY, until_time INTEGER NOT NULL, error TEXT NOT NULL)');
  $until=db()->query("SELECT until_time FROM market_backoff WHERE provider='binance_spot'")->fetchColumn();
  if($until!==false&&(int)$until>$this->now())throw new RuntimeException('Binance backoff until '.gmdate('c',(int)$until));
  $key='binance_spot:'.$path.':'.http_build_query($params);
  if(isset($this->responses[$key])&&microtime(true)-$this->responseTimes[$key]<($path==='/api/v3/klines'?60:1))return $this->responses[$key];
  $url='https://data-api.binance.vision'.$path.($params?'?'.http_build_query($params):'');
  if($this->transport)$r=($this->transport)($url);else{
   $remaining=min(12,(int)floor(($this->budget??(microtime(true)+12))-microtime(true)));if($remaining<1)throw new RuntimeException('Binance request budget exceeded');
   $headers=[];$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>$remaining,CURLOPT_CONNECTTIMEOUT=>min(5,$remaining),CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HEADERFUNCTION=>static function($ch,$line)use(&$headers){if(str_contains($line,':')){[$k,$v]=explode(':',$line,2);$headers[strtolower(trim($k))]=trim($v);}return strlen($line);}]);
   $body=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);if($body===false)throw new RuntimeException('Binance transport: '.$error);$r=['status'=>$status,'headers'=>$headers,'body'=>$body];
  }
  if(in_array($r['status'],[418,429],true)){
   $retry=$r['headers']['retry-after']??null;$delay=is_numeric($retry)?max(1,(int)ceil((float)$retry)):max(60,(int)strtotime((string)$retry)-$this->now());
   db()->prepare('INSERT OR REPLACE INTO market_backoff VALUES(?,?,?)')->execute(['binance_spot',$this->now()+$delay,'HTTP '.$r['status']]);throw new RuntimeException('Binance HTTP '.$r['status'].'; Retry-After '.$delay);
  }
  if($r['status']!==200)throw new RuntimeException('Binance HTTP '.$r['status']);
  $data=json_decode($r['body'],true,512,JSON_THROW_ON_ERROR);if(!is_array($data))throw new RuntimeException('Invalid Binance JSON');
  $this->responseTimes[$key]=microtime(true);return $this->responses[$key]=$data;
 }
 static function symbol(string $symbol):void{if(!preg_match('/^[A-Z0-9]{2,20}USDT$/D',$symbol))throw new InvalidArgumentException('Invalid Binance USDT symbol');}
 function serverTime():int{$d=$this->get('/api/v3/time');$ms=self::number($d['serverTime']??null);if($ms!=(int)$ms||abs($ms/1000-$this->now())>5)throw new RuntimeException('Binance clock skew');return (int)floor($ms/1000);}
 function price(string $symbol):float {self::symbol($symbol);$this->serverTime();$d=$this->get('/api/v3/ticker/price',['symbol'=>$symbol]);$p=self::number($d['price']??null);if(($d['symbol']??null)!==$symbol||$p<=0)throw new RuntimeException('Invalid Binance ticker');return $p;}
 static function parse(array $rows,int $seconds,int $from,int $end):array {
  if(!array_is_list($rows)||!$rows)throw new RuntimeException('Empty Binance candles');$bars=[];$previous=null;
  foreach($rows as $r){if(!is_array($r)||!array_is_list($r)||count($r)<12)throw new RuntimeException('Incomplete Binance candle');$ms=self::number($r[0]);$close=self::number($r[6]);$t=(int)($ms/1000);
   if($ms!=$t*1000||$t%$seconds!==0||$close!==($t+$seconds)*1000.-1||($previous!==null&&$t!==$previous+$seconds))throw new RuntimeException('Invalid, duplicated, unordered or gapped Binance timestamps');$previous=$t;
   $b=[];foreach([1=>'open',2=>'high',3=>'low',4=>'close',5=>'volume'] as $i=>$field)$b[$field]=self::number($r[$i]);
   if(min($b['open'],$b['high'],$b['low'],$b['close'])<=0||$b['volume']<0||$b['high']<max($b['open'],$b['low'],$b['close'])||$b['low']>min($b['open'],$b['close']))throw new RuntimeException('Invalid Binance OHLCV');
   if($t>=$from&&$t+$seconds<=$end)$bars[$t]=$b+['open_time'=>$t,'duration'=>$seconds,'provider'=>'binance_spot'];
  }return $bars;
 }
 function candles(string $symbol,string $tf,int $from,int $end):array {
  self::symbol($symbol);$sec=Indicators::TIMEFRAMES[$tf]??throw new InvalidArgumentException('Invalid timeframe');
  if($from<0||$from%$sec||$end%$sec||$end<=$from||$end>$this->serverTime())throw new RuntimeException('Invalid closed Binance interval');
  $out=[];for($start=$from;$start<$end;$start+=$sec*1000){$until=min($end,$start+$sec*1000);$part=self::parse($this->get('/api/v3/klines',['symbol'=>$symbol,'interval'=>$tf,'startTime'=>$start*1000,'endTime'=>$until*1000-1,'limit'=>1000]),$sec,$start,$until);
   for($t=$start;$t<$until;$t+=$sec)if(!isset($part[$t]))throw new RuntimeException('Missing closed Binance candle at '.$t);$out+=$part;
  }return $out;
 }
 function minutes(string $symbol,int $from,int $to):array{return $this->candles($symbol,'1m',$from,$to);}
 function monitoringMinutes(string $symbol,int $from,int $now):array {
  self::symbol($symbol);$end=(intdiv($now,60)+1)*60;
  $rows=$this->get('/api/v3/klines',['symbol'=>$symbol,'interval'=>'1m','startTime'=>$from*1000,'endTime'=>$now*1000,'limit'=>1000]);
  $bars=self::parse($rows,60,$from,$end);
  for($t=$from;$t<$end;$t+=60)if(!isset($bars[$t]))throw new RuntimeException('Missing post-signal minute at '.$t);
  return $bars;
 }
 function quote(string $symbol):array {
  self::symbol($symbol);$at=$this->serverTime();$d=$this->get('/api/v3/ticker/bookTicker',['symbol'=>$symbol]);if(($d['symbol']??null)!==$symbol)throw new RuntimeException('Binance quote symbol mismatch');
  $bid=self::number($d['bidPrice']??null);$ask=self::number($d['askPrice']??null);
  if($bid<=0||$ask<$bid||self::number($d['bidQty']??null)<=0||self::number($d['askQty']??null)<=0)throw new RuntimeException('Invalid Binance executable book');
  return ['provider'=>'binance_spot','symbol'=>$symbol,'bid'=>$bid,'ask'=>$ask,'mid'=>($bid+$ask)/2,'timestamp'=>$at,'observed_at'=>$this->now(),'timestamp_kind'=>'server_time_at_request','sample_id'=>hash('sha256',encode([$at,$d])),'depth'=>$this->depth($symbol)];
 }
 function depth(string $symbol):array{self::symbol($symbol);$d=$this->get('/api/v3/depth',['symbol'=>$symbol,'limit'=>100]);if(!is_int($d['lastUpdateId']??null))throw new RuntimeException('Invalid Binance depth');WallexExecutionMarketProvider::levels($d['bids']??[],$d['asks']??[]);return $d;}
}
