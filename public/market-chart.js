const marketChart={
 async load(button,exchange,symbol,trade){
  if(button.disabled)return;button.disabled=true;const target=button.nextElementSibling;
  target.chartObserver?.disconnect();target.textContent='در حال دریافت نمودار…';
  try{
   const response=await fetch(`api.php?market_chart=1&exchange=${encodeURIComponent(exchange)}&symbol=${encodeURIComponent(symbol)}`),d=await response.json();
   if(!response.ok)throw Error(d.error||'دریافت نمودار ناموفق بود');
   if(d.exchange!==exchange||d.symbol!==symbol)throw Error('هویت بازار نمودار مطابقت ندارد');
   if(!d.bars?.length)throw Error('کندل سالم برای نمودار دریافت نشد');
   this.render(target,d,trade);
  }catch(error){target.textContent=error.message;}finally{button.disabled=false;}
 },
 render(target,d,trade){
  target.chartObserver?.disconnect();
  const bars=[...d.bars].sort((a,b)=>a.time-b.time),n=v=>Number(v).toLocaleString('en-US',{maximumFractionDigits:Math.abs(v)>=100?2:6});
  const date=t=>new Date(t*1000).toLocaleString('fa-IR',{month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit'});
  target.innerHTML=`<p><b>${esc(exchangeUI.label(d.exchange))} · ${esc(d.symbol)}</b> · کندل ۱۵ دقیقه · قیمت USDT</p><div class="row"><label>بازهٔ نمایش <select data-chart-window><option value="64">۶۴ کندل اخیر</option><option value="32">۳۲ کندل اخیر</option><option value="16">۱۶ کندل اخیر</option></select></label><span class="small">سبز: صعودی · قرمز: نزولی · زمان محلی مرورگر</span></div><div data-chart-scroll style="overflow-x:auto;max-width:100%;margin-top:12px;direction:ltr"></div><p data-chart-reading class="small" dir="auto" aria-live="polite"></p><div data-chart-levels class="small"></div><p class="small muted">برای مشاهدهٔ قیمت باز، بالا، پایین و بسته‌شدن، روی کندل حرکت کنید یا لمس کنید. شکاف داده: ${Number(d.gaps?.length||0)}. نمودار با دکمهٔ تازه‌سازی به‌روز می‌شود.</p>`;
  const levels=trade?[{name:'ورود برنامهٔ سفارش',price:trade.entry?.price,color:'#a7baff'},{name:'حد ضرر',price:trade.stop_loss,color:'#ff9393'},...(trade.take_profits||[]).map((price,i)=>({name:`TP${i+1}`,price,color:'#63dfaf'}))].filter(x=>Number.isFinite(Number(x.price))&&Number(x.price)>0):[];
  const draw=()=>{
   const list=bars.slice(-Number(target.querySelector('[data-chart-window]').value));
   const rawLow=Math.min(...list.map(b=>b.low)),rawHigh=Math.max(...list.map(b=>b.high)),pad=Math.max((rawHigh-rawLow)*.1,rawHigh*.0002),low=rawLow-pad,high=rawHigh+pad;
   const W=Math.max(360,Math.min(960,target.clientWidth||960)),H=360,L=12,R=W-95,T=25,B=280,from=list[0].time,to=list[list.length-1].time+900;
   const x=t=>L+(R-L)*(t-from)/(to-from),y=v=>B-(B-T)*(v-low)/(high-low),ns='http://www.w3.org/2000/svg';
   const svg=document.createElementNS(ns,'svg');svg.setAttribute('viewBox',`0 0 ${W} ${H}`);svg.setAttribute('role','img');svg.setAttribute('aria-label',`نمودار کندلی ${d.symbol} با محور قیمت و زمان`);svg.style.cssText='width:100%;min-width:0;display:block;background:#0b1424;border-radius:10px';
   const add=(tag,attrs,text)=>{const e=document.createElementNS(ns,tag);for(const [key,value] of Object.entries(attrs))e.setAttribute(key,value);if(text!=null)e.textContent=text;svg.append(e);return e;};
   for(let i=0;i<=5;i++){const value=low+(high-low)*i/5,yy=y(value);add('line',{x1:L,x2:R,y1:yy,y2:yy,stroke:'#34455e','stroke-dasharray':'3 4'});add('text',{x:R+12,y:yy+5,fill:'#d5dfed','font-size':13},n(value));}
   const ticks=W<600?2:4;for(let i=0;i<=ticks;i++){const index=Math.min(list.length-1,Math.round((list.length-1)*i/ticks)),b=list[index];add('text',{x:x(b.time+450),y:B+30,fill:'#c4d0e3','font-size':12,'text-anchor':i===0?'start':i===ticks?'end':'middle'},W<600?new Date(b.time*1000).toLocaleTimeString('fa-IR',{hour:'2-digit',minute:'2-digit'}):date(b.time));}
   const width=Math.max(2,(R-L)/list.length*.62);
   for(const b of list){const xx=x(b.time+450),color=b.close>=b.open?'#59d9a4':'#ff8585';add('line',{x1:xx,x2:xx,y1:y(b.high),y2:y(b.low),stroke:color,'stroke-width':1.5});add('rect',{x:xx-width/2,y:Math.min(y(b.open),y(b.close)),width,height:Math.max(2,Math.abs(y(b.open)-y(b.close))),fill:color});}
   for(const level of levels)if(level.price>=low&&level.price<=high){const yy=y(level.price);add('line',{x1:L,x2:R,y1:yy,y2:yy,stroke:level.color,'stroke-dasharray':'7 4'});add('text',{x:L+5,y:yy-5,fill:level.color,'font-size':12},`${level.name}: ${n(level.price)}`);}
   const last=list[list.length-1];add('line',{x1:L,x2:R,y1:y(last.close),y2:y(last.close),stroke:'#f5d88b','stroke-dasharray':'2 3'});
   const cross=add('line',{x1:L,x2:L,y1:T,y2:B,stroke:'#c9d6eb',opacity:0,'stroke-dasharray':'3 3'});
   const reading=target.querySelector('[data-chart-reading]'),read=b=>{reading.textContent=`${date(b.time)} | باز: ${n(b.open)} · بالا: ${n(b.high)} · پایین: ${n(b.low)} · بسته: ${n(b.close)} USDT`;};read(last);
   const point=clientX=>{const rect=svg.getBoundingClientRect(),xx=(clientX-rect.left)/rect.width*W,when=from+(xx-L)/(R-L)*(to-from),index=list.reduce((best,b,i)=>Math.abs(b.time+450-when)<Math.abs(list[best].time+450-when)?i:best,0),b=list[index],cx=x(b.time+450);cross.setAttribute('x1',cx);cross.setAttribute('x2',cx);cross.setAttribute('opacity',1);read(b);};
   svg.addEventListener('pointermove',e=>point(e.clientX));svg.addEventListener('pointerdown',e=>point(e.clientX));svg.addEventListener('pointerleave',()=>cross.setAttribute('opacity',0));
   target.querySelector('[data-chart-scroll]').replaceChildren(svg);
   target.querySelector('[data-chart-levels]').innerHTML=levels.map(l=>`<span style="display:inline-block;margin:4px 8px;color:${l.color}">${esc(l.name)}: <b dir="ltr">${esc(n(l.price))}</b>${l.price<low||l.price>high?' (خارج از بازهٔ قیمت نمودار)':''}</span>`).join('');
  };
  if(target.clientWidth<500)target.querySelector('[data-chart-window]').value='16';
  target.querySelector('[data-chart-window]').onchange=draw;draw();
  if(typeof ResizeObserver!=='undefined'){let width=target.clientWidth;const observer=new ResizeObserver(()=>{const next=target.clientWidth;if(next>0&&Math.abs(next-width)>1){width=next;draw();}});observer.observe(target);target.chartObserver=observer;}
 }
};
