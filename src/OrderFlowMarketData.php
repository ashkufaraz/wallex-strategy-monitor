<?php
declare(strict_types=1);

// Intentionally separate from the analytical provider: only four public GETs,
// no authentication, no orders, no changes to its cache, formulas or backoff.
final class OrderFlowMarketData {
 function __construct(private ?Closure $transport=null,private ?Closure $clock=null){}
 function now():int{return $this->clock?(int)($this->clock)():time();}
 static function weight(string $path,array $params):int{return match($path){'/api/v3/time'=>1,'/api/v3/aggTrades'=>4,'/api/v3/klines'=>2,'/api/v3/depth'=>($params['limit']<=100?5:($params['limit']<=500?25:50)),default=>throw new InvalidArgumentException('Order Flow public GET allowlist')};}
 function get(string $path,array $params,array $c):array {
  self::weight($path,$params);
  if($path!=='/api/v3/time')BinanceMarketDataProvider::symbol($params['symbol']??'');
  $url='https://data-api.binance.vision'.$path.($params?'?'.http_build_query($params):'');
  if($this->transport)$r=($this->transport)($url,$c['request_timeout_ms']);else{
   $headers=[];$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT_MS=>$c['request_timeout_ms'],CURLOPT_CONNECTTIMEOUT_MS=>min(1000,$c['request_timeout_ms']),CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HEADERFUNCTION=>static function($ch,$line)use(&$headers){if(str_contains($line,':')){[$k,$v]=explode(':',$line,2);$headers[strtolower(trim($k))]=trim($v);}return strlen($line);}]);
   $body=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);if($body===false)throw new RuntimeException('Order Flow transport timeout or failure: '.$error);$r=['status'=>$status,'headers'=>$headers,'body'=>$body];
  }
  if(in_array($r['status'],[418,429],true))return ['backoff'=>max(60,(int)($r['headers']['retry-after']??60)),'http_status'=>$r['status']];
  if($r['status']!==200)throw new RuntimeException('Order Flow HTTP '.$r['status']);
  $data=json_decode($r['body'],true,512,JSON_THROW_ON_ERROR);if(!is_array($data))throw new RuntimeException('Invalid Order Flow JSON');
  return ['data'=>$data,'used_weight'=>(int)($r['headers']['x-mbx-used-weight-1m']??0)];
 }
}
