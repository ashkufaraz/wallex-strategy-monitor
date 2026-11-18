const tradeDetails = {
  entryOnlyMessage:'شرط‌های یک مسیر مجاز ورود، بدون تأیید اندیکاتورها، در زمان صدور هشدار برقرار بوده‌اند. مسیرهای جایگزین لازم نیست هم‌زمان تأیید شوند. جزئیات استراتژی وضعیت فعلی را نشان می‌دهد؛ این پیام تأیید نهایی ورود نیست.',
  sourceText(payload,current){const labels={chatgpt:'ChatGPT',grok:'Grok',deep:'Deep',gemini:'Gemini',claude:'Claude'};const saved=Array.isArray(payload.analysis_sources);const sources=(saved?payload.analysis_sources:(current||[])).filter(s=>Object.hasOwnProperty.call(labels,s)).map(s=>labels[s]);return `${saved?'منبع تحلیل هنگام صدور':'منبع فعلی استراتژی'}: ${sources.length?sources.join('، '):'مشخص نشده'}`;},
  isActive(item) { return Boolean(item.config.enabled) && !item.state.cancelled_at; },
  status(item) {
    if (item.state.cancelled_at) return `لغو شده و متوقف: ${item.state.cancel_reason}`;
    return item.config.enabled ? 'فعال' : 'غیرفعال';
  },
  toggleLabel(item) {
    return item.state.cancelled_at ? 'شروع مجدد پس از لغو' : (item.config.enabled ? 'توقف' : 'فعال‌سازی');
  },
  title(payload) { if(payload.type==='notification_test')return payload.message; if(payload.type==='scanner_pattern')return 'تأیید الگوی نموداری'; if(payload.type==='entry_conditions_only')return 'فقط شرط‌های ورود بدون اندیکاتور تأیید شده'; if(payload.type==='binance_signal')return 'سیگنال بایننس شناسایی شد'; if(payload.type==='manual_review')return 'آماده بررسی برای ورود دستی'; if(payload.type==='execution_ready')return 'آمادگی مسیر تأییدشده'; return payload.type === 'cancelled' ? 'استراتژی لغو شد' : payload.action; },
  alertHtml(payload) {
    if(payload.type==='notification_test')return payload.message;
    if(payload.type==='scanner_pattern'){const div=document.createElement('div');div.className='summary';div.textContent=payload.message;return div.outerHTML;}
    if(payload.type==='scanner_manual_review'){const div=document.createElement('div');div.className='summary';div.textContent=this.notification(payload);return div.outerHTML+this.html(payload.trade,payload.action);}
    if(payload.type==='entry_conditions_only'){const div=document.createElement('div');div.className='summary entry-only-warning';div.textContent=this.entryOnlyMessage;return div.outerHTML+(payload.entry_evidence?.length?`<details><summary>مسیر و شرط‌های ثبت‌شده هنگام صدور هشدار</summary>${strategyDetails.rules(payload.entry_evidence,true)}</details>`:'<p class="small muted">برای این هشدار قدیمی، تصویر شرط‌های زمان صدور ذخیره نشده است.</p>');}
    if(['binance_signal','manual_review'].includes(payload.type)){const div=document.createElement('div');div.className='summary';div.textContent=this.notification(payload);return div.outerHTML;}
    if (payload.type !== 'cancelled') return this.html(payload.trade, payload.action);
    const div = document.createElement('div');
    div.className = 'summary error';
    div.textContent = `استراتژی متوقف شد. دلیل: ${payload.reason}`;
    return div.outerHTML;
  },
  lines(trade, action) {
    if (!trade) return ['جزئیات سفارش در این هشدار ثبت نشده است.'];
    const price = trade.entry.reference_price ?? trade.entry.price;
    const lines = [
      `نوع معامله: ${action === 'LONG' ? 'لانگ / خرید' : 'شورت / فروش'} (${action})`,
      `نوع ورود: ${trade.entry.type === 'LIMIT' ? 'لیمیت' : 'مارکت'}`,
      `${trade.entry.type === 'LIMIT' ? 'قیمت ورود' : 'قیمت مرجع ورود هنگام هشدار'}: ${price ?? 'قیمت زمان هشدار'} USDT`,
      `حد ضرر: ${trade.stop_loss} USDT`,
      ...trade.take_profits.map((target, i) => `حد سود ${i + 1}: ${target} USDT`)
    ];
    if (trade.quantity !== undefined) lines.push(`مقدار: ${trade.quantity} واحد ارز پایه`);
    if (trade.leverage !== undefined) lines.push(`اهرم پیشنهادی استراتژی: ${trade.leverage}x`);
    if (trade.notes) lines.push(`توضیحات: ${trade.notes}`);
    if (trade.entry.type === 'MARKET') lines.push('قیمت مرجع، قیمت قطعی اجرای سفارش نیست.');
    if (price !== undefined && (action === 'LONG'
      ? price <= trade.stop_loss || price >= trade.take_profits[0]
      : price >= trade.stop_loss || price <= trade.take_profits[0])) {
      lines.push('توجه: قیمت مرجع ورود خارج از فاصلهٔ حد ضرر و اولین حد سود است؛ پیش از سفارش بررسی کنید.');
    }
    return lines;
  },
  html(trade, action) {
    const escape = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    return '<div class="summary">' + this.lines(trade, action).map(line => `<div>${escape(line)}</div>`).join('') + '</div>';
  },
  notification(payload) {const text=this.notificationBody(payload),label=payload.exchange==='nobitex'?'\u0646\u0648\u0628\u06cc\u062a\u06a9\u0633':'\u0648\u0627\u0644\u06a9\u0633';return payload.exchange&&!String(text).startsWith(label)?label+' / '+text:text;},
  notificationBody(payload) {
    if(payload.type==='notification_test')return payload.message;
    if(payload.type==='scanner_pattern')return payload.message;
    if(payload.type==='scanner_manual_review')return `${payload.symbol} · ${payload.action} · MANUAL_REVIEW_AVAILABLE\n${payload.reason}\nنسخه: ${payload.scanner_version}\nانقضا UTC: ${new Date(payload.expires_at*1000).toISOString()}\nفقط بررسی دستی و معاملهٔ آزمایشی؛ سفارش واقعی ثبت نشده است.`;
    if(payload.type==='entry_conditions_only')return `${payload.name}\n${this.entryOnlyMessage}`;
    if(payload.type==='binance_signal')return `${payload.symbol} — سیگنال ${payload.action} شناسایی شد. قیمت بایننس ${payload.price}. بررسی شرایط ورود در والکس آغاز شد. این پیام دستور معامله نیست.`;
    if(payload.type==='manual_review')return `${payload.symbol} — شرایط تخمینی برای بررسی ورود ${payload.action} فراهم است. قیمت، کارمزد و امکان اجرا را در والکس بررسی کنید. تازگی دفتر تأیید نشده (UNVERIFIED)؛ این پیام دستور معامله نیست.`;
    if(payload.type==='execution_ready')return `${payload.name}\nSignal confirmed. Manual execution on Wallex is required.\n${this.lines(payload.trade,payload.action).join('\n')}`;
    if (payload.type === 'cancelled') return `${payload.name}\nاستراتژی متوقف شد. دلیل: ${payload.reason}`;
    return [payload.name, ...this.lines(payload.trade, payload.action)].join('\n');
  }
};
