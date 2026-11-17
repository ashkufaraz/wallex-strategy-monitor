import json,os,subprocess,tempfile,time
from pathlib import Path
root=Path(__file__).resolve().parent.parent;rows=[]
with tempfile.TemporaryDirectory(prefix='pattern-race-') as directory:
    folder=Path(directory);barrier=folder/'go';cmd=[os.environ.get('PHP_BINARY','php'),'-d','disable_functions=curl_init,fsockopen,pfsockopen,stream_socket_client',str(root/'tests/pattern-race-worker.php'),str(folder/'app.sqlite')]
    def call(mode):return subprocess.check_output(cmd+[mode],cwd=root,text=True,encoding='utf8').strip()
    call('seed');workers=[]
    try:
        for i in range(6):workers.append(subprocess.Popen(cmd+['race',str(barrier),str(i)],cwd=root,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,encoding='utf8'))
        deadline=time.monotonic()+15
        while len(list(folder.glob('go.ready.*')))<6:
            assert time.monotonic()<deadline,'Barrier timeout';time.sleep(.02)
        barrier.write_text('go')
        for p in workers:
            out,err=p.communicate(timeout=20);assert p.returncode==0,(out,err);assert not err,err
        expected=dict(events=1,alerts=1,signals=1,no_manual=True);actual=json.loads(call('summary'))
        rows.append(dict(id='concurrency.six-processes',input=dict(processes=6,same_confirmation=True,book='unverified',fees='unknown'),expected=expected,actual=actual,status='PASS' if actual==expected else 'FAIL'))
        call('restart');actual=json.loads(call('summary'));rows.append(dict(id='restart.no-new-notice',input=dict(restart='new PHP process',same_closed_candle=True),expected=expected,actual=actual,status='PASS' if actual==expected else 'FAIL'))
    finally:
        for p in workers:
            if p.poll() is None:p.kill();p.wait()
(root/'reports/pattern-concurrency.json').write_text(json.dumps(rows,indent=2)+'\n',encoding='utf8');print(json.dumps(rows));assert all(r['status']=='PASS' for r in rows)
