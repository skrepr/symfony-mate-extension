<?php

declare(strict_types=1);

namespace Skrepr\SymfonyMate;

use Mcp\Capability\Attribute\McpTool;

/**
 * @phpstan-import-type Frame from Sql
 */
final class SlowQueriesTool
{
    use JsonResponse;

    public function __construct(
        private readonly ProfileReader $reader,
    ) {
    }

    /**
     * @param int         $requests  Aantal recente requests om te analyseren (1-50)
     * @param int         $top       Aantal traagste query-shapes om terug te geven
     * @param string|null $urlFilter Substring-filter op de URL, bv. '/checkout'
     */
    #[McpTool(
        name: 'slow_queries',
        title: 'Slow Queries',
        description: 'Traagste query-shapes over de laatste N requests, gegroepeerd op genormaliseerde SQL, met totale tijd, aantal uitvoeringen en de veroorzakende regel projectcode. Let op de aard van origin: bij SELECTs wijst die naar de echte trigger (actionable); bij INSERT/UPDATE/COMMIT wijst origin meestal naar de flush()-regel — kijk dan naar de persist-/business-logica, niet naar die regel zelf.',
    )]
    public function slowQueries(int $requests = 15, int $top = 10, ?string $urlFilter = null): string
    {
        $requests = max(1, min(50, $requests));
        $metas = $this->reader->findRecent($requests, $urlFilter ?? '');

        /** @var array<string, array{total_ms: float, count: int, max_ms: float, sample_sql: string, originFrame: Frame|null, chain: list<string>, seen_on: array<string, true>}> $groups */
        $groups = [];
        $analyzed = 0;
        foreach ($metas as $meta) {
            $profile = $this->reader->read($meta['token']);
            if (null === $profile) {
                continue;
            }
            ++$analyzed;
            foreach ($profile['queries'] as $q) {
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
                $g['seen_on']["{$profile['method']} {$profile['url']}"] = true;
                $groups[$key] = $g;
            }
        }

        uasort($groups, static fn ($a, $b) => $b['total_ms'] <=> $a['total_ms']);

        $ranked = [];
        foreach (\array_slice($groups, 0, max(1, $top), true) as $shape => $g) {
            $ranked[] = [
                'sql_shape' => $shape,
                'total_ms' => round($g['total_ms'], 1),
                'executions' => $g['count'],
                'avg_ms' => round($g['total_ms'] / $g['count'], 2),
                'max_ms' => $g['max_ms'],
                'origin' => null !== $g['originFrame']
                    ? Sql::formatFrame($g['originFrame'])
                    : 'onbekend — zet doctrine.dbal.profiling_collect_backtrace: true in config/packages/dev/doctrine.yaml',
                'origin_chain' => \count($g['chain']) > 1 ? $g['chain'] : null,
                'origin_context' => Sql::sourceContext($g['originFrame']),
                'seen_on' => \array_slice(array_keys($g['seen_on']), 0, 5),
            ];
        }

        return $this->json(['analyzed_requests' => $analyzed, 'slow_queries' => $ranked]);
    }
}
