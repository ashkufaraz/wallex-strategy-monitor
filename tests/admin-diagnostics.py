"""HTTP tests of admin access, using an isolated temporary PHP site with no SQLite."""
import http.cookiejar
import json
import re
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent


class AdminDiagnostics(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix='wallex-admin-test-')
        cls.root = Path(cls.temp.name)
        (cls.root/'public').mkdir()
        (cls.root/'config').mkdir()
        shutil.copy2(ROOT/'public/admin-diagnostics.php', cls.root/'public')
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        cls.url = f'http://127.0.0.1:{port}/admin-diagnostics.php'
        cls.server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(cls.root/'public')],
                                      stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                                      creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
        cls.hashed = subprocess.check_output(['php', '-r', "echo password_hash('test-only-password', PASSWORD_DEFAULT);"]).decode()
        for _ in range(40):
            try:
                urllib.request.build_opener(urllib.request.ProxyHandler({})).open(cls.url, timeout=1)
            except urllib.error.HTTPError:
                break
            except OSError:
                time.sleep(0.05)

    @classmethod
    def tearDownClass(cls):
        cls.server.terminate()
        cls.server.wait(timeout=5)
        cls.temp.cleanup()

    def setUp(self):
        self.client = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.config = self.root/'config/diagnostics-admin.php'
        self.config.write_text(f"<?php return ['password_hash'=>'{self.hashed}','expires'=>{int(time.time())+600}];")

    def request(self, data=None, suffix=''):
        payload = urllib.parse.urlencode(data).encode() if data else None
        try:
            response = self.client.open(self.url+suffix, data=payload, timeout=5)
        except urllib.error.HTTPError as error:
            response = error
        return response.code, response.read().decode(), response.headers

    def test_disabled_without_config(self):
        self.config.unlink()
        self.assertEqual(self.request()[0], 404)

    def test_expired_config(self):
        self.config.write_text("<?php return ['password_hash'=>'unused','expires'=>1];")
        self.assertEqual(self.request()[0], 404)

    def test_unauthenticated_does_not_expose_diagnostics(self):
        code, body, headers = self.request(suffix='?ping=1')
        self.assertEqual(code, 200)
        self.assertIn('type="password"', body)
        self.assertNotIn('api.wallex.ir', body)
        self.assertNotIn('X-Diagnostics-PHP', headers)
        self.assertEqual(self.request({'password': 'wrong'})[0], 200)
        self.assertIn('Sign in', self.request()[1])

    def test_authenticated_ping_and_csrf(self):
        code, body, _ = self.request({'password': 'test-only-password'})
        self.assertIn('Read-only network diagnostics', body)
        self.assertNotIn(self.hashed, body)
        token = re.search('name="csrf" value="([a-f0-9]+)"', body).group(1)
        self.assertEqual(len(token), 48)
        code, body, headers = self.request(suffix='?ping=1')
        self.assertEqual(code, 200)
        self.assertEqual(headers['X-Diagnostics-PHP'], 'reached')
        self.assertTrue(json.loads(body)['read_only'])
        self.assertEqual(self.request({'run': '1', 'csrf': 'wrong'})[0], 403)
        self.assertFalse((self.root/'data').exists())


if __name__ == '__main__':
    unittest.main()
