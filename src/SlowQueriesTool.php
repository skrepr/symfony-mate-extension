<?php

declare(strict_types=1);

namespace Skrepr\PerformanceMate;

use Mcp\Capability\Attribute\McpTool;

final class SlowQueriesTool
{
    use JsonResponse;

    public function __construct(
        private readonly ProfileReader $reader,
    ) {
    }

    /**
     * @param int         $requests  Number of recent requests to analyze (1-50)
     * @param int         $top       Number of slowest query shapes to return
     * @param string|null $urlFilter Substring filter on the URL, e.g. '/checkout'
     */
    #[McpTool(
        name: 'slow_queries',
        title: 'Slow Queries',
        description: 'Slowest query shapes over the last N requests, grouped by normalized SQL, with total time, execution count and the originating line of project code. Mind the nature of origin: for SELECTs it points to the real trigger (actionable); for INSERT/UPDATE/COMMIT origin usually points to the flush() line — look at the persist/business logic instead, not at that line itself.',
    )]
    public function slowQueries(int $requests = 15, int $top = 10, ?string $urlFilter = null): string
    {
        $requests = max(1, min(50, $requests));
        $metas = $this->reader->findRecent($requests, $urlFilter ?? '');

        $groups = [];
        $analyzed = 0;
        $skipped = [];
        foreach ($metas as $meta) {
            try {
                $profile = $this->reader->read($meta['token']);
            } catch (ProfileTooLargeException $e) {
                $skipped[] = ['token' => $e->token, 'bytes' => $e->bytes];
                continue;
            }
            if (null === $profile) {
                continue;
            }
            ++$analyzed;
            $groups = QueryShapes::accumulate($groups, $profile['queries'], "{$profile['method']} {$profile['url']}");
        }

        $ranked = [];
        foreach (QueryShapes::rank($groups, $top) as $g) {
            $ranked[] = [
                ...QueryShapes::truncateShape($g['sql_shape']),
                'total_ms' => $g['total_ms'],
                'executions' => $g['executions'],
                'avg_ms' => $g['avg_ms'],
                'max_ms' => $g['max_ms'],
                ...Sql::originFields($g['originFrame'], $g['chain']),
                'seen_on' => $g['seen_on'],
            ];
        }

        return $this->json([
            'analyzed_requests' => $analyzed,
            'skipped_too_large' => $skipped,
            'slow_queries' => $ranked,
        ]);
    }
}
