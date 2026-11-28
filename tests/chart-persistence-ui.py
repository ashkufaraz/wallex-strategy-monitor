"""Actual panel scripts with isolated fixtures: no production requests or deletions."""
import json,time
from pathlib import Path
from urllib.parse import urlparse,parse_qs
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parent.parent
config=json.loads((root/'example-btc-short.json').read_text(encoding='utf-8'));now=int(time.time())
item=dict(config=config,version='v1',can_manage=True,state=dict(stage=config['initial_stage'],setup=None,cursor=now,last_check=now,last_price=75000,price_at=now,error=None))
payload=dict(type='signal',strategy_id=config['id'],name=config['name'],symbol='BTCUSDT',exchange='wallex',action='SHORT',price=75000,event_time=now,trade=config['trade'])
outcome=dict(can_manage=True,alert_id=40,strategy_id=config['id'],exchange='wallex',cohort='confirmed',started=now,last_check=now,snapshot=dict(name=config['name'],entry_price=75000,price_at_alert=75000,trade=config['trade']),state=dict(status='OPEN',tp1_result=None,last_price=75000,targets=[dict(price=74000,hit_at=None)]))
data=dict(user=dict(id=1,name='Owner',role='owner'),can_manage_settings=True,csrf='fixture',interval=60,heartbeat=now,strategies=[item],alerts=[dict(id=40,created=now,can_manage=True,payload=payload)],archived_alerts=[],strategy_outcomes=[outcome],logs=[],comments=[])
counts=dict(chart=0,delete=0)
with sync_playwright() as pw:
 browser=pw.chromium.launch(headless=True,executable_path='C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe')
 page=browser.new_page(viewport=dict(width=1440,height=1000));errors=[];page.on('pageerror',lambda e:errors.append(str(e)));page.on('dialog',lambda d:d.accept())
 def route(r):
  url=urlparse(r.request.url);path=url.path.lstrip('/');query=parse_qs(url.query)
  if path=='api.php':
   if r.request.method=='POST':
    assert r.request.post_data_json==dict(action='delete_alert',id=40)
    counts['delete']+=1;data['alerts']=[];data['strategy_outcomes']=[];r.fulfill(json={'ok':True});return
   if 'details' in query:r.fulfill(json=dict(logs=[],execution_signals=[],architecture=dict(analysis_enabled=False)));return
   if 'market_chart' in query:
    counts['chart']+=1;end=now//900*900
    bars=[dict(time=end-(64-i)*900,open=75000+i*2,high=75015+i*2,low=74990+i*2,close=75008+i*2,volume=2) for i in range(64)]
    r.fulfill(json=dict(exchange='wallex',symbol='BTCUSDT',bars=bars,gaps=[],timeframe='15m'));return
   r.fulfill(json=data);return
  file=root/'public'/(path or 'index.html')
  if not file.is_file():r.fulfill(status=404,body='');return
  r.fulfill(body=file.read_bytes(),content_type={'.html':'text/html; charset=utf-8','.js':'application/javascript; charset=utf-8','.css':'text/css'}.get(file.suffix,'application/octet-stream'))
 page.route('**/*',route);page.goto('http://127.0.0.1:5078/');page.wait_for_selector('#strategies .card');page.locator('#strategies [data-do=details]').click()
 page.wait_for_selector('[data-strategy-overview]')
 assert page.evaluate("!!(document.querySelector('[data-panel=strategy-outcomes]').compareDocumentPosition(document.querySelector('[data-strategy-overview]'))&Node.DOCUMENT_POSITION_FOLLOWING)")
 page.locator('[data-market-chart]>summary').click();page.wait_for_selector('[data-market-chart] svg');page.select_option('[data-chart-window]','16')
 page.evaluate("window.savedChart=document.querySelector('[data-market-chart]');window.savedSvg=savedChart.querySelector('svg')")
 for _ in range(3):page.evaluate('strategyDetails.refresh()')
 assert page.evaluate("savedChart===document.querySelector('[data-market-chart]')&&savedChart.open&&savedSvg===savedChart.querySelector('svg')")
 assert page.locator('[data-chart-window]').input_value()=='16' and counts['chart']==1
 page.locator('[data-market-chart]>summary').click();page.evaluate('strategyDetails.refresh()')
 assert not page.locator('[data-market-chart]').evaluate('(e)=>e.open')
 page.locator('[data-market-chart]>summary').click();assert counts['chart']==1
 out=root/'data/chart-persistence-ui';out.mkdir(exist_ok=True)
 page.locator('[data-market-chart]').scroll_into_view_if_needed();page.screenshot(path=str(out/'desktop.png'))
 page.set_viewport_size(dict(width=390,height=844));page.wait_for_timeout(200);page.locator('[data-market-chart]').scroll_into_view_if_needed();page.screenshot(path=str(out/'mobile.png'))
 assert page.locator('[data-chart-scroll]').evaluate('(e)=>e.scrollWidth<=e.clientWidth+1')
 assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1')
 page.locator('#closeDetails').click();page.locator('[data-delete-outcome="40"]').click();page.wait_for_function('document.querySelectorAll("#outcomeRows>details").length===0')
 assert counts['delete']==1
 page.evaluate('outcomeUI.render([{...'+json.dumps(outcome)+',can_manage:false}])')
 assert page.locator('[data-delete-outcome]').count()==0
 assert not errors,errors
 browser.close()
print('PASS chart DOM/open/zoom persistence, one fetch, readable mobile plot, overview order and permitted deletion')
