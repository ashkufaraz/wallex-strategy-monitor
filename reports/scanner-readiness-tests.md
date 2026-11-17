# آزمون‌های تکمیلی آمادگی اسکنر

تمام ورودی‌ها مصنوعی‌اند؛ شبکه در PHP غیرفعال است. نتایج عملکرد زنده نیستند.

{'total': 30, 'pass': 30, 'fail': 0}

| آزمون | ورودی | موردانتظار | واقعی | وضعیت |
|---|---|---|---|---|
| operational.windows | {"config": "EMA20/50 ATR14 volume20 level20"} | {"4h": 252, "1h": 72, "15m": 72} | {"4h": 252, "1h": 72, "15m": 72} | PASS |
| operational.reject-fixture | {"fixture": "short periods, synthetic fees"} | {"blocked": true} | {"blocked": true} | PASS |
| operational.real-window | {"full": "252/72/72 closed bars"} | {"ready": "READY", "count4h": 252} | {"ready": "READY", "count4h": 252} | PASS |
| seed.EMA20 | {"closes": "1..period then period+1", "seed": "arithmetic mean"} | {"seed": 10.5, "next": 11.5} | {"seed": 10.5, "next": 11.5} | PASS |
| seed.EMA50 | {"closes": "1..period then period+1", "seed": "arithmetic mean"} | {"seed": 25.5, "next": 26.5} | {"seed": 25.5, "next": 26.5} | PASS |
| seed.ATR14 | {"first_TRs": 14, "TR": 2, "next_TR": 4} | {"seed": 2, "next": 2.142857142857143} | {"seed": 1.9999999999999993, "next": 2.1428571428571423} | PASS |
| seed.volume20 | {"previous": [10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10], "current": 30} | {"average": 10, "ratio": 3} | {"average": 10, "ratio": 3} | PASS |
| warmup.boundary | {"last4h": "T-4h", "observations": ["T-1", "T"]} | {"before": "UNAVAILABLE", "at": "READY"} | {"before": "UNAVAILABLE", "at": "READY"} | PASS |
| recovery.full-window-rollout | {"bad": "first 4h slot of 252", "missing_is_not_filled": true} | {"blocked": "UNAVAILABLE", "needed": 1, "recovered": "READY"} | {"blocked": "UNAVAILABLE", "needed": 1, "recovered": "READY"} | PASS |
| pattern.production-periods | {"three_distinct_closes": [1728000000, 1728000900, 1728001800]} | {"candidate": true, "level": 100, "close": 1728001800, "direction": "LONG"} | {"candidate": true, "level": 100, "close": 1728001800, "direction": "LONG"} | PASS |
| json.roundtrip.invalidated | {"review_model": "manual executable after delay", "terminal": "invalidated"} | {"disabled": true, "market": true, "same_invalidation": true, "reason": "SETUP_INVALIDATED", "revived": false} | {"disabled": true, "market": true, "same_invalidation": true, "reason": "SETUP_INVALIDATED", "revived": false} | PASS |
| json.roundtrip.expired | {"review_model": "manual executable after delay", "terminal": "expired"} | {"disabled": true, "market": true, "same_invalidation": true, "reason": "ENTRY_EXPIRED", "revived": false} | {"disabled": true, "market": true, "same_invalidation": true, "reason": "ENTRY_EXPIRED", "revived": false} | PASS |
| json.no-activation | {"enabled": true} | {"blocked": true} | {"blocked": true} | PASS |
| conditions.evidence | {"complete_gate": true, "missing_market": true} | {"pass_has_evidence": true, "not_evaluated": true} | {"pass_has_evidence": true, "not_evaluated": true} | PASS |
| fees.require-evidence | {"rates_bps": [10, 10], "verified": false} | {"status": "UNAVAILABLE", "reason": "cost_model"} | {"status": "UNAVAILABLE", "reason": "cost_model"} | PASS |
| cron.first-observation-120 | {"signal_at": 1728001800, "observations_after": [59, 120]} | {"before": "PENDING", "delay": 120, "schedule_delay": 60, "entry_at": 1728001920} | {"before": "PENDING", "delay": 120, "schedule_delay": 60, "entry_at": 1728001920} | PASS |
| capital.reservation-restart | {"base": 70, "risk_pct": 1} | {"accounted": 70, "fee_once": true, "reserved": true, "risk_budget": 0.7} | {"accounted": 70, "fee_once": true, "reserved": true, "risk_budget": 0.7} | PASS |
| capital.reject-other-candidate | {"existing": "PENDING", "new_candidate": true, "book": "UNVERIFIED"} | {"reason": "POSITION_ALREADY_OPEN", "second_signal": false} | {"reason": "POSITION_ALREADY_OPEN", "second_signal": false} | PASS |
| unknown.lifecycle.partial_target | {"entry": "T+1920", "later": "recovered complete bars and new candidate"} | {"status": "UNKNOWN", "no_close": true, "balance_unchanged": true, "locked": true, "no_retroactive_result": true} | {"status": "UNKNOWN", "no_close": true, "balance_unchanged": true, "locked": true, "no_retroactive_result": true} | PASS |
| unknown.lifecycle.outage | {"entry": "T+1920", "later": "recovered complete bars and new candidate"} | {"status": "UNKNOWN", "no_close": true, "balance_unchanged": true, "locked": true, "no_retroactive_result": true} | {"status": "UNKNOWN", "no_close": true, "balance_unchanged": true, "locked": true, "no_retroactive_result": true} | PASS |
| retention.checkpoint-replay | {"drop": "all observations before OPEN; keep state_before"} | {"match": true, "no_new_signal": true, "balance_same": true} | {"match": true, "no_new_signal": true, "balance_same": true} | PASS |
| replay.recovered-data-not-rewritten | {"first": "missing15m", "second": "recovered"} | {"match": true, "first": "UNAVAILABLE", "second": "WAITING_RETEST", "source_times": true} | {"match": true, "first": "UNAVAILABLE", "second": "WAITING_RETEST", "source_times": true} | PASS |
| report.insufficient | {"trades": 0, "valid_executions": 0} | {"evidence": "INSUFFICIENT_EVIDENCE", "net": null, "drawdown": null, "balance": 70} | {"evidence": "INSUFFICIENT_EVIDENCE", "net": null, "drawdown": null, "balance": 70} | PASS |
| phase.source-unverified | {"healthy_candles": true, "source_time": null} | {"phase": "COLLECTING", "manual": "UNAVAILABLE", "source": null} | {"phase": "COLLECTING", "manual": "UNAVAILABLE", "source": null} | PASS |
| report.full-period-after-retention | {"observations": ["T valid", "T+120 missing", "T+240 valid"], "retained": 1} | {"actual": 3, "expected": 3, "valid_seconds": 120, "unavailable_seconds": 120, "coverage": 66.66666666666667} | {"actual": 3, "expected": 3, "valid_seconds": 120, "unavailable_seconds": 120, "coverage": 66.66666666666667} | PASS |
| report.unknown-capital-visible | {"unresolved": true, "closed_trades": 0} | {"unknown": 1, "locked": true, "net": null, "complete": false, "costs_positive": true} | {"unknown": 1, "locked": true, "net": null, "complete": false, "costs_positive": true} | PASS |
| replay.code-version-pin | {"observation_code_version": "different archived code"} | {"blocked": true} | {"blocked": true} | PASS |
| timestamps.reject-milliseconds | {"t": 1794907800000} | {"blocked": true} | {"blocked": true} | PASS |
| adapter.actual-precision-shape | {"legacy": {"stepSize": 8, "tickSize": 2}, "modern": {"amount_precision": 8, "price_precision": 2}} | {"step": 1e-08, "tick": 0.01, "proof": true, "source_unverified": true, "read_only": true} | {"step": 1e-08, "tick": 0.01, "proof": true, "source_unverified": true, "read_only": true} | PASS |
| capital.concurrent-entry-and-restart | {"processes": 6, "capital": 70, "entry_fee_bps": 10} | {"discarded": 5, "one_trade": true, "reserve_matches": true, "accounted": 70, "unchanged_after_restart": true} | {"discarded": 5, "one_trade": true, "reserve_matches": true, "accounted": 70, "unchanged_after_restart": true} | PASS |
