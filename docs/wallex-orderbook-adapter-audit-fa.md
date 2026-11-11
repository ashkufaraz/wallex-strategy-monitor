# اعتبارسنجی Adapter دفتر سفارش والکس

تاریخ بررسی: ۲۰ آبان ۱۴۰۵ / 2026-11-11. این گزارش تکمیل‌کنندهٔ `wallex-market-api-verification-fa.md` و شواهد `wallex-market-api-evidence.json` است. هیچ فعال‌سازی، انتشار یا معامله‌ای انجام نشده است.

## نتیجه اجرایی

خواندن پاسخ واقعی REST و محاسبه عمق و VWAP پیاده‌سازی و با داده واقعی و fixture آزمایش شد. صحت ساختار داده با تأیید تازگی مظنه یا قابلیت اجرای Margin یکسان نیست: خروجی واقعی همچنان `UNVERIFIED` است و نمی‌تواند READY شود. Execution Gate و تحلیل Binance در تنظیمات پیش‌فرض خاموش باقی ماندند؛ فایل تنظیمات فعال اختصاصی وجود ندارد.

## ساختار و اعتبارسنجی دفتر

تنها مسیر مجاز Adapter، `GET https://api.wallex.ir/v1/depth?symbol=BTCUSDT` است. پاسخ باید `success: true` و دو آرایه غیرخالی `result.bid` و `result.ask` داشته باشد. هر ردیف باید object شامل `price`، `quantity` و `sum` باشد.

- `quantity` مقدار ارز پایه است؛ برای BTCUSDT واحد آن BTC است.
- `sum` ارزش همان ردیف در ارز مظنه است؛ در پاسخ‌ها و نمونه رسمی برابر price × quantity است. به‌عنوان حجم تجمعی یا مقدار قابل فروش استفاده نمی‌شود. ناسازگاری با این رابطه با تلورانس عددی محدود رد می‌شود.
- ابتدا bid نزولی و ask صعودی مرتب می‌شوند؛ Best Bid بیشترین و Best Ask کمترین قیمت معتبر است.
- ردیف ناقص، مقدار غیرعددی/نامتناهی/غیرمثبت، قیمت تکراری مبهم، دفتر خالی، crossed و به‌صورت محافظه‌کارانه locked رد می‌شوند. ردیف خراب بی‌صدا حذف نمی‌شود.
- جمع quantity هر سمت، عمق قابل مشاهده همان سمت است؛ این عدد ادعای مشاهده کل بازار نیست.

### تخمین اجرای حجم

برای خرید/LONG سطوح ask از ارزان‌ترین قیمت و برای فروش/SHORT سطوح bid از گران‌ترین قیمت مصرف می‌شوند. سطح آخر می‌تواند فقط بخشی از حجم خود را پر کند:

`VWAP = Σ(price_i × consumed_base_quantity_i) / requested_base_quantity`

اگر عمق کافی نباشد، `depth_sufficient=false` و `vwap=null` است؛ فقط `partial_vwap`، مقدار پرشده و مقدار باقی‌مانده گزارش می‌شوند. قیمت بیرون از عمق قابل مشاهده برون‌یابی نمی‌شود. حتی در صورت کفایت عمق، `execution_confirmed=false` می‌ماند. کارمزد، حرکت دفتر و اجرای واقعی در این برآورد تضمین نمی‌شوند. محاسبه سمت SHORT صرفاً ریاضی است و مجوز اجرای Margin نیست.

در GET واقعی این مرحله با TLS معتبر، Best Bid برابر 73406.93 و Best Ask برابر 73524.15 بود. برای خرید 0.001 BTC، مصرف 0.00079 در 73524.15 و 0.00021 در 73564.74، VWAP برابر **73532.6739 USDT** داد. این نمونه تاریخی است، قیمت جاری نیست. `received_at=1794370912`، `timestamp=null` و اعتماد `UNVERIFIED` ثبت شد.

ابزار تکرار بررسی فقط خواندنی، بدون دسترسی به SQLite پایدار:

```powershell
php tools/wallex-orderbook-diagnostics.php BTCUSDT LONG 0.001
```

## Timestamp و WebSocket

در پاسخ واقعی REST نه timestamp منبع و نه sequence وجود داشت. Adapter زمان محلی دریافت را فقط در `received_at` ثبت می‌کند؛ `timestamp`، `source_timestamp` و `source_sequence` تهی هستند. HTTP Date، فیلد زمانی فرضی، نگاشت تنظیمات یا دریافت دوباره همان دفتر، دلیل تازگی منبع محسوب نمی‌شوند. شناسه نمونه از محتوای دفتر ساخته می‌شود.

مستندات رسمی [Buy Depth](https://developers.wallex.ir/docs/socket-buy-depth)، [Sell Depth](https://developers.wallex.ir/docs/socket-sell-depth) و [پروتکل خام](https://developers.wallex.ir/docs/socket-raw-protocol) بررسی شدند. کانال‌های `orderbook:BTCUSDT@buyDepth` و `orderbook:BTCUSDT@sellDepth` روی `wss://api.wallex.ir/connection/websocket` مستندند، اما نمونه‌های دفتر timestamp تولید/تغییر ندارند. پروتکل اعلام می‌کند پیام‌های غیرکندلی offset ندارند و epoch/offset ارسالی در connect نادیده گرفته می‌شوند. timestamp نمونه کانال trade متعلق به معامله است، نه هر دو سمت دفتر.

بنابراین از اسناد بررسی‌شده، تازگی معتبر و هماهنگ هر دو سمت قابل تأیید نبود. این مرحله اتصال زنده WebSocket را آزمایش نکرد؛ نتیجه درباره قرارداد مستند است، نه ادعای مشاهده تمام پیام‌های عملیاتی. heartbeat، زمان دریافت، زمان معامله و زمان fair price جای زمان دفتر را نمی‌گیرند.

مسیر رسمی دیگر `GET https://api.wallex.ir/depth/all` در Swagger Spot وجود دارد؛ در بررسی قبلی پاسخ 503 HTML داده بود و این مرحله دوباره فراخوانی نشد. snapshot جایگزین با timestamp/sequence معتبر تأیید نشده است. وجود چنین راهی در تمام سامانه‌های والکس نفی نمی‌شود؛ در منابع بررسی‌شده مدرک کافی یافت نشد.

## Margin، SHORT و Dry Run

شواهد گزارش قبلی، BTCUSDT را در فهرست Spot و Margin نشان می‌دهند و مستندات Margin سمت `short` را پشتیبانی می‌کنند. این موضوع ثابت نمی‌کند که ورود تعهدی دقیقاً از دفتر عمومی BTCUSDT اجرا می‌شود یا قیمت پرشدن با Best Bid عمومی برابر است. در مستندات بررسی‌شده تضمین صریح تطبیق این دفتر با اجرای تعهدی یا دفتر مستقل با قرارداد مشخص یافت نشد. بنابراین instrument مربوط به SHORT همچنان تأییدنشده و مسدود است. بازار تعهدی را با قرارداد آتی دائمی یکسان فرض نکردیم.

مسیر رسمی پیش‌نمایش در [مستند Dry Run](https://developers.wallex.ir/docs/margin-dry-run):

`POST https://api.wallex.ir/margin-trade/v1/public/dry-run`

فقط صفحه مستندات و Swagger با GET بدون احراز هویت دریافت شدند؛ خود این endpoint، endpoint وام و endpoint ایجاد/بستن موقعیت فراخوانی نشدند. کلمه public در مسیر، دلیل بی‌نیازی از احراز هویت نیست: عملیات Dry Run در Swagger از `ApiKeyAuth` سطح اصلی ارث می‌برد.

درخواست مستند شامل `collateral`، `market`، `open_price`، `risk_coef`، `side` و فیلدهای اختیاری `stop_loss` و `take_profit` است. ساختار نتیجه مستند شامل این فیلدهاست:

```text
call_price, liquidity_price, market, max_age, open_price, risk_coef,
side, stop_loss, take_profit, extend_time,
collateral {currency,value}, loan {currency,value},
open_fee {currency,value}, trade_fee {currency,value},
extend_fee {currency,value}
```

این ساختار پاسخ واقعی یک فراخوانی Dry Run نیست. `open_price` ورودی کاربر است؛ بازگشت آن، شاهد کشف قیمت بازار یا اجرای سفارش در Best Bid نیست. نتیجه مستند، timestamp دفتر، عمق قابل اجرا یا تضمین fill ندارد.

[اصطلاحات Margin](https://developers.wallex.ir/docs/margin-types) هزینه فعال‌سازی موقعیت و تمدیدهای چهارساعته را توضیح می‌دهد که هنگام بستن از وثیقه کسر می‌شوند. خروجی Dry Run فیلدهای جداگانه هزینه افتتاح، معامله و تمدید دارد؛ نرخ عددی، فرمول دقیق تسویه، هزینه نهایی حساب و انطباق هزینه اعتبار با همه حالت‌ها در این بررسی تأیید عملیاتی نشد. این هزینه‌ها صفر فرض نشده‌اند و در VWAP خام وارد نمی‌شوند.

## تست‌ها و حدود ایمنی

- `php tests/wallex-orderbook.php`: پنجاه assertion موفق؛ extrema، object mapping، sum غیرتجمعی، VWAP خرید/فروش، پرشدن جزئی، کمبود عمق، داده خراب، crossed/locked، HTTP Date، زمان ساختگی، منع calibration و READY، منع SHORT تأییدنشده و GET-only.
- `php tests/all.php`: همه ۲۱ مجموعه PHP موفق؛ از جمله Execution Gate با ۷۳ assertion و Monitor با ۹ assertion و رگرسیون استراتژی‌های قدیمی.
- چهار مجموعه JavaScript شامل notifications، trade، details و execution-gate موفق.
- پنج برنامه Python شامل deployment، admin-diagnostics، indicator-reference، candle-reference و binance-live-reference موفق؛ به‌ترتیب دو مجموعه اول هرکدام ۴ تست، مرجع اندیکاتورها ۱۶ مقدار، کندل‌ها ۱۰ مقایسه و مرجع Binance شانزده مقایسه.
- ابزار CLI روی API واقعی پاسخ معتبر خواند؛ این یک تست ارسال سفارش یا تأیید اجرای Margin نیست.

فایل‌های این مرحله: `src/WallexExecutionMarketProvider.php`، `src/ExecutionGate.php` (فقط مانع اعتماد مظنه)، `src/ExecutionMonitor.php` (interface تزریق provider برای تست)، `tests/wallex-orderbook.php`، `tests/execution-gate.php`، `tests/execution-monitor.php`، `tests/all.php`، `tools/wallex-orderbook-diagnostics.php` و این گزارش و فایل شواهد همراه.

منطق Strategy Engine، تحلیل Binance، App و IndicatorData در این مرحله تغییر نکرده‌اند. تست‌های پایگاه داده در حافظه/محیط آزمایشی اجرا شدند؛ انتقال state، تغییر استراتژی فعال، دستکاری هشدار تاریخی، push، deployment، درخواست احراز هویت‌شده و کد ارسال سفارش اضافه یا اجرا نشد. TLS verification خاموش نشد.

موانع READY همچنان برقرارند: نبود timestamp معتبر منبع برای هر دو سمت، تأیید نشدن تطبیق instrument تعهدی با دفتر عمومی، تأیید نشدن مدل کامل هزینه‌ها و خاموش بودن feature flags. کفایت عمق به‌تنهایی هیچ‌کدام را رفع نمی‌کند.
