<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Command;

use IdSign\ImageBundle\Cache\CacheStorageInterface;
use IdSign\ImageBundle\Source\SourcePath;
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
            ->addArgument('src', InputArgument::OPTIONAL, 'Source image path to purge (e.g. uploads/photo.jpg or @reference/logo.png)')
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
        $src = null !== $src ? SourcePath::normalize($src) : null;
        /** @var string|null $modifiedBefore */
        $modifiedBefore = $input->getOption('modified-before');
        /** @var string|null $accessedBefore */
        $accessedBefore = $input->getOption('accessed-before');
        $dryRun = !((bool) $input->getOption('force'));

        // (int) "thirty" is 0: a typo would have purged every file older than now — the whole cache. An explicit 0
        // (a templated $DAYS that defaulted to empty, a guess at "no filter") means exactly the same and is refused too.
        foreach (['modified-before' => $modifiedBefore, 'accessed-before' => $accessedBefore] as $option => $days) {
            if (null !== $days && (!ctype_digit($days) || 0 === (int) $days)) {
                $io->error(\sprintf('--%s takes a number of days, at least 1, got "%s". To purge everything, run image:purge --force without it.', $option, $days));

                return Command::INVALID;
            }
        }

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
        // Same validation as deleteBySource(), so the dry run reports what --force would really
        // delete — and never walks outside the cache directory ("..", "", ".").
        try {
            $path = $this->cacheStorage->getAbsolutePath($src);
        } catch (\InvalidArgumentException $e) {
            $io->error(\sprintf('Invalid source path "%s": %s', $src, $e->getMessage()));

            return Command::INVALID;
        }

        if ($dryRun) {
            $count = $this->countSourceFiles($path);
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

        foreach ($this->walkFiles($this->cacheDirectory) as $path) {
            // Silenced: a staging file renamed by a writer, or a variant another purge removed, between the directory
            // listing and the stat — under the console ErrorHandler the warning would abort the whole purge.
            $mtime = @filemtime($path);
            $atime = null !== $atimeThreshold ? @fileatime($path) : false;

            if (false === $mtime) {
                continue;
            }

            if (null !== $mtimeThreshold && $mtime >= $mtimeThreshold) {
                continue;
            }

            if (null !== $atimeThreshold && (false === $atime || $atime >= $atimeThreshold)) {
                continue;
            }

            if ($dryRun) {
                $io->writeln($path);
                ++$count;
            } elseif (@unlink($path)) {
                // A file removed by someone else in the meantime is not one we deleted.
                ++$count;
            }
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
        // Mirrors CacheStorageInterface::deleteBySource(): a single SVG file, or a whole subtree.
        return is_file($path) ? 1 : $this->countFilesRecursive($path);
    }

    private function countFilesRecursive(string $dir): int
    {
        $count = 0;

        foreach ($this->walkFiles($dir) as $_) {
            ++$count;
        }

        return $count;
    }

    /**
     * Every file below $dir, depth first. Tolerates directories that vanish while the walk is running (a concurrent
     * purge, the application's deleteBySource()): RecursiveDirectoryIterator throws when it descends into a removed
     * directory, which would abort the command with a stack trace halfway through the cache.
     *
     * @return iterable<string> absolute paths
     */
    private function walkFiles(string $dir): iterable
    {
        $entries = @scandir($dir, \SCANDIR_SORT_NONE);

        if (false === $entries) {
            return;
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $dir.'/'.$entry;

            if (is_dir($path) && !is_link($path)) {
                yield from $this->walkFiles($path);
            } elseif (is_file($path)) {
                yield $path;
            }
        }
    }
}
