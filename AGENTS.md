# AGENTS.md

AI Mate extension (Composer package) that exposes Symfony profiler runtime data as MCP tools for AI agents. Dev-only; PHP ≥ 8.2, Symfony 6.4/7.x/8.x, DBAL 3.6+/4.x.

## Commands

```bash
composer install
composer test        # PHPUnit
composer phpstan     # PHPStan level 8 + strict rules — findings are blocking
```

End-to-end smoke test (this repo is itself a Mate root project):

```bash
vendor/bin/mate mcp:tools:list                     # all 5 tools visible?
vendor/bin/mate mcp:tools:call slow_queries '{}'   # full DI chain
```

## Architecture

- `src/*Tool.php` — the 5 MCP tools (entry layer): thin mappers from core output to JSON
- `src/ProfileReader.php` — reads profiles via `FileProfilerStorage`; OOM guards + in-process cache (core)
- `src/QueryShapes.php` — pure aggregation: shape grouping, N+1 heuristics, before/after diff (core)
- `src/Sql.php` — pure SQL/backtrace helpers (core)
- `config/services.php` — DI config, loaded by Mate; tool services MUST be `public` (the MCP SDK resolves via `$container->has(FQCN)`)
- Registration with consumers via `extra.ai-mate` in `composer.json`; see `vendor/bin/mate discover`

## Conventions

- All text (comments, tool descriptions, error messages, docs) in English; `INSTRUCTIONS.md` is the agent-facing tool guide
- Strict types; no `empty()`; explicit checks (`null ===`, `'' ===`)
- Pure logic belongs in static helper classes (`Sql`, `QueryShapes`) — testable without IO; tools stay thin
- Tool output follows the Mate design principles: distill instead of dump, hard limits with a truncation signal (`sql_shape_truncated`), sensitive data stripped
- `ProfileReader::profileFilePath()` mirrors Symfony's path scheme; the schema-pin test in `ProfileReaderTest` fails if Symfony changes it
