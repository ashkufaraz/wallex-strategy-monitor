const panelNotifications = {
  cursor: null,
  process(alerts, send) {
    const key='wallex-notification-cursor-v1';
    if(this.cursor===null){
      let saved=null;try{saved=window.localStorage?.getItem(key);}catch(e){}
      this.cursor=saved!==null&&Number.isSafeInteger(Number(saved))&&Number(saved)>=0?Number(saved):Math.max(0,...alerts.map(a=>a.id));
      try{window.localStorage?.setItem(key,String(this.cursor));}catch(e){}
    }
    for(const a of [...alerts].sort((a,b)=>a.id-b.id)){
      if(a.id<=this.cursor)continue;
      if(!send(a))break; // Do not lose an alert when permission or construction fails.
      this.cursor=a.id;
      try{window.localStorage?.setItem(key,String(this.cursor));}catch(e){}
    }
  },
  problem() {
    if (!window.isSecureContext) return 'اعلان به محیط امن نیاز دارد. برنامه را از http://127.0.0.1:8080 یا localhost باز کنید؛ برای آدرس شبکه HTTPS لازم است.';
    if (!('Notification' in window)) return 'این مرورگر اعلان سیستم را پشتیبانی نمی‌کند. برنامه را در Chrome یا Edge باز کنید.';
    if (Notification.permission === 'denied') return 'اعلان این سایت مسدود است. از تنظیمات سایت کنار نوار آدرس، Notifications را روی Allow بگذارید و صفحه را تازه کنید.';
    return null;
  },
  status() {
    return this.problem() || (Notification.permission === 'granted'
      ? 'اجازهٔ اعلان مرورگر فعال است. نمایش اعلان به تنظیمات اعلان ویندوز نیز بستگی دارد.'
      : 'اجازهٔ اعلان هنوز داده نشده است؛ «فعال‌سازی اعلان مرورگر» را بزنید.');
  },
  async enable(report) {
    const problem = this.problem();
    if (problem) { report(problem, true); return; }
    try {
      const result = await Notification.requestPermission();
      report(this.status(), result !== 'granted');
    } catch (error) { report('درخواست اجازهٔ اعلان ناموفق بود: ' + error.message, true); }
  },
  send(title, body, tag, report) {
    const problem = this.problem();
    if (problem) { report(problem, true); return false; }
    if (Notification.permission !== 'granted') {
      report('ابتدا «فعال‌سازی اعلان مرورگر» را بزنید و اجازه را تأیید کنید.', true);
      return false;
    }
    try {
      const notification = new Notification(title, { body, tag, lang: 'fa', dir: 'rtl', requireInteraction: true });
      notification.onclick = () => { window.focus(); notification.close(); };
      notification.onshow = () => report('اعلان هشدار توسط مرورگر نمایش داده شد.', false);
      notification.onerror = () => report('مرورگر نتوانست اعلان را نمایش دهد. تنظیمات اعلان مرورگر و ویندوز را بررسی کنید.', true);
      return true;
    } catch (error) {
      report('ساخت اعلان ناموفق بود: ' + error.message, true);
      return false;
    }
  }
};
