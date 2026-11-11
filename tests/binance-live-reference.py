"""Offline independent Decimal oracle on captured native Binance Spot candles."""
import json
from decimal import Decimal, getcontext
from pathlib import Path

getcontext().prec = 50
D = lambda v: Decimal(str(v))

def weighted(values, period, alpha):
    seed = sum(values[:period]) / period
    tail = values[period:]
    return seed * (1-alpha)**len(tail) + sum(
        alpha * v * (1-alpha)**(len(tail)-i-1) for i, v in enumerate(tail))

fixture = json.loads((Path(__file__).parent/'fixtures/binance-btcusdt-reference.json').read_text())
count = 0
max_delta = 0.0
for tf, item in fixture['candles'].items():
    bars = item['ohlcv']; p = item['period']
    assert len(bars) == item['warmup_count'] == 71
    sec = {'1m':60, '15m':900, '1h':3600, '4h':14400}[tf]
    assert bars[-1]['open_time'] + sec == item['close_time']
    assert all(b['open_time'] % sec == 0 for b in bars)
    assert all(b['open_time']-a['open_time'] == sec for a,b in zip(bars,bars[1:]))
    close = [D(b['close']) for b in bars]
    changes = [b-a for a,b in zip(close, close[1:])]
    gains = weighted([max(D(0), x) for x in changes], p, D(1)/p)
    losses = weighted([max(D(0), -x) for x in changes], p, D(1)/p)
    rsi = (D(50) if gains == 0 else D(100)) if losses == 0 else 100-100/(1+gains/losses)
    tr = [max(D(b['high'])-D(b['low']), abs(D(b['high'])-previous), abs(D(b['low'])-previous)) for b,previous in zip(bars[1:],close[:-1])]
    expected = {'EMA':weighted(close,p,D(2)/(p+1)), 'RSI':rsi, 'ATR':weighted(tr,p,D(1)/p), 'VOLUME':D(bars[-1]['volume'])}
    for indicator, value in expected.items():
        delta = abs(float(value)-item['values'][indicator]);max_delta=max(max_delta,delta)
        assert delta < 1e-8, (tf, indicator, delta)
        count += 1
print(f'Native Binance Decimal reference: {count} comparisons passed; max difference {max_delta:.12g}')
