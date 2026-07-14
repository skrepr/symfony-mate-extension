<?php

declare(strict_types=1);

namespace Skrepr\PerformanceMate;

use Mcp\Capability\Attribute\McpTool;

final class ProfileDiffTool
{
    use ResolvesProfile;

    public function __construct(
        private readonly ProfileReader $reader,
    ) {
    }

    /**
     * @param string|null $tokenBefore Token of the 'before' profile; empty = second most recent for urlFilter
     * @param string|null $tokenAfter  Token of the 'after' profile; empty = most recent for urlFilter
     * @param string|null $urlFilter   Substring filter on the URL to automatically compare the two most recent requests
     */
    #[McpTool(
        name: 'profile_diff',
        title: 'Profile Diff',
        description: 'Compares two profiles of the same endpoint (before/after a code change): duration, memory, query count and which query shapes disappeared or appeared. Provide two tokens, or a urlFilter to automatically compare the two most recent requests.',
    )]
    public function profileDiff(?string $tokenBefore = null, ?string $tokenAfter = null, ?string $urlFilter = null): string
    {
        $before = null !== $tokenBefore && '' !== $tokenBefore ? $tokenBefore : null;
        $after = null !== $tokenAfter && '' !== $tokenAfter ? $tokenAfter : null;
        if (null === $before || null === $after) {
            $metas = $this->reader->findRecent(2, $urlFilter ?? '');
            if (\count($metas) < 2) {
                return $this->json(['error' => 'At least two requests to this endpoint are needed to compare.']);
            }
            $after ??= $metas[0]['token'];   // most recent
            $before ??= $metas[1]['token'];  // one earlier
        }

        $pBefore = $this->resolveProfile($before, null);
        if (\is_string($pBefore)) {
            return $pBefore;
        }
        $pAfter = $this->resolveProfile($after, null);
        if (\is_string($pAfter)) {
            return $pAfter;
        }

        $diff = QueryShapes::diff(
            QueryShapes::countByShape($pBefore['queries']),
            QueryShapes::countByShape($pAfter['queries']),
        );

        return $this->json([
            'before' => ProfileReader::summarize($pBefore),
            'after' => ProfileReader::summarize($pAfter),
            'delta' => [
                'duration_ms' => $this->delta($pBefore['duration_ms'], $pAfter['duration_ms']),
                'memory_mb' => $this->delta($pBefore['memory_mb'], $pAfter['memory_mb']),
                'query_count' => $this->delta((float) $pBefore['query_count'], (float) $pAfter['query_count']),
                'query_time_ms' => $this->delta($pBefore['query_time_ms'], $pAfter['query_time_ms']),
            ],
            'queries_removed' => $diff['removed'],
            'queries_added' => $diff['added'],
            'queries_changed' => $diff['changed'],
        ]);
    }

    private function delta(?float $a, ?float $b): ?float
    {
        return null !== $a && null !== $b ? round($b - $a, 1) : null;
    }
}
