<?php

declare(strict_types=1);

namespace Skrepr\PerformanceMate;

/**
 * Pure SQL/backtrace helpers.
 *
 * @phpstan-type Frame array{file: string, line: int|null, call: string}
 * @phpstan-type SourceContext array{file: string, line: int, snippet: list<array{line: int, code: string, origin?: true}>}
 */
final class Sql
{
    /**
     * Normalize SQL so that identical query shapes coincide. Double-quoted
     * strings are deliberately left alone: those are standard SQL identifiers,
     * and replacing them would incorrectly collapse different queries.
     */
    public static function normalize(string $sql): string
    {
        $s = preg_replace('/\$(\w*)\$.*?\$\1\$/s', '?', $sql) ?? $sql;  // dollar-quoted literals (PostgreSQL)
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        $s = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/", '?', $s) ?? $s; // string literals (with \' and '' escapes)
        $s = preg_replace('/\b\d+(\.\d+)?\b/', '?', $s) ?? $s;          // numbers
        $s = preg_replace('/IN\s*\(\s*(?:\?\s*,?\s*)+\)/i', 'IN (?)', $s) ?? $s;

        return trim($s);
    }

    /**
     * True when the string contains more than one SQL statement. String literals,
     * quoted identifiers and comments are stripped first so that a ';' inside
     * them does not count; trailing ';'s do not count either.
     */
    public static function hasMultipleStatements(string $sql): bool
    {
        $stripped = preg_replace(
            [
                "/'(?:[^'\\\\]|\\\\.|'')*'/s",  // single-quoted literals (with \' and '' escapes)
                '/"(?:[^"\\\\]|\\\\.)*"/s',      // double-quoted literals/identifiers
                '/`[^`]*`/s',                     // backtick identifiers (MySQL)
                '/--[^\r\n]*/',                   // line comments
                '/#[^\r\n]*/',                    // line comments (MySQL)
                '~/\*.*?\*/~s',                   // block comments
            ],
            ' ',
            $sql,
        );
        if (null === $stripped) {
            return true; // regex failure: refuse safely
        }

        return str_contains(rtrim($stripped, "; \t\n\r"), ';');
    }

    /**
     * Topmost project frame (innermost) — where the query comes from.
     *
     * @param list<Frame> $backtrace
     *
     * @return Frame|null
     */
    public static function topFrame(array $backtrace): ?array
    {
        foreach ($backtrace as $f) {
            if ('' !== $f['file']) {
                return $f;
            }
        }

        return null;
    }

    /** @param Frame $frame */
    public static function formatFrame(array $frame): string
    {
        $call = '' !== $frame['call'] ? " ({$frame['call']})" : '';

        return "{$frame['file']}:{$frame['line']}{$call}";
    }

    /**
     * Full project frame chain (innermost first). For a write this shows the
     * path to flush(), so the agent can see the persist location.
     *
     * @param list<Frame> $backtrace
     *
     * @return list<string>
     */
    public static function frameChain(array $backtrace): array
    {
        $out = [];
        foreach ($backtrace as $f) {
            if ('' !== $f['file']) {
                $out[] = self::formatFrame($f);
            }
        }

        return $out;
    }

    /**
     * The three origin fields for tool output, with a single consistent,
     * actionable fallback when no backtrace is available. origin_chain is only
     * filled when it tells more than origin itself (more than one frame).
     *
     * @param Frame|null   $frame
     * @param list<string> $chain
     *
     * @return array{origin: string, origin_chain: list<string>|null, origin_context: SourceContext|null}
     */
    public static function originFields(?array $frame, array $chain): array
    {
        return [
            'origin' => null !== $frame
                ? self::formatFrame($frame)
                : 'unknown — set doctrine.dbal.profiling_collect_backtrace: true in config/packages/dev/doctrine.yaml',
            'origin_chain' => \count($chain) > 1 ? $chain : null,
            'origin_context' => self::sourceContext($frame),
        ];
    }

    /**
     * Reads the offending line + surrounding context from the project source. The
     * tool runs inside the container, so the absolute frame paths are directly readable.
     *
     * @param Frame|null $frame
     *
     * @return SourceContext|null
     */
    public static function sourceContext(?array $frame, int $radius = 3): ?array
    {
        if (null === $frame || '' === $frame['file'] || null === $frame['line']) {
            return null;
        }
        $file = $frame['file'];
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        $lines = @file($file, \FILE_IGNORE_NEW_LINES);
        if (false === $lines) {
            return null;
        }
        $idx = $frame['line'] - 1;
        if ($idx < 0 || $idx >= \count($lines)) {
            return null;
        }
        $start = max(0, $idx - $radius);
        $end = min(\count($lines) - 1, $idx + $radius);
        $snippet = [];
        for ($i = $start; $i <= $end; ++$i) {
            $row = ['line' => $i + 1, 'code' => $lines[$i]];
            if ($i === $idx) {
                $row['origin'] = true;
            }
            $snippet[] = $row;
        }

        return ['file' => $file, 'line' => $frame['line'], 'snippet' => $snippet];
    }
}
