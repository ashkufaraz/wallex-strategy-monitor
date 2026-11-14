(() => {
 const main=document.querySelector('main'), strategies=document.getElementById('strategies');
 const list=strategies.closest('section'), editor=document.getElementById('json').closest('section');
 const settings=document.getElementById('interval').closest('section'), history=document.getElementById('alerts').closest('section');
 const grid=document.querySelector('main>.grid');
 const sourceNames={chatgpt:'ChatGPT',grok:'Grok',deep:'Deep',gemini:'Gemini'};
 const sourceBox=document.createElement('fieldset');sourceBox.className='analysis-sources';
 sourceBox.innerHTML='<legend>منبع تحلیل (امکان انتخاب چند مورد)</legend><div class="row">'+Object.entries(sourceNames).map(([value,label])=>`<label><input type="checkbox" name="analysisSource" value="${value}"> ${label}</label>`).join('')+'</div>';
 editor.insertBefore(sourceBox,document.getElementById('json'));
 window.strategySources={
  load(config){for(const box of sourceBox.querySelectorAll('input'))box.checked=(config.analysis_sources||[]).includes(box.value);},
  sync(){try{this.load(JSON.parse(document.getElementById('json').value));}catch(e){}},
  apply(config){config.analysis_sources=Array.from(sourceBox.querySelectorAll('input:checked'),box=>box.value);},
  card(item){
   const sources=(item.config.analysis_sources||[]).filter(source=>sourceNames[source]).map(source=>sourceNames[source]);
   const p=item.entry_progress;
   return `<div class="strategy-meta"><span>منبع تحلیل: ${sources.length?sources.join('، '):'مشخص نشده'}</span>${p?`<span class="${p.passed===p.total&&p.total?'ok':'muted'}" title="شمارش بندهای شرط‌های ورود در آخرین ارزیابی و مرحله‌های طی‌شدهٔ ستاپ جاری؛ در گروه «حداقل یکی»، لازم نیست همه بندها برقرار باشند.">شرط‌های ورود (بدون اندیکاتور): <b>${p.passed} از ${p.total}</b> برقرار${p.unknown?` · ${p.unknown} بررسی‌نشده / نامعلوم`:''}</span>`:''}</div>`;
  }
 };
 sourceBox.onchange=()=>{try{const config=JSON.parse(document.getElementById('json').value);strategySources.apply(config);document.getElementById('json').value=JSON.stringify(config,null,2);}catch(e){}document.getElementById('previewBox').replaceChildren();pending=null;};
 const tabs=document.createElement('div');tabs.className='row strategy-tabs';
 tabs.innerHTML='<button type="button" id="activeStrategies" aria-pressed="true">استراتژی‌های فعال</button><button type="button" id="archivedStrategies" aria-pressed="false">آرشیو غیرفعال‌ها</button>';
 list.insertBefore(tabs,strategies);main.append(list,editor,history);grid.remove();
 const alertTabs=document.createElement('div');alertTabs.className='row';
 alertTabs.innerHTML='<button type="button" id="currentAlerts" aria-pressed="true">هشدارها</button><button type="button" id="archivedAlerts" aria-pressed="false">آرشیو هشدارها</button>';
 history.insertBefore(alertTabs,document.getElementById('clearAlerts'));
 window.alertArchive=false;
 alertTabs.onclick=e=>{if(e.target.tagName!=='BUTTON')return;window.alertArchive=e.target.id==='archivedAlerts';for(const b of alertTabs.children)b.setAttribute('aria-pressed',String((b.id==='archivedAlerts')===window.alertArchive));refresh();};
 const logs=document.createElement('details');logs.id='systemLogs';
 logs.innerHTML='<h2>گزارش رویدادهای سیستم</h2><p class="small muted">۳۰۰ رویداد آخر از ۱۰٬۰۰۰ رویداد ذخیره‌شده در سرور؛ پیام‌های مرورگر در همین نشست نگهداری می‌شوند. برای بررسی علت، جزئیات رویداد را باز کنید.</p><div class="row"><label>سطح <select id="logLevel"><option value="">همه موارد</option><option value="error">خطا</option><option value="warning">هشدار</option><option value="success">موفقیت</option><option value="info">اطلاعات</option></select></label><input id="logSearch" type="search" placeholder="جست‌وجوی پیام، شناسه و جزئیات" aria-label="جست‌وجوی لاگ"><span id="logCount" class="muted small"></span></div><div id="logEntries" aria-live="polite"></div>';
 const logTitle=logs.querySelector('h2');const logSummary=document.createElement('summary');logSummary.textContent=logTitle.textContent;logTitle.replaceWith(logSummary);
 main.append(logs,settings);
 const footer=document.createElement('footer');footer.innerHTML='سازنده:  سیدمجتبی اشکوفراز-یزد <span> | </span><a href="mailto:ashkufaraz@gmail.com">ashkufaraz@gmail.com</a><span> | </span><a dir="ltr" href="tel:09135128163">09135128163</a>';main.append(footer);
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
