# بررسی رسمی بازار BTCUSDT، شورت و دفتر سفارش والکس

زمان بررسی: ۱۰ نوامبر ۲۰۲۶، حدود 13:22 تا 13:27 UTC. این گزارش نتیجهٔ همان بازه است و تضمین دسترسی یا ظرفیت آینده نیست. تمام آزمایش‌های شبکه GET عمومی، بدون API key، Authorization یا نشست حساب کاربری بودند. chain گواهی و hostname بررسی شدند؛ TLS هرگز غیرفعال نشد. هیچ کد برنامه، تنظیم، state، SQLite واقعی، سفارش یا استقراری تغییر نکرد. فقط این گزارش و فایل شواهد عمومی ایجاد شدند.

**نتیجه:** BTCUSDT هم Spot و هم Margin/تعهدی فعال دارد. وجود قابلیت SHORT در محصول تعهدی تأیید شد؛ قابلیت SHORT را نباید به سفارش فروش اسپات تعمیم داد. endpoint رسمی و قابل‌دسترسی دفتر سفارش پیدا شد، اما timestamp رویداد/ساخت snapshot دفتر سفارش تأیید نشد. قرارداد Futures/Perpetual مستقل نیز در منابع بررسی‌شده تأیید نشد.

## ۱. مسیر رسیدن به مستندات رسمی

صفحهٔ [API در سایت اصلی والکس](https://wallex.ir/api-document) به `developers.wallex.ir` لینک می‌دهد. [مستندات فعلی رسمی](https://developers.wallex.ir/docs/) با HTTP 200 و TLS معتبر در دسترس بودند. نشانی قدیمی `https://api-docs.wallex.ir/` همچنان خطای hostname certificate mismatch داشت و بدون دورزدن TLS کنار گذاشته شد.

سه Swagger رسمی از لینک خود مستندات خوانده شدند:

- [سرویس‌های پایه](https://developers.wallex.ir/assets/files/basic-services-0ff3bee03ca00b31f254d76fff33a631.json)
- [اسپات](https://developers.wallex.ir/assets/files/spot-b9d06896d1009adc9ebc69b9a5495e7e.json)
- [تعهدی](https://developers.wallex.ir/assets/files/margin-c709e1f52cb44387a2e35ffd678c6dfa.json)

نکتهٔ ناسازگاری مستندات: Swagger پایه و اسپات، host قدیمی `api.wallex.com` را درج کرده‌اند؛ Swagger تعهدی `api.wallex.ir` دارد. درخواست‌های این بررسی فقط روی `api.wallex.ir` انجام شدند و نتیجهٔ هر مسیر جدا ثبت شده است. همچنین Swagger پایه برای بعضی GETهای دادهٔ بازار ApiKeyAuth درج کرده، اما درخواست‌های واقعی این بررسی بدون احراز هویت HTTP 200 گرفتند؛ این موفقیت مشاهده‌شده را نباید مجوز قطعی یا دائمی همهٔ endpointها دانست.

## ۲. endpointهای واقعی و نتیجهٔ GET

| کاربرد | URL دقیق آزمایش‌شده | HTTP | نتیجه |
|---|---|---:|---|
| ساختار قبلی Markets | `https://api.wallex.ir/v1/markets` | 200 | `result.symbols.BTCUSDT`، آمار bid/ask؛ نوع محصول را مشخص نمی‌کند |
| Markets با نوع محصول | `https://api.wallex.ir/hector/web/v1/markets` | 200 | `result.markets[]` شامل `is_spot` و `is_margin` |
| Order Book یک بازار | `https://api.wallex.ir/v1/depth?symbol=BTCUSDT` | 200 | `result.bid` و `result.ask`، هر طرف ۵۰ سطح در نمونهٔ واقعی |
| همهٔ Order Bookها | `https://api.wallex.ir/depth/all` | 503 | در Swagger رسمی وجود دارد؛ اجرای این نوبت HTML خطای سرویس بود |
| بازارهای تعهدی | `https://api.wallex.ir/margin-trade/v1/public/markets` | 200 | BTCUSDT در فهرست ۳۶ بازار پاسخ قرار داشت |
| نسبت لانگ/شورت | `https://api.wallex.ir/margin-trade/v1/public/ratio/BTCUSDT` | 200 | `long_percents=15`، `short_percents=85` |
| قیمت منصفانه | `https://api.wallex.ir/v1/all-fairPrice` | 200 | قیمت مرجع؛ نه Best Bid/Ask قابل‌اجرا و نه عمق سفارش |

منابع رسمی: [Markets عمومی](https://developers.wallex.ir/docs/basic-services-markets)، [Markets اسپات](https://developers.wallex.ir/docs/spot-active-markets)، [عمق بازار](https://developers.wallex.ir/docs/basic-services-depth)، [Markets تعهدی](https://developers.wallex.ir/docs/margin-active-markets). مسیر ratio و depth/all از Swagger رسمی استخراج شدند. هیچ‌یک از مسیرهای بالا حدس زده نشده‌اند و هیچ endpoint به سورس برنامه اضافه نشده است.

پاسخ anonymous بخش تعهدی هدر `x-authenticated: true` داشت، اما درخواست کلاینت هیچ کلید، cookie یا Authorization نفرستاد. این برچسب داخلی سرور اثبات احراز هویت کلاینت نیست. مسیر public طبق مستند رسمی و رفتار مشاهده‌شده گزارش شده است.

## ۳. نوع بازار BTCUSDT و SHORT

بخش‌های منتخب پاسخ واقعی Markets در `2026-11-10T13:24:04Z`:

```json
{
  "symbol": "BTCUSDT",
  "base_asset": "BTC",
  "quote_asset": "USDT",
  "is_spot": true,
  "is_margin": true,
  "is_usdt_based": true,
  "leverage_step": 0.5,
  "max_leverage": 10
}
```

ردیف BTCUSDT در پاسخ واقعی endpoint تعهدی:

```json
{
  "symbol": "BTCUSDT",
  "base": "BTC",
  "quote": "USDT",
  "min_risk_coef": "1",
  "max_risk_coef": "10",
  "risk_coef_step": "0.5",
  "liquidity_margin": "0.15",
  "max_age": 1814400
}
```

نوع قابل‌تأیید: **Spot BTC/USDT** و **Margin/تعهدی BTC/USDT**. [تعریف محصول تعهدی](https://developers.wallex.ir/docs/margin-intro)، [اصطلاحات](https://developers.wallex.ir/docs/margin-types) و [schema بازکردن موقعیت](https://developers.wallex.ir/docs/margin-open-position) پشتیبانی از `long` و `short` را مشخص می‌کنند. در schema رسمی فیلد `market` نمونهٔ BTCUSDT دارد و `side` دو مقدار long/short می‌پذیرد. endpoint نوشتنی این schema فقط خوانده شد و هیچ درخواست ایجاد موقعیت ارسال نشد.

پاسخ واقعی endpoint نسبت موقعیت‌ها نیز به‌شکل زیر بود:

```json
{"result":{"long_percents":15,"short_percents":85},"success":true}
```

پس پشتیبانی SHORT برای **محصول تعهدی BTCUSDT** در سطح محصول تأیید می‌شود. امکان بازکردن موقعیت برای حساب مشخص، وثیقه، سطح کاربری، ظرفیت اعتبار همان لحظه، محدودیت‌های اختصاصی جهت و fill واقعی بررسی نشده‌اند. منابع public این موارد را اثبات نمی‌کنند؛ هیچ GET خصوصی یا dry-run POST هم انجام نشده است.

`is_spot` و `is_margin` فیلدهای مستند تشخیص نوع محصول هستند. از `isMarketTypeEnable` در پاسخ قبلی Markets برای نتیجه‌گیری Spot/Futures یا SHORT استفاده نشد.

## ۴. Order Book و timestamp

نمونهٔ واقعی پاسخ `GET /v1/depth?symbol=BTCUSDT` در 13:24:04 UTC، کوتاه‌شده به بهترین سطح هر طرف:

```json
{
  "result": {
    "ask": [{"price":74328.45,"quantity":0.00594542,"sum":"441.913853199"}],
    "bid": [{"price":74182.38,"quantity":0.00014738,"sum":"10.9329991644"}]
  },
  "success": true
}
```

این نمونه excerpt است؛ پاسخ اصلی ۵۰ سطح در هر طرف و فیلد message داشت. فایل شواهد، تمام سطوح همین پاسخ را نگه می‌دارد. Best Bid با بیشینهٔ `result.bid[].price` و Best Ask با کمینهٔ `result.ask[].price` استخراج شدند. quantity حجم پایه است؛ مطابق مستند، sum حاصل قیمت در مقدار است و به‌عنوان حجم تجمعی فرض نشده است. برای همین snapshot، mid برابر `74255.415` و spread برابر `146.07` USDT بود؛ این‌ها قیمت فعلی یا تضمین اجرای مقدار سفارش نیستند.

در پاسخ کامل، کلیدهای بالادست فقط `result,message,success` و کلیدهای result فقط `ask,bid` بودند. در سطوح، `price,quantity,sum` وجود داشتند. timestamp، updated_at، lastUpdateId یا sequence مشاهده نشد. [schema رسمی عمق بازار](https://developers.wallex.ir/docs/basic-services-depth) نیز زمان snapshot تعریف نمی‌کند.

سه GET محدود تکمیلی با فاصلهٔ حدود دو ثانیه:

| زمان دریافت UTC | Best Bid | Best Ask | عمق خرید/فروش | timestamp دفتر سفارش |
|---|---:|---:|---|---|
|13:26:02|74182.38|74271.34|50 / 50|ندارد|
|13:26:04|74182.38|74271.33|50 / 50|ندارد|
|13:26:06|74182.38|74271.32|50 / 50|ندارد|

همهٔ این پاسخ‌ها HTTP 200، TLS معتبر و `Cache-Control: max-age=1` داشتند. تغییر Ask نشان‌دهندهٔ تغییر داده در این نمونه‌هاست، اما سن دقیق snapshot را اثبات نمی‌کند. HTTP Date، زمان دریافت کلاینت، MISS/HIT و max-age زمان تولید/تغییر دفتر سفارش نیستند؛ برای شرط سخت‌گیرانهٔ quote age نباید به‌جای timestamp منبع قرار بگیرند.

کانال رسمی [عمق خرید](https://developers.wallex.ir/docs/socket-buy-depth) و [عمق فروش](https://developers.wallex.ir/docs/socket-sell-depth) روی `wss://api.wallex.ir/connection/websocket` تعریف شده‌اند:

```text
orderbook:BTCUSDT@buyDepth
orderbook:BTCUSDT@sellDepth
```

طبق مستند، هر پیام snapshot کامل همان سمت است؛ نمونهٔ payload شامل quantity/price/sum است و timestamp منبع برای آن تعریف نشده است. دو کانال جدا هستند؛ هم‌زمانی دو سمت نیز از timestamp مشترک قابل اثبات نیست. سوکت در این بررسی اتصال یا subscribe نشده است؛ محدودیت آزمایش GET عمومی حفظ شد.

در مقابل، کانال [قیمت منصفانه](https://developers.wallex.ir/docs/socket-fair-price) با نام `public:all@fairPrice` در نمونهٔ رسمی فیلد ISO8601 به نام `ts` دارد. این timestamp متعلق به snapshot **قیمت منصفانه** است، نه Order Book. تناوب مستند آن حدود ۲۰ تا ۳۵ ثانیه است. خود GET `all-fairPrice` در پاسخ واقعی فقط map قیمت‌ها و wrapper داشت، نه `ts` دفتر سفارش. این داده نمی‌تواند جای مظنهٔ اجرایی یا timestamp دفتر سفارش را بگیرد.

## ۵. قرارداد، قیمت مرجع و انتخاب بازار بایننس

محصول رسمی پیداشده Margin/تعهدی است: نام بازار BTCUSDT، base=BTC و quote=USDT، اعتبار/وثیقه و حداکثر عمر موقعیت `1814400` ثانیه، معادل ۲۱ روز. این max_age را نباید تاریخ سررسید یک قرارداد فیوچرز فرض کرد. در مدل‌های Markets و Position همین API، قرارداد مستقل با `contractType`، `contractSize`، زمان delivery یا fundingRate مشخص نشده است. **نبودن چنین قرارداد در کل خدمات والکس ادعا نمی‌شود؛ وجود و مشخصات Futures/Perpetual مستقل در منابع بررسی‌شده تأیید نشد.**

`fair_price` در metadata و endpoint `all-fairPrice` به قیمت منصفانه اشاره دارند؛ mark/index یک قرارداد مشتقهٔ مشخص از این نام قابل استنتاج نیست. مرجع رسمی قیمت تصفیه/لیکوییدیشن یک قرارداد Futures متناظر تأیید نشده است.

نتیجهٔ تطبیق:

| بازار والکس | بازار بایننس برای مقایسه | وضعیت |
|---|---|---|
|BTCUSDT Spot، دفتر عمومی همان جفت|BTCUSDT در Binance Spot|تطبیق دارایی پایه، ارز مظنه و نوع بازار معتبر است؛ تازگی دو مظنه همچنان باید اثبات شود|
|BTCUSDT Margin/تعهدی، برای مقایسهٔ قیمت دارایی پایه|Binance Spot BTCUSDT به‌عنوان مرجع قیمت دارایی پایه|استنتاج مشروط: یکسان‌بودن دفتر اجرای موقعیت تعهدی با همین دفتر عمومی باید صریحاً تأیید شود؛ سود/زیان، هزینهٔ اعتبار و لیکوییدیشن جدا هستند|
|Futures/Perpetual والکس|فقط قرارداد متناظر بایننس با نوع، quote/settlement و مشخصات یکسان|فعلاً قابل انتخاب نیست؛ قرارداد مستقل والکس تأیید نشده است|

استفاده از Binance Futures صرفاً به‌دلیل امکان SHORT والکس معتبر نیست؛ SHORT در محصول margin نیز وجود دارد. یکسان بودن متن BTCUSDT نیز تطبیق نوع قرارداد را اثبات نمی‌کند. Basis روی mid دو دفتر اجرایی متناظر محاسبه می‌شود؛ last trade، قیمت منصفانه یا mark را نباید بی‌اعلام با آن جایگزین کرد. برای Margin، مقایسهٔ زیرساخت قیمت دارایی پایه با Spot به معنی حذف هزینهٔ اعتبار یا تأیید RR نهایی محصول تعهدی نیست.

GET تکمیلی `https://data-api.binance.vision/api/v3/exchangeInfo?symbol=BTCUSDT` در این نوبت HTTP 451 گرفت؛ TLS آن معتبر بود، اما metadata زندهٔ بایننس از آن پاسخ قابل استخراج نبود. محدودیت HTTP دور زده نشد. مشخصات Spot بایننس به [مستند رسمی](https://github.com/binance/binance-spot-api-docs/blob/master/rest-api.md) ارجاع داده شده است؛ این آزمون به‌عنوان موفقیت زنده گزارش نمی‌شود.

## ۶. اعتبارسنجی TLS

با `ssl.create_default_context()`، `CERT_REQUIRED` و hostname check، اتصال TLS مستقلی هم آزمایش شد:

| میزبان | نتیجه |
|---|---|
|developers.wallex.ir|موفق، TLSv1.3، chain و hostname معتبر|
|api.wallex.ir|موفق، TLSv1.3، chain و hostname معتبر|
|api-docs.wallex.ir|ناموفق؛ hostname mismatch، بدون ادامهٔ ناامن|

برای دو میزبان موفق: SAN شامل `*.wallex.ir` و `wallex.ir`، صادرکننده `TrustAsia / LiteSSL Pro RSA CA 2025`، بازهٔ اعتبار گواهی از 7 Sep 2026 تا 25 Mar 2027 بود. نتیجه متعلق به شبکه و trust store این محیط است؛ TLS اتصال PHP روی هاست جداگانه آزمایش نشده است.

## ۷. نتیجه برای Gate فعلی، بدون تغییر سورس

مانع اول تا سطح **شناخت محصول تعهدی و پشتیبانی عمومی SHORT در BTCUSDT** برطرف شد؛ امکان اجرای حساب شخصی و مدل دقیق تطبیق اجرای margin همچنان جداگانه نیازمند تأیید است. مانع پیدا کردن endpoint رسمی دفتر سفارش برطرف شد، اما **timestamp معتبر Order Book هنوز تأیید نشده است**.

به‌علاوه، پاسخ واقعی سطوح دفتر سفارش objectهای price/quantity/sum است؛ این با قالب آرایهٔ عددی `[price,quantity]` که adapter فعلی انتظار دارد یکسان نیست. هیچ تبدیل یا تغییر adapter در این بررسی انجام نشد. قفل فعلی Gate برای Spot/LONG نیز تغییر نکرد. این گزارش مجوز فعال‌سازی SHORT یا READY نیست.

هیچ source، تنظیم، SQLite، سفارش، push یا deployment تغییر نکرد. شواهد قابل بازبینی شامل URL، زمان، status، مدت پاسخ، headerهای عمومی، نمونهٔ واقعی، hash پاسخ و نتیجهٔ TLS در [فایل JSON همراه](wallex-market-api-evidence.json) ثبت شده‌اند.
