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
