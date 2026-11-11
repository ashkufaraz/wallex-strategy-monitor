const orderFlowView={
 safeRender(flow,item,signalId){try{return this.render(flow,item,signalId);}catch(error){return '<section class="detail-section order-flow"><h3>بررسی کمکی جریان سفارش‌ها</h3><p>اطلاعات کافی نیست؛ نمایش داده کمکی با مشکل مواجه شده است. سیگنال و بررسی ورود دستی مستقل باقی می‌مانند.</p></section>';}},
 labels:{SUPPORTIVE:'همسو با سیگنال',CONTRADICTORY:'مخالف سیگنال',MIXED:'خنثی یا ترکیبی',UNAVAILABLE:'اطلاعات کافی نیست'},
 explain(status,reason){
  if(status==='SUPPORTIVE')return 'فشار معاملات انجام‌شده و دفتر مشاهده‌شده با جهت سیگنال همسو هستند؛ این نتیجه تضمین حرکت قیمت نیست.';
  if(status==='CONTRADICTORY')return 'فشار معاملات و دفتر مشاهده‌شده مخالف جهت سیگنال هستند. برای تصمیم ورود احتیاط کنید؛ سیگنال اصلی تغییر نکرده است.';
  if(status==='MIXED')return 'شواهد خنثی یا با یکدیگر متفاوت‌اند؛ نتیجه جهت‌دار روشنی نداریم.';
  if(/late|Historical book|stale|too old/i.test(reason||''))return 'داده دیر دریافت شده یا برای این زمان معتبر نیست. دفتر گذشته را از داده فعلی بازسازی نمی‌کنیم.';
  if(/band/i.test(reason||''))return 'دفتر دریافت‌شده تمام محدوده موردنیاز را پوشش نمی‌دهد؛ نتیجه جهت‌دار ارائه نمی‌شود.';
  if(/page|gap|volume|Missing|incomplete|disagrees/i.test(reason||''))return 'بازه معاملات یا حجم‌های مرجع کامل و سازگار نیستند؛ داده ناکافی است.';
  return 'داده معتبر کافی دریافت نشده است؛ ممکن است کار هنوز در صف باشد یا ارتباط پردازشگر و منبع برقرار نباشد.';
 },
 render(flow,item,signalId){
  if(!flow?.enabled)return '';
  const id=item?.config?.id||'',visible=flow.visible!==false;
  const button=`<button type="button" data-order-flow-visibility="${esc(id)}" data-visible="${visible?'false':'true'}">${visible?'پنهان کردن تحلیل کمکی':'نمایش تحلیل کمکی'}</button>`;
  if(!visible)return `<section class="detail-section order-flow"><h3>بررسی کمکی جریان سفارش‌ها</h3>${button}<p>نمایش این تحلیل برای این استراتژی پنهان است؛ سیگنال و شرایط ورود تغییر نمی‌کنند.</p></section>`;
  const records=(flow.results||[]).filter(x=>x.signal_id===signalId);
  const historical=records.find(x=>x.kind==='SIGNAL'),latest=records.filter(x=>x.kind==='LATEST').sort((a,b)=>b.trade_window_end-a.trade_window_end)[0];
  const num=v=>v==null?'—':Number(v).toLocaleString('fa-IR',{maximumFractionDigits:6});
  const time=v=>v?new Date(v*1000).toLocaleString('fa-IR'):'ثبت نشده';
  const render=(r,kind)=>{
   const stale=kind==='LATEST'&&r&&Date.now()/1000-r.trade_window_end>flow.max_age_seconds;
   const invalid=!r||stale||r.data_completeness!=='COMPLETE_WITHIN_REQUESTED_WINDOWS';
   const status=invalid?'UNAVAILABLE':r.advisory_status;
   const tone={SUPPORTIVE:'supportive',CONTRADICTORY:'contradictory',MIXED:'mixed',UNAVAILABLE:'unavailable'}[status]||'unavailable';
   const windows=Object.values(r?.indicator_values||{}).sort((a,b)=>b.window_seconds-a.window_seconds),v=invalid?null:windows[0];
   const policy=r?.policy||{};
   const pressure=!v?'قابل بررسی نیست':v.normalized_delta>=(policy.taker_threshold??.15)?'خریداران':v.normalized_delta<=-(policy.taker_threshold??.15)?'فروشندگان':'متعادل';
   const book=!v?'قابل بررسی نیست':v.order_book.imbalance>=(policy.book_threshold??.15)?'خرید بیشتر':v.order_book.imbalance<=-(policy.book_threshold??.15)?'فروش بیشتر':'متعادل';
   const rows=[['فشار خرید و فروش',pressure,'بر پایه حجم معاملات انجام‌شده با آغازگری خریدار یا فروشنده.'],['روند فشار معاملات',!v?'قابل بررسی نیست':({UP:'صعودی',DOWN:'نزولی',FLAT:'خنثی'}[v.cvd_trend]),'تغییر تجمعی فشار معاملات در پنجره؛ رأی مستقلی از فشار خرید و فروش نیست.'],['وضعیت سفارش‌های دفتر',book,'فقط سفارش‌های قابل مشاهده در فاصله قیمتی یکسان از قیمت میانی؛ امکان حذف سریع سفارش‌ها وجود دارد.'],['حجم معاملات',!v?'قابل بررسی نیست':({HIGH:'زیاد',NORMAL:'معمولی',LOW:'کم'}[v.volume_label]),'نسبت به میانگین بازه‌های هم‌مدت گذشته؛ به‌تنهایی جهت قیمت را تعیین نمی‌کند.']];
   return `<article class="order-flow-result ${tone}" data-flow-status="${status}" data-flow-kind="${kind}"><div class="detail-heading"><h4>${kind==='SIGNAL'?'نتیجه ذخیره‌شده مربوط به زمان سیگنال':'تحلیل به‌روز بعدی'}</h4><span class="detail-chip">${esc(this.labels[status]||this.labels.UNAVAILABLE)}</span></div><p>${esc(this.explain(status,stale?'stale':r?.explanation))}</p>${kind==='SIGNAL'?'<p class="detail-hint">این نتیجه تاریخی و ثابت است؛ وضعیت زنده فعلی را نشان نمی‌دهد.</p>':''}<p class="detail-hint">${r?'محاسبه: '+time(r.calculated_at)+' · پایان بازه معاملات: '+time(r.trade_window_end):'هنوز نتیجه‌ای برای این سیگنال ثبت نشده؛ اجرای پردازشگر جدا یا درخواست کوتاه مرورگر لازم است.'}${stale?' · دادهٔ قبلی؛ نتیجه به‌روز معتبر نیست.':''}</p><div class="order-flow-rows">${rows.map(([label,value,hint])=>`<div><span>${label}</span><strong>${esc(value)}</strong><small>${hint}</small></div>`).join('')}</div>
   <details data-log="flow-${kind}-${esc(signalId||'pending')}"><summary>اطلاعات تخصصی</summary><p>منبع: Binance Spot · ${esc(r?.symbol||item?.config?.symbol||'')} · معاملات تجمیعی و snapshot مستقل REST دفتر؛ بدون دفتر WebSocket پیوسته.</p><p>Delta = حجم خرید آغازگر − حجم فروش آغازگر. CVD = مجموع تجمعی Delta با مبنای صفر ابتدای پنجره. Imbalance = (حجم خرید − حجم فروش) ÷ مجموع حجم در محدوده یکسان. Relative Volume = حجم جاری ÷ میانگین پنجره‌های هم‌مدت گذشته.</p><p>پنجره‌ها: ${windows.map(x=>num(x.window_seconds/60)+' دقیقه').join('، ')||'هنوز محاسبه نشده'} · تعداد معاملات تجمیعی: ${num(r?.trade_count)} · زمان دریافت دفتر: ${time(r?.depth_received_at)}</p><p>زمان تولید دفتر در REST موجود نیست؛ زمان دریافت و update ID به‌جای آن معرفی نمی‌شوند. دفتر لحظه سیگنال در صورت تأخیر قابل بازسازی نیست.</p>${windows.map(x=>`<div class="detail-values"><span>پنجره ${num(x.window_seconds/60)} دقیقه · Delta <b>${num(x.delta)}</b></span><span>CVD <b>${num(x.cvd)}</b></span><span>عدم توازن دفتر <b>${num(x.order_book.imbalance)}</b></span><span>حجم نسبی <b>${num(x.relative_volume)}</b></span></div>`).join('')}<p>آستانه‌های پژوهشی و قابل تنظیم‌اند؛ هیچ درصد موفقیت یا احتمال سود محاسبه نمی‌شود.</p><div dir="ltr">${r?.trade_window_start?esc(new Date(r.trade_window_start*1000).toISOString()):'—'} → ${r?.trade_window_end?esc(new Date(r.trade_window_end*1000).toISOString()):'—'}</div>${r?.advisory_status==='UNAVAILABLE'?`<p dir="auto">دلیل فنی: ${esc(r.explanation)}</p>`:''}</details></article>`;
  };
  const processor=flow.processor_last_seen&&Date.now()/1000-flow.processor_last_seen<90;
  return `<section class="detail-section order-flow" dir="rtl"><div class="detail-heading"><h3>بررسی کمکی جریان سفارش‌ها</h3>${button}</div><p>این بخش مستقل از سیگنال اصلی و بررسی ورود دستی است.</p><p class="detail-hint">${processor?'آخرین پاسخ پردازشگر کمکی به‌تازگی ثبت شده است.':flow.browser_processing_enabled?'پردازش کوتاه مرورگر فعال شده، ولی پاسخ اخیر پردازشگر هنوز تأیید نشده است.':'پردازشگر کمکی فعال تأیید نشده است؛ worker جدا لازم است. دفتر WebSocket پیوسته در این نسخه دریافت نمی‌شود.'}</p>${render(historical,'SIGNAL')}${render(latest,'LATEST')}<p class="order-flow-disclaimer">Order Flow ابزار کمکی تحلیل است و شرط صدور سیگنال یا تضمین موفقیت معامله نیست.</p></section>`;
 }
};
