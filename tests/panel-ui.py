"""Verify panel layout and archive using isolated responses, without market I/O."""
import json
from pathlib import Path
from playwright.sync_api import sync_playwright

root = Path(__file__).resolve().parent.parent
config = json.loads((root/'example-btc-short.json').read_text(encoding='utf-8'))
items = [dict(config=dict(config,id=str(i),enabled=i != 1),version=str(i),state=dict(stage=config['initial_stage'])) for i in range(5)]
items[4]['state'].update(cancelled_at=123,cancel_reason='cancelled fixture')
response = dict(csrf='test',interval=60,heartbeat=0,strategies=items,alerts=[],logs=[dict(created=1,level='error',event='test.error',message='offline',context={'strategy_id':'2'})])
response['alerts']=[dict(id=1,created=1,payload=dict(type='entry_conditions_only',strategy_id='0',name='Partial entry',symbol='BTCUSDT',action='SHORT',price=100,event_time=1,reason='Entry conditions only; indicators failed'))]
response['archived_alerts']=[]
with sync_playwright() as pw:
    browser=pw.chromium.launch(headless=True,executable_path='C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe')
    page=browser.new_page(viewport=dict(width=1440,height=1000))
    errors=[]
    page.on('pageerror',lambda e:errors.append(str(e)))
    def route(r):
        url=r.request.url.split('http://127.0.0.1:5078/',1)[-1].split('?',1)[0]
        if url=='api.php':
            if r.request.method=='POST':
                action=r.request.post_data_json['action']
                assert action in ['archive_alert','restore_alert']
                source,target=('alerts','archived_alerts') if action=='archive_alert' else ('archived_alerts','alerts')
                response[target].append(response[source].pop())
                r.fulfill(json={'ok':True});return
            r.fulfill(json=response);return
        path=root/'public'/(url or 'index.html')
        r.fulfill(body=path.read_bytes(),content_type={'.html':'text/html; charset=utf-8','.js':'application/javascript; charset=utf-8','.css':'text/css; charset=utf-8'}.get(path.suffix,'application/octet-stream'))
    page.route('**/*',route)
    page.goto('http://127.0.0.1:5078/')
    page.wait_for_selector('#strategies .card')
    page.locator('[data-archive-alert="1"]').click()
    page.wait_for_function("document.querySelectorAll('#alerts .alert').length===0")
    page.locator('#archivedAlerts').click()
    page.wait_for_selector('[data-archive-alert="1"]')
    assert page.locator('.entry-only-warning').count()==1
    page.locator('[data-archive-alert="1"]').click()
    page.wait_for_function("document.querySelectorAll('#alerts .alert').length===0")
    page.locator('#currentAlerts').click()
    page.wait_for_selector('[data-archive-alert="1"]')
    assert page.locator('#strategies .card').count()==3
    assert page.locator('#strategies .card').nth(0).bounding_box()['y']==page.locator('#strategies .card').nth(1).bounding_box()['y']
    assert page.locator('#json').bounding_box()['y']>page.locator('#strategies').bounding_box()['y']
    page.locator('#archivedStrategies').click()
    page.wait_for_function("document.querySelectorAll('#strategies .card').length===2")
    assert page.locator('#strategies [data-do=toggle]').nth(0).get_attribute('data-index')=='1'
    assert page.locator('#strategies [data-do=toggle]').nth(1).get_attribute('data-index')=='4'
    assert not page.locator('#systemLogs').get_attribute('open')
    page.locator('#systemLogs > summary').click()
    page.locator('#logLevel').select_option('error')
    assert page.locator('.log-error').count()==1
    page.locator('#logSearch').fill('missing term')
    assert page.locator('.log-entry').count()==0
    page.locator('#logSearch').fill('')
    page.locator('#activeStrategies').click()
    page.set_viewport_size(dict(width=390,height=844))
    page.wait_for_function("document.querySelectorAll('#strategies .card').length===3")
    assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
    assert not errors,errors
    (root/'data/panel-ui-check').mkdir(exist_ok=True)
    page.screenshot(path=str(root/'data/panel-ui-check/mobile-panel.png'),full_page=True)
    browser.close()
print('PASS full width grid, archive indices, editor order, log filters, mobile overflow and browser errors')
