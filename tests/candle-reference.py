"""Independent exact-decimal comparison of public minute/hour 4H fixtures."""
import json
from decimal import Decimal
from pathlib import Path


def aggregate(raw, start, end, step):
    assert raw['s'] == 'ok'
    assert all(len(raw[key]) == len(raw['t']) for key in ['o', 'h', 'l', 'c', 'v'])
    assert all(b-a == step for a, b in zip(raw['t'], raw['t'][1:]))
    indices = [i for i, t in enumerate(raw['t']) if start <= t and t+step <= end]
    assert [raw['t'][i] for i in indices] == list(range(start, end, step))
    traded = [i for i in indices if Decimal(str(raw['v'][i])) > 0]
    if not traded:
        return None
    number = lambda key, index: Decimal(str(raw[key][index]))
    return {'open_time': start, 'close_time': end,
            'open': number('o', traded[0]), 'close': number('c', traded[-1]),
            'high': max(number('h', i) for i in traded),
            'low': min(number('l', i) for i in traded),
            'volume': sum(number('v', i) for i in indices)}


items = json.loads((Path(__file__).parent/'fixtures/four-hour-comparisons.json').read_text())
for item in items:
    minute = aggregate(item['responses']['1'], item['from'], item['to'], 60)
    hour = aggregate(item['responses']['60'], item['from'], item['to'], 3600)
    assert minute == hour, (item['symbol'], item['from'], minute, hour)
    print('PASS exact Decimal OHLCV and UTC timestamps:', item['symbol'], item['from'], item['to'])
