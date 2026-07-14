# Runtime profiler tools (Skrepr)

These tools read the **Symfony profiler** of the local dev app to analyse *what actually happened* at runtime — request timing, Doctrine queries, N+1 patterns — and to run `EXPLAIN` against the database. Dev-only.

Typical performance workflow:

1. `request_breakdown` — where did the wall-clock go for a request (DB vs rendering vs http_client vs listeners)?
2. `slow_queries` — the slowest query *shapes* across recent requests, with the originating project line.
3. `detect_n_plus_one` — repeated identical queries within one request, with the likely parent query.
4. `explain_query` — feed the raw SQL of a slow query here to learn *why* it is slow (full scan, unused index, filesort).
5. `profile_diff` — after a fix, compare two profiles of the same endpoint to confirm the improvement.

Notes:
- `origin` / `origin_context` require `doctrine.dbal.profiling_collect_backtrace: true` in the app's dev config; without it they degrade to "unknown".
- `explain_query` needs a database DSN (see the extension README). `analyze=true` only runs for `SELECT`/`WITH`, and exactly one statement is accepted.
- `sample_sql` in `detect_n_plus_one` contains the actual literal values from the request — treat it as potentially sensitive; the normalized `sql_shape` fields do not.
