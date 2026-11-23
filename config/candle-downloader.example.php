<?php
// Copy to candle-downloader.local.php on the machine running PHP/Apache.
// Alternatively set CANDLE_BINANCE_PROXY in the PHP service environment.
// Empty string selects a direct connection. Never put a subscription URL here.
// Hosted Xray is provisioned by deploy/candle_proxy.py; its config is ['managed_proxy'=>true].
return ['binance_proxy' => 'socks5h://127.0.0.1:9050'];
