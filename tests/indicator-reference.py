"""Independent Decimal weighted-sum reference for captured public Wallex candles.

No network, account data, pandas, or TA library is needed. --write refreshes the
fixture expectations; normal execution verifies they still match this oracle.
"""
import json
import sys
from decimal import Decimal, getcontext
from pathlib import Path

getcontext().prec = 50
D = lambda x: Decimal(str(x))


def smoothed(values, period, alpha):
    seed = sum(values[:period]) / period
    tail = values[period:]
    # Closed-form weighted sum, independent of the PHP iterative recurrence.
    return seed * (1-alpha)**len(tail) + sum(
        alpha * value * (1-alpha)**(len(tail)-index-1)
        for index, value in enumerate(tail))


def reference(fixture):
    raw = fixture['response']
    closed = [i for i, t in enumerate(raw['t'])
              if fixture['query']['from'] <= t and t+60 <= fixture['query']['to']]
    p = 14
    indices = closed[-(5*p+1):]
    assert len(indices) == 5*p+1
    close = [D(raw['c'][i]) for i in indices]
    ema = smoothed(close, p, D(2)/(p+1))
    delta = [b-a for a, b in zip(close, close[1:])]
    gain = smoothed([max(D(0), x) for x in delta], p, D(1)/p)
    loss = smoothed([max(D(0), -x) for x in delta], p, D(1)/p)
    rsi = (D(50) if gain == 0 else D(100)) if loss == 0 else 100-100/(1+gain/loss)
    tr = [max(D(raw['h'][i])-D(raw['l'][i]),
              abs(D(raw['h'][i])-previous), abs(D(raw['l'][i])-previous))
          for i, previous in zip(indices[1:], close[:-1])]
    atr = smoothed(tr, p, D(1)/p)
    vol = [D(raw['v'][i]) for i in closed[-(p+1):]]
    average = sum(vol[:-1])/p
    return {'EMA.value': float(ema), 'EMA.close_minus_ema_pct': float(100*(close[-1]-ema)/ema),
            'RSI.value': float(rsi), 'ATR.value': float(atr), 'ATR.atr_pct': float(100*atr/close[-1]),
            'VOLUME.value': float(vol[-1]), 'VOLUME.average_previous': float(average),
            'VOLUME.ratio_to_previous_avg': float(vol[-1]/average) if average else None}


for path in Path(__file__).with_name('fixtures').glob('*-indicator-history.json'):
    fixture = json.loads(path.read_text(encoding='utf-8'))
    expected = reference(fixture)
    if '--write' in sys.argv:
        fixture['reference'] = {'method': 'Decimal 50-digit closed-form weighted sums; SMA seed; Wilder RMA',
                                'period': 14, 'warmup_count': 71, 'values': expected}
        path.write_text(json.dumps(fixture, ensure_ascii=False, indent=2)+'\n', encoding='utf-8')
    else:
        assert fixture['reference']['values'] == expected, path
    print(path.name, 'reference checked:', len(expected), 'metrics')
