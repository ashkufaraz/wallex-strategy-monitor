"""Six real PHP processes share one isolated SQLite file; no exchange transport."""
import json,os,subprocess,tempfile,time
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
PHP=os.environ.get('PHP_BINARY','php')
def run():
    with tempfile.TemporaryDirectory(prefix='scanner-race-') as directory:
        folder=Path(directory);db=folder/'audit.sqlite';barrier=folder/'barrier';workers=[]
        command=[PHP,'-d','disable_functions=curl_init,fsockopen,pfsockopen,stream_socket_client',str(ROOT/'tests/scanner-race-worker.php'),str(db)]
        def call(mode):return subprocess.check_output(command+[mode],cwd=ROOT,text=True,encoding='utf-8').strip()
        call('seed')
        try:
            for i in range(6):workers.append(subprocess.Popen(command+['race',str(barrier),str(i)],cwd=ROOT,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,encoding='utf-8'))
            deadline=time.monotonic()+10
            while len(list(folder.glob('barrier.ready.*')))<6:
                if time.monotonic()>deadline:raise AssertionError('Workers failed to reach concurrency barrier')
                time.sleep(.01)
            barrier.write_text('start',encoding='ascii');results=[]
            for worker in workers:
                out,err=worker.communicate(timeout=15);assert worker.returncode==0,(out,err);assert not err,err;results.append(json.loads(out))
            summary=json.loads(call('summary'))
            # summary intentionally changes the active setting; persisted signal config must not change.
            case1=dict(id='concurrency.six-processes',section='تکرار و اجرای هم‌زمان',input=dict(processes=6,barrier='same pre-confirmation SQLite state',network='disabled'),expected=dict(signals=1,alerts=1,losers_discarded=5),actual=dict(signals=summary['signals'],alerts=summary['alerts'],losers_discarded=sum(x['discarded'] for x in results)))
            call('restart');final=json.loads(call('summary'))
            case2=dict(id='concurrency.restart',section='راه‌اندازی مجدد و تنظیمات ثابت',input=dict(replays=['confirmation','entry+60','entry+60 repeated'],setting_change='entry_fee_bps 10 -> 90'),expected=dict(signals=1,alerts=1,trades=1,paper_status='OPEN',config_unchanged=True,recorded_entry_fee_bps=10),actual=final)
            case3=dict(id='concurrency.replay-after-duplicates',section='بازپخش پس از تکرار درخواست',input=dict(repeated_observations='confirmation and entry timestamps repeated across restart'),expected=dict(signals=1,observations=4,open_status='OPEN'),actual=json.loads(call('replay')))
            for row in [case1,case2,case3]:row['status']='PASS' if all(row['actual'].get(k)==v for k,v in row['expected'].items()) else 'FAIL'
            return [case1,case2,case3]
        finally:
            for worker in workers:
                if worker.poll() is None:worker.kill();worker.wait()
if __name__=='__main__':
    rows=run();print(json.dumps(rows,ensure_ascii=False));raise SystemExit(any(r['status']=='FAIL' for r in rows))
