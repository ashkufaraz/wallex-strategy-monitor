"""Screenshots of actual UI scripts, with all network routed to isolated fixtures."""
import argparse
import json
import time
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parent.parent


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--output',default='data/order-flow-ui')
    output=ROOT/parser.parse_args().output
    output.mkdir(parents=True,exist_ok=True)
    now=int(time.time())
    strategy=json.loads((ROOT/'example-btc-short.json').read_text(encoding='utf-8'))
    strategy.update(id='flow-ui',name='بیت‌کوین؛ بررسی مستقل جریان سفارش‌ها',enabled=True)
    item={'config':strategy,'version':'ui-v1','state':{'stage':strategy['initial_stage'],'last_check':now}}
    signal={'id':'f'*64,'strategy_id':'flow-ui','version':'ui-v1','symbol':'BTCUSDT','name':strategy['name'],'action':'SHORT','mode':'MANUAL_ADVISORY','status':'WAITING_BASIS','reason':'Waiting for consecutive distinct normal samples','signal_time':now-30,'checked_at':now,'deadline':now+870,'confirmations':[{'at':now}], 'statistics':{'calibrated':False}}
    values={'300':{'window_seconds':300,'normalized_delta':-.6,'cvd_trend':'DOWN','volume_label':'HIGH','delta':-30,'cvd':-30,'relative_volume':1.8,'order_book':{'imbalance':-.6}}}
    record={'signal_id':signal['id'],'symbol':'BTCUSDT','kind':'SIGNAL','calculated_at':now,'trade_window_start':now-300,'trade_window_end':now,'depth_received_at':now,'data_completeness':'COMPLETE_WITHIN_REQUESTED_WINDOWS','indicator_values':values,'trade_count':500,'advisory_status':'SUPPORTIVE'}
    flow={'enabled':True,'visible':True,'max_age_seconds':90,'processor_last_seen':now,'results':[record]}
    details={'logs':[],'execution_signals':[signal],'architecture':{'analysis_enabled':True,'manual_advisory_enabled':True,'enabled':False,'version_approved':True,'max_quote_age_seconds':10},'order_flow':flow}
    response={'csrf':'fixture','browser_checks':False,'order_flow_enabled':True,'order_flow_browser_enabled':False,'heartbeat':0,'interval':60,'strategies':[item],'alerts':[]}
    requests=[];errors=[];screenshots=[]
    with sync_playwright() as pw:
        edge=Path('C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe')
        browser=pw.chromium.launch(headless=True,**({'executable_path':str(edge)} if edge.exists() else {}))
        context=browser.new_context(viewport={'width':1440,'height':1100},locale='fa-IR')
        page=context.new_page()
        page.on('pageerror',lambda error:errors.append(str(error)))

        def route(request_route):
            request=request_route.request
            requests.append({'method':request.method,'url':request.url})
            url=request.url.split('127.0.0.1:5078',1)[-1]
            if url.startswith('/api.php'):
                if request.method=='POST':
                    data=request.post_data_json
                    assert data['action']=='order_flow_visibility','Unexpected mutation'
                    flow['visible']=data['visible']
                    request_route.fulfill(json={'ok':True})
                else:
                    request_route.fulfill(json=details if '?details=' in url else response)
                return
            path=(ROOT/'public'/('index.html' if url=='/' else url.split('?',1)[0].lstrip('/'))).resolve()
            if not path.is_relative_to((ROOT/'public').resolve()) or not path.is_file():
                request_route.fulfill(status=404,body='not found');return
            content_type={'.html':'text/html; charset=utf-8','.js':'application/javascript; charset=utf-8','.css':'text/css; charset=utf-8','.woff2':'font/woff2'}.get(path.suffix,'application/octet-stream')
            request_route.fulfill(body=path.read_bytes(),content_type=content_type)

        context.route('**/*',route)
        page.goto('http://127.0.0.1:5078/')
        page.get_by_role('button',name='جزئیات بررسی',exact=True).click()
        page.wait_for_selector('.order-flow')
        page.evaluate('document.fonts.ready')
        assert page.evaluate('document.querySelector(".advisory-panel").compareDocumentPosition(document.querySelector(".order-flow")) & Node.DOCUMENT_POSITION_FOLLOWING')
        for viewport,width,height in [('desktop',1440,1100),('mobile',390,844)]:
            page.set_viewport_size({'width':width,'height':height})
            for status in ['SUPPORTIVE','CONTRADICTORY','MIXED','UNAVAILABLE']:
                record['advisory_status']=status
                record['data_completeness']='UNVERIFIED' if status=='UNAVAILABLE' else 'COMPLETE_WITHIN_REQUESTED_WINDOWS'
                record['indicator_values']={} if status=='UNAVAILABLE' else values
                v=values['300']
                v.update(normalized_delta=-.6 if status=='SUPPORTIVE' else .6 if status=='CONTRADICTORY' else 0,cvd_trend='DOWN' if status=='SUPPORTIVE' else 'UP' if status=='CONTRADICTORY' else 'FLAT')
                v.update(delta=v['normalized_delta']*50,cvd=v['normalized_delta']*50)
                v['order_book']['imbalance']=v['normalized_delta']
                page.evaluate('strategyDetails.refresh()')
                page.wait_for_selector(f'[data-flow-kind="SIGNAL"][data-flow-status="{status}"]')
                assert page.locator('#strategyDetails').evaluate('(el)=>el.scrollWidth<=el.clientWidth+1')
                assert signal['status']=='WAITING_BASIS' and response['alerts']==[]
                filename=f'{viewport}-{status.lower()}.png'
                # Preserve the tested 390px width, but use a taller capture
                # canvas so the modal's scroll clipping cannot crop the card.
                if viewport=='mobile':page.set_viewport_size({'width':width,'height':1800})
                page.locator('[data-flow-kind="SIGNAL"]').screenshot(path=str(output/filename))
                if viewport=='mobile':page.set_viewport_size({'width':width,'height':height})
                screenshots.append(filename)
        # A stale later analysis must not appear as positive while the original
        # captured signal-time result remains a separately labelled history.
        record.update(advisory_status='SUPPORTIVE',data_completeness='COMPLETE_WITHIN_REQUESTED_WINDOWS',indicator_values=values)
        flow['results']=[record,{**record,'kind':'LATEST','trade_window_end':now-500}]
        page.evaluate('strategyDetails.refresh()')
        page.wait_for_selector('[data-flow-kind="LATEST"][data-flow-status="UNAVAILABLE"]')
        assert page.locator('[data-flow-kind="SIGNAL"][data-flow-status="SUPPORTIVE"]').count()==1
        page.get_by_role('button',name='پنهان کردن تحلیل کمکی',exact=True).click()
        page.get_by_role('button',name='نمایش تحلیل کمکی',exact=True).wait_for()
        assert page.locator('.order-flow-result').count()==0
        page.get_by_role('button',name='نمایش تحلیل کمکی',exact=True).click()
        page.wait_for_selector('.order-flow-result')
        assert not errors,errors
        assert all(r['url'].startswith('http://127.0.0.1:5078/') for r in requests)
        evidence={'fixture_only':True,'real_exchange_calls':0,'order_requests':0,'viewports':[1440,390],'states':['SUPPORTIVE','CONTRADICTORY','MIXED','UNAVAILABLE'],'screenshots':screenshots,'javascript_errors':errors,'historical_latest_separation':True,'stale_positive_masked':True,'visibility_toggle':True,'main_status_unchanged':True,'passed':True}
        (output/'evidence.json').write_text(json.dumps(evidence,indent=2)+'\n',encoding='utf-8')
        browser.close()
    print('Order Flow real UI scripts: all four states on desktop/mobile, no overflow, stale masking, history separation, visibility toggles and zero order/exchange requests passed.')


if __name__=='__main__':
    main()
