self.addEventListener('install',()=>self.skipWaiting());
self.addEventListener('activate',event=>event.waitUntil(self.clients.claim()));
self.addEventListener('notificationclick',event=>{
 event.notification.close();
 event.waitUntil((async()=>{
  const pages=await self.clients.matchAll({type:'window',includeUncontrolled:true});
  const target=new URL(self.registration.scope);
  const page=pages.find(client=>client.url.startsWith(target.href));
  if(page)return page.focus();
  return self.clients.openWindow(target.href);
 })());
});
