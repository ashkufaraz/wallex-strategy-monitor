"""Offline browser checks of chart-only cards, filters, toggles and alert wording."""
import json
from pathlib import Path
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parent.parent;samples=json.loads((root/'reports/pattern-alert-examples.json').read_text('utf8'))['signals'];signals=list(samples.values())
methods={id:dict(strategy_id=id,name=name,enabled=True,data_status='SUFFICIENT',pattern_status='PATTERN_CONFIRMED',execution_status='NOT_EVALUATED',reason='تأیید نموداری',last_signal=next(p for p in signals if p['strategy_id']==id)) for id,name in [('wallex-break-retest-v1','شکست و بازآزمایی'),('mean_reversion_v1','بازگشت به میانگین')]}
config=dict(enabled=True,interval_seconds=120,receipt_max_age_seconds=180,publication_max_delay_seconds=300,methods={id:dict(enabled=True) for id in methods})
for m in methods.values():
    m['last_successful_check']=1728000000
    m['data_health']={'15m':dict(latest_closed_candle=1728000000,consecutive=98,required=98,last_gap=None)}
fixture=dict(config=config,methods=methods,signals=signals,last_checked=1728000000,can_manage=True,real_orders=False)
base=dict(csrf='test',user=None,can_manage_settings=False,interval=120,heartbeat=0,strategies=[],alerts=[],archived_alerts=[],logs=[],comments=[])
with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,executable_path='C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe')
    for width in [390,1440]:
        page=browser.new_page(viewport=dict(width=width,height=1000));errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
        def route(r):
            url=r.request.url.split('http://127.0.0.1:5078/',1)[-1]
            if url.startswith('api.php'):
                if r.request.method=='POST':
                    body=r.request.post_data_json
                    if body['action']=='pattern_notification_test':
                        fixture['notification_test']=dict(send='NOT_CONFIRMED',device_receipt='UNCONFIRMED');r.fulfill(json=dict(ok=True));return
                    assert body['action']=='pattern_settings';fixture['config']=body['config']
                    for id,m in fixture['methods'].items():m['enabled']=body['config']['methods'][id]['enabled']
                    r.fulfill(json=dict(ok=True));return
                r.fulfill(json=fixture if '?patterns=' in url else base);return
            file=root/'public'/(url.split('?',1)[0] or 'index.html');r.fulfill(body=file.read_bytes(),content_type={'.html':'text/html; charset=utf-8','.js':'application/javascript; charset=utf-8','.css':'text/css; charset=utf-8'}.get(file.suffix,'application/octet-stream'))
        page.route('**/*',route);page.goto('http://127.0.0.1:5078/');page.wait_for_selector('#patternCards article',state='attached')
        assert not page.locator('#patternPanel').evaluate('(p)=>p.open')
        assert not page.locator('#patternCards').is_visible()
        assert page.evaluate("document.getElementById('patternPanel').nextElementSibling===document.getElementById('alerts').closest('section')")
        page.locator('#patternPanel > summary').click()
        page.wait_for_selector('#patternCards article')
        assert page.locator('#patternCards').inner_text().count('98 / 98')==2
        assert page.locator('#patternCards article').count()==2;assert page.locator('#patternSignals article').count()==3
        assert not page.locator('#scannerReport').is_visible();assert not page.locator('#patternCards details').first.get_attribute('open')
        page.locator('#patternMethodFilter').select_option('mean_reversion_v1');assert page.locator('#patternSignals article').count()==2
        page.locator('#patternDirectionFilter').select_option('BEARISH');assert page.locator('#patternSignals article').count()==1
        assert 'الگوی نزولی' in page.locator('#patternSignals').inner_text();assert 'شرایط اجرای معامله بررسی نشده' in page.locator('#patternSignals').inner_text()
        text=page.evaluate("p=>tradeDetails.notification({type:'scanner_pattern',message:p})",samples['mean_LONG']['message']);assert text==samples['mean_LONG']['message']
        page.locator('[data-pattern-toggle="wallex-break-retest-v1"]').click();page.wait_for_function("document.querySelector('#patternActionResult').textContent.includes('انجام')")
        assert fixture['config']['methods']['mean_reversion_v1']['enabled'] is True
        assert 'notification_test' not in fixture
        page.locator('#patternNotificationTest').click();page.wait_for_function("document.querySelector('#patternNotificationResult').textContent.length>0")
        assert len(fixture['signals'])==3
        fixture.pop('notification_test')
        assert not errors,errors;assert page.evaluate('document.documentElement.scrollWidth<=innerWidth');page.screenshot(path=str(root/f'data/pattern-ui-{width}.png'),full_page=True);page.close()
    browser.close()
print('PASS chart cards, independent execution text, filters, owner toggle, existing notification rendering, collapsed advanced view and mobile layout')
