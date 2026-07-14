<?php

declare(strict_types=1);

namespace Skrepr\SymfonyMate;

use Mcp\Capability\Attribute\McpTool;

/**
 * @phpstan-import-type Frame from Sql
 */
final class NPlusOneTool
{
    use JsonResponse;

    public function __construct(
        private readonly ProfileReader $reader,
    ) {
    }

    /**
     * @param string|null $token     Profiler-token; leeg = recentste (evt. via urlFilter)
     * @param string|null $urlFilter Substring-filter op de URL om het recentste passende request te pakken
     * @param int         $threshold Minimaal aantal herhalingen om te rapporteren
     */
    #[McpTool(
        name: 'detect_n_plus_one',
        title: 'Detect N+1',
        description: 'Detecteert N+1-patronen binnen één request: identieke query-shapes die herhaald worden uitgevoerd, met de veroorzakende regel code en de vermoedelijke parent-query (1+N). Geef een token, of een urlFilter om het recentste passende request te pakken.',
    )]
    public function detectNPlusOne(?string $token = null, ?string $urlFilter = null, int $threshold = 5): string
    {
        $threshold = max(2, $threshold);
        $token = $this->resolveToken($token, $urlFilter);
        if (null === $token) {
            return $this->json(['error' => 'Geen requests gevonden — doe eerst een request naar de app.']);
        }
        try {
            $profile = $this->reader->read($token);
        } catch (ProfileTooLargeException $e) {
            return $this->json(['error' => $e->getMessage()]);
        }
        if (null === $profile) {
            return $this->json(['error' => "Geen profiel gevonden voor token {$token}."]);
        }
        $queries = $profile['queries'];

        // Groeperen op (shape + origin): een echte N+1 is dezelfde query die vanuit
        // één regel in een lus herhaald wordt. Zonder backtrace is origin leeg en
        // valt dit terug op groeperen op shape.
        /** @var array<string, array{shape: string, count: int, total_ms: float, sample_sql: string, originFrame: Frame|null, chain: list<string>, firstIndex: int}> $groups */
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

        $suspects = array_filter($groups, static fn ($g) => $g['count'] >= $threshold);
        usort($suspects, static fn ($a, $b) => $b['count'] <=> $a['count']);

        $out = [];
        foreach ($suspects as $g) {
            // De query direct vóór de eerste herhaling is vaak de 'parent' waarvan
            // het resultaat wordt geïtereerd — de klassieke 1+N-signatuur.
            $likelyParent = null;
            if ($g['firstIndex'] > 0) {
                $parent = $queries[$g['firstIndex'] - 1];
                $parentShape = Sql::normalize($parent['sql']);
                if ($parentShape !== $g['shape']) {
                    $pf = Sql::topFrame($parent['backtrace']);
                    $likelyParent = [
                        'sql_shape' => mb_substr($parentShape, 0, 300),
                        'origin' => null !== $pf ? Sql::formatFrame($pf) : null,
                    ];
                }
            }
            $out[] = [
                'executions' => $g['count'],
                'total_ms' => round($g['total_ms'], 1),
                'sample_sql' => mb_substr($g['sample_sql'], 0, 400),
                'origin' => null !== $g['originFrame']
                    ? Sql::formatFrame($g['originFrame'])
                    : 'onbekend — zet profiling_collect_backtrace aan voor bestand:regel',
                'origin_chain' => \count($g['chain']) > 1 ? $g['chain'] : null,
                'origin_context' => Sql::sourceContext($g['originFrame']),
                'likely_parent' => $likelyParent,
                'hint' => 'Overweeg een JOIN/fetch-join, batch-loading of fetch: EAGER voor deze relatie.',
            ];
        }

        return $this->json(['request' => ProfileReader::summarize($profile), 'n_plus_one_suspects' => $out]);
    }

    private function resolveToken(?string $token, ?string $urlFilter): ?string
    {
        if (null !== $token && '' !== $token) {
            return $token;
        }
        $metas = $this->reader->findRecent(1, $urlFilter ?? '');

        return $metas[0]['token'] ?? null;
    }
}
