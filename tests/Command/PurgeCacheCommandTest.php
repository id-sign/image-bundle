<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Tests\Command;

use IdSign\ImageBundle\Cache\LocalFilesystemCacheStorage;
use IdSign\ImageBundle\Command\PurgeCacheCommand;
use PHPUnit\Framework\TestCase;
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

    public function testDryRunCountsNormalizedSource(): void
    {
        $this->tester->execute(['src' => '@default/uploads/photo.jpg']);

        self::assertDirectoryExists($this->cacheDir.'/uploads/photo.jpg');
        self::assertStringContainsString('Would delete 2 cached file(s) for "uploads/photo.jpg"', $this->tester->getDisplay());
    }
}
