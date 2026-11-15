"""Prepare a private one-use owner activation link; publish only its hash to CI."""
import argparse
import hashlib
import html
import json
import re
import secrets
import shutil
import subprocess
import urllib.error
import urllib.request
from pathlib import Path

ROOT=Path(__file__).resolve().parent.parent
def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--configure-github',action='store_true')
    args=parser.parse_args()
    config=ROOT/'config/owner-bootstrap.php';link=Path.home()/'.config/wallex-strategy-monitor/owner-activation.html'
    link.parent.mkdir(parents=True,exist_ok=True)
    legacy=ROOT/'data/owner-activation.html'
    if legacy.exists() and not link.exists():shutil.move(str(legacy),str(link))
    if config.exists() and link.exists():
        match=re.search(r"'token_hash'\s*=>\s*'([a-f0-9]{64})'",config.read_text(encoding='utf-8'))
        if not match:raise RuntimeError('Existing bootstrap configuration is invalid')
        digest=match.group(1)
    else:
        token=secrets.token_hex(32);digest=hashlib.sha256(token.encode()).hexdigest()
        config.write_text("<?php return ['token_hash'=>'"+digest+"'];\n",encoding='utf-8')
        url='https://ashkufaraz.ir/public/#activate_owner='+token
        link.write_text('<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="referrer" content="no-referrer"><title>فعال‌سازی خصوصی مالک</title><body style="font:18px/2 Tahoma;padding:30px"><h1>حساب مالک: ashkufaraz@gmail.com</h1><p>این فایل خصوصی است. لینک فقط یک‌بار برای تعیین رمز اولیه استفاده می‌شود؛ آن را به اشتراک نگذارید.</p><a rel="noreferrer" href="'+html.escape(url,quote=True)+'">فعال‌سازی حساب و تعیین رمز عبور</a></body></html>',encoding='utf-8')
    if args.configure_github:
        git=shutil.which('git') or 'C:/Program Files/Git/cmd/git.exe'
        result=subprocess.run([git,'-c','credential.helper=store','credential','fill'],input='protocol=https\nhost=github.com\n\n',text=True,capture_output=True,check=True)
        credential=dict(line.split('=',1) for line in result.stdout.splitlines() if '=' in line)
        token=credential.get('password','')
        if not token:raise RuntimeError('GitHub authentication unavailable')
        opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
        base='https://api.github.com/repos/ashkufaraz/wallex-strategy-monitor/actions/variables'
        payload=json.dumps(dict(name='OWNER_BOOTSTRAP_HASH',value=digest)).encode()
        headers={'Authorization':'Bearer '+token,'Content-Type':'application/json','Accept':'application/vnd.github+json'}
        try:response=opener.open(urllib.request.Request(base+'/OWNER_BOOTSTRAP_HASH',data=payload,headers=headers,method='PATCH'),timeout=20)
        except urllib.error.HTTPError as e:
            if e.code!=404:raise
            response=opener.open(urllib.request.Request(base,data=payload,headers=headers,method='POST'),timeout=20)
        response.close()
        print('GitHub owner bootstrap hash configured; activation token stays local.')
    print('Private activation file:',link)
if __name__=='__main__':main()
