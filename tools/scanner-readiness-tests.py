"""Run offline follow-up cases and generate input/expected/actual Markdown."""
import json,os,subprocess
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
php=os.environ.get('PHP_BINARY','php')
subprocess.run([php,'-d','disable_functions=curl_init,fsockopen,pfsockopen,stream_socket_client','tests/scanner-readiness.php'],cwd=ROOT,check=True)
subprocess.run([os.sys.executable,'tests/scanner-capital-concurrency.py'],cwd=ROOT,check=True)
report=json.loads((ROOT/'reports/scanner-readiness-tests.json').read_text('utf8'))
report['cases'].append(json.loads((ROOT/'reports/scanner-capital-concurrency.json').read_text('utf8')))
report['summary']={key:sum(1 for r in report['cases'] if key=='total' or r['status']==key.upper()) for key in ['total','pass','fail']}
(ROOT/'reports/scanner-readiness-tests.json').write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n',encoding='utf8')
def cell(x):return json.dumps(x,ensure_ascii=False).replace('|','\\|').replace('\n',' ') if not isinstance(x,str) else x
lines=['# آزمون‌های تکمیلی آمادگی اسکنر','','تمام ورودی‌ها مصنوعی‌اند؛ شبکه در PHP غیرفعال است. نتایج عملکرد زنده نیستند.','',str(report['summary']),'','| آزمون | ورودی | موردانتظار | واقعی | وضعیت |','|---|---|---|---|---|']
for r in report['cases']:lines.append('| '+' | '.join(cell(r[k]) for k in ['id','input','expected','actual','status'])+' |')
(ROOT/'reports/scanner-readiness-tests.md').write_text('\n'.join(lines)+'\n',encoding='utf8');print(report['summary'])
