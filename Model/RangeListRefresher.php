<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Model;

use Hryvinskyi\BotVerification\Api\CrawlerRegistryInterface;
use Hryvinskyi\BotVerification\Api\Data\AddressFamily;
use Hryvinskyi\BotVerification\Api\Data\RangeSnapshotInterface;
use Hryvinskyi\BotVerification\Api\Data\RefreshResultInterface;
use Hryvinskyi\BotVerification\Api\Data\RefreshResultInterfaceFactory;
use Hryvinskyi\BotVerification\Api\RangeListFetcherInterface;
use Hryvinskyi\BotVerification\Api\RangeListRefresherInterface;
use Hryvinskyi\BotVerification\Api\RangeSnapshotBuilderInterface;
use Hryvinskyi\BotVerification\Api\RangeSnapshotRepositoryInterface;
use Hryvinskyi\BotVerification\Api\TimeSourceInterface;
use Hryvinskyi\BotVerification\Exception\RangeListRejectedException;
use Hryvinskyi\BotVerification\Exception\RangeListStorageException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

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
 * store a list is not a rejection: it ends the refresh with a storage exception that names the list, chains the
 * storage's own exception and carries what the refresh did with the lists before it, rejections included.
 *
 * A list that would shrink is held back the same way, unless the caller accepts the shrink. It shrinks when, in any
 * address family its stored snapshot covers, it covers fewer than half of the stored snapshot's addresses of that
 * family; a family it no longer holds covers none. A sudden collapse of a published list is more likely a fault on the
 * publisher's side - a partial or placeholder file that still reads, a file whose prefixes all narrowed - than a real
 * reorganisation, and storing it would turn every real crawler outside the remaining ranges into an impostor. The
 * measure is address space per family, not the number of blocks: a publisher that fills the gaps between its blocks
 * covers more in fewer blocks, and a list that loses one family loses those crawlers whatever the other family holds.
 * Exactly half is accepted, growth and a family the stored snapshot never held are never held back, and a list stored
 * for the first time is never held back. While a list is held back its snapshot ages like one whose fetch keeps
 * failing: once it is older than the maximum age it stops accusing anyone.
 */
class RangeListRefresher implements RangeListRefresherInterface
{
    /**
     * Largest coverage named as a count of addresses; anything larger is named as the prefix it is worth
     */
    private const LARGEST_COUNT = 4294967296.0;

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

            $this->store($listCode, $snapshot, $refreshed, $rejected);
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
     * Store a list's snapshot, or end the refresh with what it did before this list
     *
     * @param string $listCode Code of the list
     * @param RangeSnapshotInterface $snapshot Snapshot of the list as just fetched and accepted
     * @param array<string, int> $refreshed The lists stored so far in this refresh: code => ranges stored
     * @param array<string, string> $rejected The lists rejected so far in this refresh: code => reason
     * @return void
     * @throws RangeListStorageException When the storage fails; it chains the storage's exception
     */
    private function store(string $listCode, RangeSnapshotInterface $snapshot, array $refreshed, array $rejected): void
    {
        try {
            $this->snapshots->save($listCode, $snapshot);
        } catch (\Exception $exception) {
            throw new RangeListStorageException(
                __(
                    'The address list %1 could not be stored and the refresh stopped: %2',
                    $listCode,
                    $exception->getMessage()
                ),
                $listCode,
                $this->resultFactory->create(['refreshed' => $refreshed, 'rejected' => $rejected]),
                $exception
            );
        }
    }

    /**
     * Reject a snapshot that covers fewer than half of the addresses the stored one covers in any of its families
     *
     * @param string $listCode Code of the list
     * @param RangeSnapshotInterface $snapshot Snapshot of the list as just fetched
     * @return void
     * @throws RangeListRejectedException When the list shrank below half of what is stored in a family
     */
    private function holdBackShrink(string $listCode, RangeSnapshotInterface $snapshot): void
    {
        $stored = $this->snapshots->find($listCode);
        if ($stored === null) {
            return;
        }

        foreach (AddressFamily::cases() as $family) {
            $storedCoverage = $stored->coveredAddresses($family);
            $coverage = $snapshot->coveredAddresses($family);
            if ($coverage * 2 >= $storedCoverage) {
                continue;
            }

            throw new RangeListRejectedException(__(
                'The list\'s %1 ranges shrank from %2 to %3; the previous list is kept. Refresh with --force to '
                . 'accept it.',
                $family->label(),
                $this->describeCoverage($family, $storedCoverage),
                $this->describeCoverage($family, $coverage)
            ));
        }
    }

    /**
     * A number of covered addresses as people read it
     *
     * Up to 2^32 addresses - every IPv4 coverage, and a small IPv6 one - it is the count. A larger coverage is named
     * as the prefix length of a single block of the family that would cover as much, to one decimal: 2^64 IPv6
     * addresses are the space of a /64.0.
     *
     * @param AddressFamily $family Family the addresses belong to
     * @param float $addresses Number of addresses covered, not negative
     * @return Phrase
     */
    private function describeCoverage(AddressFamily $family, float $addresses): Phrase
    {
        if ($addresses <= 0) {
            return __('no addresses');
        }
        if ($addresses === 1.0) {
            return __('1 address');
        }
        if ($addresses <= self::LARGEST_COUNT) {
            return __('%1 addresses', number_format($addresses, 0, '.', ','));
        }

        return __('the space of a /%1', sprintf('%.1f', $family->bits() - log($addresses, 2)));
    }
}
