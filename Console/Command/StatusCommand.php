<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Console\Command;

use Hryvinskyi\BotVerification\Api\CrawlerRegistryInterface;
use Hryvinskyi\BotVerification\Api\Data\RangeSnapshotInterface;
use Hryvinskyi\BotVerification\Api\RangeSnapshotRepositoryInterface;
use Hryvinskyi\BotVerification\Api\SnapshotFreshnessInterface;
use Hryvinskyi\BotVerification\Api\TimeSourceInterface;
use Hryvinskyi\BotVerification\Model\Cache\Type\RangeSnapshotCache;
use Magento\Framework\App\Cache\StateInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bot-verification:status` - the size and age of the stored snapshot of every declared address list, and whether
 * the module's cache type is on
 *
 * Verification fails open: a list that was never fetched, or whose snapshot has grown older than the maximum age,
 * accuses nobody, so an impostor is let through without any sign on the request path. This command is that sign for
 * a monitor - it exits non-zero while any declared list is missing or stale. Staleness is asked of the same service
 * the verdict asks, so the two cannot disagree.
 *
 * It also exits non-zero while the cache type is off. A new cache type stays off until it is enabled, and without it
 * every request that claims a crawler reads the lists from the database; nothing else would show that the enabling
 * step was skipped. With no list declared there is nothing to read, and the cache type is not reported.
 */
class StatusCommand extends Command
{
    public const COMMAND_NAME = 'bot-verification:status';

    private const SECONDS_PER_HOUR = 3600;
    private const SECONDS_PER_DAY = 86400;

    /**
     * @param CrawlerRegistryInterface $registry The declared lists
     * @param RangeSnapshotRepositoryInterface $snapshots Where the snapshots are kept
     * @param SnapshotFreshnessInterface $freshness Whether a snapshot is too old to accuse anyone
     * @param TimeSourceInterface $timeSource The current moment, for the age of each snapshot
     * @param StateInterface $cacheState Whether the module's cache type is on
     */
    public function __construct(
        private readonly CrawlerRegistryInterface $registry,
        private readonly RangeSnapshotRepositoryInterface $snapshots,
        private readonly SnapshotFreshnessInterface $freshness,
        private readonly TimeSourceInterface $timeSource,
        private readonly StateInterface $cacheState
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAME)
            ->setDescription('Show the age and size of every published address list');

        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $listCodes = array_keys($this->registry->getRangeListUrls());
        if ($listCodes === []) {
            $output->writeln('No address lists are declared.');

            return Command::SUCCESS;
        }

        $healthy = true;
        foreach ($listCodes as $listCode) {
            $healthy = $this->renderList($output, $listCode) && $healthy;
        }
        $output->writeln(sprintf('Maximum age: %s days', $this->formatDays($this->freshness->getMaxAge())));
        $healthy = $this->renderCacheState($output) && $healthy;

        return $healthy ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Print whether the module's cache type is on, as an error when it is off
     *
     * @param OutputInterface $output Console output
     * @return bool Whether the cache type is on
     */
    private function renderCacheState(OutputInterface $output): bool
    {
        $type = RangeSnapshotCache::TYPE_IDENTIFIER;
        if ($this->cacheState->isEnabled($type)) {
            $output->writeln(sprintf('Cache type %s: enabled', $type));

            return true;
        }

        $output->writeln(sprintf(
            '<error>Cache type %1$s: disabled — every request that claims a crawler reads the lists from the'
            . ' database; run bin/magento cache:enable %1$s</error>',
            $type
        ));

        return false;
    }

    /**
     * Print one list's line, as an error when its snapshot is missing or stale
     *
     * @param OutputInterface $output Console output
     * @param string $listCode Code of the list
     * @return bool Whether the list has a snapshot that is not stale
     */
    private function renderList(OutputInterface $output, string $listCode): bool
    {
        $snapshot = $this->snapshots->find($listCode);
        if ($snapshot === null) {
            $output->writeln(sprintf('<error>%s: never fetched</error>', OutputFormatter::escape($listCode)));

            return false;
        }

        $line = sprintf(
            '%s: %d ranges, fetched %d h ago',
            OutputFormatter::escape($listCode),
            $snapshot->countRanges(),
            $this->ageInHours($snapshot)
        );
        if (!$this->freshness->isStale($snapshot)) {
            $output->writeln($line);

            return true;
        }

        $output->writeln(sprintf('<error>%s — stale</error>', $line));

        return false;
    }

    /**
     * Whole hours since the snapshot was fetched
     *
     * @param RangeSnapshotInterface $snapshot Snapshot of a list
     * @return int Never negative: a fetch stamped later than now by another server's clock counts as just now
     */
    private function ageInHours(RangeSnapshotInterface $snapshot): int
    {
        return intdiv(max(0, $this->timeSource->now() - $snapshot->getFetchedAt()), self::SECONDS_PER_HOUR);
    }

    /**
     * A number of seconds in days, as a whole number when it is one
     *
     * @param int $seconds Duration in seconds
     * @return string E.g. '7' or '3.5'
     */
    private function formatDays(int $seconds): string
    {
        return rtrim(rtrim(number_format($seconds / self::SECONDS_PER_DAY, 2, '.', ''), '0'), '.');
    }
}
