<?php

declare(strict_types=1);

namespace Skrepr\PerformanceMate\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Skrepr\PerformanceMate\ProfileReader;
use Skrepr\PerformanceMate\ProfileTooLargeException;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\HttpKernel\Profiler\Profile;

final class ProfileReaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir().'/mate-profiler-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->dir = $dir;
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $item) {
            \assert($item instanceof \SplFileInfo);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dir);
    }

    #[DataProvider('provideParseBytes')]
    public function testParseBytes(string $value, int $expected): void
    {
        self::assertSame($expected, ProfileReader::parseBytes($value));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideParseBytes(): iterable
    {
        yield 'megabytes' => ['128M', 128 * 1024 * 1024];
        yield 'gigabytes' => ['1G', 1024 * 1024 * 1024];
        yield 'kilobytes lowercase' => ['512k', 512 * 1024];
        yield 'bare bytes' => ['134217728', 134217728];
        yield 'unlimited' => ['-1', -1];
        yield 'empty string' => ['', 0];
        yield 'with spaces' => [' 256M ', 256 * 1024 * 1024];
        // PHP's zend_ini_parse_quantity stops the integer parse at the dot and
        // thus turns '0.5G' into 0; parseBytes deliberately mirrors that behavior.
        yield 'fractional follows PHP semantics' => ['0.5G', 0];
    }

    /**
     * Pins the path scheme of profileFilePath() against a profile actually
     * written by FileProfilerStorage: with maxProfileBytes=1, read() may only
     * throw ProfileTooLargeException if the guard FINDS the file. If Symfony
     * changes the scheme, this test fails — that is the signal to update
     * ProfileReader::profileFilePath().
     */
    public function testGuardFindsTheFileWrittenByFileProfilerStorage(): void
    {
        $this->writeProfile('abcdef');

        $reader = new ProfileReader($this->dir, maxProfileBytes: 1);

        $this->expectException(ProfileTooLargeException::class);
        $reader->read('abcdef');
    }

    public function testReadReturnsStructuredProfile(): void
    {
        $this->writeProfile('abcdef');

        $profile = (new ProfileReader($this->dir))->read('abcdef');

        self::assertNotNull($profile);
        self::assertSame('abcdef', $profile['token']);
        self::assertSame('GET', $profile['method']);
        self::assertSame('http://localhost/test', $profile['url']);
        self::assertSame(200, $profile['status_code']);
        self::assertSame(0, $profile['query_count']);
        self::assertSame([], $profile['queries']);
    }

    public function testReadCachesTheDistilledProfile(): void
    {
        $this->writeProfile('abcdef');
        $reader = new ProfileReader($this->dir);

        $first = $reader->read('abcdef');
        self::assertNotNull($first);

        // Delete everything on disk: a second read can only succeed if it
        // comes from the in-process cache.
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $item) {
            \assert($item instanceof \SplFileInfo);
            if ($item->isFile()) {
                unlink($item->getPathname());
            }
        }

        self::assertSame($first, $reader->read('abcdef'));
        // A fresh reader without a cache no longer finds the profile.
        self::assertNull((new ProfileReader($this->dir))->read('abcdef'));
    }

    public function testReadReturnsNullForUnknownToken(): void
    {
        self::assertNull((new ProfileReader($this->dir))->read('doesnotexist'));
    }

    public function testFindRecentAndLatestToken(): void
    {
        $this->writeProfile('token1', 'http://localhost/a');
        $this->writeProfile('token2', 'http://localhost/b');

        $reader = new ProfileReader($this->dir);

        $recent = $reader->findRecent(10);
        self::assertCount(2, $recent);

        self::assertSame('token2', $reader->latestToken('/b'));
        self::assertNull($reader->latestToken('/does-not-exist'));
    }

    private function writeProfile(string $token, string $url = 'http://localhost/test'): void
    {
        $profile = new Profile($token);
        $profile->setMethod('GET');
        $profile->setUrl($url);
        $profile->setStatusCode(200);
        $profile->setTime(time());

        $storage = new FileProfilerStorage('file:'.$this->dir);
        self::assertTrue($storage->write($profile));
    }
}
