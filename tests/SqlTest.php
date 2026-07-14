<?php

declare(strict_types=1);

namespace Skrepr\PerformanceMate\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Skrepr\PerformanceMate\Sql;

final class SqlTest extends TestCase
{
    #[DataProvider('provideNormalize')]
    public function testNormalize(string $sql, string $expected): void
    {
        self::assertSame($expected, Sql::normalize($sql));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideNormalize(): iterable
    {
        yield 'whitespace collapses' => [
            "SELECT *\n  FROM t\t WHERE a = b",
            'SELECT * FROM t WHERE a = b',
        ];
        yield 'string literal becomes ?' => [
            "SELECT * FROM t WHERE name = 'jacob'",
            'SELECT * FROM t WHERE name = ?',
        ];
        yield 'escaped quote inside literal' => [
            "SELECT * FROM t WHERE name = 'it\\'s'",
            'SELECT * FROM t WHERE name = ?',
        ];
        yield 'numbers become ?' => [
            'SELECT * FROM t WHERE id = 42 AND score > 3.14',
            'SELECT * FROM t WHERE id = ? AND score > ?',
        ];
        yield 'digit in identifier stays' => [
            'SELECT col1 FROM t1',
            'SELECT col1 FROM t1',
        ];
        yield 'IN list with values collapses' => [
            'SELECT * FROM t WHERE id IN (1, 2, 3)',
            'SELECT * FROM t WHERE id IN (?)',
        ];
        yield 'IN list with placeholders collapses' => [
            'SELECT * FROM t WHERE id IN (?, ?, ?)',
            'SELECT * FROM t WHERE id IN (?)',
        ];
        yield 'semantically equal queries get the same shape' => [
            "SELECT * FROM users WHERE email = 'a@example.com'   AND age > 30",
            'SELECT * FROM users WHERE email = ? AND age > ?',
        ];
        yield 'doubled-quote escaping inside literal' => [
            "SELECT * FROM t WHERE name = 'it''s'",
            'SELECT * FROM t WHERE name = ?',
        ];
        yield 'dollar-quoted literal (PostgreSQL)' => [
            'SELECT * FROM t WHERE body = $$text; with ' . "'odd'" . ' content$$',
            'SELECT * FROM t WHERE body = ?',
        ];
        yield 'dollar-quoted literal with tag' => [
            'SELECT * FROM t WHERE body = $fn$SELECT 1;$fn$',
            'SELECT * FROM t WHERE body = ?',
        ];
        yield 'double-quoted identifier stays' => [
            'SELECT "name" FROM "users" WHERE id = 7',
            'SELECT "name" FROM "users" WHERE id = ?',
        ];
    }

    #[DataProvider('provideHasMultipleStatements')]
    public function testHasMultipleStatements(string $sql, bool $expected): void
    {
        self::assertSame($expected, Sql::hasMultipleStatements($sql));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideHasMultipleStatements(): iterable
    {
        yield 'one statement' => ['SELECT 1', false];
        yield 'trailing semicolon is ok' => ['SELECT 1;', false];
        yield 'double trailing semicolon is ok' => ['SELECT 1;;  ', false];
        yield 'semicolon in string literal is ok' => ["SELECT ';' FROM t WHERE a = 'x;y'", false];
        yield 'semicolon in escaped literal is ok' => ["SELECT * FROM t WHERE a = 'it\\'s; fine'", false];
        yield 'semicolon in double-quoted identifier is ok' => ['SELECT "col;odd" FROM t', false];
        yield 'semicolon in backtick identifier is ok' => ['SELECT `col;odd` FROM t', false];
        yield 'semicolon in line comment is ok' => ["SELECT 1 -- ; no second statement\nFROM t", false];
        yield 'semicolon in block comment is ok' => ['SELECT 1 /* ; */ FROM t', false];
        yield 'two statements' => ['SELECT 1; DELETE FROM users', true];
        yield 'two statements with terminator' => ['SELECT 1; SELECT 2;', true];
        yield 'injection after literal' => ["SELECT * FROM t WHERE a = 'x'; DROP TABLE t", true];
    }

    public function testTopFrameReturnsFirstProjectFrame(): void
    {
        $frames = [
            ['file' => '/app/src/Repo.php', 'line' => 10, 'call' => 'Repo::find'],
            ['file' => '/app/src/Controller.php', 'line' => 20, 'call' => 'Controller::show'],
        ];

        self::assertSame($frames[0], Sql::topFrame($frames));
        self::assertNull(Sql::topFrame([]));
    }

    public function testFormatFrame(): void
    {
        self::assertSame(
            '/app/src/Repo.php:10 (Repo::find)',
            Sql::formatFrame(['file' => '/app/src/Repo.php', 'line' => 10, 'call' => 'Repo::find']),
        );
        self::assertSame(
            '/app/src/Repo.php:10',
            Sql::formatFrame(['file' => '/app/src/Repo.php', 'line' => 10, 'call' => '']),
        );
    }

    public function testFrameChainFormatsAllFrames(): void
    {
        $frames = [
            ['file' => '/app/src/Repo.php', 'line' => 10, 'call' => 'Repo::find'],
            ['file' => '/app/src/Controller.php', 'line' => 20, 'call' => 'Controller::show'],
        ];

        self::assertSame(
            ['/app/src/Repo.php:10 (Repo::find)', '/app/src/Controller.php:20 (Controller::show)'],
            Sql::frameChain($frames),
        );
    }

    public function testSourceContextReadsSnippetAroundLine(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'mate-test-');
        self::assertNotFalse($file);
        file_put_contents($file, implode("\n", ['line 1', 'line 2', 'line 3', 'line 4', 'line 5']));

        try {
            $context = Sql::sourceContext(['file' => $file, 'line' => 3, 'call' => ''], 1);

            self::assertNotNull($context);
            self::assertSame($file, $context['file']);
            self::assertSame(3, $context['line']);
            self::assertSame([
                ['line' => 2, 'code' => 'line 2'],
                ['line' => 3, 'code' => 'line 3', 'origin' => true],
                ['line' => 4, 'code' => 'line 4'],
            ], $context['snippet']);
        } finally {
            unlink($file);
        }
    }

    public function testOriginFieldsWithFrame(): void
    {
        $frame = ['file' => '/app/src/Repo.php', 'line' => 10, 'call' => 'Repo::find'];
        $chain = ['/app/src/Repo.php:10 (Repo::find)', '/app/src/Controller.php:20 (Controller::show)'];

        $fields = Sql::originFields($frame, $chain);

        self::assertSame('/app/src/Repo.php:10 (Repo::find)', $fields['origin']);
        self::assertSame($chain, $fields['origin_chain']);
        // The frame's file does not exist here, so no source context.
        self::assertNull($fields['origin_context']);
    }

    public function testOriginFieldsChainOfOneAddsNothingBeyondOrigin(): void
    {
        $frame = ['file' => '/app/src/Repo.php', 'line' => 10, 'call' => 'Repo::find'];

        self::assertNull(Sql::originFields($frame, ['/app/src/Repo.php:10 (Repo::find)'])['origin_chain']);
    }

    public function testOriginFieldsWithoutFrameFallsBackToHint(): void
    {
        $fields = Sql::originFields(null, []);

        self::assertStringContainsString('profiling_collect_backtrace', $fields['origin']);
        self::assertNull($fields['origin_chain']);
        self::assertNull($fields['origin_context']);
    }

    public function testSourceContextReturnsNullForMissingFileOrInvalidLine(): void
    {
        self::assertNull(Sql::sourceContext(null));
        self::assertNull(Sql::sourceContext(['file' => '/does/not/exist.php', 'line' => 1, 'call' => '']));
        self::assertNull(Sql::sourceContext(['file' => __FILE__, 'line' => null, 'call' => '']));
        self::assertNull(Sql::sourceContext(['file' => __FILE__, 'line' => 1000000, 'call' => '']));
    }
}
