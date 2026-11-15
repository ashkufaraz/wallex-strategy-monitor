"""Exercise a real service worker with a mobile-style illegal Notification constructor."""
import json
import threading
from pathlib import Path
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parent.parent
class Handler(SimpleHTTPRequestHandler):
    def __init__(self,*args,**kwargs):super().__init__(*args,directory=str(root/'public'),**kwargs)
    def log_message(self,*args):pass
    def do_GET(self):
        if self.path.startswith('/api.php'):
            body=json.dumps(dict(csrf='test',interval=60,heartbeat=0,strategies=[],alerts=[],archived_alerts=[],logs=[])).encode()
            self.send_response(200);self.send_header('Content-Type','application/json');self.end_headers();self.wfile.write(body);return
        super().do_GET()
server=ThreadingHTTPServer(('127.0.0.1',0),Handler)
threading.Thread(target=server.serve_forever,daemon=True).start()
try:
    with sync_playwright() as p:
        browser=p.chromium.launch(headless=True,executable_path='C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe')
        context=browser.new_context(permissions=['notifications'],is_mobile=True,viewport=dict(width=390,height=844))
        page=context.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
        page.add_init_script("window.Notification=new Proxy(Notification,{construct(){throw new TypeError('Illegal constructor. Use ServiceWorkerRegistration.showNotification');}})")
        page.goto(f'http://127.0.0.1:{server.server_port}/',wait_until='networkidle')
        page.locator('#test').click()
        page.wait_for_function('!!navigator.serviceWorker.controller')
        page.wait_for_function("!panelNotifications.processing")
        notes=page.evaluate("async()=>{const r=await navigator.serviceWorker.ready;return (await r.getNotifications()).map(x=>({title:x.title,tag:x.tag}));}")
        assert len(notes)==1,notes
        assert not errors,errors
        page.reload(wait_until='networkidle')
        assert page.evaluate('!!navigator.serviceWorker.controller')
        assert not errors,errors
        browser.close()
    print('PASS real mobile-style service worker registers, displays test notification and survives reload')
finally:server.shutdown()
