const strategyDetails = {
  buttonLabel: 'جزئیات بررسی',
  id: null, loading: false,
  indicators(snapshot, config = null) {
    const definitions=config?.conditions||snapshot?.conditions||[];
    if(!definitions.length)return '<div class="detail-notice">اندیکاتورها هنوز بررسی نشده‌اند.</div>';
    const tf={'1m':'یک‌دقیقه‌ای','15m':'پانزده‌دقیقه‌ای','1h':'یک‌ساعته','4h':'چهارساعته'};
    const metrics={value:'مقدار اندیکاتور',close_minus_ema_pct:'فاصله قیمت از EMA (%)',atr_pct:'ATR نسبت به قیمت (%)',average_previous:'میانگین حجم قبلی',ratio_to_previous_avg:'نسبت حجم به میانگین'};
    const operators={lt:'کمتر از',lte:'حداکثر',gt:'بیشتر از',gte:'حداقل',eq:'برابر با',neq:'نابرابر با',between:'در بازه'};
    const rows=definitions.map(c=>({...c,...snapshot?.conditions?.find(x=>x.id===c.id)}));
    const passed=rows.filter(c=>c.status==='PASS').length;
    const summary=!snapshot?'در انتظار تأیید شرط نهایی قیمت':passed===rows.length?'همه اندیکاتورها تأیید شدند':rows.some(c=>c.status==='FAIL')?'تأیید اندیکاتورها کامل نشده':'داده کافی برای تأیید موجود نیست';
    return `<section class="detail-section detail-indicators"><div class="detail-heading"><h3>فیلترهای اندیکاتوری ورود</h3><span class="detail-chip ${passed===rows.length?'positive':'neutral'}">${passed} از ${rows.length} تأیید</span></div><p><b>${summary}</b></p><p class="detail-hint">همه فیلترها باید هم‌زمان تأیید شوند. ارزیابی پس از اجرای شرط نهایی ورود روی کندل بسته‌شده انجام می‌شود.${snapshot?` آخرین ارزیابی: ${time(snapshot.as_of)}؛ نتیجه مربوط به همان زمان است.`:' سبز شدن پیش‌نمایش قیمت به معنی اجرای تأیید نهایی نیست.'}</p><div class="detail-indicator-grid">${rows.map(c=>{
      const status=c.status||'PENDING',color=status==='PASS'?'yes':status==='FAIL'?'no':'unknown';
      const label={PASS:'تأیید شد',FAIL:'برقرار نیست',UNAVAILABLE:'داده در دسترس نیست',PENDING:'هنوز ارزیابی نشده'}[status]||status;
      const target=Array.isArray(c.value)?c.value.map(v=>this.number(v)).join(' تا '):this.number(c.value);
      const gauge=c.indicator==='RSI'&&c.observed!=null?`<div class="indicator-gauge" role="img" aria-label="${esc(`RSI: ${c.observed} / 100`)}"><span style="width:${Math.max(0,Math.min(100,Number(c.observed)))}%"></span></div><div class="indicator-scale"><span>0</span><span>RSI</span><span>100</span></div>`:'';
      return `<article data-indicator="${esc(c.id)}" data-status="${esc(status)}" class="detail-rule ${color}"><div class="detail-rule-title"><span>${status==='PASS'?'✓':status==='FAIL'?'×':'…'}</span><b>${esc(c.indicator)} · دوره ${esc(c.period)}</b></div><p>${esc(tf[c.timeframe]||c.timeframe)} · ${esc(label)}</p><div class="detail-hint">${esc(metrics[c.metric]||c.metric)}</div><div class="detail-values"><span>مقدار محاسبه‌شده<b dir="ltr">${esc(this.number(c.observed))}</b></span><span>شرط لازم<b>${esc(operators[c.operator]||c.operator)} ${esc(target)}</b></span></div>${gauge}${c.error?`<p class="error">${esc(c.error)}</p>`:''}<details><summary>زمان کندل و جزئیات ارزیابی</summary><div>${esc(c.id)} · ${esc(c.symbol||'')} · ${esc(c.metric)}</div><div>بسته‌شدن: ${time(c.candle_close)} · شروع: ${time(c.candle_open)}</div></details></article>`;
    }).join('')}</div></section>`;
  },
  init() {
    const dialog = document.createElement('dialog');
    dialog.id = 'strategyDetails';
    dialog.innerHTML = '<div class="row"><h2>جزئیات بررسی استراتژی</h2><button type="button" id="closeDetails">بستن</button></div><div id="detailsContent"></div>';
    document.body.append(dialog);
    dialog.addEventListener('click',async event=>{
      const visibility=event.target.closest('[data-order-flow-visibility]');
      if(visibility){visibility.disabled=true;try{await request({action:'order_flow_visibility',strategy_id:visibility.dataset.orderFlowVisibility,visible:visibility.dataset.visible==='true'});await this.refresh();}catch(error){visibility.disabled=false;msg(error.message,true);}return;}
      const button=event.target.closest('[data-manual-signal]');if(!button)return;
      if(!confirm('فقط انجام دستی معامله ثبت شود؟ این دکمه سفارشی ارسال نمی‌کند.'))return;
      button.disabled=true;
      try{await request({action:'acknowledge_execution',signal_id:button.dataset.manualSignal});await this.refresh();}catch(error){button.disabled=false;msg(error.message,true);}
    });
    document.getElementById('closeDetails').onclick = () => dialog.close();
    dialog.addEventListener('close', () => { this.id = null; });
  },
  async open(item) {
    this.id = item.config.id;
    delete document.getElementById('detailsContent').dataset.loaded;
    document.getElementById('detailsContent').textContent = 'در حال دریافت جزئیات…';
    document.getElementById('strategyDetails').showModal();
    await this.refresh();
  },
  source(source) {
    if (typeof source === 'number') return String(source);
    if (source.price) return 'قیمت مشاهده‌شده';
    const c = source.candle;
    const fields={open:'قیمت بازشدن',close:'قیمت بسته‌شدن',high:'بیشترین قیمت',low:'کمترین قیمت',volume:'حجم'};
    const tf={'1m':'یک‌دقیقه‌ای','5m':'پنج‌دقیقه‌ای','15m':'پانزده‌دقیقه‌ای','30m':'سی‌دقیقه‌ای','1h':'یک‌ساعته','4h':'چهارساعته','1d':'روزانه'}[c.timeframe]||c.timeframe;
    return `${fields[c.field] || c.field} کندل ${tf}، ${c.offset === 1 ? 'آخرین کندل بسته‌شده' : `${c.offset} کندل قبل`}`;
  },
  number(value) { return value == null ? '—' : Number(value).toLocaleString('en-US',{maximumFractionDigits:8}); },
  candleSnapshot(node) {
    return ['left','right'].map(side=>{
      const c=node[`${side}_candle`];
      if(!c)return '';
      const utc=n=>new Date(n*1000).toISOString();
      return `<details class="detail-hint"><summary>OHLCV و بازه دقیق کندل ارزیابی</summary><div>کندل مبنای ارزیابی · ${esc(c.provider??'wallex')} · ${esc(c.timeframe)} · field=${esc(c.field)}<div dir="ltr">${esc(utc(c.open_time))} → ${esc(utc(c.close_time))}</div><div dir="ltr">${['open','high','low','close','volume'].map(k=>`${k}: ${esc(c.ohlcv[k]??'—')}`).join(' · ')}</div>${c.provider==='binance_spot'?'OHLCV کندل مستقیم Binance Spot؛ بدون تجمیع والکس.':'OHLCV ورودی موتور؛ قیمت‌های دقیقه‌های صفرحجم در OHLC لحاظ نمی‌شوند.'}</div></details>`;
    }).join('');
  },
  metric(label,value,tone='',hint='') {
    return `<div class="detail-metric ${tone}"><span>${esc(label)}</span><strong dir="ltr">${esc(this.number(value))} <small>USDT</small></strong>${hint?`<div class="detail-hint">${esc(hint)}</div>`:''}</div>`;
  },
  order(item,price) {
    const t=item.config.trade,action=item.config.action;
    if(!t)return '<div class="detail-empty">جزئیات سفارش در JSON این استراتژی وارد نشده است. بخش trade را اضافه کن.</div>';
    const entry=t.entry.type==='LIMIT'?t.entry.price:price;
    return `<div class="detail-section"><div class="detail-heading"><h3>۲. سفارش موردنظر</h3><span class="detail-chip ${action==='LONG'?'positive':'negative'}">${action==='LONG'?'خرید / لانگ':'فروش / شورت'} · ${esc(action)}</span><span class="detail-chip">${t.entry.type==='LIMIT'?'سفارش لیمیت':'سفارش مارکت'}</span></div><div class="detail-metrics">${this.metric(action==='LONG'?'قیمت خرید / ورود':'قیمت فروش / ورود',entry,'entry',t.entry.type==='MARKET'?'مرجع قیمت بازار؛ قیمت اجرا قطعی نیست':'قیمت تعیین‌شده در استراتژی')}${this.metric('حد ضرر',t.stop_loss,'stop','سطح توقف زیان')}${t.take_profits.map((target,i)=>this.metric(`حد سود ${i+1}`,target,'target',`هدف ${i+1}`)).join('')}</div><div class="detail-meta"><span>مقدار: <b dir="ltr">${esc(t.quantity??'تعیین نشده')}</b> واحد ارز پایه</span><span>اهرم: <b>${esc(t.leverage??'تعیین نشده')}</b></span><span>ثبت سفارش: دستی</span></div>${t.notes?`<p>${esc(t.notes)}</p>`:''}<details class="detail-calculations"><summary>محاسبهٔ سود، زیان و نسبت سود به زیان</summary>${this.trade(t,action,price)}</details></div>`;
  },
  conditionOverview(item,logs,latest) {
    const groups={yes:[],no:[],unknown:[]};
    const labels={gt:'بیشتر از',gte:'بیشتر یا مساوی',lt:'کمتر از',lte:'کمتر یا مساوی',eq:'برابر با',neq:'نابرابر با'};
    const flatten=(node,negated=false,path=[])=>{
      if(node.group)return node.children.flatMap(c=>flatten(c,node.group==='not'?!negated:negated,[...path,{all:'همه با هم',any:'حداقل یکی',not:'نقیض'}[node.group]]));
      return [{...node,result:node.result==null?null:(negated?!node.result:node.result),negated,path}];
    };
    item.config.stages.forEach((stage,stageIndex)=>stage.transitions.forEach((rule,i)=>{
      let found=latest?.status!=='error'?latest?.waiting?.find(r=>r.stage===stage.id&&r.rule===i+1):null;
      let at=found?latest.checked:null,preview=!!found;
      if(!found)for(const entry of logs){if(entry.version!==item.version||entry.report.status==='error')continue;const match=[...(entry.report.trace||[])].reverse().find(r=>r.kind==='transition'&&r.stage===stage.id&&r.rule===i+1);if(match){found=match;at=entry.report.checked;break;}}
      const unknown=c=>c.all?{group:'all',result:null,children:c.all.map(unknown)}:c.any?{group:'any',result:null,children:c.any.map(unknown)}:c.not?{group:'not',result:null,children:[unknown(c.not)]}:{...c,left_source:c.left,right_source:c.right,left:null,right:null,result:null};
      const leaves=flatten(found?found.condition:unknown(rule.when));
      leaves.forEach((node,j)=>{
        const key=node.result===true?'yes':node.result===false?'no':'unknown';
        const sentence=node.left_source?`${node.negated?'نقیض: ':''}${this.source(node.left_source)} ${labels[node.op]} ${this.source(node.right_source)}`:'این قانون هنوز ارزیابی نشده است';
        const whole=found?.condition.result===true?'برقرار':found?.condition.result===false?'برقرار نیست':'نامعلوم';
        groups[key].push(`<article data-status="${key==='yes'?'PASS':key==='no'?'FAIL':'UNAVAILABLE'}" class="detail-rule ${key}"><div class="detail-rule-title"><span>${key==='yes'?'✓':key==='no'?'×':'…'}</span><b>مرحله ${stageIndex+1} · شرط ${i+1}${leaves.length>1?` / بند ${j+1}`:''}</b></div><p>${esc(sentence)}</p><p>${key==='yes'?'مقدار مشاهده‌شده شرط مقایسه را برآورده می‌کند.':key==='no'?'مقدار مشاهده‌شده شرط مقایسه را برآورده نمی‌کند.':'داده کافی نیست یا موتور هنوز این شرط را بررسی نکرده است.'}</p>${node.left_source?`<div class="detail-values"><span>مقدار مشاهده‌شده <b dir="ltr">${esc(this.number(node.left))}</b></span><span>مقدار مقایسه <b dir="ltr">${esc(this.number(node.right))}</b></span></div>`:''}${this.candleSnapshot(node)}<details><summary>شناسه مرحله و رویداد شرط</summary>${esc(rule.event)} · ${esc(stage.id)}</details><div class="detail-hint">${at?`${preview?'پیش‌نمایش آخرین کندل / قیمت':'آخرین ارزیابی واقعی'}: ${time(at)}`:'در انتظار رسیدن موتور به این شرط'}</div>${leaves.length>1?`<div class="detail-hint">رابطهٔ بندها: ${esc(node.path.join(' ← '))} · نتیجهٔ کل قانون: ${whole}</div>`:''}</article>`);
      });
    }));
    return `<div class="detail-section"><div class="detail-heading"><h3>۳. وضعیت شرط‌های ورود</h3></div><p class="detail-hint">سبز یعنی خودِ شرط برقرار است، نه تأیید نهایی ورود. شرط‌های مراحل قبلی با زمان آخرین ارزیابی نمایش داده می‌شوند؛ مقادیر آن‌ها الزاماً مربوط به قیمت فعلی نیست.</p><div class="detail-condition-columns">${[['yes','شرط‌های برقرار','✓'],['no','شرط‌های برقرارنشده','×'],['unknown','هنوز بررسی نشده / داده ناموجود','…']].map(([key,title,icon])=>`<div class="detail-column ${key}"><h4>${icon} ${title}<span>${groups[key].length}</span></h4>${groups[key].join('')||'<div class="detail-empty">موردی وجود ندارد</div>'}</div>`).join('')}</div></div>`;
  },
  dashboard(item,logs,latest) {
    const price=latest?.price??item.state.last_price;
    const at=latest?.price!=null?latest.checked:item.state.price_at;
    const recent=at&&(Date.now()/1000-at)<Math.max(180,3*(data.interval||60));
    const active=item.config.enabled&&!item.state.cancelled_at;
    const result=item.state.cancelled_at?'استراتژی لغو شده':!active?'پایش غیرفعال است':latest?.status==='error'?'آخرین بررسی با خطا مواجه شده':latest?.status==='signal'?'ورود در آخرین بررسی تأیید شده':'در آخرین بررسی ورود تأیید نشده است';
    return `<header class="detail-title"><div><span class="detail-eyebrow">نمای کلی استراتژی</span><h2>${esc(item.config.name)}</h2><div class="detail-hint">${esc(item.config.symbol)} · ${esc(tradeDetails.status(item))}</div></div><span class="detail-chip ${latest?.status==='signal'?'positive':'neutral'}">${result}</span></header><div class="detail-section detail-price"><div><h3>۱. قیمت بازار در آخرین بررسی</h3><div class="detail-live ${recent&&active?'positive':'neutral'}">${at?`${recent&&active?'● آخرین دریافت':'◷ دادهٔ قبلی'} · ${time(at)}`:'هنوز قیمتی دریافت نشده'}</div><p class="detail-hint">آخرین تلاش بررسی: ${time(item.state.last_check)} · مرحلهٔ فعلی: ${esc(item.state.stage)}</p></div><div class="detail-hero-number" dir="ltr">${esc(this.number(price))}<span>USDT</span></div></div>${!recent||!active?'<div class="detail-notice">این عدد قیمت زندهٔ لحظه‌ای نیست. برای دریافت قیمت تازه، پایش استراتژی و پردازشگر باید فعال باشند.</div>':''}${latest?.error?`<div class="detail-notice negative">خطای بررسی: ${esc(latest.error)}</div>`:''}${this.order(item,price)}${this.conditionOverview(item,logs,latest)}${item.config.indicator_conditions?.enabled?this.indicators(latest?.indicator_snapshot??item.state.indicators,item.config.indicator_conditions):''}<details class="detail-safety"><summary>شرط‌های ابطال ستاپ و لغو کامل در آخرین بررسی</summary>${this.rules((latest?.trace||[]).filter(r=>r.kind!=='transition'),true)}</details>`;
  },
  condition(node) {
    const label = node.result === true ? 'برقرار' : node.result === false ? 'برقرار نیست' : 'داده کافی نیست';
    const color = node.result === true ? 'ok' : node.result === false ? 'error' : 'muted';
    if (node.group) return `<div class="condition"><b>${esc(node.group)}: <span class="${color}">${label}</span></b>${node.children.map(c => this.condition(c)).join('')}</div>`;
    if (!node.left_source) return `<div class="muted">${label} · ${esc(node.error || '')}</div>`;
    const op = {gt:'>',gte:'≥',lt:'<',lte:'≤',eq:'=',neq:'≠'}[node.op];
    return `<div class="condition"><span class="${color}">${label}</span> · ${esc(this.source(node.left_source))} <b dir="ltr">${esc(node.left ?? '—')} ${esc(op)} ${esc(node.right ?? '—')}</b> · ${esc(this.source(node.right_source))}${this.candleSnapshot(node)}${node.error ? `<div class="error small">${esc(node.error)}</div>` : ''}</div>`;
  },
  rules(rules, executed) {
    if (!rules?.length) return '<div class="muted">شرطی در این بخش بررسی نشده است.</div>';
    const kinds = {transition:'انتقال مرحله', cancellation:'لغو کامل', invalidation:'ابطال ستاپ'};
    return rules.map(r => `<div class="card"><b>${esc(executed ? kinds[r.kind] : 'پیش‌نمایش شرط')} · ${esc(r.stage || '')} · ${esc(r.event)} · شرط ${esc(r.rule || 1)}</b>${r.reason ? `<div>${esc(r.reason)}</div>` : ''}${r.to ? `<div>مقصد: ${esc(r.to)}</div>` : ''}${r.candle_close ? `<div class="small muted">بسته‌شدن کندل: ${time(r.candle_close)}</div>` : ''}${this.condition(r.condition)}</div>`).join('');
  },
  trade(trade, action, price) {
    if (!trade) return tradeDetails.html(null, action);
    const snapshot = {...trade, entry:{...trade.entry}};
    if (snapshot.entry.type === 'MARKET' && price != null) snapshot.entry.reference_price = price;
    let html = tradeDetails.html(snapshot, action);
    const entry = snapshot.entry.price ?? snapshot.entry.reference_price;
    if (!entry) return html;
    const direction = action === 'LONG' ? 1 : -1;
    const risk = direction * (entry - trade.stop_loss);
    const quantity = trade.quantity;
    html += `<div class="summary">فاصلهٔ ورود تا حد ضرر: ${esc(risk)} USDT به‌ازای هر واحد (${esc((100*risk/entry).toFixed(2))}٪)`;
    if (quantity != null) html += `<div>زیان ناخالص در حد ضرر با مقدار تعیین‌شده: ${esc(Number((risk*quantity).toPrecision(8)))} USDT</div>`;
    for (const [i,target] of trade.take_profits.entries()) {
      const reward = direction*(target-entry);
      html += `<div>هدف ${i+1}: فاصله ${esc(Number(reward.toPrecision(8)))} USDT${risk > 0 ? ` · نسبت سود به زیان ${esc((reward/risk).toFixed(2))}` : ''}${quantity != null ? ` · سود ناخالص با کل مقدار: ${esc(Number((reward*quantity).toPrecision(8)))} USDT` : ''}</div>`;
    }
    return html + '<div class="small muted">محاسبهٔ اختلاف قیمت با کل مقدار است؛ کارمزد، لغزش، اهرم و تقسیم خروج بین هدف‌ها لحاظ نشده‌اند. مقادیر منفی یعنی سطوح با قیمت مرجع سازگار نیستند.</div></div>';
  },
  async refresh() {
    if (!this.id || this.loading) return;
    const id = this.id; this.loading = true;
    try {
      const response = await fetch(`api.php?details=${encodeURIComponent(id)}`, {cache:'no-store'});
      const result = await response.json();
      if (!response.ok) throw Error(result.error || 'خطای دریافت جزئیات');
      if (this.id !== id) return;
      const item = data?.strategies.find(x => x.config.id === id);
      if (!item) throw Error('این استراتژی حذف شده است.');
      const content = document.getElementById('detailsContent');
      const dialog = document.getElementById('strategyDetails');
      const scrollTop = dialog.scrollTop;
      const expanded = new Set([...content.querySelectorAll('details[open]')].map(d => d.dataset.log));
      const statuses = {indicator_blocked:'ورود در انتظار تأیید اندیکاتورها',checked:'بررسی شد',blocked:'ابطال ستاپ یا انقضا',signal:'ورود تأیید شد',cancelled:'لغو شد',error:'خطای بررسی'};
      const latest = result.logs.find(x => x.version === item.version)?.report;
      content.innerHTML = (result.architecture?.analysis_enabled ? this.conditionOverview(item,result.logs,latest)+(item.config.indicator_conditions?.enabled?this.indicators(latest?.indicator_snapshot??item.state.indicators,item.config.indicator_conditions):'')+'<details class="detail-safety"><summary>ابطال و لغو استراتژی</summary>'+this.rules((latest?.trace||[]).filter(x=>x.kind!=='transition'),true)+'</details>' : this.dashboard(item,result.logs,latest)) + '<h3 class="detail-history-heading">سوابق ارزیابی واقعی و پیش‌نمایش شرط‌ها</h3>' + (result.logs.length ? result.logs.map((entry, i) => {
        const r = entry.report;
        return `<details data-log="${entry.id}" ${expanded.has(String(entry.id)) ? 'open' : ''}><summary>${time(r.checked)} · ${esc(statuses[r.status] || r.status)} · ${esc(r.from_stage)} → ${esc(r.stage)}${entry.version !== item.version ? ' · نسخهٔ قبلی' : ''}</summary><div>قیمت این بررسی: ${esc(r.price ?? 'دریافت نشد')} USDT</div>${r.error ? `<div class="error">${esc(r.error)} · نتیجهٔ این چرخه ثبت عملیاتی نشده است.</div>` : ''}<h4>شرط‌هایی که موتور واقعاً به آن‌ها رسید</h4>${this.rules(r.trace,true)}${(r.indicators||[]).map(x=>this.indicators(x.snapshot)).join('')}<h4>پیش‌نمایش شرط‌های مرحلهٔ پایان بررسی</h4><div class="small muted">این بخش فقط نتیجهٔ شرط‌ها روی آخرین کندل بسته‌شده است؛ به معنی اجرای انتقال نیست. شرط کندلی تا رویداد بسته‌شدن جدید منتظر می‌ماند. نتیجهٔ شرط‌های داخلی برای توضیح کامل نمایش داده می‌شود، حتی اگر موتور به‌علت نتیجهٔ شرط قبلی به آن‌ها نیاز نداشته باشد.</div>${this.rules(r.waiting,false)}${r.trade ? `<h4>برنامهٔ معامله در زمان این بررسی</h4>${this.trade(r.trade,r.action || item.config.action,r.price)}` : ''}</details>`;
      }).join('') : '<p>هنوز گزارشی ثبت نشده است. پردازشگر باید با کد جدید اجرا شود؛ پس از اولین بررسی این بخش پر می‌شود.</p>');
      content.dataset.loaded = 'true';
      const signals=(result.execution_signals||[]).filter(p=>p.version===item.version);
      const flow=typeof orderFlowView!=='undefined'?orderFlowView.safeRender(result.order_flow,item,signals[0]?.id):'';
      if(typeof executionGateView!=='undefined')content.insertAdjacentHTML('afterbegin',executionGateView.render(signals,result.architecture,item)+flow);
      dialog.scrollTop = scrollTop;
    } catch (error) {
      if (this.id === id) document.getElementById('detailsContent').textContent = error.message;
    } finally { this.loading = false; }
  }
};
