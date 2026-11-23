"""Provision a private Xray client over verified FTPS; never log subscription credentials."""
import base64
import ftplib
import hashlib
import io
import json
import os
import ssl
import time
import urllib.parse
import urllib.request
import urllib.error
import uuid
import zipfile

XRAY_URL = 'https://github.com/XTLS/Xray-core/releases/download/v26.11.9/Xray-linux-64.zip'
XRAY_SHA256 = 'b651f7e1d9966ee2c1b1ee1bf8b52c72771824d3065dce96bbc8bcc19ed95f82'


def decode64(value):
    return base64.b64decode(value + '=' * (-len(value) % 4)).decode('utf-8')


def subscription_nodes(text):
    if '://' not in text:
        text = decode64(text.strip())
    nodes = []
    for line in text.splitlines():
        try:
            uri = urllib.parse.urlsplit(line.strip())
            if uri.scheme == 'vmess':
                d = json.loads(decode64(line.split('://', 1)[1]))
                protocol, host, port = 'vmess', d['add'], int(d['port'])
                user = {'id': str(uuid.UUID(d['id'])), 'alterId': int(d.get('aid', 0)), 'security': d.get('scy') or 'auto'}
                net, security = d.get('net', 'tcp'), d.get('tls') or 'none'
                server_name, fingerprint, alpn = d.get('sni') or d.get('host'), d.get('fp') or 'chrome', d.get('alpn', '')
                path, transport_host, mode, extra = d.get('path') or '/', d.get('host', ''), 'auto', {}
            elif uri.scheme == 'vless':
                q = dict(urllib.parse.parse_qsl(uri.query))
                protocol, host, port = 'vless', uri.hostname, uri.port
                user = {'id': str(uuid.UUID(uri.username)), 'encryption': q.get('encryption', 'none')}
                if q.get('flow'):
                    user['flow'] = q['flow']
                net, security = q.get('type', 'tcp'), q.get('security', 'none')
                server_name, fingerprint, alpn = q.get('sni') or q.get('host'), q.get('fp') or 'chrome', q.get('alpn', '')
                path, transport_host, mode = q.get('path') or '/', q.get('host', ''), q.get('mode', 'auto')
                extra = json.loads(q.get('extra') or '{}')
            else:
                continue
            # The supplied subscription uses TLS over WS/XHTTP. Skip unsupported transports explicitly.
            if net not in ('ws', 'xhttp', 'tcp') or security != 'tls' or not host or not port or not 1 <= port <= 65535:
                continue
            stream = {'network': net, 'security': 'tls', 'tlsSettings': {
                'serverName': server_name or host, 'fingerprint': fingerprint, 'allowInsecure': False}}
            if alpn:
                stream['tlsSettings']['alpn'] = alpn.split(',')
            if net == 'ws':
                stream['wsSettings'] = {'path': path, 'headers': {'Host': transport_host or server_name or host}}
            elif net == 'xhttp':
                stream['xhttpSettings'] = {'path': path, 'host': transport_host or server_name or host, 'mode': mode, 'extra': extra}
            node = {'protocol': protocol, 'tag': 'subscription',
                    'settings': {'vnext': [{'address': host, 'port': port, 'users': [user]}]}, 'streamSettings': stream}
            if node not in nodes:
                nodes.append(node)
        except (ValueError, KeyError, TypeError, UnicodeError):
            continue
    if not nodes or len(nodes) > 200:
        raise ValueError('Subscription has no supported TLS nodes or exceeds the node limit')
    return nodes


def fetch(url, limit):
    with urllib.request.urlopen(url, timeout=40) as response:
        payload = response.read(limit + 1)
        if len(payload) > limit:
            raise ValueError('Download size limit exceeded')
        return payload


def provision():
    subscription = os.environ['CANDLE_PROXY_SUBSCRIPTION']
    if urllib.parse.urlsplit(subscription).scheme != 'https':
        raise ValueError('Subscription must use HTTPS')
    try:
        nodes = subscription_nodes(fetch(subscription, 2 * 1024 * 1024).decode('utf-8-sig'))
    except Exception:
        # urllib exceptions can contain the private subscription URL.
        raise RuntimeError('Could not download or parse the private subscription') from None
    archive = fetch(XRAY_URL, 80 * 1024 * 1024)
    if hashlib.sha256(archive).hexdigest() != XRAY_SHA256:
        raise ValueError('Xray release digest mismatch')
    binary = zipfile.ZipFile(io.BytesIO(archive)).read('xray')
    ftp = ftplib.FTP_TLS(context=ssl.create_default_context(), timeout=40)
    marker = 'candle-proxy-check-' + uuid.uuid4().hex + '.php'
    token = uuid.uuid4().hex
    uploaded = False
    staging = 'config/candle-proxy/staging-' + uuid.uuid4().hex
    staging_created = False
    root = os.environ.get('FTP_REMOTE_DIR', '/public_html').rstrip('/')
    if not root.startswith('/') or '..' in root.split('/') or not root:
        raise ValueError('Explicit non-root FTP directory required')

    def put(path, data, mode=None):
        temporary = path + '.upload-' + uuid.uuid4().hex
        ftp.storbinary('STOR ' + temporary, io.BytesIO(data))
        if mode:
            ftp.sendcmd('SITE CHMOD ' + mode + ' ' + temporary)
        ftp.rename(temporary, path)

    try:
        ftp.connect(os.environ['FTP_HOST'], int(os.environ.get('FTP_PORT', '21')))
        ftp.login(os.environ['FTP_USERNAME'], os.environ['FTP_PASSWORD'])
        ftp.prot_p()
        ftp.cwd(root)
        # Existing build protections must be in place before uploading secrets.
        protection = io.BytesIO()
        ftp.retrbinary('RETR config/.htaccess', protection.write)
        if b'Require all denied' not in protection.getvalue():
            raise RuntimeError('Private config directory is not protected')
        try:
            ftp.mkd('config/candle-proxy')
        except ftplib.error_perm:
            ftp.cwd('config/candle-proxy')
            ftp.cwd(root)
        put('config/candle-proxy/.htaccess', b'Require all denied\n')
        # Test the new subscription privately before changing a working configuration.
        ftp.mkd(staging)
        staging_created = True
        put(staging + '/xray', binary, '700')
        put(staging + '/nodes.json', json.dumps(nodes).encode(), '600')
        # A short-lived authenticated check can only download a fixed public market.
        payload = ("<?php if(!hash_equals('" + token + "', $_SERVER['HTTP_X_PROBE_TOKEN']??'')){http_response_code(404);exit;} "
                   + "$staging=__DIR__.'/' . '" + staging + "';" + r"""
header('Content-Type: application/json');
define('CANDLE_DOWNLOADER_TEST',true);
require __DIR__.'/public/candle-downloader.php';
require_once __DIR__.'/src/CandleProxy.php';
try {
    $u=historyUrl('binance','BTCUSDT','15',time(),$exchanges,$frames);
    $raw=CandleProxy::get($u,$staging);
    if($raw['status']!==200||$raw['body']===false)throw new RuntimeException('upstream');
    $r=normalizeBinanceKlines($raw['body'],'15',time(),$frames);
    $p=json_decode($r['json'],true,512,JSON_THROW_ON_ERROR);
    echo json_encode(['ok'=>true,'candles'=>count($p['t']),'market_type'=>$r['info']['market_type']]);
} catch(Throwable $e){http_response_code(502);echo json_encode(['ok'=>false,'error'=>'Managed proxy connection failed']);}
""").encode()
        put(marker, payload, '600')
        uploaded = True
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        url = os.environ['SITE_URL'].rstrip('/') + '/' + marker
        for attempt in range((len(nodes) + 5) // 6):
            try:
                with opener.open(urllib.request.Request(url, headers={'X-Probe-Token': token}), timeout=65) as response:
                    result = json.load(response)
                if result.get('ok') is True and result.get('market_type') == 'futures' and result.get('candles', 0) > 0:
                    print('Host Xray verified:', result['candles'], 'closed Futures candles;', len(nodes), 'private subscription nodes.')
                    break
            except (OSError, ValueError):
                print('Testing next subscription nodes on host:', attempt + 1, flush=True)
                time.sleep(1)
        else:
            raise RuntimeError('No subscription node could reach Binance from this host')
        # Atomic file promotion; a failed check leaves the old proxy configuration intact.
        ftp.rename(staging + '/xray', 'config/candle-proxy/xray')
        ftp.rename(staging + '/nodes.json', 'config/candle-proxy/nodes.json')
        candidate_state = 'data/' + staging.rsplit('/', 1)[1] + '.json'
        state = io.BytesIO()
        ftp.retrbinary('RETR ' + candidate_state, state.write)
        put('data/candle-proxy-state.json', state.getvalue(), '600')
        put('config/candle-downloader.local.php', b"<?php return ['managed_proxy'=>true];\n", '600')
        for path in ['config/candle-proxy/nodes.json', 'config/candle-downloader.local.php', 'config/candle-proxy/xray']:
            try:
                opener.open(os.environ['SITE_URL'].rstrip('/') + '/' + path, timeout=15)
            except urllib.error.HTTPError as e:
                if e.code in (403, 404):
                    continue
            raise RuntimeError('Private proxy files must not be accessible over HTTP')
    finally:
        if uploaded:
            ftp.delete(marker)
        if staging_created:
            for path in [staging + '/xray', staging + '/nodes.json', 'data/' + staging.rsplit('/', 1)[1] + '.json']:
                try:
                    ftp.delete(path)
                except ftplib.error_perm:
                    pass
            ftp.rmd(staging)
        ftp.close()


if __name__ == '__main__':
    provision()
