<?php

declare(strict_types=1);

namespace Skrepr\PerformanceMate;

use Mcp\Capability\Attribute\McpTool;

final class NPlusOneTool
{
    use ResolvesProfile;

    public function __construct(
        private readonly ProfileReader $reader,
    ) {
    }

    /**
     * @param string|null $token     Profiler token; empty = most recent (optionally via urlFilter)
     * @param string|null $urlFilter Substring filter on the URL to pick the most recent matching request
     * @param int         $threshold Minimum number of repetitions to report
     */
    #[McpTool(
        name: 'detect_n_plus_one',
        title: 'Detect N+1',
        description: 'Detects N+1 patterns within a single request: identical query shapes executed repeatedly, with the originating line of code and the likely parent query (1+N). Provide a token, or a urlFilter to pick the most recent matching request. Note: sample_sql contains the actual parameter values from the request (useful as input for explain_query, but potentially sensitive).',
    )]
    public function detectNPlusOne(?string $token = null, ?string $urlFilter = null, int $threshold = 5): string
    {
        $threshold = max(2, $threshold);
        $profile = $this->resolveProfile($token, $urlFilter);
        if (\is_string($profile)) {
            return $profile;
        }
        $queries = $profile['queries'];

        $groups = QueryShapes::groupByShapeAndOrigin($queries);

        $out = [];
        foreach (QueryShapes::nPlusOneSuspects($groups, $queries, $threshold) as $s) {
            $out[] = [
                'executions' => $s['executions'],
                'total_ms' => $s['total_ms'],
                'sample_sql' => $s['sample_sql'],
                ...(($s['sample_sql_truncated'] ?? false) ? ['sample_sql_truncated' => true] : []),
                ...Sql::originFields($s['originFrame'], $s['chain']),
                'likely_parent' => $s['likely_parent'],
                'hint' => 'Consider a JOIN/fetch join, batch loading or fetch: EAGER for this relation.',
            ];
        }

        return $this->json(['request' => ProfileReader::summarize($profile), 'n_plus_one_suspects' => $out]);
    }
}
