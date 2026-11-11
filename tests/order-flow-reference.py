"""Independent Decimal reference on checksum-verified official historical Spot data."""
import json
import subprocess
from decimal import Decimal, getcontext
from pathlib import Path

getcontext().prec=45
ROOT=Path(__file__).resolve().parent.parent
fixture=json.loads((ROOT/'tests/fixtures/order-flow-binance-history.json').read_text(encoding='utf-8'))
assert fixture['historical'] and not fixture['synthetic'] and all(s['checksum_verified'] for s in fixture['sources'])
out=json.loads(subprocess.run(['php','tools/order-flow-replay.php'],cwd=ROOT,capture_output=True,text=True,check=True).stdout)
assert out['result']['advisory_status']=='UNAVAILABLE' and fixture['book'] is None
end=fixture['trade_window_end']
bars={row[0]//1000:Decimal(row[5]) for row in fixture['klines']}
comparisons=0
for seconds in [60,300]:
    rows=[r for r in fixture['rows'] if (end-seconds)*1000<=r['T']<end*1000]
    buy=sum((Decimal(r['q']) for r in rows if r['m'] is False),Decimal(0))
    sell=sum((Decimal(r['q']) for r in rows if r['m'] is True),Decimal(0))
    delta=buy-sell
    total=buy+sell
    volume=sum((bars[t] for t in range(end-seconds,end,60)),Decimal(0))
    assert volume==total
    previous=[sum((bars[t] for t in range(end-(i+1)*seconds,end-i*seconds,60)),Decimal(0)) for i in [1,2]]
    expected={'aggressive_buy_volume':buy,'aggressive_sell_volume':sell,'delta':delta,'cvd':delta,'normalized_delta':delta/total,'relative_volume':volume/(sum(previous)/2)}
    actual=out['result']['indicator_values'][str(seconds)]
    for key,value in expected.items():
        assert abs(Decimal(str(actual[key]))-value)<=max(Decimal('1e-8'),abs(value)*Decimal('1e-12')),(seconds,key,actual[key],value)
        comparisons+=1
    # Threshold behavior is replayed, without claiming profitability or a
    # historical book that was never captured.
    for threshold in ['0.05','0.15','0.30']:
        pressure='BUY' if delta/total>=Decimal(threshold) else 'SELL' if delta/total<=-Decimal(threshold) else 'NEUTRAL'
        print(json.dumps({'window':seconds,'threshold':threshold,'historical_pressure':pressure,'advisory':'UNAVAILABLE_NO_HISTORICAL_BOOK'}))
print(f'Official historical XRPUSDT Decimal reference: {comparisons} comparisons passed; no fabricated historical order book or profit claims.')
