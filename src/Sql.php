<?php

declare(strict_types=1);

namespace Skrepr\SymfonyRuntimeMate;

/**
 * Pure SQL-/backtrace-helpers.
 *
 * @phpstan-type Frame array{file: string, line: int|null, call: string}
 */
final class Sql
{
    /** SQL normaliseren zodat identieke query-shapes samenvallen. */
    public static function normalize(string $sql): string
    {
        $s = preg_replace('/\s+/', ' ', $sql) ?? $sql;
        $s = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", '?', $s) ?? $s;   // string-literals
        $s = preg_replace('/\b\d+(\.\d+)?\b/', '?', $s) ?? $s;          // getallen
        $s = preg_replace('/IN\s*\(\s*(?:\?\s*,?\s*)+\)/i', 'IN (?)', $s) ?? $s;

        return trim($s);
    }

    /**
     * Bovenste project-frame (innermost) — waar de query vandaan komt.
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
     * Volledige project-frame-keten (innermost eerst). Bij een write toont dit
     * de weg naar flush(), zodat de agent de persist-locatie ziet.
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
     * Leest de veroorzakende regel + omliggende context uit de projectbron. De
     * tool draait in de container, dus de absolute frame-paden zijn direct leesbaar.
     *
     * @param Frame|null $frame
     *
     * @return array{file: string, line: int, snippet: list<array{line: int, code: string, origin?: true}>}|null
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
