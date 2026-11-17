"""Browser verification of scanner audit, settings and responsive public display."""
import json,os,subprocess
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parent.parent
code="define('TEST_DATABASE',':memory:');require 'src/App.php';require 'tests/scanner-fixture.php';[$c,$d,$b]=scannerFixture();$r=ScannerRunner::backtest($d,$c);echo encode(['config'=>$c,'version'=>ScannerConfig::version($c),'enabled'=>true,'last_checked'=>$b+3600,'last_result'=>['decision'=>['status'=>'UNAVAILABLE','reason'=>'Source timestamp unavailable']],'signals'=>$r['signals'],'checks'=>[['observed'=>$b,'status'=>'REJECTED','reason'=>'fixture <script>window.hacked=1</script>','decision'=>$r['decisions'][0]]],'trades'=>$r['trades'],'report'=>$r['report'],'last_backtest'=>null,'can_manage'=>true,'real_orders'=>false]);"
fixture=json.loads(subprocess.check_output([os.environ.get('PHP_BINARY','php'),'-r',code],cwd=ROOT))
base=dict(csrf='test',user=None,can_manage_settings=False,interval=60,heartbeat=0,strategies=[],alerts=[],archived_alerts=[],logs=[],comments=[])
with sync_playwright() as pw:
    browser=pw.chromium.launch(headless=True,executable_path='C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe')
    page=browser.new_page(viewport=dict(width=390,height=844));errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
    def route(r):
        url=r.request.url.split('http://127.0.0.1:5078/',1)[-1]
        if url.startswith('api.php'):
            if r.request.method=='POST':
                body=r.request.post_data_json;assert body['action']=='scanner_settings'
                fixture['config']=body['config'];r.fulfill(json=dict(ok=True));return
            r.fulfill(json=fixture if '?scanner=' in url else base);return
        path=ROOT/'public'/(url.split('?',1)[0] or 'index.html')
        r.fulfill(body=path.read_bytes(),content_type={'.html':'text/html; charset=utf-8','.js':'application/javascript; charset=utf-8','.css':'text/css; charset=utf-8'}.get(path.suffix,'application/octet-stream'))
    page.route('**/*',route);page.goto('http://127.0.0.1:5078/');page.wait_for_function("document.querySelector('#scannerStatus').textContent.includes('UNAVAILABLE')")
    assert page.locator('#scannerReport').text_content().find('Profit Factor')>=0
    assert not page.locator('#scannerChecks').is_visible()
    page.locator('#scannerSignals').locator('..').locator('summary').first.click()
    page.locator('#scannerSignals details summary').click()
    with page.expect_download() as download:page.locator('[data-scanner-json]').click()
    assert download.value.suggested_filename.endswith('.json')
    page.locator('#scannerSettings > summary').click()
    c=fixture['config'].copy();c['enabled']=False;page.locator('#scannerConfig').fill(json.dumps(c));page.locator('[data-scanner-action=save]').click()
    page.wait_for_function("document.querySelector('#scannerActionResult').textContent.includes('انجام')")
    assert fixture['config']['enabled'] is False
    assert not page.evaluate('window.hacked')
    assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
    assert not errors,errors
    page.screenshot(path=str(ROOT/'data/scanner-mobile.png'),full_page=True)
    browser.close()
print('PASS scanner mobile layout, audit escaping, signal JSON download and owner configuration')
