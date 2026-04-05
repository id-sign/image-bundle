<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Command;

use IdSign\ImageBundle\Cache\CacheStorageInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'image:purge',
    description: 'Purge cached optimized images',
)]
class PurgeCacheCommand extends Command
{
    public function __construct(
        private readonly CacheStorageInterface $cacheStorage,
        private readonly string $cacheDirectory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('src', InputArgument::OPTIONAL, 'Source image path to purge (e.g. uploads/photo.jpg)')
            ->addOption('modified-before', null, InputOption::VALUE_REQUIRED, 'Delete files not modified in N days')
            ->addOption('accessed-before', null, InputOption::VALUE_REQUIRED, 'Delete files not accessed in N days (on noatime filesystems falls back to mtime)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Actually delete files (without this flag, only shows what would be deleted)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string|null $src */
        $src = $input->getArgument('src');
        /** @var string|null $modifiedBefore */
        $modifiedBefore = $input->getOption('modified-before');
        /** @var string|null $accessedBefore */
        $accessedBefore = $input->getOption('accessed-before');
        $dryRun = !((bool) $input->getOption('force'));

        if (!is_dir($this->cacheDirectory)) {
            $io->success('Cache directory does not exist. Nothing to purge.');

            return Command::SUCCESS;
        }

        if (null !== $src) {
            return $this->purgeBySource($io, $src, $dryRun);
        }

        if (null !== $modifiedBefore || null !== $accessedBefore) {
            return $this->purgeByAge($io, $modifiedBefore, $accessedBefore, $dryRun);
        }

        return $this->purgeAll($io, $dryRun);
    }

    private function purgeBySource(SymfonyStyle $io, string $src, bool $dryRun): int
    {
        if ($dryRun) {
            $count = $this->countSourceFiles($this->cacheDirectory.'/'.$src);
            $io->success(\sprintf('Would delete %d cached file(s) for "%s".', $count, $src));
        } else {
            $count = $this->cacheStorage->deleteBySource($src);
            $io->success(\sprintf('Deleted %d cached file(s) for "%s".', $count, $src));
        }

        return Command::SUCCESS;
    }

    private function purgeByAge(SymfonyStyle $io, ?string $modifiedBefore, ?string $accessedBefore, bool $dryRun): int
    {
        $now = time();
        $mtimeThreshold = null !== $modifiedBefore ? $now - ((int) $modifiedBefore * 86400) : null;
        $atimeThreshold = null !== $accessedBefore ? $now - ((int) $accessedBefore * 86400) : null;

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->cacheDirectory, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();

            if (null !== $mtimeThreshold && filemtime($path) >= $mtimeThreshold) {
                continue;
            }

            if (null !== $atimeThreshold && fileatime($path) >= $atimeThreshold) {
                continue;
            }

            if ($dryRun) {
                $io->writeln($path);
            } else {
                unlink($path);
            }
            ++$count;
        }

        $action = $dryRun ? 'Would delete' : 'Deleted';
        $io->success(\sprintf('%s %d cached file(s).', $action, $count));

        return Command::SUCCESS;
    }

    private function purgeAll(SymfonyStyle $io, bool $dryRun): int
    {
        if ($dryRun) {
            $count = $this->countFilesRecursive($this->cacheDirectory);
            $io->success(\sprintf('Would delete %d cached file(s).', $count));
        } else {
            $count = $this->cacheStorage->purgeAll();
            $io->success(\sprintf('Deleted %d cached file(s).', $count));
        }

        return Command::SUCCESS;
    }

    private function countSourceFiles(string $path): int
    {
        if (is_file($path)) {
            return 1;
        }

        if (!is_dir($path)) {
            return 0;
        }

        $count = 0;
        $iterator = new \DirectoryIterator($path);

        foreach ($iterator as $file) {
            if (!$file->isDot() && $file->isFile()) {
                ++$count;
            }
        }

        return $count;
    }

    private function countFilesRecursive(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                ++$count;
            }
        }

        return $count;
    }
}
