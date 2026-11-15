"""Real HTTP sessions against an isolated hosting build. No exchange or production I/O."""
import hashlib
import http.cookiejar
import importlib.util
import json
import os
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path

ROOT=Path(__file__).resolve().parent.parent
spec=importlib.util.spec_from_file_location('hosting_build',ROOT/'deploy/build.py')
builder=importlib.util.module_from_spec(spec);spec.loader.exec_module(builder)
PHP=os.environ.get('PHP_BINARY','php')

class Client:
    def __init__(self,url):
        self.url=url;self.cookies=http.cookiejar.CookieJar();self.csrf=''
        self.opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(self.cookies))
    def request(self,body=None,path='api.php',expected=200,token=None):
        headers={};raw=None
        if body is not None:
            headers={'Content-Type':'application/json','X-CSRF-Token':self.csrf if token is None else token};raw=json.dumps(body).encode()
        req=urllib.request.Request(self.url+path,data=raw,headers=headers)
        try:response=self.opener.open(req,timeout=10)
        except urllib.error.HTTPError as e:response=e
        content=response.read();assert response.status==expected,(response.status,content[:400])
        if path.startswith('api.php'):
            result=json.loads(content);self.csrf=result.get('csrf',self.csrf);return result
        return content.decode('utf-8')

def run(browser=False):
    with tempfile.TemporaryDirectory(prefix='wallex-accounts-') as directory:
        package=Path(directory)/'site';builder.build(package,'127.0.0.1','admin','',public_access=True,require_https=False)
        token='a'*64
        (package/'config/owner-bootstrap.php').write_text("<?php return ['token_hash'=>'"+hashlib.sha256(token.encode()).hexdigest()+"'];",encoding='utf-8')
        api=package/'public/api.php';api.write_text(api.read_text(encoding='utf-8').replace('$api=new Wallex();',"$api=new class extends Wallex {function price(string $symbol):float{return 75000.;}};"),encoding='utf-8')
        config=json.loads((ROOT/'example-btc-short.json').read_text(encoding='utf-8'));config['id']='legacy'
        fixture=package/'fixture.json';fixture.write_text(json.dumps(config),encoding='utf-8')
        def php(code):
            subprocess.run([PHP,'-r',"require "+builder.php_string(str(package/'src/App.php'))+';'+code],check=True,capture_output=True)
        php("$s=json_decode(file_get_contents("+builder.php_string(str(fixture))+"),true);db()->prepare('INSERT INTO strategies VALUES(?,?,?,?)')->execute(['legacy','v1',encode($s),encode(Strategy::fresh($s,time()))]);")
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        (package/'config/hosting.php').write_text("<?php return json_decode("+builder.php_string(json.dumps(dict(public_access=True,hosts=[f'127.0.0.1:{port}'],username='admin',password_hash='',require_https=False)))+",true);",encoding='utf-8')
        log=(Path(directory)/'server.log').open('w')
        server=subprocess.Popen([PHP,'-S',f'127.0.0.1:{port}','-t',str(package/'public')],stdout=log,stderr=log)
        url=f'http://127.0.0.1:{port}/'
        guest,a,b,owner=[Client(url) for _ in range(4)]
        try:
            for attempt in range(60):
                try:initial=guest.request();break
                except (OSError,urllib.error.URLError):time.sleep(.05)
            else:raise AssertionError('test server did not start')
            assert initial['user'] is None and initial['strategies'][0]['can_manage'] is False
            assert initial['logs']==[]
            guest.request(path='api.php?details=legacy')
            assert 'stages' in guest.request(path='guide.php?name=strategy&download=1')
            assert 'indicator_conditions' in guest.request(path='guide.php?name=indicators&download=1')
            guest.request(path='guide.php?name=../../.env',expected=404)
            guest.request(dict(action='save',json=json.dumps(config)),expected=401)
            guest.request(dict(action='toggle',id='legacy',expected_version='v1'),expected=401)
            guest.request(dict(action='register',email='ashkufaraz@gmail.com',name='imposter',password='test-password-123'),expected=400)
            guest.request(dict(action='activate_owner',email='ashkufaraz@gmail.com',password='test-password-123',token='b'*64),expected=403)
            for client,email,name in [(a,'a@example.com','Alice'),(b,'b@example.com','Bob')]:
                client.request();client.request(dict(action='register',email=email,name=name,password='test-password-123'))
            first=dict(config,id='alice');second=dict(config,id='bob')
            a.request(dict(action='save',json=json.dumps(first)));b.request(dict(action='save',json=json.dumps(second)))
            state=a.request();alice=next(x for x in state['strategies'] if x['config']['id']=='alice');bob=next(x for x in state['strategies'] if x['config']['id']=='bob')
            assert alice['can_manage'] and not bob['can_manage']
            for action in ['toggle','delete']:
                b.request(dict(action=action,id='alice',expected_version=alice['version']),expected=403)
            b.request(dict(action='save',json=json.dumps(first),expected_version=alice['version']),expected=403)
            a.request(dict(action='settings',interval=30),expected=403)
            a.request(dict(action='order_flow_visibility',strategy_id='bob',visible=False),expected=403)
            a.request(dict(action='toggle',id='alice',expected_version=alice['version']))
            a.request(dict(action='save',json=json.dumps(first),expected_version=next(x for x in a.request()['strategies'] if x['config']['id']=='alice')['version']))
            php("foreach(['alice','bob'] as $id){$q=db()->prepare('SELECT config FROM strategies WHERE id=?');$q->execute([$id]);recordAlert(json_decode($q->fetchColumn(),true),'v1','price',500,75000.);}")
            alerts=a.request()['alerts'];mine=next(x for x in alerts if x['payload']['strategy_id']=='alice');other=next(x for x in alerts if x['payload']['strategy_id']=='bob')
            assert mine['can_manage'] and not other['can_manage']
            for action in ['archive_alert','delete_alert','restore_alert']:b.request(dict(action=action,id=mine['id']),expected=403)
            a.request(dict(action='archive_alert',id=mine['id']));assert any(x['id']==mine['id'] for x in guest.request()['archived_alerts'])
            a.request(dict(action='restore_alert',id=mine['id']))
            a.request(dict(action='clear_alerts',id=max(x['id'] for x in alerts)))
            assert len(a.request()['alerts'])==1 and a.request()['alerts'][0]['id']==other['id']
            owner.request();owner.request(dict(action='activate_owner',email='ashkufaraz@gmail.com',password='owner-password-123',token=token))
            reserved=owner.request();assert reserved['can_manage_settings'] and next(x for x in reserved['strategies'] if x['config']['id']=='legacy')['can_manage']
            owner.request(dict(action='activate_owner',email='ashkufaraz@gmail.com',password='other-password-123',token=token),expected=403)
            owner.request(dict(action='delete',id='bob',expected_version=bob['version']),expected=403)
            owner.request(dict(action='settings',interval=45))
            b.request(dict(action='delete',id='bob',expected_version=bob['version']))
            a.request(dict(action='save',json=json.dumps(second)),expected=403)
            assert next(x for x in b.request()['alerts'] if x['id']==other['id'])['can_manage']
            old=a.csrf;a.request(dict(action='logout'));a.request(dict(action='comment',name='old',body='blocked'),token=old,expected=403)
            a.request(dict(action='login',email='a@example.com',password='wrong-password'),expected=401)
            a.request(dict(action='login',email='a@example.com',password='test-password-123'))
            guest.request(dict(action='comment',name='<img src=x onerror=alert(1)>',body='<script>window.hacked=1</script>'))
            assert guest.request()['comments'][0]['body'].startswith('<script>')
            if browser:
                from playwright.sync_api import sync_playwright
                with sync_playwright() as pw:
                    app=pw.chromium.launch(headless=True,executable_path='C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe')
                    page=app.new_page(viewport=dict(width=390,height=844));errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
                    page.goto(url,wait_until='networkidle');page.wait_for_selector('#commentList .public-comment')
                    assert not page.evaluate('window.hacked')
                    assert page.locator('#strategies [data-do=edit]').count()==0
                    assert page.locator('#alerts [data-delete-alert]').count()==0
                    assert page.locator('#preview').is_disabled()
                    page.locator('#openAccount').click();assert not page.locator('#accountNameLabel').is_visible()
                    page.locator('#accountEmail').fill('a@example.com');page.locator('#accountPassword').fill('test-password-123');page.locator('#submitAccount').click()
                    page.wait_for_selector('#logoutAccount');assert page.locator('#strategies [data-do=edit]').count()==1
                    page.locator('#logoutAccount').click();page.wait_for_selector('#openAccount');assert page.locator('#strategies [data-do=edit]').count()==0
                    page.locator('#commentName').fill('Public visitor');page.locator('#commentBody').fill('Hello from browser');page.locator('#submitComment').click()
                    page.wait_for_function("document.querySelector('#commentList').textContent.includes('Hello from browser')")
                    assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
                    assert not errors,errors
                    app.close()
            with sqlite3.connect(package/'data/app.sqlite') as db:
                assert all('password-123' not in row[0] for row in db.execute('SELECT password_hash FROM users WHERE password_hash IS NOT NULL'))
            db.close()
            with sqlite3.connect(package/'data/app.sqlite') as connection:connection.execute('DELETE FROM request_limits')
            connection.close()
            for i in range(5):guest.request(dict(action='comment',name='Visitor',body='Rate limit test'))
            guest.request(dict(action='comment',name='Visitor',body='Excess request'),expected=429)
            print('PASS isolated HTTP accounts, CSRF/session rotation, owner activation, cross-user strategy and alert restrictions, anonymous guides/comments'+(' and mobile UI/XSS' if browser else ''))
        finally:server.terminate();server.wait(timeout=10);log.close()

if __name__=='__main__':
    import sys
    run('--browser' in sys.argv)
