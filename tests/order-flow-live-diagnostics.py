"""Validate saved real diagnostic evidence without network or persistent SQLite."""
import json
import subprocess
from pathlib import Path

root = Path(__file__).resolve().parent.parent
path = root / 'data/order-flow-live-btc-diagnostic-v2.json'
report = json.loads(path.read_text(encoding='utf-8'))
assert report['kind'] == 'LIVE_DIAGNOSTIC'
assert report['symbol'] == 'BTCUSDT'
assert report['persistent_database'] is False
assert report['signal_snapshot_modified'] is False
assert report['production_enabled'] is False
assert set(report['windows']) == {'60', '300'}
for seconds, window in report['windows'].items():
    assert window['end'] - window['start'] == int(seconds)
    assert window['end'] % 60 == 0
    if window['status'] == 'UNAVAILABLE':
        assert window['metrics'] is None
for request in report['requests']:
    assert request['url'].startswith('https://data-api.binance.vision/api/v3/')
    assert request['tls_verification_enabled'] is True
    if request['http_status'] == 0:
        assert request['tls_verified'] is False
assert report['resources']['request_count'] <= 16
assert report['disabled_step_benchmark']['network_requests'] == 0
before = path.read_bytes()
source = (root/'tools/order-flow-live-diagnostics.php').read_text(encoding='utf-8')
assert "define('TEST_DATABASE',':memory:')" in source
assert 'CURLOPT_SSL_VERIFYPEER=>true' in source
assert 'CURLOPT_SSL_VERIFYHOST=>2' in source
assert 'Refusing to overwrite existing evidence' in source
assert path.read_bytes() == before
print('Saved live diagnostic evidence: isolation, UTC windows, missing-data handling, TLS and request budget passed.')
