<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Model;

use Hryvinskyi\BotVerification\Api\CrawlerRegistryInterface;
use Hryvinskyi\BotVerification\Api\Data\RangeSnapshotInterface;
use Hryvinskyi\BotVerification\Api\Data\RefreshResultInterface;
use Hryvinskyi\BotVerification\Api\Data\RefreshResultInterfaceFactory;
use Hryvinskyi\BotVerification\Api\RangeListFetcherInterface;
use Hryvinskyi\BotVerification\Api\RangeListRefresherInterface;
use Hryvinskyi\BotVerification\Api\RangeSnapshotBuilderInterface;
use Hryvinskyi\BotVerification\Api\RangeSnapshotRepositoryInterface;
use Hryvinskyi\BotVerification\Api\TimeSourceInterface;
use Hryvinskyi\BotVerification\Exception\RangeListRejectedException;
use Magento\Framework\Exception\LocalizedException;

/**
 * Refreshes the snapshots of the published address lists: fetch, build, store
 *
 * Every declared list is refreshed the same way - the lists of crawlers and of trusted proxies alike - in the order
 * the registry declares them, each fetched in the format the registry names for it and stamped with the moment it was
 * fetched.
 *
 * A snapshot is replaced only by a list that was fetched and read whole, every prefix valid. A list that is rejected -
 * it could not be fetched, it is not in its format, a prefix is malformed, no parser reads its format - is reported
 * with the reason and not saved, so its previous snapshot stays in force, and the next list is refreshed. A failure to
 * store a list is not a rejection: it ends the refresh with the storage's exception.
 *
 * A list that would shrink to fewer than half of the ranges of its stored snapshot is held back the same way, unless
 * the caller accepts the shrink. A sudden collapse of a published list is more likely a fault on the publisher's side
 * - a partial or placeholder file that still reads - than a real reorganisation, and storing it would turn every real
 * crawler outside the remaining ranges into an impostor. Exactly half is accepted, and a list stored for the first
 * time is never held back. While a list is held back its snapshot ages like one whose fetch keeps failing: once it is
 * older than the maximum age it stops accusing anyone.
 */
class RangeListRefresher implements RangeListRefresherInterface
{
    /**
     * @param CrawlerRegistryInterface $registry The declared lists, their addresses and formats
     * @param RangeListFetcherInterface $fetcher Fetches a list from where it is published
     * @param RangeSnapshotBuilderInterface $builder Turns the fetched prefixes into a snapshot
     * @param RangeSnapshotRepositoryInterface $snapshots Where the snapshots are kept
     * @param TimeSourceInterface $timeSource The moment each list is fetched
     * @param RefreshResultInterfaceFactory $resultFactory Creates the result of the refresh
     */
    public function __construct(
        private readonly CrawlerRegistryInterface $registry,
        private readonly RangeListFetcherInterface $fetcher,
        private readonly RangeSnapshotBuilderInterface $builder,
        private readonly RangeSnapshotRepositoryInterface $snapshots,
        private readonly TimeSourceInterface $timeSource,
        private readonly RefreshResultInterfaceFactory $resultFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function refresh(array $listCodes = [], bool $acceptShrink = false): RefreshResultInterface
    {
        $refreshed = [];
        $rejected = [];
        foreach ($this->listsToRefresh($listCodes) as $listCode => $url) {
            try {
                $snapshot = $this->builder->build(
                    $this->fetcher->fetch($url, $this->registry->getRangeListFormat($listCode)),
                    $this->timeSource->now()
                );
                if (!$acceptShrink) {
                    $this->holdBackShrink($listCode, $snapshot);
                }
            } catch (RangeListRejectedException $exception) {
                $rejected[$listCode] = $exception->getMessage();
                continue;
            }

            $this->snapshots->save($listCode, $snapshot);
            $refreshed[$listCode] = $snapshot->countRanges();
        }

        return $this->resultFactory->create(['refreshed' => $refreshed, 'rejected' => $rejected]);
    }

    /**
     * The addresses of the lists to refresh, in declaration order
     *
     * @param list<string> $listCodes Requested list codes; empty for every declared list
     * @return array<string, string> List code => URL of the published list
     * @throws LocalizedException When a requested code is not declared
     */
    private function listsToRefresh(array $listCodes): array
    {
        $declared = $this->registry->getRangeListUrls();
        if ($listCodes === []) {
            return $declared;
        }

        $unknown = array_diff($listCodes, array_keys($declared));
        if ($unknown !== []) {
            throw new LocalizedException(__(
                'No address list is declared as %1; nothing was refreshed.',
                implode(', ', array_unique($unknown))
            ));
        }

        return array_intersect_key($declared, array_flip($listCodes));
    }

    /**
     * Reject a snapshot that holds fewer than half of the ranges of the one stored for its list
     *
     * @param string $listCode Code of the list
     * @param RangeSnapshotInterface $snapshot Snapshot of the list as just fetched
     * @return void
     * @throws RangeListRejectedException When the list shrank below half of what is stored
     */
    private function holdBackShrink(string $listCode, RangeSnapshotInterface $snapshot): void
    {
        $stored = $this->snapshots->find($listCode);
        if ($stored === null || $snapshot->countRanges() * 2 >= $stored->countRanges()) {
            return;
        }

        throw new RangeListRejectedException(__(
            'The list shrank from %1 to %2 ranges; the previous list is kept. Refresh with --force to accept it.',
            $stored->countRanges(),
            $snapshot->countRanges()
        ));
    }
}
