<?php
declare(strict_types=1);
define('CANDLE_DOWNLOADER_TEST', true);
require dirname(__DIR__) . '/public/candle-downloader.php';
$checks = 0;
function checkDownloader(bool $ok, string $label): void {
    global $checks;
    if (!$ok) { throw new RuntimeException($label); }
    $checks++;
}
function rejectsDownloader(callable $fn, string $label): void {
    try { $fn(); } catch (RuntimeException | InvalidArgumentException $e) { checkDownloader(true, $label); return; }
    throw new RuntimeException('Expected rejection: ' . $label);
}
$now = 1795406400;
foreach ($frames as $frame => $setting) {
    $url = historyUrl('binance', 'BTCUSDT', (string)$frame, $now, $exchanges, $frames);
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    checkDownloader(strpos($url, 'https://fapi.binance.com/fapi/v1/klines?') === 0, 'Futures endpoint');
    checkDownloader((int)$query['endTime'] === $now * 1000 - 1 && $query['interval'] === $setting['short'], 'Time boundaries');
}
$start = intdiv($now, 900) * 900;
$rows = [[$start * 1000 - 900000, '10', '12', '9', '11', '2', $start * 1000 - 1],
    [$start * 1000, '11', '13', '10', '12', '3', ($start + 900) * 1000 - 1]];
$result = normalizeBinanceKlines(json_encode($rows), '15', $start + 100, $frames);
$p = json_decode($result['json'], true);
checkDownloader(count($p['t']) === 1 && $result['info']['discarded_open_candles'] === 1, 'Drop open candle');
checkDownloader($result['info']['market_type'] === 'futures' && (float)$p['v'][0] === 2.0, 'Futures provenance and base volume');
rejectsDownloader(fn() => normalizeBinanceKlines(json_encode([$rows[0], $rows[0]]), '15', $now, $frames), 'Duplicate');
$rows[0][2] = '1';
rejectsDownloader(fn() => normalizeBinanceKlines(json_encode($rows), '15', $now, $frames), 'Invalid high');
rejectsDownloader(fn() => fetchCandleJson('wallex', 'BTCUSDT', '15', $now, $exchanges, $frames), 'No silent margin substitution');
rejectsDownloader(fn() => fetchCandleJson('nobitex', 'BTCUSDT', '15', $now, $exchanges, $frames), 'No silent margin substitution');
$bad = ['s'=>'ok','t'=>[$start-900,$start], 'o'=>[10,10],'h'=>[9,12],'l'=>[8,8],'c'=>[11,11],'v'=>[1,1]];
rejectsDownloader(fn() => parseUdf(json_encode($bad)), 'Invalid UDF price');
checkDownloader(strpos(actionUrl('wallex','BTCUSDT','single','BTCUSDT','15',true),'reference=1') !== false, 'Explicit reference choice retained');
if (in_array('--live', $argv, true)) {
    foreach (['binance', 'wallex', 'nobitex'] as $exchange) {
        foreach (array_keys($frames) as $frame) {
            $r = fetchCandleJson($exchange, 'BTCUSDT', (string)$frame, time(), $exchanges, $frames, true);
            $p = json_decode($r['json'], true, 512, JSON_THROW_ON_ERROR);
            checkDownloader(count($p['t']) > 0 && $p['_meta']['only_closed_candles'], 'Live closed candles');
            checkDownloader($p['_meta']['market_type'] === ($exchange === 'binance' ? 'futures' : 'margin_reference'), 'Live provenance');
            echo $exchange . ' ' . $frame . ': ' . count($p['t']) . " closed candles\n";
        }
    }
    rejectsDownloader(fn() => marginMarket('nobitex', 'NONEXISTENTUSDT'), 'Unsupported margin symbol');
}
echo "Downloader: $checks checks passed.\n";
