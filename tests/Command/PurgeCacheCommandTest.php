<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Tests\Command;

use IdSign\ImageBundle\Cache\LocalFilesystemCacheStorage;
use IdSign\ImageBundle\Command\PurgeCacheCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class PurgeCacheCommandTest extends TestCase
{
    private string $cacheDir;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir().'/id_sign_image_purge_test_'.uniqid();

        foreach (['uploads/photo.jpg', '@reference/logo.png'] as $src) {
            mkdir($this->cacheDir.'/'.$src, 0o775, true);
            touch($this->cacheDir.'/'.$src.'/aa00bb11cc22dd33_64_auto_none_80.webp');
            touch($this->cacheDir.'/'.$src.'/meta.json');
        }

        $this->tester = new CommandTester(new PurgeCacheCommand(
            new LocalFilesystemCacheStorage($this->cacheDir, 3600, 0o660, 0o770),
            $this->cacheDir,
        ));
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->cacheDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->cacheDir);
    }

    public function testPurgeBySourceDeletesTheSourceDirectory(): void
    {
        $this->tester->execute(['src' => 'uploads/photo.jpg', '--force' => true]);

        self::assertDirectoryDoesNotExist($this->cacheDir.'/uploads/photo.jpg');
        self::assertDirectoryExists($this->cacheDir.'/@reference/logo.png');
        self::assertStringContainsString('Deleted 2 cached file(s) for "uploads/photo.jpg"', $this->tester->getDisplay());
    }

    public function testDefaultPrefixPurgesTheSameDirectoryAsNoPrefix(): void
    {
        $this->tester->execute(['src' => '@default/uploads/photo.jpg', '--force' => true]);

        self::assertDirectoryDoesNotExist($this->cacheDir.'/uploads/photo.jpg');
        self::assertDirectoryDoesNotExist($this->cacheDir.'/@default');
        self::assertStringContainsString('for "uploads/photo.jpg"', $this->tester->getDisplay());
    }

    public function testNamedSourcePrefixPurgesItsOwnDirectory(): void
    {
        $this->tester->execute(['src' => '@reference/logo.png', '--force' => true]);

        self::assertDirectoryDoesNotExist($this->cacheDir.'/@reference/logo.png');
        self::assertDirectoryExists($this->cacheDir.'/uploads/photo.jpg');
    }

    public function testWholeSourcePrefixIsPurgedRecursively(): void
    {
        $this->tester->execute(['src' => '@reference', '--force' => true]);

        self::assertDirectoryDoesNotExist($this->cacheDir.'/@reference');
        self::assertDirectoryExists($this->cacheDir.'/uploads/photo.jpg');
        self::assertStringContainsString('Deleted 2 cached file(s) for "@reference"', $this->tester->getDisplay());
    }

    public function testDryRunCountsWholeSourcePrefixRecursively(): void
    {
        $this->tester->execute(['src' => '@reference']);

        self::assertDirectoryExists($this->cacheDir.'/@reference/logo.png');
        self::assertStringContainsString('Would delete 2 cached file(s) for "@reference"', $this->tester->getDisplay());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSourcePaths(): iterable
    {
        yield 'bare default prefix normalizes to an empty path' => ['@default/'];
        yield 'current directory' => ['.'];
        yield 'parent directory' => ['../..'];
        yield 'empty segment' => ['uploads//photo.jpg'];
    }

    #[DataProvider('invalidSourcePaths')]
    public function testInvalidSourcePathIsRejectedBeforeCountingOrDeleting(string $src): void
    {
        $dryRun = $this->tester->execute(['src' => $src]);
        self::assertSame(Command::INVALID, $dryRun);
        self::assertStringContainsString('Invalid source path', $this->tester->getDisplay());

        $force = $this->tester->execute(['src' => $src, '--force' => true]);
        self::assertSame(Command::INVALID, $force);
        self::assertFileExists($this->cacheDir.'/uploads/photo.jpg/meta.json');
        self::assertFileExists($this->cacheDir.'/@reference/logo.png/meta.json');
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function invalidDays(): iterable
    {
        yield 'word' => [['--modified-before' => 'thirty']];
        yield 'negative' => [['--accessed-before' => '-5']];
        yield 'fraction' => [['--modified-before' => '1.5']];
    }

    /**
     * (int) "thirty" is 0: every file older than now, i.e. the whole cache, would have gone.
     *
     * @param array<string, string> $options
     */
    #[DataProvider('invalidDays')]
    public function testNonNumericDaysAreRejectedBeforeDeleting(array $options): void
    {
        $exitCode = $this->tester->execute($options + ['--force' => true]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('takes a number of days', $this->tester->getDisplay());
        self::assertFileExists($this->cacheDir.'/uploads/photo.jpg/aa00bb11cc22dd33_64_auto_none_80.webp');
    }

    public function testPurgeByAgeRemovesTheDirectoriesItEmptied(): void
    {
        $old = time() - 10 * 86400;
        foreach (['aa00bb11cc22dd33_64_auto_none_80.webp', 'meta.json'] as $name) {
            touch($this->cacheDir.'/uploads/photo.jpg/'.$name, $old);
        }

        $this->tester->execute(['--modified-before' => '5', '--force' => true]);

        self::assertDirectoryDoesNotExist($this->cacheDir.'/uploads', 'photo.jpg/ and then uploads/ were emptied');
        self::assertDirectoryExists($this->cacheDir.'/@reference/logo.png', 'its files are newer');
    }

    public function testDryRunCountsNormalizedSource(): void
    {
        $this->tester->execute(['src' => '@default/uploads/photo.jpg']);

        self::assertDirectoryExists($this->cacheDir.'/uploads/photo.jpg');
        self::assertStringContainsString('Would delete 2 cached file(s) for "uploads/photo.jpg"', $this->tester->getDisplay());
    }
}
