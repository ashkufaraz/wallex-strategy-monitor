"""Offline browser checks: view/default controls cannot rewrite market identity."""
import json, os, subprocess
from pathlib import Path
from urllib.parse import urlparse, parse_qs
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parent.parent
scanner=json.loads(subprocess.check_output([os.environ.get('PHP_BINARY','php'),'-r',"define('TEST_DATABASE',':memory:');require 'src/App.php';echo encode(ScannerService::viewAll());"],cwd=ROOT))
patterns={'markets':{}}
for exchange in ['wallex','nobitex']:
    for symbol in ['BTCUSDT','XAUTUSDT']:
        key=f'0:{exchange}:{symbol}'
        methods={id:dict(strategy_id=id,name=name,exchange=exchange,symbol=symbol,enabled=True,data_status='SUFFICIENT',pattern_status='WAITING_PATTERN',execution_status='NOT_EVALUATED',reason='fixture',conditions=[dict(id='volume',actual=1,required=1.5,status='FAIL')],data_health={'15m':dict(latest_closed_candle=1794977100,consecutive=98,required=98,last_gap=None)},last_signal=None) for id,name in [('wallex-break-retest-v1','شکست و بازآزمایی'),('mean_reversion_v1','بازگشت به میانگین')]}
        patterns['markets'][key]=dict(market_key=key,exchange=exchange,symbol=symbol,account_id=0,methods=methods,signals=[],config=dict(enabled=True,methods={id:dict(enabled=True) for id in methods}),can_manage=True,last_checked=1794977100)
base=dict(csrf='test',user=None,can_manage_settings=True,default_exchange='wallex',interval=120,heartbeat=0,strategies=[],alerts=[],archived_alerts=[],logs=[],comments=[])
example=json.loads((ROOT/'public/example.json').read_text('utf8'))
for i,exchange in enumerate(['wallex','nobitex']):
    config={**example,'id':f'fixture_{exchange}','exchange':exchange,'enabled':True}
    base['strategies'].append(dict(config=config,state=dict(stage=config['initial_stage'],setup=None,cursor=1794977000,error=None,last_check=None),version='v1',can_manage=True))
posts=[]
with sync_playwright() as pw:
    browser=pw.chromium.launch(headless=True,executable_path='C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe')
    for width in [390,1440]:
        base['default_exchange']='wallex';posts.clear()
        page=browser.new_page(viewport=dict(width=width,height=1000));errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
        def route(r):
            parsed=urlparse(r.request.url);q=parse_qs(parsed.query)
            if parsed.path.endswith('api.php'):
                if r.request.method=='POST':
                    body=r.request.post_data_json;posts.append(body)
                    if body['action']=='default_exchange':base['default_exchange']=body['exchange'];r.fulfill(json=dict(ok=True,default_exchange=body['exchange']));return
                    if body['action']=='pattern_settings':
                        key=f"0:{body['exchange']}:{body['symbol']}";patterns['markets'][key]['config']=body['config']
                        for id,m in patterns['markets'][key]['methods'].items():m['enabled']=body['config']['methods'][id]['enabled']
                        r.fulfill(json=dict(ok=True));return
                    raise AssertionError(body)
                if 'patterns' in q:r.fulfill(json=patterns);return
                if 'scanner' in q:r.fulfill(json=scanner);return
                if 'details' in q:r.fulfill(json=dict(logs=[],execution_signals=[],architecture=dict(analysis_enabled=False)));return
                if 'market_chart' in q:
                    r.fulfill(json=dict(exchange=q['exchange'][0],symbol=q['symbol'][0],**{'from':1794973500,'to':1794977100},gaps=[],bars=[dict(time=1794973500,open=200,high=202,low=199,close=201,volume=2)]));return
                r.fulfill(json=base);return
            file=ROOT/'public'/(parsed.path.lstrip('/') or 'index.html');r.fulfill(body=file.read_bytes(),content_type={'.html':'text/html; charset=utf-8','.js':'application/javascript; charset=utf-8','.css':'text/css; charset=utf-8'}.get(file.suffix,'application/octet-stream'))
        page.route('**/*',route);page.goto('http://127.0.0.1:5078/');page.wait_for_selector('#patternCards article',state='attached')
        assert not page.locator('#patternPanel').evaluate('(p)=>p.open')
        assert not page.locator('#patternCards').is_visible()
        assert page.evaluate("document.getElementById('patternPanel').nextElementSibling===document.getElementById('alerts').closest('section')")
        page.locator('#patternPanel > summary').click()
        page.wait_for_selector('#patternCards article')
        assert page.locator('#patternCards article').count()==8
        before=json.dumps(base['strategies'],sort_keys=True)
        page.locator('#exchangeFilter').select_option('nobitex');page.wait_for_function("document.querySelectorAll('#patternCards article').length===4")
        assert page.locator('#strategies .card').count()==1
        assert not posts
        page.locator('#json').fill(json.dumps(example));draft=page.locator('#json').input_value()
        page.locator('#defaultExchange').select_option('nobitex');page.wait_for_function("document.querySelector('#exchangeMessage').textContent.length>0")
        assert page.locator('#json').input_value()==draft
        assert json.dumps(base['strategies'],sort_keys=True)==before
        page.locator('#example').click();page.wait_for_function("JSON.parse(document.querySelector('#json').value).exchange==='nobitex'")
        page.locator('[data-pattern-market="0:nobitex:XAUTUSDT"][data-pattern-toggle="mean_reversion_v1"]').click();page.wait_for_function("document.querySelector('#patternActionResult').textContent.length>0")
        assert posts[-1]['exchange']=='nobitex' and posts[-1]['symbol']=='XAUTUSDT'
        assert patterns['markets']['0:wallex:XAUTUSDT']['methods']['mean_reversion_v1']['enabled'] is True
        page.locator('#strategies [data-do="details"]').click();page.wait_for_selector('#marketChartButton');page.locator('#marketChartButton').click();page.wait_for_selector('#marketChartContent svg')
        assert 'نوبیتکس' in page.locator('#marketChartContent').inner_text()
        assert not errors,errors
        assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
        page.screenshot(path=str(ROOT/f'data/exchanges-ui-{width}.png'),full_page=True);page.close()
    browser.close()
print('PASS exchange filters, immutable default/draft/history, eight cards, scoped method toggle, exact-provider chart and responsive layouts')
