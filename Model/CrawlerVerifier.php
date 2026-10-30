<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Model;

use Hryvinskyi\BotVerification\Api\AddressParserInterface;
use Hryvinskyi\BotVerification\Api\ClaimMatcherInterface;
use Hryvinskyi\BotVerification\Api\CrawlerRegistryInterface;
use Hryvinskyi\BotVerification\Api\CrawlerVerifierInterface;
use Hryvinskyi\BotVerification\Api\Data\VerdictInterface;
use Hryvinskyi\BotVerification\Api\Data\VerdictInterfaceFactory;
use Hryvinskyi\BotVerification\Api\Data\VerdictStatus;
use Hryvinskyi\BotVerification\Api\RangeSnapshotRepositoryInterface;
use Hryvinskyi\BotVerification\Api\SnapshotFreshnessInterface;

/**
 * Judges a User-Agent's claim to be a known crawler against the address lists its owner publishes
 *
 * - A User-Agent that claims no crawler gets a None verdict; nothing else is read.
 * - A crawler vouches for an address that lies in any of its lists, however old that list's snapshot is.
 * - A list speaks only for the address families it publishes. A snapshot that holds no range of the address's
 *   family is passed over: it neither vouches nor, by its age, stops an accusation. A crawler that crawls from a
 *   family its owner does not publish is therefore never accused from that family.
 * - A crawler accuses an address only when the address is a valid public one, every list of the crawler has a
 *   snapshot, at least one of them holds ranges of the address's family, none of those is older than the maximum
 *   age, and the address lies in none of them.
 * - Otherwise it can neither vouch nor accuse - a list never fetched, a stale snapshot of the address's family, no
 *   list of that family at all, a private, loopback, reserved or unreadable address, a crawler with no list - and the
 *   verdict is unverifiable. Missing data can confirm nobody and accuse nobody.
 * - A User-Agent that claims several crawlers is judged by every one of them, in order of occurrence, and gets the
 *   worst verdict: impostor as soon as one accuses it (the rest are not judged), otherwise unverifiable if one cannot
 *   vouch for it, otherwise genuine. Naming a second crawler whose lists happen to hold the address therefore cannot
 *   hide an impostor.
 *
 * The verdict names the first accusing crawler, the first crawler that could not vouch, or for a genuine verdict the
 * first claimed crawler. Only snapshots fetched beforehand are read; nothing here goes to the network.
 */
class CrawlerVerifier implements CrawlerVerifierInterface
{
    /**
     * @param CrawlerRegistryInterface $registry Lists of each crawler
     * @param ClaimMatcherInterface $claimMatcher Crawlers a User-Agent claims
     * @param AddressParserInterface $addressParser Reads the client address
     * @param RangeSnapshotRepositoryInterface $snapshots Last snapshot of each list
     * @param SnapshotFreshnessInterface $freshness Whether a snapshot is too old to accuse
     * @param VerdictInterfaceFactory $verdictFactory Creates the verdict
     */
    public function __construct(
        private readonly CrawlerRegistryInterface $registry,
        private readonly ClaimMatcherInterface $claimMatcher,
        private readonly AddressParserInterface $addressParser,
        private readonly RangeSnapshotRepositoryInterface $snapshots,
        private readonly SnapshotFreshnessInterface $freshness,
        private readonly VerdictInterfaceFactory $verdictFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function verify(string $userAgent, string $clientIp): VerdictInterface
    {
        $claims = $this->claimMatcher->claims($userAgent);
        if ($claims === []) {
            return $this->verdictFactory->create(['status' => VerdictStatus::None]);
        }

        $address = $this->addressParser->toPublicBinary($clientIp);
        if ($address === null) {
            return $this->verdict(VerdictStatus::Unverifiable, $claims[0]);
        }

        $unverifiedBy = null;
        foreach ($claims as $crawlerCode) {
            $status = $this->judge($crawlerCode, $address);
            if ($status === VerdictStatus::Impostor) {
                return $this->verdict(VerdictStatus::Impostor, $crawlerCode);
            }
            if ($status === VerdictStatus::Unverifiable) {
                $unverifiedBy ??= $crawlerCode;
            }
        }
        if ($unverifiedBy !== null) {
            return $this->verdict(VerdictStatus::Unverifiable, $unverifiedBy);
        }

        return $this->verdict(VerdictStatus::Genuine, $claims[0]);
    }

    /**
     * What one claimed crawler makes of an address
     *
     * A snapshot is checked for age only when it does not hold the address and holds ranges of its family. A crawler
     * with no list has no list of the address's family, so it cannot accuse.
     *
     * @param string $crawlerCode Code of the claimed crawler
     * @param string $address Packed valid public address
     * @return VerdictStatus Genuine, Impostor or Unverifiable
     */
    private function judge(string $crawlerCode, string $address): VerdictStatus
    {
        $everyListIsPresent = true;
        $aListSpeaksForTheFamily = false;
        $aListOfTheFamilyIsStale = false;
        foreach ($this->registry->getRangeListCodes($crawlerCode) as $listCode) {
            $snapshot = $this->snapshots->find($listCode);
            if ($snapshot === null) {
                $everyListIsPresent = false;
                continue;
            }
            if ($snapshot->contains($address)) {
                return VerdictStatus::Genuine;
            }
            if (!$snapshot->holdsFamilyOf($address)) {
                continue;
            }
            $aListSpeaksForTheFamily = true;
            if ($this->freshness->isStale($snapshot)) {
                $aListOfTheFamilyIsStale = true;
            }
        }

        return $everyListIsPresent && $aListSpeaksForTheFamily && !$aListOfTheFamilyIsStale
            ? VerdictStatus::Impostor
            : VerdictStatus::Unverifiable;
    }

    /**
     * A verdict about a named crawler
     *
     * @param VerdictStatus $status Genuine, Impostor or Unverifiable
     * @param string $crawlerCode Code of the crawler the verdict is about
     * @return VerdictInterface
     */
    private function verdict(VerdictStatus $status, string $crawlerCode): VerdictInterface
    {
        return $this->verdictFactory->create(['status' => $status, 'crawlerCode' => $crawlerCode]);
    }
}
