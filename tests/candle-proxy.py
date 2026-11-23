import base64
import importlib.util
import json
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location('candle_proxy', Path(__file__).resolve().parents[1] / 'deploy/candle_proxy.py')
proxy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(proxy)
UUID = '01234567-0123-4123-8123-0123456789ab'


class SubscriptionTests(unittest.TestCase):
    def test_vmess_base64_and_duplicate_nodes(self):
        node = {'add': 'example.com', 'port': '443', 'id': UUID, 'net': 'ws', 'tls': 'tls',
                'path': '/socket?ed=2048', 'host': 'cdn.example.com', 'sni': 'tls.example.com', 'alpn': 'http/1.1'}
        uri = 'vmess://' + base64.b64encode(json.dumps(node).encode()).decode().rstrip('=')
        subscription = base64.b64encode((uri + '\n' + uri).encode()).decode().rstrip('=')
        nodes = proxy.subscription_nodes(subscription)
        self.assertEqual(len(nodes), 1)
        self.assertEqual(nodes[0]['streamSettings']['tlsSettings']['serverName'], 'tls.example.com')
        self.assertFalse(nodes[0]['streamSettings']['tlsSettings']['allowInsecure'])
        self.assertEqual(nodes[0]['streamSettings']['wsSettings']['path'], '/socket?ed=2048')

    def test_xhttp_preserves_encoded_path_and_extra(self):
        uri = f'vless://{UUID}@example.com:443?type=xhttp&security=tls&path=%2Fstream%3Fa%3D1&host=cdn.example.com&mode=auto&extra=%7B%22noGRPCHeader%22%3Atrue%7D'
        node = proxy.subscription_nodes(uri)[0]
        self.assertEqual(node['streamSettings']['xhttpSettings']['path'], '/stream?a=1')
        self.assertTrue(node['streamSettings']['xhttpSettings']['extra']['noGRPCHeader'])
        self.assertEqual(node['settings']['vnext'][0]['users'][0]['id'], UUID)

    def test_rejects_plaintext_and_malformed_nodes(self):
        for value in ['vmess://bad', f'vless://{UUID}@example.com:443?security=none&type=ws',
                      f'vless://invalid@example.com:443?security=tls&type=ws']:
            with self.assertRaises(ValueError):
                proxy.subscription_nodes(value)


if __name__ == '__main__':
    unittest.main()
