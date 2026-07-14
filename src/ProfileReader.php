<?php

declare(strict_types=1);

namespace Skrepr\PerformanceMate;

use Symfony\Bridge\Doctrine\DataCollector\DoctrineDataCollector;
use Symfony\Component\HttpKernel\DataCollector\MemoryDataCollector;
use Symfony\Component\HttpKernel\DataCollector\RequestDataCollector;
use Symfony\Component\HttpKernel\DataCollector\TimeDataCollector;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\VarDumper\Cloner\Data;

/**
 * Reads Symfony profiles directly via FileProfilerStorage. Provides, per profile,
 * the queries (with project backtrace frames) and a per-category timeline — exactly
 * what the analysis tools need and what the normalized bridge output does not give.
 *
 * @phpstan-type Frame array{file: string, line: int|null, call: string}
 * @phpstan-type Query array{connection: string, sql: string, ms: float, backtrace: list<Frame>}
 * @phpstan-type StructuredProfile array{
 *     token: string, method: string, url: string, route: ?string, status_code: int,
 *     duration_ms: ?float, memory_mb: ?float, query_count: int, query_time_ms: float,
 *     queries: list<Query>, timeline_ms: array<string, float>,
 * }
 */
final class ProfileReader
{
    /** Above this file size (bytes on disk) we skip a profile instead of unserializing it. */
    public const DEFAULT_MAX_PROFILE_BYTES = 4 * 1024 * 1024;

    /** Lower bound for this process's memory_limit; see ensureMemoryHeadroom(). */
    private const MIN_MEMORY_LIMIT_BYTES = 1024 * 1024 * 1024;

    /** Number of distilled profiles kept in the in-process cache. */
    private const MAX_CACHED_PROFILES = 20;

    private readonly FileProfilerStorage $storage;

    /**
     * Cache of distilled profiles. The MCP server is long-lived and the agent
     * workflow reads the same profile several times (breakdown → N+1 → diff);
     * unserializing is the most expensive operation here (±100x the file size
     * in RAM). Profiles are immutable once written, so invalidation is not
     * needed. We keep the small distilled array, never the raw Profile object.
     *
     * @var array<string, StructuredProfile>
     */
    private array $cache = [];

    public function __construct(
        private readonly string $profilerDir,
        private readonly int $maxProfileBytes = self::DEFAULT_MAX_PROFILE_BYTES,
    ) {
        $this->storage = new FileProfilerStorage('file:'.$profilerDir);
        self::ensureMemoryHeadroom();
    }

    /**
     * Recent request metas (newest first), optionally filtered on a URL substring.
     *
     * @return list<array{token: string, method: string, url: string, status_code: int, time: int}>
     */
    public function findRecent(int $limit, string $urlFilter = ''): array
    {
        $out = [];
        foreach ($this->storage->find(null, '' !== $urlFilter ? $urlFilter : null, $limit, null) as $meta) {
            $out[] = [
                'token' => (string) $meta['token'],
                'method' => (string) $meta['method'],
                'url' => (string) $meta['url'],
                'status_code' => (int) ($meta['status_code'] ?? 0),
                'time' => (int) $meta['time'],
            ];
        }

        return $out;
    }

    /** Token of the most recent request (optionally filtered on a URL substring), or null. */
    public function latestToken(string $urlFilter = ''): ?string
    {
        $metas = $this->findRecent(1, $urlFilter);

        return $metas[0]['token'] ?? null;
    }

    /**
     * Full, structured profile for a single token, or null if it does not exist.
     *
     * @return StructuredProfile|null
     *
     * @throws ProfileTooLargeException when the profile file exceeds the safe read limit
     */
    public function read(string $token): ?array
    {
        if (isset($this->cache[$token])) {
            $cached = $this->cache[$token];
            // Move to the end: the most recently used profile is evicted last.
            unset($this->cache[$token]);
            $this->cache[$token] = $cached;

            return $cached;
        }

        $fileFound = $this->guardProfileSize($token);
        $profile = $this->storage->read($token);
        if (null === $profile) {
            return null;
        }

        // Fail closed: the storage found a profile that profileFilePath() could
        // not find — then Symfony's path scheme has changed and the size guard
        // is out of play. Fail hard so this gets noticed instead of a silent
        // OOM exposure. (The re-check covers the race where the profile was
        // written only after the guard check.)
        if (!$fileFound && !is_file($this->profileFilePath($token))) {
            throw new \LogicException(sprintf('Profile %s was read but profileFilePath() did not find the file: the path scheme of FileProfilerStorage has presumably changed. Update ProfileReader::profileFilePath().', $token));
        }

        $out = [
            'token' => $profile->getToken(),
            'method' => (string) $profile->getMethod(),
            'url' => (string) $profile->getUrl(),
            'route' => null,
            'status_code' => (int) $profile->getStatusCode(),
            'duration_ms' => null,
            'memory_mb' => null,
            'query_count' => 0,
            'query_time_ms' => 0.0,
            'queries' => [],
            'timeline_ms' => [],
        ];

        $time = $profile->hasCollector('time') ? $profile->getCollector('time') : null;
        if ($time instanceof TimeDataCollector) {
            $out['duration_ms'] = round($time->getDuration(), 1);
            $out['timeline_ms'] = $this->timeline($time);
        }
        $memory = $profile->hasCollector('memory') ? $profile->getCollector('memory') : null;
        if ($memory instanceof MemoryDataCollector) {
            $out['memory_mb'] = round($memory->getMemory() / 1048576, 1);
        }
        $request = $profile->hasCollector('request') ? $profile->getCollector('request') : null;
        if ($request instanceof RequestDataCollector) {
            $out['route'] = $request->getRoute();
        }
        $db = $profile->hasCollector('db') ? $profile->getCollector('db') : null;
        if ($db instanceof DoctrineDataCollector) {
            $queries = $this->queries($db);
            $out['queries'] = $queries;
            $out['query_count'] = \count($queries);
            $out['query_time_ms'] = round(array_sum(array_column($queries, 'ms')), 1);
        }

        if (\count($this->cache) >= self::MAX_CACHED_PROFILES) {
            array_shift($this->cache); // least recently used sits at the front
        }
        $this->cache[$token] = $out;

        return $out;
    }

    /**
     * Refuses a profile that is too large to unserialize safely. Symfony reads
     * the profile in one go (±100x the file size in RAM); an outlier would take
     * the process down with an uncatchable OOM. Missing files are let through:
     * storage->read() neatly returns null for those.
     *
     * @return bool whether the profile file was found at the expected path
     *
     * @throws ProfileTooLargeException
     */
    private function guardProfileSize(string $token): bool
    {
        $path = $this->profileFilePath($token);
        if (!is_file($path)) {
            return false;
        }
        $bytes = filesize($path);
        if (false !== $bytes && $bytes > $this->maxProfileBytes) {
            throw new ProfileTooLargeException($token, $bytes, $this->maxProfileBytes);
        }

        return true;
    }

    /** Same path scheme as Symfony's FileProfilerStorage::getFilename(). */
    private function profileFilePath(string $token): string
    {
        return $this->profilerDir.'/'.substr($token, -2, 2).'/'.substr($token, -4, 2).'/'.$token;
    }

    /**
     * Raises memory_limit (upwards only) to a lower bound. A profile is read in a
     * single unserialize() call and costs ±100x the file size in RAM; PHP's default
     * of 128M already kills the process on a single 500 profile with an exception
     * dump. The hard upper bound remains $maxProfileBytes, which keeps the peak
     * within this limit.
     */
    private static function ensureMemoryHeadroom(): void
    {
        $current = self::parseBytes(\ini_get('memory_limit'));
        if ($current < 0) {
            return; // unlimited — nothing to do
        }
        if ($current < self::MIN_MEMORY_LIMIT_BYTES) {
            @ini_set('memory_limit', (string) self::MIN_MEMORY_LIMIT_BYTES);
        }
    }

    /**
     * memory_limit notation ('128M', '1G', '-1', bytes) to bytes; negative = unlimited.
     * Deliberately mirrors PHP's own zend_ini_parse_quantity: the integer parse stops
     * at the first non-digit, so '0.5G' is 0 — exactly what PHP itself makes of it.
     *
     * @internal public for tests
     */
    public static function parseBytes(string $value): int
    {
        $value = trim($value);
        if ('' === $value) {
            return 0;
        }
        $number = (int) $value;

        return match (strtolower($value[-1])) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * Wall clock per category from the Stopwatch events. Categories can nest
     * (e.g. 'controller' contains 'doctrine'/'template'), so they do not
     * necessarily add up to the total duration.
     *
     * @return array<string, float>
     */
    private function timeline(TimeDataCollector $timeCollector): array
    {
        $byCategory = [];
        foreach ($timeCollector->getEvents() as $event) {
            $cat = $event->getCategory();
            $byCategory[$cat] = ($byCategory[$cat] ?? 0.0) + $event->getDuration();
        }
        arsort($byCategory);

        return array_map(static fn (float $v): float => round($v, 1), $byCategory);
    }

    /**
     * @return list<Query>
     */
    private function queries(DoctrineDataCollector $db): array
    {
        $out = [];
        foreach ($db->getQueries() as $connection => $connQueries) {
            foreach ($connQueries as $q) {
                $out[] = [
                    'connection' => (string) $connection,
                    'sql' => (string) $this->plain($q['sql'] ?? ''),
                    'ms' => round((float) $this->plain($q['executionMS'] ?? 0) * 1000, 2),
                    'backtrace' => $this->frames($q['backtrace'] ?? null),
                ];
            }
        }

        return $out;
    }

    /**
     * Project frames from a query backtrace (vendor filtered out, max 6). Only
     * present with doctrine.dbal.profiling_collect_backtrace: true; empty otherwise.
     *
     * @return list<Frame>
     */
    private function frames(mixed $bt): array
    {
        if ($bt instanceof Data) {
            $bt = $bt->getValue(true);
        }
        if (!\is_array($bt)) {
            return [];
        }
        $frames = [];
        foreach ($bt as $frame) {
            if (!\is_array($frame)) {
                continue;
            }
            $file = $frame['file'] ?? null;
            if (null === $file || str_contains((string) $file, '/vendor/')) {
                continue;
            }
            $frames[] = [
                'file' => (string) $file,
                'line' => isset($frame['line']) ? (int) $frame['line'] : null,
                'call' => trim(($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '')),
            ];
            if (\count($frames) >= 6) {
                break;
            }
        }

        return $frames;
    }

    /** Convert Symfony VarDumper Data objects to plain PHP values. */
    private function plain(mixed $value): mixed
    {
        if ($value instanceof Data) {
            try {
                return $value->getValue(true);
            } catch (\Throwable) {
                return (string) $value;
            }
        }

        return $value;
    }

    /**
     * Compact summary of a structured profile for tool output.
     *
     * @param StructuredProfile $p
     *
     * @return array<string, mixed>
     */
    public static function summarize(array $p): array
    {
        return [
            'token' => $p['token'],
            'method' => $p['method'],
            'url' => $p['url'],
            'route' => $p['route'],
            'status_code' => $p['status_code'],
            'duration_ms' => $p['duration_ms'],
            'memory_mb' => $p['memory_mb'],
            'query_count' => $p['query_count'],
            'query_time_ms' => $p['query_time_ms'],
        ];
    }
}
