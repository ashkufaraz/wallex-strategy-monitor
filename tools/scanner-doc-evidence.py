"""Read-only retrieval of official Wallex documentation; no credentials."""
import datetime,hashlib,json,re,urllib.request
from pathlib import Path
opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
rows=[]
for route in ['basic-services-markets','basic-services-depth','basic-services-candles','socket-buy-depth','socket-sell-depth','socket-connection']:
    url='https://developers.wallex.ir/docs/'+route
    try:
        with opener.open(url,timeout=25) as r: raw=r.read();status=r.status
        text=raw.decode('utf8');Path('data/wallex-current-'+route+'.html').write_text(text,encoding='utf8')
        # Retain field names and hashes, not a reproduction of the documentation.
        fields={k:len(re.findall(k,text,re.I)) for k in ['timestamp','lastUpdate','stepSize','tickSize','quantity','buyDepth','sellDepth','sequence']}
        rows.append(dict(url=url,status=status,sha256=hashlib.sha256(raw).hexdigest(),bytes=len(raw),field_mentions=fields))
    except Exception as e: rows.append(dict(url=url,error=str(e)))
Path('reports/scanner-wallex-doc-evidence.json').write_text(json.dumps(dict(retrieved_utc=datetime.datetime.now(datetime.timezone.utc).isoformat(),sources=rows,conclusion='REST snapshot has no source time. Socket documentation must prove generation timestamp before executable adapter can be trusted.'),indent=2)+'\n',encoding='utf8')
print(json.dumps(rows))
