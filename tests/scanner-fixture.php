<?php
function scannerFixture():array {
 $c=ScannerConfig::validate(['fees_verified'=>true,'fee_reference'=>'SYNTHETIC_FIXTURE_ONLY','observation_interval_seconds'=>60,'tp1_r'=>3,'ema_fast'=>2,'ema_slow'=>3,'atr_period'=>2,'level_lookback'=>5,'level_touches'=>2,'touch_separation'=>2,'volume_period'=>2]);$base=14400*120000;$bars=[];
 for($i=-20;$i<3;$i++){$price=95+($i+20)*0.2;$bars['4h'][]=['time'=>$base+$i*14400,'open'=>$price-0.1,'high'=>$price+0.5,'low'=>$price-0.5,'close'=>$price,'volume'=>100.0];}
 for($i=-20;$i<6;$i++)$bars['1h'][]=['time'=>$base+$i*3600,'open'=>99.0,'high'=>$i===-1?101.0:100.0,'low'=>98.5,'close'=>$i===-1?100.8:99.4,'volume'=>100.0];
 for($i=-30;$i<8;$i++)$bars['15m'][]=['time'=>$base+$i*900,'open'=>99.3,'high'=>99.8,'low'=>98.9,'close'=>99.4,'volume'=>10.0];
 $special=[-1=>[99.5,101.0,99.5,100.8,30],0=>[100.7,101.0,100.0,100.5,12],1=>[100.5,102.0,100.4,101.6,30],2=>[101.6,104.0,100.5,103.0,15],3=>[103.0,112.0,101.0,108.0,15]];
 foreach($bars['15m'] as &$b){$i=(int)(($b['time']-$base)/900);if(isset($special[$i])){[$b['open'],$b['high'],$b['low'],$b['close'],$b['volume']]=$special[$i];}}unset($b);
 $market=['provider'=>'wallex','symbol'=>'BTCUSDT','quote_asset'=>'USDT','instrument'=>'SPOT','supports_long'=>true,'supports_short'=>false,'step_size'=>0.001,'tick_size'=>0.01,'min_qty'=>0.001,'min_notional'=>1.0];
 $observations=[];foreach([0,900,1800,1860,2700,3600] as $delta){$time=$base+$delta;$ask=$delta<1800?100.8:($delta===1860?101.8:101.7);$observations[]=['observed_at'=>$time,'book'=>['provider'=>'wallex','symbol'=>'BTCUSDT','trust_status'=>'VERIFIED','source_timestamp'=>$time,'received_at'=>$time,'bids'=>[[$ask-0.04,100.0]],'asks'=>[[$ask,100.0]]]];}
 return [$c,['provider'=>'wallex','symbol'=>'BTCUSDT','bars'=>$bars,'market'=>$market,'observations'=>$observations],$base];
}
