"""Concurrent entry reservation, persisted wallet and restart, without exchange I/O."""
import json,os,subprocess,tempfile,time
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
with tempfile.TemporaryDirectory(prefix='scanner-wallet-') as directory:
    folder=Path(directory);barrier=folder/'barrier'
    command=[os.environ.get('PHP_BINARY','php'),'-d','disable_functions=curl_init,fsockopen,pfsockopen,stream_socket_client',str(ROOT/'tests/scanner-race-worker.php'),str(folder/'audit.sqlite')]
    def call(mode):return subprocess.check_output(command+[mode],cwd=ROOT,text=True,encoding='utf8').strip()
    call('seed');call('confirm');workers=[]
    try:
        for i in range(6):workers.append(subprocess.Popen(command+['entryrace',str(barrier),str(i)],cwd=ROOT,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,encoding='utf8'))
        deadline=time.monotonic()+12
        while len(list(folder.glob('barrier.ready.*')))<6:
            assert time.monotonic()<deadline,'Barrier timeout'
            time.sleep(.02)
        barrier.write_text('go');results=[]
        for p in workers:
            out,err=p.communicate(timeout=20);assert p.returncode==0,(out,err);assert not err,err;results.append(json.loads(out))
        before=json.loads(call('wallet'));call('restart');after=json.loads(call('wallet'))
        expected=dict(discarded=5,one_trade=True,reserve_matches=True,accounted=70,unchanged_after_restart=True)
        actual=dict(discarded=sum(x['discarded'] for x in results),one_trade=after['trades']==1,reserve_matches=after['reserve_matches'],accounted=after['accounted'],unchanged_after_restart=before==after)
        row=dict(id='capital.concurrent-entry-and-restart',input=dict(processes=6,capital=70,entry_fee_bps=10),expected=expected,actual=actual,status='PASS' if actual==expected else 'FAIL',wallet=after)
        Path('reports/scanner-capital-concurrency.json').write_text(json.dumps(row,indent=2)+'\n',encoding='utf8');print(json.dumps(row));assert actual==expected
    finally:
        for p in workers:
            if p.poll() is None:p.kill();p.wait()
