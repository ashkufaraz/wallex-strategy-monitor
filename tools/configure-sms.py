"""Upload an encrypted Actions secret from ignored local configuration. Never print credentials."""
import base64,json,subprocess,time,urllib.request,urllib.error
from pathlib import Path
from nacl.public import PublicKey,SealedBox
root=Path(__file__).resolve().parent.parent
path=root/'config/sms.json';config=json.loads(path.read_text(encoding='utf-8'))
if not config['enabled_at']:
 config['enabled_at']=int(time.time());path.write_text(json.dumps(config),encoding='utf-8')
result=subprocess.run(['C:/Program Files/Git/cmd/git.exe','-c','credential.helper=store','credential','fill'],input='protocol=https\nhost=github.com\n\n',text=True,capture_output=True,check=True)
token=dict(line.split('=',1) for line in result.stdout.splitlines() if '=' in line)['password']
o=urllib.request.build_opener(urllib.request.ProxyHandler({}))
base='https://api.github.com/repos/ashkufaraz/wallex-strategy-monitor/actions'
headers={'Authorization':'Bearer '+token,'Content-Type':'application/json','Accept':'application/vnd.github+json'}
def api(path,method='GET',body=None):
 return o.open(urllib.request.Request(base+path,headers=headers,method=method,data=json.dumps(body).encode() if body is not None else None),timeout=25)
with api('/secrets/public-key') as response:pub=json.load(response)
encrypted=base64.b64encode(SealedBox(PublicKey(base64.b64decode(pub['key']))).encrypt(config['api_key'].encode())).decode()
api('/secrets/KAVENEGAR_API_KEY','PUT',dict(encrypted_value=encrypted,key_id=pub['key_id'])).close()
body=dict(name='SMS_ENABLED_AT',value=str(config['enabled_at']))
try:api('/variables/SMS_ENABLED_AT','PATCH',body).close()
except urllib.error.HTTPError as error:
 if error.code!=404:raise
 api('/variables','POST',body).close()
print('Encrypted SMS secret and activation cutoff configured.')
