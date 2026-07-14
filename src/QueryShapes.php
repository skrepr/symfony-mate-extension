<?php

declare(strict_types=1);

namespace Skrepr\PerformanceMate;

/**
 * Pure aggregation logic over query lists from profiles: shape grouping,
 * N+1 detection and before/after diffs. Deliberately free of IO so this is
 * directly testable; the tools only map the outcome to JSON output.
 *
 * @phpstan-import-type Frame from Sql
 * @phpstan-import-type Query from ProfileReader
 *
 * @phpstan-type ShapeGroup array{total_ms: float, count: int, max_ms: float, sample_sql: string, originFrame: Frame|null, chain: list<string>, seen_on: array<string, true>}
 * @phpstan-type RankedShape array{sql_shape: string, total_ms: float, executions: int, avg_ms: float, max_ms: float, originFrame: Frame|null, chain: list<string>, seen_on: list<string>}
 * @phpstan-type OriginGroup array{shape: string, count: int, total_ms: float, sample_sql: string, originFrame: Frame|null, chain: list<string>, firstIndex: int}
 * @phpstan-type Suspect array{executions: int, total_ms: float, sample_sql: string, sample_sql_truncated?: true, originFrame: Frame|null, chain: list<string>, likely_parent: array{sql_shape: string, sql_shape_truncated?: true, origin: string|null}|null}
 */
final class QueryShapes
{
    /** Maximum length of an sql_shape in tool output; anything longer gets an sql_shape_truncated signal. */
    public const MAX_SHAPE_CHARS = 400;

    /** Maximum length of sample_sql in tool output; anything longer gets a sample_sql_truncated signal. */
    public const MAX_SAMPLE_SQL_CHARS = 400;

    /**
     * Adds one request's queries to the shape groups (for slow_queries).
     * The origin frames of the first query with a backtrace win per group.
     *
     * @param array<string, ShapeGroup> $groups
     * @param list<Query>               $queries
     *
     * @return array<string, ShapeGroup>
     */
    public static function accumulate(array $groups, array $queries, string $seenOn): array
    {
        foreach ($queries as $q) {
            $key = Sql::normalize($q['sql']);
            $g = $groups[$key] ?? [
                'total_ms' => 0.0, 'count' => 0, 'max_ms' => 0.0,
                'sample_sql' => $q['sql'], 'originFrame' => null, 'chain' => [], 'seen_on' => [],
            ];
            $g['total_ms'] += $q['ms'];
            ++$g['count'];
            $g['max_ms'] = max($g['max_ms'], $q['ms']);
            if (null === $g['originFrame']) {
                $tf = Sql::topFrame($q['backtrace']);
                if (null !== $tf) {
                    $g['originFrame'] = $tf;
                    $g['chain'] = Sql::frameChain($q['backtrace']);
                }
            }
            $g['seen_on'][$seenOn] = true;
            $groups[$key] = $g;
        }

        return $groups;
    }

    /**
     * Slowest shapes first (by total time), capped at $top, with derived
     * avg_ms and seen_on capped at 5 endpoints.
     *
     * @param array<string, ShapeGroup> $groups
     *
     * @return list<RankedShape>
     */
    public static function rank(array $groups, int $top): array
    {
        uasort($groups, static fn (array $a, array $b): int => $b['total_ms'] <=> $a['total_ms']);

        $out = [];
        foreach (\array_slice($groups, 0, max(1, $top), true) as $shape => $g) {
            $out[] = [
                'sql_shape' => $shape,
                'total_ms' => round($g['total_ms'], 1),
                'executions' => $g['count'],
                'avg_ms' => round($g['total_ms'] / $g['count'], 2),
                'max_ms' => $g['max_ms'],
                'originFrame' => $g['originFrame'],
                'chain' => $g['chain'],
                'seen_on' => \array_slice(array_keys($g['seen_on']), 0, 5),
            ];
        }

        return $out;
    }

    /**
     * Groups by (shape + origin): a real N+1 is the same query repeated from a
     * single line in a loop. Without a backtrace the origin is empty and this
     * falls back to grouping by shape alone.
     *
     * @param list<Query> $queries
     *
     * @return array<string, OriginGroup>
     */
    public static function groupByShapeAndOrigin(array $queries): array
    {
        $groups = [];
        foreach ($queries as $i => $q) {
            $shape = Sql::normalize($q['sql']);
            $tf = Sql::topFrame($q['backtrace']);
            $key = $shape.' '.(null !== $tf ? Sql::formatFrame($tf) : '');
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'shape' => $shape, 'count' => 0, 'total_ms' => 0.0, 'sample_sql' => $q['sql'],
                    'originFrame' => $tf, 'chain' => null !== $tf ? Sql::frameChain($q['backtrace']) : [],
                    'firstIndex' => $i,
                ];
            }
            ++$groups[$key]['count'];
            $groups[$key]['total_ms'] += $q['ms'];
        }

        return $groups;
    }

    /**
     * Suspects (>= $threshold repetitions), most repeated first, with the
     * likely parent: the query directly before the first repetition is often
     * the one whose result is being iterated — the classic 1+N signature.
     *
     * @param array<string, OriginGroup> $groups
     * @param list<Query>                $queries
     *
     * @return list<Suspect>
     */
    public static function nPlusOneSuspects(array $groups, array $queries, int $threshold): array
    {
        $suspects = array_filter($groups, static fn (array $g): bool => $g['count'] >= $threshold);
        usort($suspects, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        $out = [];
        foreach ($suspects as $g) {
            $likelyParent = null;
            if ($g['firstIndex'] > 0) {
                $parent = $queries[$g['firstIndex'] - 1];
                $parentShape = Sql::normalize($parent['sql']);
                if ($parentShape !== $g['shape']) {
                    $pf = Sql::topFrame($parent['backtrace']);
                    $likelyParent = [
                        ...self::truncateShape($parentShape),
                        'origin' => null !== $pf ? Sql::formatFrame($pf) : null,
                    ];
                }
            }
            $suspect = [
                'executions' => $g['count'],
                'total_ms' => round($g['total_ms'], 1),
                'sample_sql' => mb_substr($g['sample_sql'], 0, self::MAX_SAMPLE_SQL_CHARS),
                'originFrame' => $g['originFrame'],
                'chain' => $g['chain'],
                'likely_parent' => $likelyParent,
            ];
            if (mb_strlen($g['sample_sql']) > self::MAX_SAMPLE_SQL_CHARS) {
                $suspect['sample_sql_truncated'] = true;
            }
            $out[] = $suspect;
        }

        return $out;
    }

    /**
     * Truncate a shape to MAX_SHAPE_CHARS, with a truncation signal (per the
     * Mate design principles): the agent must know it is reasoning about a
     * sample, not the full query.
     *
     * @return array{sql_shape: string, sql_shape_truncated?: true}
     */
    public static function truncateShape(string $shape): array
    {
        if (mb_strlen($shape) <= self::MAX_SHAPE_CHARS) {
            return ['sql_shape' => $shape];
        }

        return ['sql_shape' => mb_substr($shape, 0, self::MAX_SHAPE_CHARS), 'sql_shape_truncated' => true];
    }

    /**
     * @param list<Query> $queries
     *
     * @return array<string, int> shape => number of executions
     */
    public static function countByShape(array $queries): array
    {
        $m = [];
        foreach ($queries as $q) {
            $shape = Sql::normalize($q['sql']);
            $m[$shape] = ($m[$shape] ?? 0) + 1;
        }

        return $m;
    }

    /**
     * Which query shapes disappeared, appeared or changed in count between
     * two requests (for profile_diff).
     *
     * @param array<string, int> $before
     * @param array<string, int> $after
     *
     * @return array{
     *     removed: list<array{sql_shape: string, sql_shape_truncated?: true, executions: int}>,
     *     added: list<array{sql_shape: string, sql_shape_truncated?: true, executions: int}>,
     *     changed: list<array{sql_shape: string, sql_shape_truncated?: true, before: int, after: int}>,
     * }
     */
    public static function diff(array $before, array $after): array
    {
        $removed = [];
        $added = [];
        $changed = [];
        foreach ($before as $shape => $count) {
            if (!isset($after[$shape])) {
                $removed[] = [...self::truncateShape($shape), 'executions' => $count];
            } elseif ($after[$shape] !== $count) {
                $changed[] = [...self::truncateShape($shape), 'before' => $count, 'after' => $after[$shape]];
            }
        }
        foreach ($after as $shape => $count) {
            if (!isset($before[$shape])) {
                $added[] = [...self::truncateShape($shape), 'executions' => $count];
            }
        }

        return ['removed' => $removed, 'added' => $added, 'changed' => $changed];
    }
}
