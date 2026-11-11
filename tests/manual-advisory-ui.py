"""Render actual panel scripts with isolated HTTP fixtures; never contact an exchange or real PHP."""
import argparse
import json
import os
import time
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parent.parent


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--output', default='data/manual-advisory-ui')
    args = parser.parse_args()
    output = ROOT / args.output
    output.mkdir(parents=True, exist_ok=True)
    now = int(time.time())
    strategy = json.loads((ROOT / 'example-btc-short.json').read_text(encoding='utf-8'))
    strategy.update(id='isolated-ui', name='بیت‌کوین؛ بررسی ورود دستی', action='SHORT', enabled=True)
    strategy['trade'] = {'entry': {'type': 'MARKET'}, 'quantity': .001, 'stop_loss': 75000, 'take_profits': [72000, 71000, 70000]}
    strategy['initial_stage'] = 'entry'
    strategy['stages'] = [{'id':'entry','transitions':[{'event':'bar_close:15m','signal':True,'when':{'all':[{'left':{'candle':{'field':'low','timeframe':'15m','offset':1}},'op':'lte','right':74600},{'left':{'candle':{'field':'high','timeframe':'15m','offset':1}},'op':'gte','right':74300}]}}]}, {'id':'other','transitions':[{'event':'price','signal':True,'when':{'left':{'price':True},'op':'gt','right':75000}}]}]
    strategy['indicator_conditions'] = {'enabled':True}
    item = {'config': strategy, 'version': 'ui-v1', 'state': {'stage': strategy['initial_stage'], 'last_check': now, 'last_price': 74000, 'price_at': now}}
    candle_close = now//900*900
    indicator_snapshot = {'as_of':now,'conditions':[{'id':name.lower(),'indicator':name,'period':period,'timeframe':'1h','status':status,'symbol':'BTCUSDT','metric':'value','observed':value,'operator':'lt','value':target,'candle_open':candle_close-3600,'candle_close':candle_close} for name,period,status,value,target in [('RSI',14,'PASS',37,50),('EMA',20,'FAIL',74010,74000),('ATR',14,'UNAVAILABLE',None,1000),('Volume',20,'PASS',12,20)]]}
    item['state']['indicators']=indicator_snapshot
    leaves=[]
    for field,observed,target,result,op in [('low',74000,74600,True,'lte'),('high',74249,74300,False,'gte')]:
        leaves.append({'left_source':{'candle':{'field':field,'timeframe':'15m','offset':1}},'right_source':target,'left':observed,'right':target,'op':op,'result':result,'left_candle':{'provider':'binance_spot','field':field,'timeframe':'15m','open_time':candle_close-900,'close_time':candle_close,'ohlcv':{'open':74000,'high':74249,'low':74000,'close':74249,'volume':1}}})
    report={'checked':now,'status':'checked','stage':'entry','from_stage':'entry','price':74000,'trace':[],'waiting':[{'stage':'entry','rule':1,'condition':{'group':'all','result':False,'children':leaves}}]}
    signal = dict(id='a'*64, strategy_id=strategy['id'], name=strategy['name'], symbol='BTCUSDT', version='ui-v1', action='SHORT', mode='MANUAL_ADVISORY', status='MANUAL_REVIEW_AVAILABLE', reason='شرایط تخمینی برای بررسی ورود دستی فراهم است؛ تازگی دفتر و اجرای تعهدی تأیید نشده‌اند.', signal_price=74000, signal_time=now-120, signal_event='bar_close:15m', checked_at=now, deadline=now+780, stop=75000, tp1=72000, take_profits=[72000,71000,70000], minimum_rr=1.5, basis=.02, confirmations=[{'at':now-20},{'at':now-10},{'at':now}], statistics={'calibrated':True,'median':.01,'tolerance':.1,'calibration_count':120,'window_count':60}, validity={'current_price':74000}, quotes={'reference':{'mid':74000,'timestamp':now,'observed_at':now},'wallex':{'bid':74010,'ask':74020,'spread':10,'received_at':now,'timestamp':None,'trust_status':'UNVERIFIED'}}, estimate={'vwap':74009,'requested_base_quantity':.001,'visible_base_quantity':.4}, rr={'entry':74009,'stop':75015,'take_profits':[72014,71014,70014],'gross_rr':1.98})
    response = {'csrf':'fixture','browser_checks':False,'heartbeat':0,'interval':60,'strategies':[item],'alerts':[]}
    details = {'logs':[{'id':1,'version':'ui-v1','report':report}], 'execution_signals':[signal], 'architecture':{'analysis_enabled':True,'manual_advisory_enabled':True,'enabled':False,'version_approved':True,'confirmation_samples':3,'max_quote_age_seconds':10}}
    requests = []
    errors = []
    with sync_playwright() as pw:
        edge = Path('C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe')
        browser = pw.chromium.launch(headless=True, **({'executable_path':str(edge)} if edge.exists() else {}))
        context = browser.new_context(viewport={'width':1440,'height':1100}, locale='fa-IR')
        page = context.new_page()
        page.on('pageerror', lambda error: errors.append(str(error)))
        page.add_init_script("window.testNotifications=[]; window.Notification=class {static permission='granted';constructor(title, options){window.testNotifications.push({title,...options});}};")

        def route(request_route):
            request = request_route.request
            requests.append({'method':request.method,'url':request.url})
            url = request.url.split('127.0.0.1:5078',1)[-1]
            if url.startswith('/api.php'):
                assert request.method == 'GET', 'Unexpected mutation request in UI test'
                request_route.fulfill(json=details if '?details=' in url else response)
                return
            relative = 'index.html' if url == '/' else url.split('?',1)[0].lstrip('/')
            path = (ROOT / 'public' / relative).resolve()
            if not path.is_relative_to((ROOT/'public').resolve()) or not path.is_file():
                request_route.fulfill(status=404,body='not found')
                return
            content_type = {'.html':'text/html; charset=utf-8','.js':'application/javascript; charset=utf-8','.css':'text/css; charset=utf-8','.woff2':'font/woff2'}.get(path.suffix,'application/octet-stream')
            request_route.fulfill(body=path.read_bytes(),content_type=content_type)

        context.route('**/*', route)
        page.goto('http://127.0.0.1:5078/')
        page.get_by_role('button',name='جزئیات بررسی',exact=True).click()
        page.wait_for_selector('.advisory-panel')
        page.evaluate('document.fonts.ready')
        assert page.locator('.advisory-panel').inner_text().find('آماده بررسی') >= 0 or page.get_by_text('آماده بررسی برای ورود دستی',exact=True).count()
        assert page.get_by_text('فعال بودن پایش تأیید نشده',exact=False).count()
        assert page.locator('[data-manual-signal]').count() == 0
        assert all(page.locator(f'[data-status="{status}"]').count()>0 for status in ['PASS','FAIL','UNAVAILABLE'])
        assert page.locator('#detailsContent').inner_text().find('74,249')>=0
        assert page.locator('#detailsContent').inner_text().find('پیش‌نمایش آخرین کندل')>=0
        # Both alerts go through the actual polling and Notification constructor.
        for index, kind in enumerate(['binance_signal','manual_review'],1):
            response['alerts'].insert(0,{'id':index,'created':now,'payload':{'type':kind,'name':strategy['name'],'symbol':'BTCUSDT','action':'SHORT','price':74000,'event_time':now}})
            page.evaluate('refresh()')
        assert page.evaluate('window.testNotifications.length') == 2
        assert 'UNVERIFIED' in page.evaluate('window.testNotifications[1].body')
        for name,width,height in [('desktop',1440,1100),('mobile',390,844)]:
            page.set_viewport_size({'width':width,'height':height})
            page.locator('#strategyDetails').evaluate('(el)=>el.scrollTop=0')
            page.wait_for_timeout(100)
            assert page.locator('#strategyDetails').evaluate('(el)=>el.scrollWidth<=el.clientWidth+1'), 'Horizontal overflow '+name
            page.screenshot(path=str(output / f'{name}.png'))
            page.locator('.detail-condition-columns').scroll_into_view_if_needed()
            page.screenshot(path=str(output / f'{name}-conditions.png'))
            for state in ['WAITING_CALIBRATION','WAITING_DATA','EXPIRED_TIMEOUT','CANCELLED_STOP_LOSS']:
                details['execution_signals'][0]['status']=state
                page.evaluate('strategyDetails.refresh()')
                page.wait_for_function('(s)=>document.querySelector("#detailsContent").innerHTML.includes(s)',arg=state)
                assert page.locator('[data-manual-signal]').count()==0
            details['execution_signals'][0]['status']='MANUAL_REVIEW_AVAILABLE'
            page.evaluate('strategyDetails.refresh()')
            page.wait_for_function('document.querySelector("#detailsContent").innerHTML.includes("MANUAL_REVIEW_AVAILABLE")')
        assert not errors, errors
        assert all(x['method']=='GET' and x['url'].startswith('http://127.0.0.1:5078/') for x in requests)
        (output/'evidence.json').write_text(json.dumps({'isolated':True,'real_exchange_calls':0,'mutation_requests':0,'notification_constructor_calls':2,'browser_errors':errors,'viewports':[1440,390],'screenshots':['desktop.png','mobile.png','desktop-conditions.png','mobile-conditions.png'],'passed':True},indent=2)+'\n',encoding='utf-8')
        browser.close()
    print('Actual panel browser test passed: desktop/mobile, no horizontal overflow, two notifications, four blocking states, GET-only fixtures.')


if __name__ == '__main__':
    main()
