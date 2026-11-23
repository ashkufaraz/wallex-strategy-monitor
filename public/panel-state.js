// Restore disclosure state synchronously whenever live reports replace markup.
(() => {
 const descriptor=Object.getOwnPropertyDescriptor(Element.prototype,'innerHTML');
 const identity=d=>d.dataset.outcome?`outcome:${d.dataset.outcome}`:d.dataset.log?`log:${d.dataset.log}`:d.dataset.panel||d.id||d.querySelector('summary')?.textContent.trim()||'';
 const snapshot=root=>{
  const counts=new Map();
  return [...root.querySelectorAll('details')].map(d=>{
   const parents=[];for(let p=d.parentElement;p&&p!==root;p=p.parentElement){if(p.matches('details')||p.dataset.panel)parents.unshift(identity(p));else if(p.classList.contains('card'))parents.unshift(p.querySelector('b')?.textContent||'');}
   const base=JSON.stringify([...parents,identity(d)]),index=counts.get(base)||0;counts.set(base,index+1);
   return {node:d,key:base+':'+index,open:d.open};
  });
 };
 Object.defineProperty(Element.prototype,'innerHTML',{...descriptor,set(html){
  const previous=new Map(snapshot(this).map(d=>[d.key,d.open]));
  descriptor.set.call(this,html);
  if(previous.size)for(const d of snapshot(this))if(previous.has(d.key))d.node.open=previous.get(d.key);
 }});
})();
