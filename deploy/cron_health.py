"""Inspect/recover only a proven stale same-account scheduler lock holder."""
import ftplib, io, json, os, ssl, urllib.request, uuid
from pathlib import Path

ftp=ftplib.FTP_TLS(context=ssl.create_default_context(),timeout=30)
marker='cron-health-'+uuid.uuid4().hex+'.php';token=uuid.uuid4().hex;uploaded=False
try:
    ftp.connect(os.environ['FTP_HOST'],21);ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD']);ftp.prot_p();ftp.cwd(os.environ['FTP_REMOTE_DIR'])
    payload=Path(__file__).with_suffix('.php').read_text().replace('__TOKEN__',token).replace('__RECOVER__',os.environ.get('RECOVER_STALE','false'))
    ftp.storbinary('STOR '+marker,io.BytesIO(payload.encode()));uploaded=True
    op=urllib.request.build_opener(urllib.request.ProxyHandler({}))
    req=urllib.request.Request(os.environ['SITE_URL'].rstrip('/')+'/'+marker,headers={'X-Probe-Token':token})
    with op.open(req,timeout=20) as r: print(json.dumps(json.load(r)))
finally:
    if uploaded:ftp.delete(marker)
    ftp.close()
