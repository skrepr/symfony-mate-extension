# skrepr/ai-performance-mate-extension

Een [AI Mate](https://symfony.com/doc/current/ai/components/mate.html)-extensie die **runtime-data uit de Symfony profiler** beschikbaar maakt voor AI-agents (Claude Code, Cursor, …). Waar code-intelligence kijkt naar wat er op disk staat, kijkt deze naar wat er *daadwerkelijk gebeurt*: request-timing, Doctrine-queries, N+1-patronen en query-plannen.

Dit is de opvolger van de losse Node-MCP-server `symfony-runtime-mcp`: dezelfde analyse-tools, maar nu in-process als Mate-extensie. Daardoor vervallen `docker exec`, een gekopieerd extractor-script en host↔container-padmapping — de tools draaien waar de code en de database leven.

> **Alleen voor lokale dev-omgevingen.** Richt dit nooit op productie.

## Tools

| Tool | Doet |
|---|---|
| `slow_queries` | Traagste query-shapes over de laatste N requests, met totale tijd, aantal uitvoeringen en herkomst (bestand:regel + broncode) |
| `detect_n_plus_one` | Herhaalde identieke queries binnen één request, met de vermoedelijke parent-query (1+N) |
| `request_breakdown` | Wall-clock van één request opgesplitst per categorie (DB / externe HTTP / rendering / listeners …) |
| `profile_diff` | Voor/na-vergelijking van hetzelfde endpoint: duur, geheugen, query-count en verdwenen/bijgekomen query-shapes |
| `explain_query` | Draait `EXPLAIN` en zegt *waarom* een query traag is (full scan, ongebruikte index, filesort). `analyze=true` alleen voor `SELECT`/`WITH` |

Complementair aan de officiële `symfony/ai-symfony-mate-extension` (die ruwe profiler- en container-data ontsluit): installeer beide naast elkaar.

## Installatie

```bash
composer require --dev skrepr/ai-performance-mate-extension
```

**Draait AI Mate al in je project** (er bestaat een `mate/`-map)? Dan ben je klaar: de Mate-composer-plugin draait `mate discover` automatisch na elke `composer require`/`update`.

**Nog geen AI Mate?** Het pakket trekt `symfony/ai-mate` automatisch mee; initialiseer het eenmalig:

```bash
vendor/bin/mate init          # maakt mate/ + mcp.json (Claude Code e.d. pikken die vanzelf op)
vendor/bin/mate discover      # registreert de extensie; daarna gebeurt dit automatisch
```

De vier profiler-tools werken meteen: ze lezen `var/cache/dev/profiler`. Doe een paar requests naar je app en roep bijvoorbeeld `request_breakdown` aan. Check met `vendor/bin/mate mcp:tools:list` of de tools geregistreerd zijn.

## Configuratie

Alle config is optioneel en gaat via `mate/config.php` in je project.

### `explain_query` — databaseverbinding

De extensie kent je app-connectie niet (Mate boot de kernel niet), dus `explain_query` bouwt zelf een verbinding uit een DSN. Standaard valt hij terug op `DATABASE_URL` uit de omgeving; laad die door `mate.env_file` te zetten:

```php
// mate/config.php
return static function (ContainerConfigurator $container): void {
    $container->parameters()
        ->set('mate.env_file', '.env')   // laadt .env én .env.local
    ;
};
```

`mate.env_file` verwacht een **string** (géén array) en vereist `symfony/dotenv` in je project — in een standaard Symfony-app al aanwezig.

Gebruik je een andere env-var of meerdere connecties, wijs de DSN dan expliciet aan:

```php
$container->parameters()
    ->set('mate.env_file', '.env')
    ->set('skrepr_mate.database_url', '%env(resolve:POSTCODE_DB_URL)%')
;
```

### Herkomst van queries (sterk aangeraden)

Zonder backtraces weet `slow_queries`/`detect_n_plus_one` *dat* een query traag is, maar niet *waar* hij vandaan komt. Zet in `config/packages/dev/doctrine.yaml`:

```yaml
doctrine:
    dbal:
        profiling_collect_backtrace: true
```

Daarmee wijst `origin` naar het exacte bestand:regel in je projectcode, inclusief de broncode eromheen.

### Afwijkende profiler-locatie

```php
$container->parameters()
    ->set('skrepr_mate.profiler_dir', '%mate.root_dir%/var/cache/dev/profiler')
;
```

## Bekende beperking: de Symfony-bridge op PHP < 8.4

Gebruik je óók `symfony/ai-symfony-mate-extension`, dan falen z'n `symfony-profiler-*`-tools op **PHP < 8.4** met *"Cannot generate lazy proxy"*: de collector-formatters zijn `final` en `->lazy()`, en vóór PHP 8.4 maakt Symfony een lazy proxy via subclassing. Op PHP 8.4+ speelt dit niet. Work-around zonder de vendor te patchen — herdefinieer de formatters non-lazy in `mate/config.php`:

```php
foreach (['Request','Exception','Mailer','Translation','Doctrine','Time','Logger','Memory'] as $name) {
    $fqcn = "Symfony\\AI\\Mate\\Bridge\\Symfony\\Profiler\\Service\\Formatter\\{$name}CollectorFormatter";
    $container->services()->set($fqcn)->tag('ai_mate.profiler_collector_formatter');
}
```

## Requirements

- PHP ≥ 8.2
- Symfony 6.4 / 7.x / 8.x met de profiler aan in dev (`symfony/web-profiler-bundle`)
- Doctrine DBAL 3.2+ / 4.x

## Licentie

MIT
