# Test results ? 2026-11-09

Environment: Windows, XAMPP PHP 8.2.12.

Hosting deployment preparation: all 121 PHP checks passed (including 7 authentication checks), all 3 Node suites passed, and 3 Python deployment tests passed. Package tests verify protected directories, hashed panel credentials, actual PHP authentication, allowed hosts, exclusion of runtime data, invalid settings and unsafe package rejection. FTPS TLS certificate validation for the configured server succeeded; actual login returned FTP 530, so no site files were uploaded. InfinityFree's documented free-plan restrictions prevent the existing permanent CLI worker from running there. GitHub deployment remains disabled until credentials, site hostname and execution mode are resolved.

Dashboard clarity update: dedicated observed-price hero with timestamp/staleness, entry/stop/target cards, green/red/unknown condition columns with actual operands and group context, and collapsed technical history. Node rendering tests cover mixed condition results, missing data, escaped names, metric cards and collapsed history. All 114 PHP checks and all three Node suites passed. The new stylesheet returns HTTP 200. An actual browser screenshot could not be obtained because the computer-use runtime failed to initialize.

Diagnostics update: all 114 PHP checks passed, including 15 new checks for condition trees/operand values, unknown data, actual traces versus waiting previews, persisted timestamps and trade snapshots, 30-record retention, error reports, concurrent edits, skipped inactive strategies and 4h preview boundaries. `node tests/details.js` passed dialog rendering, trade calculation and escaping checks; notification and trade suites also passed. All 16 PHP files passed syntax checks. Live browser interaction was not verified for this update.

4h update: all 99 PHP checks passed, including 12 new checks for validation, OHLCV aggregation, missing-minute handling, offset lookback/alignment, simultaneous 4h/1h/15m ordering, cancellation and legacy compatibility. Only referenced timeframes are fetched/processed. Node notification and trade tests passed. All 14 PHP files passed syntax validation. Live exchange connectivity and OS notification delivery were not tested for this update.

Run all automated checks with `php tests/all.php`.

- Engine: 12 checks passed.
- Integration: 16 checks passed using isolated in-memory SQLite storage, including trade details on a confirmed strategy alert.
- Wallex response adapter: 7 checks passed using deterministic fixtures.
- Trade schema and alert snapshot: 22 checks passed, covering LONG/SHORT, MARKET/LIMIT, legacy compatibility, invalid inputs and immutable history.
- All 13 PHP files passed syntax checks.
- Browser JavaScript passed Node syntax parsing.
- `node tests/trade.js`: trade display, notification details, legacy alerts and HTML escaping passed.
- `node tests/notifications.js`: notification behavior checks passed.
- Alert history: 14 isolated SQLite checks passed for individual deletion, clearing more than 100 records, retaining newer alerts, input validation, unchanged strategy state/settings and increasing IDs after deletion.
- Local HTTP deletion endpoints: missing CSRF token returns 403; invalid alert ID with a valid token returns 400. Real alert history was not deleted during verification.

Regression fixes verified:
- A current invalidation price prevents a new setup from arming or signalling in the same cycle.
- Fractional/non-finite minute timestamps are rejected instead of truncated.
- Malformed OHLC arrays are rejected with a controlled data error.
- Tests no longer leave locked SQLite temporary files on Windows.

Integration checks include duplicate suppression, expiration, price invalidation, network recovery, downtime skipping, incomplete candles, no partial alerts, and disabled strategies.

Live exchange connectivity, browser interaction, and Windows notification delivery were not tested in this run. Adapter fixtures verify parsing and validation, not the current exchange API contract.

Cancellation update: all 87 PHP checks passed, including 16 cancellation checks covering validation, persistent stopping across restart, reason snapshots, duplicate suppression, recovery, independent strategies, and cancellation precedence over entry/invalidation. Node notification and trade presentation tests passed. The active worker must be restarted to load updated PHP definitions.


## InfinityFree transfer ? 2026-11-09

- Verified FTPS authentication and `/htdocs` document root. The supplied domain directory `/wallex.free.nf/htdocs` does not exist in the FTP account.
- Existing unrelated application files were preserved; uploaded 23 protected project files to `/htdocs/wallex`.
- Intended panel URL: `https://wallex.free.nf/wallex/public/`. HTTPS verification currently fails; browser login and server PHP/database capabilities remain unverified.
- PHP, Node interface suites and three deployment tests passed locally.
- GitHub authentication renewed; deployment secrets configured for `/htdocs/wallex`. Automatic FTPS deployment enabled for pushes to main; website HTTPS and runtime verification remain pending.
- Hosting panel credentials are stored only in ignored local `.env.hosting`. No permanent strategy worker is deployed on the free host.


## Domain directory correction ? 2026-11-09

- The authenticated InfinityFree domain dashboard explicitly reported the missing document root `wallex.free.nf/htdocs`. The earlier `/htdocs/wallex` upload was outside this domain document root.
- Created `/wallex.free.nf/htdocs` through FTPS and corrected the repository FTP_REMOTE_DIR variable to this path. Existing `/htdocs` application files were preserved.
- Correct panel URL is `https://wallex.free.nf/public/` (root redirects to public/). HTTPS and PHP runtime checks still required.


## Browser checks and local startup ? 2026-11-09

- Local PHP server and CLI worker started on 127.0.0.1:5078; API returned five strategies with current prices and no errors. Local host allowlist and Windows launcher now support port 5078.
- Hosting package enables sequential browser-driven checks, one due strategy per request, 15-second network budget, per-strategy interval, shared lock and visible errors. No permanent PHP process is started by HTTP.
- All PHP suites (including 10 new browser-runner assertions), Node interface suites and four deployment tests passed.

## Optional live indicators - 2026-11-10

- All 13 PHP suites, three Node interface suites, four Python deployment tests and independent Decimal reference checks passed.
- Optional RSI/EMA/ATR/VOLUME validation, closed-candle aggregation, caching, PASS/FAIL/UNAVAILABLE, pending recovery, cancellation priority and duplicate prevention tested in isolated SQLite databases.
- BTCUSDT and ETHUSDT real captured OHLCV: 16 formula comparisons passed at absolute tolerance 1e-8; maximum error approximately 1.46e-11.
- Fresh real API probe of the complete indicator example returned valid values for all four conditions: RSI 45.960844 (FAIL), EMA distance -0.151664% (FAIL), volume ratio 0.634714 (FAIL), ATR 0.525245% (PASS). Snapshot timestamp 1794305023; these are test observations, not current quotes. No live strategies or alerts changed by probe.
- Legacy missing/disabled indicator blocks make zero additional history calls. Actual browser OS notification delivery was not retested for this feature; existing notification tests pass.

## Live indicators final source audit - 2026-11-10 (supersedes earlier pending retry semantics)

- Found and removed old candle + new indicator snapshot mixing. A FAIL/UNAVAILABLE candle event is consumed; only a new closed entry event can retry the final rule. Historical final events cannot emit entry alerts. event_time, snapshot as_of and unique event key identify the new close.
- Old release pending_signal candidates are discarded. Enabled-only error recovery preserves stage while advancing cursor to avoid replaying consumed events. Legacy recovery remains unchanged.
- Validated complete raw history BEFORE caching. Missing closed tail, malformed/gapped response and network errors receive at most two fetch attempts, 200ms apart, within existing deadlines. Missing entry candles use the same enabled-only bounded retry. No old-candle fallback. Raw cache namespace bumped to v2 to bypass earlier incomplete entries.
- Executed all 13 PHP suites: 276 PASS assertions; three Node suites; four Python deployment tests; 16 real-fixture Decimal comparisons at tolerance 1e-8. Maximum formula error remains about 1.46e-11.
- Added separate coverage: RSI rising/falling/flat, EMA periods 1/2/3/5/14/20/50/200, ATR severe spike and gap, volume/reference exclusion, missing data, delayed publication, disconnect/reconnect, FAIL-to-PASS new event, event identity, deduplication, old pending migration, legacy candle request counts.
- Decimal sensitivity measured on identical captured BTC/ETH OHLCV with 71 versus 80 bars, first-value versus SMA initialization, and one-minute shifted close; detailed measured deltas documented in docs/live-indicators.md.
- Fresh real Wallex read-only probe at epoch 1794306008 produced four valid metrics: RSI 43.871249 (FAIL), EMA distance -0.258693% (FAIL), volume ratio 0.0185085 (FAIL), ATR 0.525245% (PASS). No live strategy/alert changes.
- Delay/outage tests are deterministic simulations. Actual publication delay distribution across market boundaries, external chart parity, and browser OS notification delivery were not measured in this audit. A delay exceeding the bounded retry fails closed and may miss an entry rather than replay it later.


## Zero-volume aggregation and hosting network audit - 2026-11-10

- Candidate CandleAggregation tested separately before wiring runtime wrappers. Zero-volume source bars count for timestamp completeness, not OHLC. Volume sums all source bars. All-zero intervals produce explicit UNAVAILABLE and no alert.
- Actual original BTC fixture: 189 of 240 minute bars have no trades. Ignoring their carried prices changes Open/Low from 74417.46 to 74479.50, matching hourly aggregation.
- Captured BTCUSDT/ETHUSDT/SOLUSDT at UTC starts 2026-11-09 20:00, 2026-11-10 00:00 and 04:00, plus original capture: 10 comparisons / 9 distinct market-windows. All UTC boundaries and OHLC equal. Exact Decimal volume equals; PHP maximum volume rounding difference 1.4210854715202004e-14, tolerance 1e-9.
- All 15 PHP suites passed (423 assertions), three Node suites, four deployment tests, four admin HTTP tests, 16 formula reference comparisons and 10 exact Decimal candle comparisons passed.
- Chrome console observed api.php requests failing with net::ERR_SSL_PROTOCOL_ERROR before HTTP. Read-only browser-to-PHP ping also succeeded (200, 196.9ms), proving intermittent transport failure. TLS issue's deeper network/server origin remains unproven.
- Independent PHP-on-host test at epoch 1794309447: markets 200/502.48ms; minute history 200/1350.19ms/60 closed bars; hourly history 200/281.68ms/4 closed bars. All cURL errno 0, TLS verified, JSON/schema valid. Last data closes 1794309420 and 1794308400.
- Isolated expiring admin diagnostics endpoint installed without loading App.php, touching SQLite, executing a tick, changing strategy configurations or submitting any orders. Authentication configuration and raw private diagnostic report are excluded from Git.
- Full Persian findings: docs/four-hour-host-audit.md. TLS transport instability remains; aggregation fix does not claim to repair it.
