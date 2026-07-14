<?php

declare(strict_types=1);

namespace Skrepr\PerformanceMate;

trait JsonResponse
{
    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data): string
    {
        $flags = \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR;
        $json = json_encode($data, $flags);

        // JSON_PARTIAL_OUTPUT_ON_ERROR lets encoding degrade silently (values
        // replaced or dropped); make that visible to the agent.
        if (\JSON_ERROR_NONE !== json_last_error()) {
            $data['_encoding_warning'] = 'Part of the data could not be encoded as JSON and has been replaced or dropped ('.json_last_error_msg().').';
            $json = json_encode($data, $flags);
        }

        return false !== $json ? $json : '{"_encoding_warning": "json_encode failed entirely"}';
    }
}
