<?php

declare(strict_types=1);

namespace Skrepr\PerformanceMate;

use Mcp\Capability\Attribute\McpTool;

final class RequestBreakdownTool
{
    use ResolvesProfile;

    public function __construct(
        private readonly ProfileReader $reader,
    ) {
    }

    /**
     * @param string|null $token     Profiler token; empty = most recent (optionally via urlFilter)
     * @param string|null $urlFilter Substring filter on the URL to pick the most recent matching request
     */
    #[McpTool(
        name: 'request_breakdown',
        title: 'Request Breakdown',
        description: 'Breaks down the wall clock of a single request per category (Doctrine/DB, external http_client calls, template rendering, event listeners, …) — so you can see where the time went, not just in the DB. Note: categories can nest (e.g. controller contains doctrine/template), so they do not necessarily add up to 100%.',
    )]
    public function requestBreakdown(?string $token = null, ?string $urlFilter = null): string
    {
        $p = $this->resolveProfile($token, $urlFilter);
        if (\is_string($p)) {
            return $p;
        }

        $total = $p['duration_ms'] ?? 0.0;
        $pct = static fn (float $ms): ?float => $total > 0 ? round(($ms / $total) * 100, 1) : null;

        $categories = [];
        foreach ($p['timeline_ms'] as $category => $ms) {
            $categories[] = ['category' => $category, 'ms' => $ms, 'pct_of_total' => $pct($ms)];
        }

        return $this->json([
            'request' => ProfileReader::summarize($p),
            'total_ms' => $total,
            'database' => [
                'query_count' => $p['query_count'],
                'query_time_ms' => $p['query_time_ms'],
                'pct_of_total' => $pct($p['query_time_ms']),
            ],
            'categories' => $categories,
            'note' => [] !== $categories
                ? "Categories can nest (e.g. 'controller' contains 'doctrine'/'template'); they do not necessarily add up to total_ms."
                : "No timeline available — is the 'time' collector running (enabled by default in dev with the profiler)?",
        ]);
    }
}
