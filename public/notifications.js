const panelNotifications = {
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
      const notification = new Notification(title, { body, tag, lang: 'fa', dir: 'rtl' });
      notification.onerror = () => report('مرورگر نتوانست اعلان را نمایش دهد. تنظیمات اعلان مرورگر و ویندوز را بررسی کنید.', true);
      return true;
    } catch (error) {
      report('ساخت اعلان ناموفق بود: ' + error.message, true);
      return false;
    }
  }
};
