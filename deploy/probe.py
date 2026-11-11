"""Verify that an FTP account points at the requested public website."""
import ftplib
import io
import os
import ssl
import urllib.request
import uuid


def probe():
    ftp = ftplib.FTP_TLS(context=ssl.create_default_context(), timeout=20)
    marker = 'hosting-check-' + uuid.uuid4().hex + '.txt'
    payload = uuid.uuid4().hex.encode()
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
        with opener.open(url, timeout=20) as response:
            if response.read() != payload:
                raise RuntimeError('FTP directory does not match the public website')
        print('Verified: FTP directory serves the requested website.', flush=True)
    finally:
        if uploaded:
            ftp.delete(marker)
        ftp.close()


if __name__ == '__main__':
    probe()
