<?php
/**
 * Candle JSON Downloader v2.5 — independent read-only utility.
 * PHP 7.4+, cURL or allow_url_fopen. Bulk ZIP works without ZipArchive too.
 * Copy to the public/ directory of your strategy monitor; no bootstrap needed.
 * Does not change strategies, databases, caches, alerts, or orders.
 */
declare(strict_types=1);

$exchanges = [
    'wallex'  => ['title' => 'والکس — تعهدی', 'base' => 'https://api.wallex.ir/v1/udf/history'],
    'nobitex' => ['title' => 'نوبیتکس — تعهدی', 'base' => 'https://apiv2.nobitex.ir/market/udf/history'],
    'binance' => ['title' => 'بایننس فیوچرز (USDⓈ-M)', 'base' => 'https://fapi.binance.com/fapi/v1/klines'],
];
$markets = [
    'BTCUSDT'  => ['title' => 'بیت‌کوین / تتر'],
    'XAUTUSDT' => ['title' => 'تتر گلد / تتر'],
];
$frames = [
    '15'  => ['label' => '۱۵ دقیقه', 'short' => '15m', 'lookback' => 270000,  'seconds' => 900],
    '60'  => ['label' => '۱ ساعت', 'short' => '1h', 'lookback' => 1184400, 'seconds' => 3600],
    '240' => ['label' => '۴ ساعت', 'short' => '4h', 'lookback' => 4737600, 'seconds' => 14400],
];

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
/** Gregorian date to Jalali, no PHP intl extension required. */
function gregorianToJalali(int $gy, int $gm, int $gd): array
{
    $monthOffsets = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = $gm > 2 ? $gy + 1 : $gy;
    $days = 355666 + 365 * $gy + intdiv($gy2 + 3, 4)
        - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400)
        + $gd + $monthOffsets[$gm - 1];
    $jy = -1595 + 33 * intdiv($days, 12053);
    $days %= 12053;
    $jy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + intdiv($days, 31);
        $jd = 1 + $days % 31;
    } else {
        $jm = 7 + intdiv($days - 186, 30);
        $jd = 1 + ($days - 186) % 30;
    }
    return [$jy, $jm, $jd];
}
/** Show the Jalali date/time in Iran. API from/to are still Unix seconds. */
function readableTimestamp(int $seconds): string
{
    $local = (new DateTimeImmutable('@' . $seconds))->setTimezone(new DateTimeZone('Asia/Tehran'));
    [$jy, $jm, $jd] = gregorianToJalali((int)$local->format('Y'), (int)$local->format('n'), (int)$local->format('j'));
    return sprintf('%04d/%02d/%02d %s', $jy, $jm, $jd, $local->format('H:i:s'));
}
/** Accept a listed market or a user-typed USDT market, without changing the API host. */
function normalizeAsset(string $raw): string
{
    $value = strtoupper(trim($raw));
    if ($value === 'ALL') { return 'all'; }
    $value = preg_replace('~[\s/\-]+~u', '', $value);
    if (!is_string($value) || !preg_match('/^[A-Z0-9_]{2,28}$/D', $value)) {
        throw new InvalidArgumentException('نماد ارز را با حروف لاتین وارد کن؛ مانند ETH یا ETHUSDT.');
    }
    if (substr($value, -4) !== 'USDT') { $value .= 'USDT'; }
    if (strlen($value) > 32 || !preg_match('/^[A-Z0-9_]{2,28}USDT$/D', $value)) {
        throw new InvalidArgumentException('نماد بازار تتری معتبر نیست؛ مانند BTCUSDT یا 1M_PEPEUSDT وارد کن.');
    }
    return $value;
}
function param(string $key, string $fallback): string
{
    $v = $_GET[$key] ?? $fallback;
    return is_string($v) ? $v : '';
}
function historyUrl(string $exchange, string $symbol, string $frame, int $to, array $exchanges, array $frames): string
{
    if ($exchange === 'binance') {
        // Binance USD-M Futures returns native trade klines (not mark-price candles).
        // All configured windows fit into its 1000-candle limit.
        $intervals = ['15' => '15m', '60' => '1h', '240' => '4h'];
        $query = [
            'symbol' => $symbol,
            'interval' => $intervals[$frame],
            'startTime' => ($to - $frames[$frame]['lookback']) * 1000,
            'endTime' => $to * 1000 - 1,
            'limit' => 1000,
        ];
    } else {
        $query = [
            'symbol' => $symbol,
            'resolution' => $frame,
            'from' => $to - $frames[$frame]['lookback'],
            'to' => $to,
        ];
        if ($exchange === 'wallex') { $query['currencyCode'] = 'تتر'; }
    }
    return $exchanges[$exchange]['base'] . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}
function actionUrl(string $exchange, string $asset, string $action, string $symbol = '', string $frame = '', bool $reference = false): string
{
    return '?' . http_build_query([
        'exchange' => $exchange,
        'asset' => $asset,
        'action' => $action,
        'symbol' => $symbol,
        'tf' => $frame,
        'reference' => $reference ? '1' : '0',
    ], '', '&', PHP_QUERY_RFC3986);
}
/** Server-side configuration; subscription URLs are not HTTP/SOCKS proxies. */
function downloaderConfig(): array
{
    $path = dirname(__DIR__) . '/config/candle-downloader.local.php';
    $config = is_file($path) ? require $path : [];
    return is_array($config) ? $config : [];
}
function binanceProxy(): string
{
    $proxy = getenv('CANDLE_BINANCE_PROXY');
    if ($proxy === false) {
        $proxy = downloaderConfig()['binance_proxy'] ?? '';
    }
    if (!is_string($proxy)) { throw new RuntimeException('تنظیم پروکسی معتبر نیست.'); }
    if ($proxy !== '' && !preg_match('~^(?:socks5h?|https?)://[^\s/?#]+:\d{1,5}$~D', $proxy)) {
        throw new RuntimeException('پروکسی باید نشانی HTTP یا SOCKS همراه پورت باشد؛ لینک سابسکریپشن قابل استفاده مستقیم نیست.');
    }
    return $proxy;
}
function httpGet(string $url, ?string $proxy = null): string
{
    $managed = $proxy === null && parse_url($url, PHP_URL_HOST) === 'fapi.binance.com'
        && getenv('CANDLE_BINANCE_PROXY') === false && (downloaderConfig()['managed_proxy'] ?? false) === true;
    if ($managed) {
        if (!function_exists('curl_init')) { throw new RuntimeException('افزونه cURL باید روی هاست فعال باشد.'); }
        require_once dirname(__DIR__) . '/src/CandleProxy.php';
        $response = CandleProxy::get($url);
        $body = $response['body']; $status = $response['status'];
    } else {
    $proxy = $proxy ?? (parse_url($url, PHP_URL_HOST) === 'fapi.binance.com' ? binanceProxy() : '');
    if ($proxy !== '' && !function_exists('curl_init')) {
        throw new RuntimeException('برای اتصال پروکسی، افزونه PHP cURL باید فعال باشد.');
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) { throw new RuntimeException('ایجاد اتصال HTTP ناموفق بود.'); }
        curl_setopt_array($ch, [
            CURLOPT_PROXY => $proxy, // Do not send Iranian exchanges through environment proxies.
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 7,
            CURLOPT_TIMEOUT => 22, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPGET => true, CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'StrategyMonitor-ManualCandleDownload/2.0',
            // SSL verification intentionally enabled.
        ]);
        try {
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
        } finally { curl_close($ch); }
    } elseif (ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET', 'timeout' => 22, 'follow_location' => 0,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: StrategyMonitor-ManualCandleDownload/2.0\r\nConnection: close\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $m)) { $status = (int) $m[1]; }
        }
        $err = 'ارتباط شبکه و تنظیمات گواهی HTTPS را بررسی کن.';
    } else {
        throw new RuntimeException('cURL یا allow_url_fopen باید فعال باشد.');
    }
    }
    if ($body === false) { throw new RuntimeException('اتصال به صرافی ناموفق بود (cURL). وضعیت پروکسی و گواهی HTTPS را بررسی کن.'); }
    if ($status !== 200) {
        $detail = json_decode($body, true);
        $message = is_array($detail) ? ($detail['msg'] ?? $detail['errmsg'] ?? '') : '';
        throw new RuntimeException('پاسخ HTTP ' . $status . ' از صرافی.' . (is_string($message) ? ' ' . mbSafeError($message) : ''));
    }
    if (strlen($body) > 16 * 1024 * 1024) { throw new RuntimeException('پاسخ API بیش از اندازه بزرگ است.'); }
    return $body;
}
function mbSafeError(string $message): string { return substr(str_replace(["\r", "\n"], ' ', $message), 0, 500); }
function parseUdf(string $body): array
{
    $p = json_decode($body, true);
    if (!is_array($p) || json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('پاسخ API یک JSON معتبر نیست.');
    }
    if (($p['s'] ?? null) === 'no_data') { throw new RuntimeException('صرافی برای این نماد/بازه no_data برگرداند.'); }
    if (($p['s'] ?? null) !== 'ok') { throw new RuntimeException('وضعیت API برابر ok نیست؛ نماد/بازار را بررسی کن.'); }
    foreach (['t', 'o', 'h', 'l', 'c', 'v'] as $column) {
        if (!isset($p[$column]) || !is_array($p[$column])) {
            throw new RuntimeException('ستون ' . $column . ' در پاسخ وجود ندارد.');
        }
    }
    $count = count($p['t']);
    if ($count < 2 || $count > 150000) { throw new RuntimeException('تعداد کندل‌ها کافی یا معتبر نیست.'); }
    foreach (['o', 'h', 'l', 'c', 'v'] as $column) {
        if (count($p[$column]) !== $count) { throw new RuntimeException('طول آرایه‌های OHLCV یکسان نیست.'); }
    }
    $last = null;
    for ($i = 0; $i < $count; $i++) {
        $t = $p['t'][$i];
        if (!is_numeric($t) || (float)$t != (int)$t || (int)$t < 1000000000 || (int)$t > 9999999999) {
            throw new RuntimeException('زمان کندل باید Unix ثانیه‌ای باشد.');
        }
        $t = (int)$t;
        if ($last !== null && $t <= $last) { throw new RuntimeException('کندل‌های API به ترتیب صعودی و یکتا نیستند.'); }
        $p['t'][$i] = $t;
        $last = $t;
        foreach (['o', 'h', 'l', 'c', 'v'] as $k) {
            if (!is_numeric($p[$k][$i]) || !is_finite((float)$p[$k][$i])) {
                throw new RuntimeException('عدد OHLCV نامعتبر در پاسخ API.');
            }
        }
        $o = (float)$p['o'][$i]; $hi = (float)$p['h'][$i]; $lo = (float)$p['l'][$i]; $c = (float)$p['c'][$i];
        if ($lo < 0 || (float)$p['v'][$i] < 0 || $hi < max($o, $c) || $lo > min($o, $c) || $hi < $lo) {
            throw new RuntimeException('محدوده قیمت یا حجم کندل نامعتبر است.');
        }
    }
    return $p;
}
/**
 * Convert Binance USD-M Futures native Klines into the same UDF OHLCV
 * format used elsewhere in the monitor: s,t,o,h,l,c,v, with Unix seconds.
 * Only CLOSED candles are included; a missing bar is never synthesized.
 */
function normalizeBinanceKlines(string $body, string $frame, int $now, array $frames): array
{
    $data = json_decode($body, true);
    if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('پاسخ بایننس JSON معتبر نیست.');
    }
    if (isset($data['code'])) {
        throw new RuntimeException('خطای بایننس: ' . (string)($data['msg'] ?? $data['code']));
    }
    if (!$data) { throw new RuntimeException('بایننس برای این نماد/بازه کندلی برنگرداند.'); }
    $step = $frames[$frame]['seconds'];
    $p = ['s' => 'ok', 't' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];
    $previous = null;
    $ignoredOpen = 0;
    foreach ($data as $row) {
        if (!is_array($row) || count($row) < 7 || !is_numeric($row[0]) || !is_numeric($row[6])) {
            throw new RuntimeException('ساختار یکی از کندل‌های بایننس نامعتبر است.');
        }
        $openMs = (int)$row[0];
        $closeMs = (int)$row[6];
        if ($openMs % 1000 !== 0 || $openMs < 1000000000000) {
            throw new RuntimeException('زمان کندل بایننس معتبر نیست.');
        }
        $t = intdiv($openMs, 1000);
        if ($t % $step !== 0 || ($previous !== null && $t <= $previous)) {
            throw new RuntimeException('فاصله یا ترتیب زمانی کندل‌های بایننس نامعتبر است.');
        }
        $previous = $t;
        if ($closeMs !== ($openMs + $step * 1000 - 1)) {
            throw new RuntimeException('مدت کندل بایننس با تایم‌فریم انتخابی سازگار نیست.');
        }
        // Exclude the currently forming candle (even when the API returns it).
        if ($t + $step > $now) { $ignoredOpen++; continue; }
        foreach ([1, 2, 3, 4, 5] as $index) {
            if (!is_numeric($row[$index]) || !is_finite((float)$row[$index])) {
                throw new RuntimeException('مقادیر قیمت یا حجم بایننس نامعتبر است.');
            }
        }
        $open = (float)$row[1];
        $high = (float)$row[2];
        $low = (float)$row[3];
        $close = (float)$row[4];
        $volume = (float)$row[5];
        if ($low < 0 || $volume < 0 || $high < max($open, $close) || $low > min($open, $close) || $high < $low) {
            throw new RuntimeException('محدوده قیمت یا حجم کندل بایننس نامعتبر است.');
        }
        $p['t'][] = $t;
        $p['o'][] = $open;
        $p['h'][] = $high;
        $p['l'][] = $low;
        $p['c'][] = $close;
        $p['v'][] = $volume;
    }
    if (!$p['t']) { throw new RuntimeException('هیچ کندل بسته‌شده‌ای در پاسخ بایننس وجود ندارد.'); }
    $json = json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    if ($json === false) { throw new RuntimeException('ساخت JSON بایننس ناموفق بود.'); }
    return ['json' => $json, 'info' => [
        'mode' => 'native', 'source_resolution_minutes' => (int)$frame,
        'target_resolution_minutes' => (int)$frame,
        'closed_candles' => count($p['t']), 'discarded_open_candles' => $ignoredOpen,
        'only_closed_candles' => true,
        'source' => 'Binance USD-M Futures public /fapi/v1/klines',
        'market_type' => 'futures', 'price_type' => 'trade',
    ]];
}
function validNativeIntervals(array $times, int $step, bool $requireUtcAlignment): bool
{
    $phase = $times[0] % $step;
    if ($requireUtcAlignment && $phase !== 0) { return false; }
    $last = null;
    foreach ($times as $t) {
        if (($t % $step) !== $phase) { return false; }
        if ($last !== null && ($t - $last) % $step !== 0) { return false; }
        $last = $t;
    }
    return true;
}
function decimalSum(string $a, string $b): string
{
    if (function_exists('bcadd')) { return rtrim(rtrim(bcadd($a, $b, 16), '0'), '.') ?: '0'; }
    return rtrim(rtrim(sprintf('%.12F', (float)$a + (float)$b), '0'), '.') ?: '0';
}
function larger($a, $b): bool
{
    return function_exists('bccomp') ? bccomp((string)$a, (string)$b, 16) > 0 : (float)$a > (float)$b;
}
function lower($a, $b): bool
{
    return function_exists('bccomp') ? bccomp((string)$a, (string)$b, 16) < 0 : (float)$a < (float)$b;
}
/**
 * A smaller Wallex source bucket may be returned despite resolution=15/240.
 * Produce ONLY full, closed target buckets with every source interval observed.
 * Missing 1m/1h rows are NOT synthesized (no fake bars/no fake zero volumes).
 */
function aggregateFullBuckets(array $p, int $targetStep, int $sourceStep, int $asOf): array
{
    if ($targetStep % $sourceStep !== 0) { throw new RuntimeException('تجمیع این تایم‌فریم پشتیبانی نمی‌شود.'); }
    $needed = intdiv($targetStep, $sourceStep);
    $groups = [];
    $rows = count($p['t']);
    for ($i = 0; $i < $rows; $i++) {
        $t = $p['t'][$i];
        $start = intdiv($t, $targetStep) * $targetStep;  // UTC target frame
        if ($start + $targetStep > $asOf) { continue; } // drop still-open target candle
        if (!isset($groups[$start])) { $groups[$start] = []; }
        $groups[$start][] = $i;
    }
    $result = ['s' => 'ok', 't' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];
    $skipped = 0;
    foreach ($groups as $start => $indexes) {
        // Strict coverage: source candle for EACH position, no unknown gaps.
        if (count($indexes) !== $needed || $p['t'][$indexes[0]] !== $start ||
            $p['t'][$indexes[$needed - 1]] !== $start + $targetStep - $sourceStep) {
            $skipped++;
            continue;
        }
        $valid = true;
        for ($n = 0; $n < $needed; $n++) {
            if ($p['t'][$indexes[$n]] !== $start + $n * $sourceStep) { $valid = false; break; }
        }
        if (!$valid) { $skipped++; continue; }
        $first = $indexes[0];
        $last = $indexes[$needed - 1];
        $high = (string)$p['h'][$first];
        $low = (string)$p['l'][$first];
        $volume = '0';
        foreach ($indexes as $i) {
            if (larger($p['h'][$i], $high)) { $high = (string)$p['h'][$i]; }
            if (lower($p['l'][$i], $low)) { $low = (string)$p['l'][$i]; }
            $volume = decimalSum($volume, (string)$p['v'][$i]);
        }
        $result['t'][] = (int)$start;
        $result['o'][] = $p['o'][$first];
        $result['h'][] = $high;
        $result['l'][] = $low;
        $result['c'][] = $p['c'][$last];
        $result['v'][] = $volume;
    }
    if (count($result['t']) < 1) {
        throw new RuntimeException('داده پایه برای ساخت حتی یک کندل کامل کافی نیست؛ کندل ناقص تولید نشد.');
    }
    return [$result, $skipped];
}
/** @return array{json:string,info:array} */
function normalizeUdf(string $body, string $exchange, string $frame, int $now, array $frames): array
{
    $p = parseUdf($body);
    $step = $frames[$frame]['seconds'];
    $direct = validNativeIntervals($p['t'], $step, $exchange === 'wallex');
    $skipped = 0;
    $source = $frame;
    if (!$direct) {
        // We deliberately do not reinterpret Nobitex arbitrary phase data as Wallex UTC bars.
        if ($exchange !== 'wallex') {
            throw new RuntimeException('فاصله زمانی داده‌های نوبیتکس با تایم‌فریم انتخابی سازگار نیست.');
        }
        $sourceStep = $frame === '15' ? 60 : ($frame === '240' ? 3600 : 0);
        if ($sourceStep === 0 || !validNativeIntervals($p['t'], $sourceStep, true)) {
            throw new RuntimeException('تایم‌فریم واقعی API قابل تشخیص نیست؛ هیچ برچسب زمانی اشتباهی ذخیره نشد.');
        }
        $source = (string) intdiv($sourceStep, 60);
        [$p, $skipped] = aggregateFullBuckets($p, $step, $sourceStep, $now);
        $mode = 'aggregated';
    } else {
        $mode = 'native';
        // Only closed candles. Avoid sending a still-open OHLCV to a trading analysis.
        $kept = [];
        foreach ($p['t'] as $i => $t) { if ($t + $step <= $now) { $kept[] = $i; } }
        foreach (['t', 'o', 'h', 'l', 'c', 'v'] as $key) {
            $p[$key] = array_values(array_map(static function ($i) use ($p, $key) { return $p[$key][$i]; }, $kept));
        }
        if (!$p['t']) { throw new RuntimeException('هیچ کندل بسته‌شده‌ای در پاسخ وجود ندارد.'); }
    }
    if (!validNativeIntervals($p['t'], $step, $exchange === 'wallex')) {
        throw new RuntimeException('پس از پردازش، اعتبارسنجی تایم‌فریم موفق نبود.');
    }
    $json = json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    if ($json === false) { throw new RuntimeException('تبدیل خروجی به JSON ناموفق بود.'); }
    return ['json' => $json, 'info' => [
        'mode' => $mode, 'source_resolution_minutes' => (int)$source,
        'target_resolution_minutes' => (int)$frame,
        'closed_candles' => count($p['t']), 'discarded_incomplete_buckets' => $skipped,
        'only_closed_candles' => true,
    ]];
}
function marginMarket(string $exchange, string $symbol): array
{
    static $lists = [];
    $urls = ['wallex' => 'https://api.wallex.ir/margin-trade/v1/public/markets',
        'nobitex' => 'https://apiv2.nobitex.ir/margin/markets/list'];
    if (!isset($lists[$exchange])) {
        $data = json_decode(httpGet($urls[$exchange]), true);
        if (!is_array($data) || ($exchange === 'wallex' ? ($data['success'] ?? false) !== true : ($data['status'] ?? '') !== 'ok')) {
            throw new RuntimeException('دریافت فهرست بازارهای تعهدی ناموفق بود.');
        }
        $rows = $exchange === 'wallex' ? ($data['result'] ?? null) : ($data['markets'] ?? null);
        if (!is_array($rows)) { throw new RuntimeException('ساختار فهرست تعهدی نامعتبر است.'); }
        $lists[$exchange] = [];
        foreach ($rows as $key => $row) {
            if (is_array($row)) { $lists[$exchange][$exchange === 'wallex' ? ($row['symbol'] ?? '') : $key] = $row; }
        }
    }
    $market = $lists[$exchange][$symbol] ?? null;
    if (!is_array($market) || ($exchange === 'nobitex' && ($market['buyEnabled'] ?? false) !== true && ($market['sellEnabled'] ?? false) !== true)) {
        throw new RuntimeException('نماد ' . $symbol . ' در فهرست بازارهای تعهدی فعال این صرافی نیست.');
    }
    return $market;
}
function fetchCandleJson(string $exchange, string $symbol, string $frame, int $now, array $exchanges, array $frames, bool $reference = false): array
{
    if (!isset($exchanges[$exchange], $frames[$frame]) || normalizeAsset($symbol) !== $symbol) {
        throw new InvalidArgumentException('صرافی، نماد یا تایم‌فریم نامعتبر است.');
    }
    $market = null;
    if ($exchange !== 'binance') {
        if (!$reference) {
            throw new RuntimeException('API رسمی بررسی‌شده کندل مستقل تعهدی ارائه نمی‌کند. برای دریافت داده عمومی بازار پس از بررسی فعال بودن تعهدی، گزینه «کندل مرجع عمومی» را انتخاب کن. این داده، کندل اختصاصی تعهدی نیست.');
        }
        $market = marginMarket($exchange, $symbol);
    }
    $url = historyUrl($exchange, $symbol, $frame, $now, $exchanges, $frames);
    $body = httpGet($url);
    if ($exchange === 'binance') {
        $result = normalizeBinanceKlines($body, $frame, $now, $frames);
    } else {
        $result = normalizeUdf($body, $exchange, $frame, $now, $frames);
    }
    $result['info'] += ['market_type' => 'margin_reference', 'dedicated_margin_candles' => false,
        'margin_market' => $market, 'source' => $exchanges[$exchange]['base']];
    if ($exchange === 'binance') { unset($result['info']['dedicated_margin_candles'], $result['info']['margin_market']); }
    // Preserve the UDF fields consumed by the monitor; add explicit provenance to single downloads too.
    $payload = json_decode($result['json'], true, 512, JSON_THROW_ON_ERROR);
    $payload['_meta'] = ['exchange' => $exchange, 'symbol' => $symbol, 'as_of_utc' => gmdate('c', $now)] + $result['info'];
    $result['json'] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    return $result;
}
function downloadName(string $exchange, string $symbol, string $frame, int $now, array $frames): string
{
    $date = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('Asia/Tehran'));
    return $exchange . '_' . ($exchange === 'binance' ? 'futures' : 'margin-reference') . '_' . $symbol . '_' . $frames[$frame]['short'] . '_' . $date->format('Ymd_His') . '.json';
}
function sendAttachment(string $data, string $filename, string $mime): void
{
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($data));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    echo $data;
    exit;
}
/** Lightweight ZIP (STORE) fallback for hosting plans without php_zip. */
final class SimpleZipWriter
{
    private $handle;
    private $headers = [];
    private $offset = 0;

    public function __construct(string $path)
    {
        $this->handle = fopen($path, 'wb');
        if (!$this->handle) { throw new RuntimeException('ایجاد فایل ZIP ممکن نیست.'); }
    }

    private function write(string $data): void
    {
        $length = strlen($data);
        if (fwrite($this->handle, $data) !== $length) {
            throw new RuntimeException('خطا هنگام نوشتن ZIP.');
        }
        $this->offset += $length;
    }

    public function addFromString(string $filename, string $content): bool
    {
        if (strlen($filename) > 65535 || strlen($content) > 0x7FFFFFFF) { return false; }
        $size = strlen($content);
        $crc = crc32($content);
        // DOS time/date: valid values for current UTC date; no dependency on zip extension.
        $stamp = getdate();
        $dosTime = ($stamp['hours'] << 11) | ($stamp['minutes'] << 5) | (intdiv($stamp['seconds'], 2));
        $dosDate = ((max(1980, $stamp['year']) - 1980) << 9) | ($stamp['mon'] << 5) | $stamp['mday'];
        $start = $this->offset;
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $dosTime, $dosDate,
            $crc, $size, $size, strlen($filename), 0);
        $this->write($local . $filename . $content);
        $this->headers[] = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0,
            $dosTime, $dosDate, $crc, $size, $size, strlen($filename), 0, 0, 0, 0, 0, $start) . $filename;
        return true;
    }

    public function close(): void
    {
        if (!is_resource($this->handle)) { return; }
        $centralOffset = $this->offset;
        foreach ($this->headers as $header) { $this->write($header); }
        $centralSize = $this->offset - $centralOffset;
        $count = count($this->headers);
        $this->write(pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, $centralSize, $centralOffset, 0));
        fclose($this->handle);
        $this->handle = null;
    }
}

function bulkZip(string $exchange, array $selected, int $now, array $exchanges, array $frames, bool $reference = false): void
{
    if (function_exists('set_time_limit')) { @set_time_limit(180); }
    $temp = tempnam(sys_get_temp_dir(), 'candles_');
    if ($temp === false) { throw new RuntimeException('ایجاد فایل موقت ZIP ممکن نیست.'); }
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($temp); throw new RuntimeException('ساخت ZIP ممکن نیست.');
        }
    } else {
        $zip = new SimpleZipWriter($temp);
    }
    $report = [
        'exchange' => $exchange, 'created_at_utc' => gmdate('c', $now),
        'source' => 'Binance USD-M Futures; Iranian exchanges: explicit opt-in public reference only, checked against margin markets',
        'note' => 'Only validated, closed candles. Incomplete derived buckets are omitted, never filled.',
        'files' => [],
    ];
    $success = 0;
    try {
        foreach ($selected as $symbol => $_market) {
            foreach ($frames as $frame => $_tf) {
                $filename = downloadName($exchange, $symbol, (string)$frame, $now, $frames);
                try {
                    $result = fetchCandleJson($exchange, $symbol, (string)$frame, $now, $exchanges, $frames, $reference);
                    if (!$zip->addFromString($filename, $result['json'])) {
                        throw new RuntimeException('افزودن فایل به ZIP ناموفق بود.');
                    }
                    $report['files'][] = ['name' => $filename, 'status' => 'ok'] + $result['info'];
                    $success++;
                } catch (Throwable $e) {
                    $report['files'][] = ['name' => $filename, 'status' => 'error', 'error' => $e->getMessage()];
                }
            }
        }
        $zip->addFromString('download-report.json', json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    } finally { $zip->close(); }
    if ($success === 0) {
        @unlink($temp);
        $messages = [];
        foreach ($report['files'] as $entry) { $messages[] = $entry['name'] . ': ' . ($entry['error'] ?? 'خطا'); }
        throw new RuntimeException('هیچ کندل معتبری دریافت نشد. ' . implode(' | ', $messages));
    }
    $date = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('Asia/Tehran'));
    $name = $exchange . '_candles_' . $date->format('Ymd_His') . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($temp));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    readfile($temp);
    @unlink($temp);
    exit;
}

// CLI tests can include the file without rendering or making requests.
if (!defined('CANDLE_DOWNLOADER_TEST')) {
    $exchange = param('exchange', 'wallex');
    $rawAsset = param('asset', 'all');
    $action = param('action', '');
    $reference = param('reference', '0') === '1';
    $error = '';
    if (!isset($exchanges[$exchange])) { $exchange = 'wallex'; $error = 'صرافی انتخاب‌شده نامعتبر است.'; }
    try {
        $asset = normalizeAsset($rawAsset);
    } catch (InvalidArgumentException $e) {
        $asset = 'all';
        $error = $e->getMessage();
    }
    $selectedMarkets = $asset === 'all'
        ? $markets
        : [$asset => ($markets[$asset] ?? ['title' => 'بازار ' . $asset])];
    $now = time();
    if ($action !== '') {
        try {
            if ($error !== '') { throw new RuntimeException($error); }
            if ($action === 'single') {
                $symbol = param('symbol', '');
                $frame = param('tf', '');
                if (!isset($selectedMarkets[$symbol], $frames[$frame])) {
                    throw new RuntimeException('درخواست دانلود نامعتبر است.');
                }
                $result = fetchCandleJson($exchange, $symbol, $frame, $now, $exchanges, $frames, $reference);
                sendAttachment($result['json'], downloadName($exchange, $symbol, $frame, $now, $frames), 'application/json; charset=UTF-8');
            } elseif ($action === 'bulk') {
                bulkZip($exchange, $selectedMarkets, $now, $exchanges, $frames, $reference);
            } else { throw new RuntimeException('درخواست نامعتبر است.'); }
        } catch (Throwable $e) {
            http_response_code(502);
            $error = $e->getMessage();
        }
    }
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>دانلود تاریخچه کندل‌ها | مانیتور استراتژی</title>
<style>
:root { color-scheme:light; --bg:#f4f6f9; --card:#fff; --ink:#17243a; --muted:#64748b; --line:#e1e7ef; --primary:#175ecb; --primary-hover:#094baf; }
* { box-sizing:border-box; }
body { margin:0; background:var(--bg); color:var(--ink); font:15px/1.8 Tahoma,Arial,sans-serif; }
.container { max-width:960px; margin:38px auto; padding:0 18px 36px; }
header { margin-bottom:24px; }
h1 { margin:0 0 8px; font-size:25px; } h2 { margin:0; font-size:19px; } p { margin:8px 0; }
.muted { color:var(--muted); }
.panel { background:var(--card); border:1px solid var(--line); border-radius:16px; padding:22px; margin-bottom:18px; box-shadow:0 4px 20px rgba(17,32,61,.04); }
.controls { display:flex; flex-wrap:wrap; gap:14px; align-items:end; }
.control { flex:1; min-width:200px; }
label { display:block; font-weight:bold; margin-bottom:6px; }
select,input[type="text"] { width:100%; border:1px solid #ccd7e5; padding:11px 12px; border-radius:9px; background:white; font:inherit; color:var(--ink); }
.field-hint { display:block; font-size:12px; font-weight:normal; color:var(--muted); margin-top:4px; }
button,.button { background:var(--primary); color:#fff; border:0; border-radius:9px; padding:10px 17px; cursor:pointer; font:inherit; font-weight:bold; text-decoration:none; text-align:center; display:inline-block; white-space:nowrap; }
button:hover,.button:hover { background:var(--primary-hover); }
button.bulk { background:#0f766e; } button.bulk:hover { background:#115e59; }
.button.alt { color:var(--primary); background:#edf4ff; } .button.alt:hover { background:#deebff; }
.button.bulk { background:#0f766e; } .button.bulk:hover { background:#115e59; }
.coin-header { display:flex; flex-wrap:wrap; gap:12px; align-items:center; justify-content:space-between; margin-bottom:10px; }
.tag { font:13px/1.5 Consolas,monospace; background:#eff5ff; color:#1351a1; border-radius:999px; padding:6px 10px; direction:ltr; }
.item { display:flex; flex-wrap:wrap; gap:12px; justify-content:space-between; align-items:center; padding:15px 0; border-top:1px solid var(--line); }
.details { flex:1; min-width:180px; } .details strong { display:block; font-size:16px; }
.details small { color:var(--muted); direction:ltr; display:block; text-align:right; }
.details .human-time { direction:rtl; text-align:right; }
.date-number { display:inline-block; direction:ltr; unicode-bidi:isolate; white-space:nowrap; }
.actions { display:flex; flex-wrap:wrap; gap:9px; }
.error { background:#fff1f2; border:1px solid #fecdd3; border-radius:12px; color:#9f1239; padding:14px 16px; margin:18px 0; }
.note { font-size:13px; color:var(--muted); }
.toolbar { display:flex; gap:12px; align-items:center; justify-content:space-between; flex-wrap:wrap; margin-top:15px; }
@media(max-width:580px) { .container{margin-top:20px}.panel{padding:16px}h1{font-size:21px}.actions{width:100%}.actions .button{flex:1} }
</style>
</head>
<body><main class="container">
<header><h1>دانلود تاریخچه کندل‌ها (JSON)</h1><p class="muted">ابزار مستقل و فقط‌خواندنی برای دریافت کندل‌های موردنیاز تحلیل استراتژی</p></header>
<section class="panel">
<form method="get" action="">
<div class="controls">
<div class="control"><label for="exchange">صرافی</label><select name="exchange" id="exchange">
<?php foreach ($exchanges as $key => $detail): ?>
<option value="<?= h($key) ?>" <?= $exchange === $key ? 'selected' : '' ?>><?= h($detail['title']) ?></option>
<?php endforeach; ?></select></div>
<div class="control"><label for="asset">ارز (انتخاب یا تایپ نماد جدید)</label>
<input type="text" id="asset" name="asset" list="market-options" value="<?= h($asset) ?>" placeholder="مثلاً ETHUSDT یا PEPE" autocomplete="off" spellcheck="false" dir="ltr" required>
<datalist id="market-options">
<option value="all" label="بیت‌کوین + تتر گلد (هر دو)"></option>
<?php foreach ($markets as $key => $detail): ?>
<option value="<?= h($key) ?>" label="<?= h($detail['title']) ?>"></option>
<?php endforeach; ?>
</datalist>
<span class="field-hint">برای هر دو ارز «all»؛ برای ارز جدید مثلاً ETH یا ETHUSDT را بنویس. بایننس: کندل معاملات فیوچرز USDⓈ-M با جفت تتر.</span></div>
<div class="control"><label><input type="checkbox" name="reference" value="1" <?= $reference ? 'checked' : '' ?>> کندل مرجع عمومی برای بازارهای تعهدی</label>
<span class="field-hint">فقط والکس و نوبیتکس: ابتدا فعال بودن تعهدی نماد بررسی می‌شود، سپس کندل عمومی بازار دانلود می‌شود. این گزینه کندل اختصاصی تعهدی ارائه نمی‌کند.</span></div>
<button type="submit">ساخت لینک‌ها</button>
<button class="bulk" type="submit" name="action" value="bulk">دانلود همه (ZIP)</button>
</div></form>
<div class="toolbar">
<span class="note">زمان ساخت لینک: <span class="date-number"><?= h(readableTimestamp($now)) ?></span> (ایران) — زمان و بازه هنگام دانلود دوباره محاسبه می‌شود.</span>
</div>
</section>
<?php if ($exchange !== 'binance'): ?>
<div class="panel note">در APIهای رسمی بررسی‌شدهٔ این صرافی، مسیر مستقل کندل تعهدی پیدا نشده است. دانلود اختصاصی تعهدی در دسترس نیست؛ دریافت کندل عمومی فقط با انتخاب صریح گزینهٔ بالا انجام می‌شود. نماد فاقد تعهدی فعال رد می‌شود و منبع داده در JSON ثبت می‌شود.</div>
<?php else: ?>
<div class="panel note">منبع: Binance USDⓈ-M Futures، قیمت معاملات. پروکسی روی سرور PHP تنظیم می‌شود و باید روشن باشد. «لینک API» مستقیماً در مرورگر باز می‌شود و از تنظیم پروکسی PHP استفاده نمی‌کند.</div>
<?php endif; ?>
<?php if ($error !== ''): ?><div role="alert" class="error"><?= h($error) ?></div><?php endif; ?>
<?php foreach ($selectedMarkets as $symbol => $market): ?>
<section class="panel"><div class="coin-header"><h2><?= h($market['title']) ?></h2><span class="tag"><?= h($symbol) ?></span></div>
<?php foreach ($frames as $frame => $setting): ?>
<?php $url = historyUrl($exchange, $symbol, (string)$frame, $now, $exchanges, $frames);
      $startTime = $now - $setting['lookback'];
      $startDate = readableTimestamp($startTime);
      $endDate = readableTimestamp($now); ?>
<div class="item"><div class="details"><strong><?= h($setting['label']) ?> (<?= h($setting['short']) ?>)</strong>
<small class="human-time">شروع: <span class="date-number"><?= h($startDate) ?></span></small>
<small class="human-time">پایان: <span class="date-number"><?= h($endDate) ?></span></small></div>
<div class="actions"><a class="button alt" href="<?= h($url) ?>" target="_blank" rel="noopener noreferrer">لینک API</a>
<a class="button" href="<?= h(actionUrl($exchange, $asset, 'single', $symbol, (string)$frame, $reference)) ?>">دانلود JSON</a></div></div>
<?php endforeach; ?></section>
<?php endforeach; ?>
<p class="note">بازه‌ها: ۱۵ دقیقه = ۷۵ ساعت، ۱ ساعت = ۱۳ روز و ۱۷ ساعت، ۴ ساعت = ۵۴ روز و ۲۰ ساعت. فقط کندل‌های بسته‌شده دانلود می‌شوند؛ کندل ناقص یا مفقود ساخته نمی‌شود. بایننس از کندل اصلی فیوچرز استفاده می‌کند. منبع و نوع داده در _meta هر JSON و خطاهای دانلود گروهی در download-report.json ثبت می‌شوند.</p>
</main></body></html>
<?php } ?>
