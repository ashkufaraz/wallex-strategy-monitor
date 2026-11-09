import importlib.util
import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent


def load(name, filename):
    spec = importlib.util.spec_from_file_location(name, ROOT / filename)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


builder = load('hosting_builder', 'deploy/build.py')
uploader = load('hosting_uploader', 'deploy/upload.py')


class DeploymentTests(unittest.TestCase):
    def test_protected_package_and_actual_authentication(self):
        with tempfile.TemporaryDirectory() as folder:
            output = Path(folder) / 'site'
            builder.build(output, 'demo.example.com', 'admin', 'example-password-for-tests')
            names = [name for name, _ in uploader.inventory(output)]
            self.assertIn('public/index.php', names)
            self.assertNotIn('worker.php', names)
            self.assertFalse(any(name.endswith(('.sqlite', '.log', '.lock')) for name in names))
            config = (output / 'config/hosting.php').read_text()
            self.assertNotIn('example-password-for-tests', config)
            self.assertIn('requireHostingAccess()', (output / 'public/api.php').read_text())
            self.assertNotIn('Local access only', (output / 'public/api.php').read_text())
            for directory in ['data', 'src', 'config']:
                self.assertIn('Require all denied', (output / directory / '.htaccess').read_text())
            php = os.environ.get('PHP_BINARY', 'php')
            source = '$_SERVER=json_decode($argv[2],true);require $argv[1];requireHostingAccess();echo "AUTHORIZED";'
            server = {'HTTP_HOST': 'demo.example.com', 'HTTPS': 'on', 'REMOTE_ADDR': '127.0.0.1',
                      'PHP_AUTH_USER': 'admin', 'PHP_AUTH_PW': 'example-password-for-tests'}
            def request(headers):
                return subprocess.check_output([php, '-r', source, str(output / 'src/HostingAccess.php'), json.dumps(headers)], text=True)
            self.assertEqual(request(server), 'AUTHORIZED')
            self.assertEqual(request({**server, 'PHP_AUTH_PW': 'bad'}), 'Authentication required.')
            self.assertEqual(request({**server, 'HTTP_HOST': 'evil.example.com'}), 'Host not allowed.')
            (output / 'data/leak.sqlite').write_text('not real data')
            with self.assertRaises(ValueError):
                uploader.inventory(output)

    def test_public_package_allows_anonymous_access_but_checks_host(self):
        with tempfile.TemporaryDirectory() as folder:
            output = Path(folder) / 'site'
            builder.build(output, 'demo.example.com', '', '', public_access=True)
            source = '$_SERVER=json_decode($argv[2],true);require $argv[1];requireHostingAccess();echo "AUTHORIZED";'
            def request(host):
                return subprocess.check_output([os.environ.get('PHP_BINARY', 'php'), '-r', source,
                    str(output / 'src/HostingAccess.php'), json.dumps({'HTTP_HOST': host, 'HTTPS': 'on'})], text=True)
            self.assertEqual(request('demo.example.com'), 'AUTHORIZED')
            self.assertEqual(request('evil.example.com'), 'Host not allowed.')
            self.assertFalse(list((output / 'data').glob('auth-*')))
            self.assertIn('csrf', (output / 'public/api.php').read_text())
            self.assertIn("define('HOSTING_BROWSER_CHECKS',true)", (output / 'public/api.php').read_text())
            self.assertIn('public/browser-runner.js', [n for n, _ in uploader.inventory(output)])

    def test_invalid_inputs_and_nonempty_destination_rejected(self):
        with tempfile.TemporaryDirectory() as folder:
            with self.assertRaises(ValueError):
                builder.build(Path(folder) / 'bad-host', 'https://example.com/path', 'admin', 'example-password-for-tests')
            with self.assertRaises(ValueError):
                builder.build(Path(folder) / 'bad-password', 'example.com', 'admin', 'weak')
            output = Path(folder) / 'existing'
            output.mkdir()
            (output / 'file').write_text('existing')
            with self.assertRaises(ValueError):
                builder.build(output, 'example.com', 'admin', 'example-password-for-tests')

    def test_missing_protection_rejected(self):
        with tempfile.TemporaryDirectory() as folder:
            (Path(folder) / 'index.php').write_text('<?php')
            with self.assertRaises(ValueError):
                uploader.inventory(folder)


if __name__ == '__main__':
    unittest.main()
