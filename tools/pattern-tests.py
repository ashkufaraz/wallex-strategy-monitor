"""Offline independent indicator reference, fixtures and input/expected/actual audit."""
import json,math,os,subprocess,sys
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
def reference(bars):
    closes=[b['close'] for b in bars];p=14
    def rsi(values):
        delta=[b-a for a,b in zip(values,values[1:])];up=sum(max(v,0) for v in delta[:p])/p;down=sum(max(-v,0) for v in delta[:p])/p
        for v in delta[p:]:up=(13*up+max(v,0))/14;down=(13*down+max(-v,0))/14
        return 50. if up==down==0 else 100. if down==0 else 100-100/(1+up/down)
    def bb(values):
        values=values[-20:];mean=sum(values)/20;sd=math.sqrt(sum((x-mean)**2 for x in values)/20);return dict(middle=mean,lower=mean-2*sd,upper=mean+2*sd,stddev_population=sd)
    tr=[];pd=[];md=[]
    for a,b in zip(bars,bars[1:]):
        tr.append(max(b['high']-b['low'],abs(b['high']-a['close']),abs(b['low']-a['close'])))
        up=b['high']-a['high'];down=a['low']-b['low'];pd.append(up if up>down and up>0 else 0.);md.append(down if down>up and down>0 else 0.)
    atr=sum(tr[:14])/14
    for x in tr[14:]:atr=(13*atr+x)/14
    sums=[sum(x[:14]) for x in [tr,pd,md]];dx=[]
    for i in range(13,len(tr)):
        if i>13:sums=[v*13/14+series[i] for v,series in zip(sums,[tr,pd,md])]
        _,plus,minus=sums;dx.append(100*abs(plus-minus)/(plus+minus) if plus+minus else 0.)
    adx=sum(dx[:14])/14
    for x in dx[14:]:adx=(13*adx+x)/14
    return dict(previous=dict(close=closes[-2],bands=bb(closes[:-1]),rsi=rsi(closes[:-1])),current=dict(close=closes[-1],bands=bb(closes),rsi=rsi(closes),adx=adx,atr=atr))
t=14400*120000
def bars(direction):
    b=[dict(time=t+(i-98)*900,open=100.,high=140.,low=60.,close=100.+(-.5 if i%2 else .5),volume=10.) for i in range(98)]
    b[-2]['close']=70. if direction=='LONG' else 130.;b[-1]['close']=95. if direction=='LONG' else 105.;return b
cases={d:bars(d) for d in ['LONG','BEARISH']}
nonzero=[dict(time=t+(i-98)*900,open=100+math.sin(i/5)+i*.03,close=100+math.sin(i/5)+i*.03,high=102+math.sin(i/5)+i*.03,low=98+math.sin(i/5)+i*.03,volume=10.) for i in range(98)]
fixture=dict(anchor=t,bars=cases,reference={d:reference(b) for d,b in cases.items()},nonzero_bars=nonzero,nonzero_reference=reference(nonzero))
(ROOT/'data/pattern-reference.json').write_text(json.dumps(fixture,indent=2)+'\n',encoding='utf8')
php=os.environ.get('PHP_BINARY','php')
subprocess.run([php,'-d','disable_functions=curl_init,fsockopen,pfsockopen,stream_socket_client','tests/patterns.php','data/pattern-reference.json'],cwd=ROOT,check=True)
subprocess.run([sys.executable,'tests/pattern-concurrency.py'],cwd=ROOT,check=True)
report=json.loads((ROOT/'reports/pattern-tests.json').read_text('utf8'));report['cases']+=json.loads((ROOT/'reports/pattern-concurrency.json').read_text('utf8'))
report['summary']={k:sum(k=='total' or row['status']==k.upper() for row in report['cases']) for k in ['total','pass','fail']}
(ROOT/'reports/pattern-tests.json').write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n',encoding='utf8')
def cell(v):return json.dumps(v,ensure_ascii=False,separators=(',',':')).replace('|','\\|').replace('\n',' ')
lines=['# آزمون هشدار مستقل الگو','','دادهٔ مصنوعی، شبکهٔ PHP غیرفعال، مرجع مستقل Python برای BB/RSI/ADX/ATR. آزمون تک‌شرط روی ورودی اندیکاتورها جدا از آزمون مسیر کامل OHLC است.','',str(report['summary']),'','| آزمون | ورودی | انتظار | واقعی | نتیجه |','|---|---|---|---|---|']
for row in report['cases']:lines.append('| '+' | '.join(cell(row[k]) for k in ['id','input','expected','actual','status'])+' |')
(ROOT/'reports/pattern-tests.md').write_text('\n'.join(lines)+'\n',encoding='utf8');print(report['summary']);sys.exit(bool(report['summary']['fail']))
