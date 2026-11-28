const exchangeUI={
 filter:'all',defaultExchange:'wallex',
 label(value){return value==='binance'?'بایننس فیوچرز':(value==='nobitex'?'نوبیتکس':'والکس');},
 matches(item){return this.filter==='all'||(item.exchange||'wallex')===this.filter;},
 sync(d){this.defaultExchange=d.default_exchange||'wallex';const select=document.getElementById('defaultExchange');if(select&&document.activeElement!==select)select.value=this.defaultExchange;document.getElementById('exchangeDefaults').hidden=!d.can_manage_settings;},
 init(){
  const box=document.createElement('div');box.className='row';box.innerHTML='<label>نمایش <select id="exchangeFilter"><option value="all">همه</option><option value="wallex">والکس</option><option value="nobitex">نوبیتکس</option><option value="binance">بایننس فیوچرز</option></select></label><label id="exchangeDefaults" hidden>صرافی پیش‌فرض موارد جدید <select id="defaultExchange"><option value="wallex">والکس</option><option value="nobitex">نوبیتکس</option><option value="binance">بایننس فیوچرز</option></select></label><span id="exchangeMessage" role="status"></span>';document.querySelector('header').after(box);
  const editor=document.createElement('label');editor.innerHTML='صرافی این استراتژی <select id="strategyExchange"><option value="wallex">والکس</option><option value="nobitex">نوبیتکس</option><option value="binance">بایننس فیوچرز</option></select>';document.getElementById('json').before(editor);
  document.getElementById('exchangeFilter').onchange=e=>{this.filter=e.target.value;window.dispatchEvent(new Event('exchange-filter'));if(typeof refresh==='function')refresh();};
  document.getElementById('defaultExchange').onchange=async e=>{try{const r=await request({action:'default_exchange',exchange:e.target.value});this.defaultExchange=r.default_exchange;document.getElementById('exchangeMessage').textContent='پیش‌فرض موارد جدید ذخیره شد؛ سوابق قبلی تغییر نکرد.';}catch(error){e.target.value=this.defaultExchange;document.getElementById('exchangeMessage').textContent=error.message;}};
  document.getElementById('strategyExchange').onchange=e=>{try{const draft=JSON.parse(document.getElementById('json').value);draft.exchange=e.target.value;document.getElementById('json').value=JSON.stringify(draft,null,2);document.getElementById('previewBox').replaceChildren();}catch(error){}};
 },
 async chart(button,exchange,symbol,trade){return marketChart.load(button,exchange,symbol,trade);}

};
exchangeUI.init();
