<?php

declare(strict_types=1);

namespace Skrepr\PerformanceMate;

/**
 * Thrown when a profile file is larger than the safe read limit.
 *
 * Symfony unserializes a profile in one go and that costs ±100x the file size
 * in RAM (a 500 with a full exception dump is ~1 MB on disk and ~100 MB in
 * memory). A single outlier would otherwise take the server process down with
 * a fatal, uncatchable OOM. See ProfileReader::$maxProfileBytes.
 */
final class ProfileTooLargeException extends \RuntimeException
{
    public function __construct(
        public readonly string $token,
        public readonly int $bytes,
        public readonly int $maxBytes,
    ) {
        parent::__construct(sprintf(
            'Profile %s is %d bytes on disk and exceeds the safe read limit of %d bytes; skipped to prevent an out-of-memory. Raise skrepr_mate.max_profile_bytes if you still want to analyze this profile.',
            $token,
            $bytes,
            $maxBytes,
        ));
    }
}
