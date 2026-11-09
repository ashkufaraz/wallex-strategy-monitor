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
