const fs=require('fs'),vm=require('vm'),assert=require('assert');
const result=JSON.parse(fs.readFileSync(process.argv[2],'utf8'));
const sent=[];
function Notification(title,options){sent.push({title,...options});}
Notification.permission='granted';
const context=vm.createContext({window:{isSecureContext:true,Notification},Notification});
vm.runInContext(fs.readFileSync('public/trade.js','utf8')+'\nthis.trade=tradeDetails;',context);
vm.runInContext(fs.readFileSync('public/notifications.js','utf8')+'\nthis.notify=panelNotifications;',context);
const payload=result.alert;
const text=context.trade.notification(payload);
assert(context.notify.send('SIMULATION BTC SHORT',text,'simulation',()=>assert.fail('Unexpected notification error')));
assert.equal(sent.length,1);
for(const value of ['SHORT','75000','75600','74000','73600'])assert(sent[0].body.includes(value),value+' missing from notification');
const html=context.trade.alertHtml(payload);
for(const value of ['75000','75600','74000','73600'])assert(html.includes(value));
console.log('PASS: recorded simulation alert renders all order details and invokes browser Notification exactly once (mock browser).');
