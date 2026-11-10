import json,time,requests
from pathlib import Path
s=requests.Session();s.trust_env=False
end=int(time.time())//14400*14400
items=[]
original=json.loads(Path('data/four-hour-live-comparison.json').read_text())
items.append(original)
for symbol in ['BTCUSDT','ETHUSDT','SOLUSDT']:
 for offset in range(3):
  until=end-offset*14400
  item={'symbol':symbol,'from':until-14400,'to':until,'captured_at':int(time.time()),'responses':{}}
  for resolution in ['1','60']:
   r=s.get('https://api.wallex.ir/v1/udf/history',params={'symbol':symbol,'resolution':resolution,'from':item['from'],'to':until},timeout=15);r.raise_for_status();item['responses'][resolution]=r.json()
  items.append(item)
  print(symbol,item['from'],item['to'],flush=True)
Path('tests/fixtures/four-hour-comparisons.json').write_text(json.dumps(items,indent=1)+'\n',encoding='utf-8')
