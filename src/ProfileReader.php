<?php

declare(strict_types=1);

namespace Skrepr\SymfonyRuntimeMate;

use Symfony\Bridge\Doctrine\DataCollector\DoctrineDataCollector;
use Symfony\Component\HttpKernel\DataCollector\MemoryDataCollector;
use Symfony\Component\HttpKernel\DataCollector\RequestDataCollector;
use Symfony\Component\HttpKernel\DataCollector\TimeDataCollector;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\VarDumper\Cloner\Data;

/**
 * Leest Symfony-profielen direct via FileProfilerStorage. Levert per profiel
 * queries (met project-backtrace-frames) en een timeline per categorie — precies
 * wat de analyse-tools nodig hebben en wat de genormaliseerde bridge-output niet geeft.
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
    private readonly FileProfilerStorage $storage;

    public function __construct(string $profilerDir)
    {
        $this->storage = new FileProfilerStorage('file:'.$profilerDir);
    }

    /**
     * Recente request-meta's (nieuwste eerst), optioneel gefilterd op URL-substring.
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

    /**
     * Volledig, gestructureerd profiel voor één token, of null als het niet bestaat.
     *
     * @return StructuredProfile|null
     */
    public function read(string $token): ?array
    {
        $profile = $this->storage->read($token);
        if (null === $profile) {
            return null;
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

        return $out;
    }

    /**
     * Wall-clock per categorie uit de Stopwatch-events. Categorieen kunnen nesten
     * (bv. 'controller' omvat 'doctrine'/'template'), dus ze tellen niet per se op
     * tot de totale duur.
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
     * Project-frames uit een query-backtrace (vendor eruit, max 6). Alleen aanwezig
     * met doctrine.dbal.profiling_collect_backtrace: true; anders leeg.
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

    /** Symfony VarDumper Data-objecten omzetten naar platte PHP-waarden. */
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
     * Compacte samenvatting van een gestructureerd profiel voor tool-output.
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
