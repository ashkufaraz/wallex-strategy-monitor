(() => {
 const main=document.querySelector('main'), strategies=document.getElementById('strategies');
 const list=strategies.closest('section'), editor=document.getElementById('json').closest('section');
 const settings=document.getElementById('interval').closest('section'), history=document.getElementById('alerts').closest('section');
 const grid=document.querySelector('main>.grid');
 const tabs=document.createElement('div');tabs.className='row strategy-tabs';
 tabs.innerHTML='<button type="button" id="activeStrategies" aria-pressed="true">استراتژی‌های فعال</button><button type="button" id="archivedStrategies" aria-pressed="false">آرشیو غیرفعال‌ها</button>';
 list.insertBefore(tabs,strategies);main.append(list,editor,history);grid.remove();
 const logs=document.createElement('section');logs.id='systemLogs';
 logs.innerHTML='<h2>گزارش رویدادهای سیستم</h2><p class="small muted">۳۰۰ رویداد آخر از ۱۰٬۰۰۰ رویداد ذخیره‌شده در سرور؛ پیام‌های مرورگر در همین نشست نگهداری می‌شوند. برای بررسی علت، جزئیات رویداد را باز کنید.</p><div class="row"><label>سطح <select id="logLevel"><option value="">همه موارد</option><option value="error">خطا</option><option value="warning">هشدار</option><option value="success">موفقیت</option><option value="info">اطلاعات</option></select></label><input id="logSearch" type="search" placeholder="جست‌وجوی پیام، شناسه و جزئیات" aria-label="جست‌وجوی لاگ"><span id="logCount" class="muted small"></span></div><div id="logEntries" aria-live="polite"></div>';
 main.append(logs,settings);
 const footer=document.createElement('footer');footer.innerHTML='سازنده: اشکو فراز <span> | </span><a href="mailto:ashkufaraz@gmail.com">ashkufaraz@gmail.com</a><span> | </span><a dir="ltr" href="tel:09135128163">09135128163</a>';main.append(footer);
 window.strategyArchive=false;
 window.updateStrategyCounts=()=>{
  document.getElementById('activeStrategies').textContent=`استراتژی‌های فعال (${data.strategies.filter(x=>tradeDetails.isActive(x)).length})`;
  document.getElementById('archivedStrategies').textContent=`آرشیو غیرفعال‌ها (${data.strategies.filter(x=>!tradeDetails.isActive(x)).length})`;
  if(!strategies.children.length)strategies.textContent=window.strategyArchive?'آرشیو خالی است.':'استراتژی فعالی وجود ندارد.';
 };
 tabs.addEventListener('click',event=>{
  if(event.target.tagName!=='BUTTON')return;
  window.strategyArchive=event.target.id==='archivedStrategies';
  for(const b of tabs.children)b.setAttribute('aria-pressed',String((b.id==='archivedStrategies')===window.strategyArchive));
  refresh();
 });
 const escape=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const local=[],labels={error:'خطا',warning:'هشدار',success:'موفقیت',info:'اطلاعات'};let server=[],signature='';
 function render(rows=server){
  server=rows;const level=document.getElementById('logLevel').value,term=document.getElementById('logSearch').value.toLowerCase();
  const entries=[...local,...server].sort((a,b)=>b.created-a.created).filter(x=>(!level||x.level===level)&&JSON.stringify(x).toLowerCase().includes(term)).slice(0,300);
  const next=JSON.stringify(entries);if(next===signature)return;signature=next;
  document.getElementById('logCount').textContent=`${entries.length} رویداد`;
  document.getElementById('logEntries').innerHTML=entries.map(x=>`<details class="log-entry log-${escape(x.level)}"><summary><span class="log-label">${escape(labels[x.level]||x.level)}</span> <time>${escape(new Date(x.created*1000).toLocaleString('fa-IR'))}</time> <b>${escape(x.message)}</b><span class="small muted"> · ${escape(x.event)}</span></summary><pre dir="ltr">${escape(JSON.stringify(x.context,null,2))}</pre></details>`).join('')||'<p class="muted">رویدادی یافت نشد.</p>';
 }
 window.panelLog={render,local(message,level='info',context={}){const previous=local[0];if(previous&&previous.message===message&&Date.now()/1000-previous.created<10)return;local.unshift({created:Date.now()/1000,level,event:'browser',message,context});local.splice(100);render();}};
 document.getElementById('logLevel').onchange=()=>render();document.getElementById('logSearch').oninput=()=>render();
 window.addEventListener('error',e=>panelLog.local(e.message||'خطای بارگذاری فایل مرورگر','error',{file:e.filename,line:e.lineno}));
 window.addEventListener('unhandledrejection',e=>panelLog.local(String(e.reason?.message||e.reason),'error'));
})();
