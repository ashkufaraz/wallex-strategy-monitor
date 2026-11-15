const fs=require('fs'),vm=require('vm'),assert=require('assert');
(async()=>{
 let instance,receipts=0;
 function Notification(){instance=this;}Notification.permission='granted';
 const c=vm.createContext({window:{isSecureContext:true,Notification},Notification,setTimeout,clearTimeout});
 vm.runInContext(fs.readFileSync('public/notifications.js','utf8')+';this.api=panelNotifications;',c);
 assert.equal(c.api.send('test','body','signal-1',()=>{},()=>receipts++),true);
 assert.equal(receipts,0,'constructor success is not receipt');
 instance.onshow();assert.equal(receipts,1,'onshow confirms native delivery');
 let fail=true;
 const reg={scope:'https://example.com/',showNotification:async()=>{if(fail)throw Error('failed');}};
 function Mobile(){throw Error('Illegal constructor');}Mobile.permission='granted';
 c.Notification=Mobile;c.window.Notification=Mobile;c.navigator={serviceWorker:{register:async()=>{},ready:Promise.resolve(reg)}};
 assert.equal(await c.api.send('test','body','signal-2',()=>{},()=>receipts++),false);
 assert.equal(receipts,1,'failed service-worker display cannot acknowledge');
 fail=false;assert.equal(await c.api.send('test','body','signal-2',()=>{},()=>receipts++),true);
 assert.equal(receipts,2,'service-worker success acknowledges');
 console.log('PASS SMS receipt follows actual native show or successful service-worker request');
})().catch(e=>{console.error(e);process.exitCode=1;});
