"""One command builds reproducible input/expected/actual reports with networking disabled."""
import argparse,importlib.util,json,os,subprocess
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
parser=argparse.ArgumentParser();parser.add_argument('--output',default='reports/scanner-audit-after');args=parser.parse_args()
output=Path(args.output);output=output if output.is_absolute() else ROOT/output
php=os.environ.get('PHP_BINARY','php')
completed=subprocess.run([php,'-d','disable_functions=curl_init,fsockopen,pfsockopen,stream_socket_client','tests/scanner-audit.php',str(output.relative_to(ROOT))],cwd=ROOT)
report=json.loads(output.with_suffix('.json').read_text(encoding='utf-8'))
spec=importlib.util.spec_from_file_location('scanner_race',ROOT/'tests/scanner-concurrency.py');module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
report['cases']+=module.run();report['summary']={key:sum(1 for r in report['cases'] if key=='total' or r['status']==key.upper()) for key in ['total','pass','fail']}
report['network']='PHP curl_init, fsockopen, pfsockopen, stream_socket_client disabled; adapters are synthetic spies only'
output.with_suffix('.json').write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
def cell(v):return (json.dumps(v,ensure_ascii=False,separators=(',',':')) if isinstance(v,(dict,list)) else str(v)).replace('|','\\|').replace('\n',' ')
lines=['# گزارش آزمون قطعی اسکنر','','ورودی‌ها مصنوعی و تکرارپذیرند؛ این گزارش ارزیابی سودآوری نیست. اتصال بازار غیرفعال است. دادهٔ پایه: tests/scanner-fixture.php. زمان مبنا T = '+report['anchor_utc']+'.','',f"تعداد: {report['summary']['total']} · PASS: {report['summary']['pass']} · FAIL: {report['summary']['fail']}",'','مقایسهٔ عددی با تلورانس نسبی 1e-8؛ شناسه‌ها، وضعیت‌ها و زمان‌ها نیز صریح بررسی می‌شوند. جزئیات کامل در JSON هم‌نام موجود است.','','| آزمون | بخش | ورودی / تغییر نسبت به پایه | موردانتظار | واقعی | وضعیت |','|---|---|---|---|---|---|']
for r in report['cases']:lines.append('| '+' | '.join(cell(r[k]) for k in ['id','section','input','expected','actual','status'])+' |')
output.with_suffix('.md').write_text('\n'.join(lines)+'\n',encoding='utf-8');print(json.dumps(report['summary']))
raise SystemExit(bool(report['summary']['fail']) or bool(completed.returncode))
