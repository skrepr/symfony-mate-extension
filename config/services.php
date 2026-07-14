<?php

declare(strict_types=1);

/*
 * DI configuration of the extension. Loaded by AI Mate (via extra.ai-mate.includes)
 * into Mate's own container — Mate does NOT boot the app kernel.
 *
 * The tool classes MUST be public: the MCP SDK resolves them via
 * $container->has(FQCN), and private services are invisible there.
 */

use Skrepr\PerformanceMate\ExplainTool;
use Skrepr\PerformanceMate\NPlusOneTool;
use Skrepr\PerformanceMate\ProfileDiffTool;
use Skrepr\PerformanceMate\ProfileReader;
use Skrepr\PerformanceMate\RequestBreakdownTool;
use Skrepr\PerformanceMate\SlowQueriesTool;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $container->parameters()
        // Directory where the profiles live. Default = the same location as the Symfony bridge.
        ->set('skrepr_mate.profiler_dir', '%mate.root_dir%/var/cache/dev/profiler')
        // DSN for explain_query. Empty = fall back to the DATABASE_URL from the
        // environment (set mate.env_file in your app config for that). Override
        // for a different DB:
        //   ->set('skrepr_mate.database_url', '%env(resolve:MY_DB_URL)%')
        ->set('skrepr_mate.database_url', '')
        // Profiles larger than this (bytes on disk) are skipped instead of
        // unserialized: Symfony reads a profile in one go (±100x the file size
        // in RAM), so an outlier — usually a 500 with a full exception dump —
        // would take the process down with an OOM.
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
