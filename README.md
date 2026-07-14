# skrepr/ai-performance-mate-extension

An [AI Mate](https://symfony.com/doc/current/ai/components/mate.html) extension that exposes **runtime data from the Symfony profiler** to AI agents (Claude Code, Cursor, …). Where code intelligence looks at what sits on disk, this looks at what *actually happens*: request timing, Doctrine queries, N+1 patterns and query plans.

This is the successor to the standalone Node MCP server `symfony-runtime-mcp`: the same analysis tools, but now in-process as a Mate extension. That eliminates `docker exec`, a copied extractor script and host↔container path mapping — the tools run where the code and the database live.

> **For local dev environments only.** Never point this at production.

## Tools

| Tool | What it does |
|---|---|
| `slow_queries` | Slowest query shapes over the last N requests, with total time, execution count and origin (file:line + source code) |
| `detect_n_plus_one` | Repeated identical queries within a single request, with the likely parent query (1+N) |
| `request_breakdown` | Wall clock of a single request broken down per category (DB / external HTTP / rendering / listeners …) |
| `profile_diff` | Before/after comparison of the same endpoint: duration, memory, query count and disappeared/appeared query shapes |
| `explain_query` | Runs `EXPLAIN` and tells you *why* a query is slow (full scan, unused index, filesort). `analyze=true` for `SELECT`/`WITH` only |

Complementary to the official `symfony/ai-symfony-mate-extension` (which exposes raw profiler and container data): install both side by side.

## Installation

```bash
composer require --dev skrepr/ai-performance-mate-extension
```

**Already running AI Mate in your project** (a `mate/` directory exists)? Then you are done: the Mate composer plugin runs `mate discover` automatically after every `composer require`/`update`.

**No AI Mate yet?** The package pulls in `symfony/ai-mate` automatically; initialize it once:

```bash
vendor/bin/mate init          # creates mate/ + mcp.json (Claude Code and friends pick those up automatically)
vendor/bin/mate discover      # registers the extension; happens automatically from then on
```

The four profiler tools work right away: they read `var/cache/dev/profiler`. Make a few requests to your app and call `request_breakdown`, for example. Check with `vendor/bin/mate mcp:tools:list` that the tools are registered.

## Configuration

All configuration is optional and goes through `mate/config.php` in your project.

### `explain_query` — database connection

The extension does not know your app connection (Mate does not boot the kernel), so `explain_query` builds its own connection from a DSN. By default it falls back to `DATABASE_URL` from the environment; load that by setting `mate.env_file`:

```php
// mate/config.php
return static function (ContainerConfigurator $container): void {
    $container->parameters()
        ->set('mate.env_file', '.env')   // loads .env and .env.local
    ;
};
```

`mate.env_file` expects a **string** (not an array) and requires `symfony/dotenv` in your project — already present in a standard Symfony app.

Using a different env var or multiple connections? Point at the DSN explicitly:

```php
$container->parameters()
    ->set('mate.env_file', '.env')
    ->set('skrepr_mate.database_url', '%env(resolve:POSTCODE_DB_URL)%')
;
```

### Query origins (strongly recommended)

Without backtraces, `slow_queries`/`detect_n_plus_one` know *that* a query is slow, but not *where* it comes from. Set in `config/packages/dev/doctrine.yaml`:

```yaml
doctrine:
    dbal:
        profiling_collect_backtrace: true
```

With that, `origin` points to the exact file:line in your project code, including the surrounding source.

### Custom profiler location

```php
$container->parameters()
    ->set('skrepr_mate.profiler_dir', '%mate.root_dir%/var/cache/dev/profiler')
;
```

## Known limitation: the Symfony bridge on PHP < 8.4

If you also use `symfony/ai-symfony-mate-extension`, its `symfony-profiler-*` tools fail on **PHP < 8.4** with *"Cannot generate lazy proxy"*: the collector formatters are `final` and `->lazy()`, and before PHP 8.4 Symfony creates a lazy proxy through subclassing. On PHP 8.4+ this does not apply. Workaround without patching the vendor — redefine the formatters non-lazy in `mate/config.php`:

```php
foreach (['Request','Exception','Mailer','Translation','Doctrine','Time','Logger','Memory'] as $name) {
    $fqcn = "Symfony\\AI\\Mate\\Bridge\\Symfony\\Profiler\\Service\\Formatter\\{$name}CollectorFormatter";
    $container->services()->set($fqcn)->tag('ai_mate.profiler_collector_formatter');
}
```

## Requirements

- PHP ≥ 8.2
- Symfony 6.4 / 7.x / 8.x with the profiler enabled in dev (`symfony/web-profiler-bundle`)
- Doctrine DBAL 3.6+ / 4.x

## License

MIT
