const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
let count = 0;
function fixture(secure, permission, throws = false, supported = true) {
  const messages = [];
  function Notification() { if (throws) throw Error('blocked'); count++; }
  Notification.permission = permission;
  Notification.requestPermission = async () => (Notification.permission = 'granted');
  const window = { isSecureContext: secure };
  if (supported) window.Notification = Notification;
  const context = vm.createContext({ window, Notification });
  vm.runInContext(fs.readFileSync('public/notifications.js', 'utf8') + '\nthis.api = panelNotifications;', context);
  return { api: context.api, report: (...args) => messages.push(args), messages };
}
(async () => {
  for (const args of [[false,'granted'],[true,'denied'],[true,'default'],[true,'granted',false,false],[true,'granted',true]]) {
    const f = fixture(...args);
    assert.strictEqual(f.api.send('test','body','tag',f.report),false);
    assert.strictEqual(f.messages[0][1],true);
  }
  const f = fixture(true,'default');
  await f.api.enable(f.report);
  assert.strictEqual(f.api.send('test','body','tag',f.report),true);
  assert.strictEqual(count,1);
  new Function(fs.readFileSync('public/index.html','utf8').match(/<script>([\s\S]*?)<\/script>/)[1]);
  console.log('Notification checks and browser script syntax passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });

const tracker=fixture(true,'granted').api;
const saved={};
// The cursor survives reload and a failed send remains pending.
tracker.cursor=10;
let attempts=[];
tracker.process([{id:11},{id:12}],a=>{attempts.push(a.id);return false;});
assert.equal(tracker.cursor,10);
assert.deepStrictEqual(attempts,[11]);
tracker.process([{id:12},{id:11}],a=>{attempts.push(a.id);return true;});
assert.equal(tracker.cursor,12);
assert.deepStrictEqual(attempts,[11,11,12]);
tracker.process([{id:12}],()=>assert.fail('duplicate notification'));

const storage=new Map();
function reloadTracker(){
 const c=vm.createContext({window:{localStorage:{getItem:k=>storage.has(k)?storage.get(k):null,setItem:(k,v)=>storage.set(k,v)}}});
 vm.runInContext(fs.readFileSync('public/notifications.js','utf8')+';this.api=panelNotifications;',c);return c.api;
}
reloadTracker().process([{id:20}],()=>assert.fail('first visit must not replay old alerts'));
let delivered=[];
reloadTracker().process([{id:20},{id:21}],a=>{delivered.push(a.id);return true;});
assert.deepStrictEqual(delivered,[21]);
reloadTracker().process([{id:21}],()=>assert.fail('reload must not duplicate alerts'));
