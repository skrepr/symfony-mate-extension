# Changelog

## 0.2.0 — 2026-07-14

- All project text (comments, tool descriptions, error messages, docs) translated to English
- Truncation signal now consistent everywhere: `profile_diff` and `detect_n_plus_one` report `sql_shape_truncated`/`sample_sql_truncated` for truncated SQL; the shape limit is unified at 400 characters (was 300 in `profile_diff` and `likely_parent`)
- One consistent fallback message for `origin` when backtraces are missing (points to `doctrine.dbal.profiling_collect_backtrace`)
- `profile_diff` now names the token when a profile cannot be read

## 0.1.0 — 2026-07-14

First release.

- Five MCP tools for AI Mate: `slow_queries`, `detect_n_plus_one`, `request_breakdown`, `profile_diff` and `explain_query`
- OOM protection: oversized profiles are skipped (`skrepr_mate.max_profile_bytes`), with fail-closed detection if Symfony's profiler path scheme ever changes
- In-process cache of distilled profiles (the MCP server is long-lived)
- `explain_query` refuses multi-statements and runs `EXPLAIN ANALYZE` only for `SELECT`/`WITH`
- Tool output follows the Mate design principles: normalized query shapes, hard limits with a truncation signal
- Configuration via `skrepr_mate.profiler_dir`, `skrepr_mate.database_url` and `skrepr_mate.max_profile_bytes`
