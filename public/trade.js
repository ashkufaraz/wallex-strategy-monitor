const tradeDetails = {
  status(item) {
    if (item.state.cancelled_at) return `لغو شده و متوقف: ${item.state.cancel_reason}`;
    return item.config.enabled ? 'فعال' : 'غیرفعال';
  },
  toggleLabel(item) {
    return item.state.cancelled_at ? 'شروع مجدد پس از لغو' : (item.config.enabled ? 'توقف' : 'فعال‌سازی');
  },
  title(payload) { if(payload.type==='binance_signal')return 'سیگنال بایننس شناسایی شد'; if(payload.type==='manual_review')return 'آماده بررسی برای ورود دستی'; if(payload.type==='execution_ready')return 'آمادگی مسیر تأییدشده'; return payload.type === 'cancelled' ? 'استراتژی لغو شد' : payload.action; },
  alertHtml(payload) {
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
  notification(payload) {
    if(payload.type==='binance_signal')return `${payload.symbol} — سیگنال ${payload.action} شناسایی شد. قیمت بایننس ${payload.price}. بررسی شرایط ورود در والکس آغاز شد. این پیام دستور معامله نیست.`;
    if(payload.type==='manual_review')return `${payload.symbol} — شرایط تخمینی برای بررسی ورود ${payload.action} فراهم است. قیمت، کارمزد و امکان اجرا را در والکس بررسی کنید. تازگی دفتر تأیید نشده (UNVERIFIED)؛ این پیام دستور معامله نیست.`;
    if(payload.type==='execution_ready')return `${payload.name}\nSignal confirmed. Manual execution on Wallex is required.\n${this.lines(payload.trade,payload.action).join('\n')}`;
    if (payload.type === 'cancelled') return `${payload.name}\nاستراتژی متوقف شد. دلیل: ${payload.reason}`;
    return [payload.name, ...this.lines(payload.trade, payload.action)].join('\n');
  }
};
