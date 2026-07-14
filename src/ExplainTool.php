<?php

declare(strict_types=1);

namespace Skrepr\PerformanceMate;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Mcp\Capability\Attribute\McpTool;

/**
 * Runs EXPLAIN on a query and tells you WHY it is slow (full table scan,
 * unused index, filesort).
 *
 * A reusable extension does not know the app connection, so the tool builds
 * its own connection from a DSN: the skrepr_mate.database_url parameter,
 * falling back to the DATABASE_URL from the environment (set mate.env_file
 * in your app).
 */
final class ExplainTool
{
    use JsonResponse;

    private ?Connection $connection = null;

    public function __construct(
        private readonly string $databaseUrl = '',
    ) {
    }

    /**
     * @param string       $sql     Raw SQL ('?' placeholders allowed; pass params along)
     * @param list<scalar> $params  Positional params for the '?' placeholders
     * @param bool         $analyze EXPLAIN ANALYZE: actually executes the query for real timings. SELECT/WITH only.
     */
    #[McpTool(
        name: 'explain_query',
        title: 'Explain Query',
        description: 'Runs EXPLAIN on a query and tells you WHY it is slow (full table scan, missing/unused index, filesort). analyze=true runs EXPLAIN ANALYZE (real timings) but is only allowed for SELECT/WITH.',
    )]
    public function explainQuery(string $sql, array $params = [], bool $analyze = false): string
    {
        $sql = rtrim(trim($sql), "; \t\n\r");
        if ('' === $sql) {
            return $this->json(['error' => 'explain_query requires an SQL string.']);
        }

        // The read-only check below only looks at the first statement; a second
        // statement ('SELECT 1; DELETE ...') would simply be executed by drivers
        // with emulated prepares. Refuse hard.
        if (Sql::hasMultipleStatements($sql)) {
            return $this->json(['error' => 'Multiple SQL statements detected — explain_query accepts exactly one.']);
        }

        // ANALYZE actually executes the query; for non-SELECTs that would
        // perform the write. Refuse and only allow plan-only EXPLAIN.
        $head = strtoupper(ltrim($sql, " \t\n\r("));
        $isRead = str_starts_with($head, 'SELECT') || str_starts_with($head, 'WITH');
        if ($analyze && !$isRead) {
            return $this->json(['error' => 'ANALYZE refused: query is not a SELECT/WITH — EXPLAIN ANALYZE would actually execute the write. Use analyze=false.']);
        }

        try {
            $connection = $this->connection();
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No DB connection: '.$e->getMessage()]);
        }

        $platform = $this->detectPlatform($connection);
        $statement = match ($platform) {
            'mysql' => ($analyze ? 'EXPLAIN ANALYZE ' : 'EXPLAIN ').$sql,
            'postgresql' => ($analyze ? 'EXPLAIN (ANALYZE, FORMAT TEXT) ' : 'EXPLAIN ').$sql,
            'sqlite' => 'EXPLAIN QUERY PLAN '.$sql,
            default => 'EXPLAIN '.$sql,
        };

        try {
            $rows = $connection->executeQuery($statement, $params)->fetchAllAssociative();
        } catch (\Throwable $e) {
            return $this->json(['error' => 'EXPLAIN failed: '.$e->getMessage().' — does the number of params match the placeholders?']);
        }

        return $this->json([
            'platform' => $platform,
            'statement' => $statement,
            'analyzed' => $analyze,
            'warnings' => self::warnings($platform, $rows),
            'rows' => $rows,
        ]);
    }

    private function connection(): Connection
    {
        if (null !== $this->connection) {
            return $this->connection;
        }
        $fallback = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
        $url = '' !== $this->databaseUrl
            ? $this->databaseUrl
            : (\is_string($fallback) ? $fallback : '');
        if ('' === $url) {
            throw new \RuntimeException('no DSN configured — set skrepr_mate.database_url in your mate config, or DATABASE_URL in the environment.');
        }

        // Via DsnParser instead of ['url' => ...]: the latter no longer works on DBAL 4.x.
        $params = (new DsnParser([
            'mysql' => 'pdo_mysql',
            'mysql2' => 'pdo_mysql',
            'mariadb' => 'pdo_mysql',
            'pgsql' => 'pdo_pgsql',
            'postgres' => 'pdo_pgsql',
            'postgresql' => 'pdo_pgsql',
            'sqlite' => 'pdo_sqlite',
            'sqlite3' => 'pdo_sqlite',
            'mssql' => 'pdo_sqlsrv',
        ]))->parse($url);

        return $this->connection = DriverManager::getConnection($params);
    }

    private function detectPlatform(Connection $connection): string
    {
        try {
            $pc = strtolower($connection->getDatabasePlatform()::class);
        } catch (\Throwable) {
            return 'other';
        }

        return match (true) {
            str_contains($pc, 'mysql'), str_contains($pc, 'mariadb') => 'mysql',
            str_contains($pc, 'postgre') => 'postgresql',
            str_contains($pc, 'sqlite') => 'sqlite',
            default => 'other',
        };
    }

    /**
     * Platform-aware heuristics on EXPLAIN output: flags the classic slowness
     * signals so the agent does not have to parse query plan jargon.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<string>
     *
     * @internal public for tests
     */
    public static function warnings(string $platform, array $rows): array
    {
        $w = [];
        if ('mysql' === $platform) {
            foreach ($rows as $r) {
                $table = (string) ($r['table'] ?? '?');
                if ('ALL' === strtoupper((string) ($r['type'] ?? ''))) {
                    $w[] = "Full table scan on '{$table}' (type=ALL) — often a missing index.";
                }
                $key = $r['key'] ?? null;
                $possibleKeys = $r['possible_keys'] ?? null;
                if ((null === $key || '' === $key) && null !== $possibleKeys && '' !== $possibleKeys) {
                    $w[] = "Index available but not used on '{$table}' (possible_keys={$possibleKeys}, key=NULL).";
                }
                $extra = (string) ($r['Extra'] ?? $r['extra'] ?? '');
                if (1 === preg_match('/using filesort/i', $extra)) {
                    $w[] = "Filesort on '{$table}' — consider an index that covers the ORDER BY.";
                }
                if (1 === preg_match('/using temporary/i', $extra)) {
                    $w[] = "Temporary table on '{$table}' — often caused by GROUP BY/DISTINCT without a covering index.";
                }
            }
        } elseif ('postgresql' === $platform) {
            foreach ($rows as $r) {
                $line = (string) ($r['QUERY PLAN'] ?? reset($r) ?? '');
                if (1 === preg_match('/Seq Scan on (\S+)/i', $line, $m)) {
                    $w[] = "Sequential scan on '{$m[1]}' — possibly a missing index.";
                }
            }
        } elseif ('sqlite' === $platform) {
            foreach ($rows as $r) {
                $detail = trim((string) ($r['detail'] ?? implode(' ', array_map('strval', $r))));
                if (1 === preg_match('/^SCAN\b/i', $detail)) {
                    $w[] = "Full scan: {$detail}";
                }
            }
        }

        return array_values(array_unique($w));
    }
}
