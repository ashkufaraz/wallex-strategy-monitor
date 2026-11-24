"""Regression: very long strategy names must not widen page or strategy dialog."""
import json
from pathlib import Path
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parent.parent
config=json.loads((root/'public/example.json').read_text('utf8'));config['id']='mobile_fixture';config['name']='Strategy name '*150
item=dict(config=config,version='v1',can_manage=False,state=dict(stage=config['initial_stage'],last_check=100))
outcome=dict(alert_id=1,strategy_id=config['id'],exchange='binance',cohort='confirmed',started=100,last_check=200,snapshot=dict(name=config['name'],entry_price=100),state=dict(status='OPEN',tp1_result=None,targets=[]))
base=dict(csrf='test',user=None,can_manage_settings=False,interval=120,heartbeat=0,strategies=[item],strategy_outcomes=[outcome],alerts=[],archived_alerts=[],logs=[],comments=[])
details=dict(logs=[],architecture=dict(analysis_enabled=False),execution_signals=[])
with sync_playwright() as p:
 browser=p.chromium.launch(headless=True,executable_path='C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe')
 for width in [320,360,390,768]:
  page=browser.new_page(viewport=dict(width=width,height=850));errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
  def route(r):
   path=r.request.url.split('http://127.0.0.1:5078/',1)[-1]
   if path.startswith('api.php'):
    r.fulfill(json=details if '?details=' in path else dict(markets={}) if 'all_markets' in path else base);return
   file=root/'public'/(path.split('?',1)[0] or 'index.html');r.fulfill(body=file.read_bytes(),content_type={'.js':'application/javascript','.css':'text/css','.html':'text/html'}.get(file.suffix,'application/octet-stream'))
  page.route('**/*',route);page.goto('http://127.0.0.1:5078/',wait_until='networkidle')
  assert page.locator('#outcomeStrategy option').count()==2
  assert page.evaluate('document.documentElement.scrollWidth<=innerWidth'),(width,page.evaluate('document.documentElement.scrollWidth'))
  assert page.evaluate("document.getElementById('patternPanel').nextElementSibling.id==='scannerAdvanced'")
  page.locator('[data-do=details]').click();page.wait_for_selector('#strategyDetails[open] .detail-safety')
  assert page.locator('.detail-safety').evaluate("e=>e.nextElementSibling.dataset.panel==='strategy-outcomes'")
  bounds=page.locator('#strategyDetails').bounding_box();assert bounds['x']>=0 and bounds['x']+bounds['width']<=width+1
  assert page.locator('#strategyDetails').evaluate('e=>e.scrollWidth<=e.clientWidth'),width
  assert not errors,errors
  page.screenshot(path=str(root/f'data/nov24-mobile-{width}.png'),full_page=True);page.close()
 browser.close()
print('PASS long strategy selector, page and dialog fit 320/360/390/768px; result and advanced panel order')
