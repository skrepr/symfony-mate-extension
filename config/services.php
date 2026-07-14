<?php

declare(strict_types=1);

/*
 * DI-configuratie van de extensie. Geladen door AI Mate (via extra.ai-mate.includes)
 * in Mate's eigen container — Mate boot de app-kernel NIET.
 *
 * De tool-classes MOETEN public zijn: de MCP-SDK resolvet ze via
 * $container->has(FQCN), en privé services zijn daar onzichtbaar.
 */

use Skrepr\SymfonyMate\ExplainTool;
use Skrepr\SymfonyMate\NPlusOneTool;
use Skrepr\SymfonyMate\ProfileDiffTool;
use Skrepr\SymfonyMate\ProfileReader;
use Skrepr\SymfonyMate\RequestBreakdownTool;
use Skrepr\SymfonyMate\SlowQueriesTool;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $container->parameters()
        // Map waar de profielen staan. Default = dezelfde locatie als de Symfony-bridge.
        ->set('skrepr_mate.profiler_dir', '%mate.root_dir%/var/cache/dev/profiler')
        // DSN voor explain_query. Leeg = val terug op de DATABASE_URL uit de omgeving
        // (zet dan mate.env_file in je app-config). Overschrijf voor een andere DB:
        //   ->set('skrepr_mate.database_url', '%env(resolve:MIJN_DB_URL)%')
        ->set('skrepr_mate.database_url', '')
        // Profielen groter dan dit (bytes op schijf) worden overgeslagen i.p.v.
        // ge-unserialized: Symfony leest een profiel in één keer in (±100x de
        // bestandsgrootte aan RAM), dus een uitschieter — meestal een 500 met een
        // volledige exception-dump — zou het proces met een OOM omleggen.
        ->set('skrepr_mate.max_profile_bytes', ProfileReader::DEFAULT_MAX_PROFILE_BYTES)
    ;

    $services = $container->services();
    $services
        ->defaults()
            ->autowire()
            ->autoconfigure()
    ;

    $services->set(ProfileReader::class)
        ->args(['%skrepr_mate.profiler_dir%', '%skrepr_mate.max_profile_bytes%'])
    ;

    foreach ([SlowQueriesTool::class, NPlusOneTool::class, ProfileDiffTool::class, RequestBreakdownTool::class] as $tool) {
        $services->set($tool)
            ->args([service(ProfileReader::class)])
            ->public()
        ;
    }

    $services->set(ExplainTool::class)
        ->args(['%skrepr_mate.database_url%'])
        ->public()
    ;
};
