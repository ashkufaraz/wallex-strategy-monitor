<?php
declare(strict_types=1);

/** Private, request-scoped Xray client. No public listener or arbitrary URL forwarding. */
final class CandleProxy
{
    private static $process = null;
    private static ?string $temporary = null;
    private static ?string $proxy = null;
    private static ?int $selected = null;
    private static bool $registered = false;

    public static function stop(): void
    {
        if (is_resource(self::$process)) { proc_terminate(self::$process); proc_close(self::$process); }
        self::$process = null; self::$proxy = null;
        if (self::$temporary !== null) { @unlink(self::$temporary); self::$temporary = null; }
    }

    private static function start(array $outbound, string $directory): string
    {
        self::stop();
        if (!function_exists('proc_open') || !is_executable($directory . '/xray')) {
            throw new RuntimeException('هسته Xray روی هاست نصب نشده یا اجازه اجرا ندارد.');
        }
        // Allocate on loopback only; each HTTP request has its own port and child process.
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) { throw new RuntimeException('ایجاد پورت محلی پروکسی ممکن نیست.'); }
        $port = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $file = tempnam($directory, 'request-');
        if ($file === false) { throw new RuntimeException('ایجاد تنظیم موقت پروکسی ممکن نیست.'); }
        chmod($file, 0600);
        // Xray's external loader requires the .json suffix even with -format json.
        if (!rename($file, $file . '.json')) { @unlink($file); throw new RuntimeException('ایجاد تنظیم JSON پروکسی ممکن نیست.'); }
        $file .= '.json';
        self::$temporary = $file;
        $config = ['log' => ['loglevel' => 'none'],
            'inbounds' => [['listen' => '127.0.0.1', 'port' => $port, 'protocol' => 'socks', 'settings' => ['auth' => 'noauth', 'udp' => false]]],
            'outbounds' => [$outbound]];
        file_put_contents($file, json_encode($config, JSON_THROW_ON_ERROR));
        $pipes = [];
        self::$process = proc_open([$directory . '/xray', 'run', '-format', 'json', '-c', $file],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, $directory);
        if (!is_resource(self::$process)) { self::stop(); throw new RuntimeException('اجرای Xray روی هاست ناموفق بود.'); }
        $deadline = microtime(true) + 2;
        do {
            $ready = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.1);
            if ($ready !== false) { fclose($ready); return self::$proxy = 'socks5h://127.0.0.1:' . $port; }
            if (!proc_get_status(self::$process)['running']) { break; }
            usleep(25000);
        } while (microtime(true) < $deadline);
        self::stop();
        throw new RuntimeException('هسته Xray آماده اتصال نشد.');
    }

    /** @return array{body:string|false,status:int,errno:int} */
    public static function get(string $url, ?string $testDirectory = null, float $seconds = 45): array
    {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== 'fapi.binance.com'
            || !in_array(parse_url($url, PHP_URL_PATH), ['/fapi/v1/klines','/fapi/v1/time','/fapi/v2/ticker/price','/fapi/v1/ticker/bookTicker','/fapi/v1/depth'], true)) {
            throw new InvalidArgumentException('پروکسی فقط برای دادهٔ عمومی فیوچرز بایننس مجاز است.');
        }
        $directory = dirname(__DIR__) . '/config/candle-proxy';
        if ($testDirectory !== null) {
            if (!preg_match('~^' . preg_quote($directory, '~') . '/staging-[a-f0-9]{32}$~D', $testDirectory)) {
                throw new InvalidArgumentException('Invalid private staging directory');
            }
            $directory = $testDirectory;
        }
        $nodes = json_decode((string)@file_get_contents($directory . '/nodes.json'), true);
        if (!is_array($nodes) || !$nodes || count($nodes) > 200) {
            throw new RuntimeException('کانفیگ فعال پروکسی روی هاست موجود نیست.');
        }
        if (!self::$registered) { register_shutdown_function([self::class, 'stop']); self::$registered = true; }
        $statePath = dirname(__DIR__) . '/data/' . ($testDirectory === null ? 'candle-proxy-state' : basename($directory)) . '.json';
        $state = json_decode((string)@file_get_contents($statePath), true);
        // A deployment changes the list; stale indexes are harmless and are bounded below.
        $first = self::$selected ?? (max(0, (int)($state['index'] ?? 0)) % count($nodes));
        $last = ['body' => false, 'status' => 0, 'errno' => 0];
        $deadline = microtime(true) + max(1, $seconds);
        for ($attempt = 0; $attempt < min(6, count($nodes)); $attempt++) {
            if (microtime(true) >= $deadline) { break; }
            $index = ($first + $attempt) % count($nodes);
            try {
                $proxy = self::$proxy !== null && self::$selected === $index ? self::$proxy : self::start($nodes[$index], $directory);
                self::$selected = $index;
                $ch = curl_init($url);
                $headers = [];
                $remaining = max(1, (int)ceil($deadline - microtime(true)));
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => $proxy,
                    CURLOPT_CONNECTTIMEOUT => min(5, $remaining), CURLOPT_TIMEOUT => min(7, $remaining), CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_HEADERFUNCTION => static function($ch,$line)use(&$headers){if(str_contains($line,':')){[$k,$v]=explode(':',$line,2);$headers[strtolower(trim($k))]=trim($v);}return strlen($line);},
                    CURLOPT_ENCODING => '', CURLOPT_HTTPHEADER => ['Accept: application/json'],
                    CURLOPT_USERAGENT => 'CandleDownloader-Futures/2.5']);
                try { $last = ['body' => curl_exec($ch), 'status' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'errno' => curl_errno($ch), 'headers' => $headers]; }
                finally { curl_close($ch); }
                if ($last['body'] !== false && in_array($last['status'], [200, 400, 418, 429], true)) {
                    @file_put_contents($statePath, json_encode(['index' => $index, 'checked_at' => time()]), LOCK_EX);
                    @chmod($statePath, 0600);
                    return $last;
                }
            } catch (RuntimeException $e) { /* Try another subscription node without exposing its credentials. */ }
            self::stop();
        }
        self::$selected = null;
        @file_put_contents($statePath, json_encode(['index' => ($first + max(1,$attempt)) % count($nodes), 'checked_at' => time()]), LOCK_EX);
        @chmod($statePath, 0600);
        throw new RuntimeException('اتصال پروکسی مستقل هاست به بایننس برقرار نشد؛ چند کانفیگ سابسکریپشن آزمایش شد. دوباره تلاش کن.');
    }
}
