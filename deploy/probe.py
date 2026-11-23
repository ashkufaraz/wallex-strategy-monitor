"""Verify that an FTP account points at the requested public website."""
import ftplib
import io
import os
import ssl
import urllib.request
import uuid


def probe():
    ftp = ftplib.FTP_TLS(context=ssl.create_default_context(), timeout=20)
    marker = 'hosting-check-' + uuid.uuid4().hex + '.php'
    token = uuid.uuid4().hex
    payload = ("<?php if(!hash_equals('" + token + "', $_SERVER['HTTP_X_PROBE_TOKEN']??'')){http_response_code(404);exit;} " + r"""
header('Content-Type: application/json');
$r=['os'=>PHP_OS_FAMILY,'arch'=>php_uname('m'),'php'=>PHP_VERSION,'functions'=>[]];
foreach(['proc_open','exec','shell_exec','putenv','chmod','curl_init','fsockopen'] as $fn)$r['functions'][$fn]=function_exists($fn);
if(function_exists('curl_init')){
$c=curl_init('https://fapi.binance.com/fapi/v1/klines?symbol=BTCUSDT&interval=1h&limit=2');
curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10]);
$b=curl_exec($c);$r['binance_direct']=['status'=>curl_getinfo($c,CURLINFO_HTTP_CODE),'errno'=>curl_errno($c)];curl_close($c);
}
echo json_encode($r);
""").encode()
    uploaded = False
    try:
        ftp.connect(os.environ['FTP_HOST'], 21)
        ftp.login(os.environ['FTP_USERNAME'], os.environ['FTP_PASSWORD'])
        ftp.prot_p()
        ftp.cwd(os.environ.get('FTP_REMOTE_DIR', '/'))
        print('FTP directory:', ftp.pwd(), flush=True)
        print('FTP entries:', ftp.nlst(), flush=True)
        ftp.storbinary('STOR ' + marker, io.BytesIO(payload))
        uploaded = True
        url = os.environ['SITE_URL'].rstrip('/') + '/' + marker
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        with opener.open(urllib.request.Request(url, headers={'X-Probe-Token':token}), timeout=25) as response:
            print('PHP_CAPABILITIES:', response.read().decode(), flush=True)
        print('Verified: FTP directory serves the requested website.', flush=True)
    finally:
        if uploaded:
            ftp.delete(marker)
        ftp.close()


if __name__ == '__main__':
    probe()
