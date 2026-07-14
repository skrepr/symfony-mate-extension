<?php

declare(strict_types=1);

namespace Skrepr\SymfonyMate;

trait JsonResponse
{
    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data): string
    {
        $json = json_encode(
            $data,
            \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        return false !== $json ? $json : '{}';
    }
}
