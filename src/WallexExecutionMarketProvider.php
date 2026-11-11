<?php
declare(strict_types=1);

interface ExecutionQuoteProvider {
 public function quote(string $symbol,int $now):array;
}

final class WallexExecutionMarketProvider implements ExecutionQuoteProvider {
 const DEPTH_PATH='/v1/depth';
 function __construct(private Wallex $api,private array $config,private ?Closure $clock=null){}
 static function levels(array $bids,array $asks):array {
  if(!$bids||!$asks)throw new RuntimeException('Order book unavailable');
  foreach([$bids,$asks] as $side=>$rows){$last=null;foreach($rows as $row){if(!is_array($row)||count($row)<2)throw new RuntimeException('Invalid depth row');$p=BinanceMarketDataProvider::number($row[0]);$q=BinanceMarketDataProvider::number($row[1]);if($p<=0||$q<=0||($last!==null&&($side===0?$p>=$last:$p<=$last)))throw new RuntimeException('Invalid or unordered depth');$last=$p;}}
  $bid=(float)$bids[0][0];$ask=(float)$asks[0][0];if($ask<$bid)throw new RuntimeException('Crossed order book');return ['bid'=>$bid,'ask'=>$ask,'mid'=>($bid+$ask)/2,'spread'=>$ask-$bid,'bids'=>$bids,'asks'=>$asks];
 }
 static function field(array $data,string $path):mixed{foreach(explode('.',$path) as $key){if(!is_array($data)||!array_key_exists($key,$data))throw new RuntimeException('Order book field unavailable: '.$path);$data=$data[$key];}return $data;}
 // The official REST book has object rows. Never treat sum as base quantity.
 static function normalize(array $data):array {
  if(($data['success']??null)!==true||!is_array($data['result']??null))throw new RuntimeException('Incomplete or unsuccessful Wallex order book');
  $sides=[];
  foreach(['bid','ask'] as $side){
   $rows=$data['result'][$side]??null;
   if(!is_array($rows)||!array_is_list($rows)||!$rows)throw new RuntimeException('Empty or invalid Wallex '.$side.' depth');
   $levels=[];$seen=[];
   foreach($rows as $row){
    if(!is_array($row)||!isset($row['price'],$row['quantity'],$row['sum']))throw new RuntimeException('Incomplete Wallex depth row');
    $p=BinanceMarketDataProvider::number($row['price']);$q=BinanceMarketDataProvider::number($row['quantity']);$sum=BinanceMarketDataProvider::number($row['sum']);$notional=$p*$q;
    if($p<=0||$q<=0||$sum<=0||!is_finite($notional)||abs($sum-$notional)>max(1e-8,abs($notional)*1e-8))throw new RuntimeException('Invalid Wallex price, base quantity or row notional');
    $key=sprintf('%.17g',$p);if(isset($seen[$key]))throw new RuntimeException('Duplicate Wallex price level; visible liquidity is ambiguous');$seen[$key]=true;
    $levels[]=[$p,$q];
   }
   usort($levels,fn($a,$b)=>$side==='bid'?($b[0]<=>$a[0]):($a[0]<=>$b[0]));$sides[$side]=$levels;
  }
  $book=self::levels($sides['bid'],$sides['ask']);
  if($book['ask']<=$book['bid'])throw new RuntimeException('Crossed or locked Wallex book');
  $book['visible_bid_quantity']=array_sum(array_column($book['bids'],1));$book['visible_ask_quantity']=array_sum(array_column($book['asks'],1));
  if(!is_finite($book['visible_bid_quantity'])||!is_finite($book['visible_ask_quantity']))throw new RuntimeException('Invalid total visible depth');
  $book['quantity_unit']='base_asset';return $book;
 }
 function snapshot(string $symbol,int $now):array {
  BinanceMarketDataProvider::symbol($symbol);
  $data=$this->api->get(self::DEPTH_PATH,['symbol'=>$symbol]);$received=max($now,$this->clock?(int)($this->clock)():time());$q=self::normalize($data);
  // No source timestamp or sequence is documented for this endpoint. Ignore any
  // unverified time-like extension, configuration mapping, HTTP Date or local clock.
  return $q+['provider'=>'wallex','symbol'=>$symbol,'source_url'=>'https://api.wallex.ir'.self::DEPTH_PATH.'?'.http_build_query(['symbol'=>$symbol]),
   'receipt_id'=>bin2hex(random_bytes(16)),'received_at'=>$received,'timestamp'=>null,'source_timestamp'=>null,'source_sequence'=>null,
   'trust_status'=>'UNVERIFIED','trust_reason'=>'Official Wallex REST order book has no verified source timestamp or sequence; received_at is local receipt only',
   'sample_id'=>hash('sha256',encode([$symbol,$q['bids'],$q['asks']]))];
 }
 static function estimate(array $book,string $action,float $quantity):array {
  if(!in_array($action,['LONG','SHORT'],true)||!is_finite($quantity)||$quantity<=0)throw new InvalidArgumentException('Positive base quantity and LONG/SHORT required');
  $validated=self::levels($book['bids']??[],$book['asks']??[]);if($validated['ask']<=$validated['bid'])throw new RuntimeException('Crossed or locked book');
  $rows=$action==='LONG'?$validated['asks']:$validated['bids'];$remaining=$quantity;$filled=0.;$notional=0.;$used=[];
  foreach($rows as [$price,$available]){
   if($remaining<=0)break;$take=min($remaining,(float)$available);$filled+=$take;$notional+=$price*$take;$remaining-=$take;$used[]=['price'=>(float)$price,'base_quantity'=>$take];
  }
  if(!is_finite($filled)||!is_finite($notional))throw new RuntimeException('Non-finite execution estimate');
  $sufficient=$remaining<=0.;
  return ['action'=>$action,'side'=>$action==='LONG'?'ask':'bid','requested_base_quantity'=>$quantity,'visible_base_quantity'=>array_sum(array_column($rows,1)),
   'filled_base_quantity'=>$filled,'unfilled_base_quantity'=>max(0.,$remaining),'depth_sufficient'=>$sufficient,
   'vwap'=>$sufficient?$notional/$quantity:null,'partial_vwap'=>$filled>0?$notional/$filled:null,'estimated_quote_amount'=>$notional,'levels_used'=>$used,
   'status'=>$sufficient?'VISIBLE_DEPTH_SUFFICIENT':'INSUFFICIENT_VISIBLE_DEPTH','execution_confirmed'=>false,
   'reason'=>$sufficient?'Indicative visible-book estimate only; quote freshness and instrument execution are not verified':'Requested base quantity exceeds visible depth; no execution approval',
   'fees_included'=>false,'trust_status'=>$book['trust_status']??'UNVERIFIED'];
 }
 function quote(string $symbol,int $now):array {
  $b=$this->config['orderbook'];if($b===null)throw new RuntimeException('Wallex execution quote is not configured; use snapshot for UNVERIFIED read-only diagnostics');
  if(($b['path']??null)!==self::DEPTH_PATH)throw new RuntimeException('Only the documented Wallex /v1/depth endpoint is supported');
  return $this->snapshot($symbol,$now);
 }
}
