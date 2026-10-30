<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Unit\Model;

use Hryvinskyi\BotVerification\Api\Data\RangeSnapshotInterface;
use Hryvinskyi\BotVerification\Api\Data\RangeSnapshotInterfaceFactory;
use Hryvinskyi\BotVerification\Api\Data\RefreshResultInterface;
use Hryvinskyi\BotVerification\Api\Data\RefreshResultInterfaceFactory;
use Hryvinskyi\BotVerification\Api\RangeSnapshotRepositoryInterface;
use Hryvinskyi\BotVerification\Exception\RangeListRejectedException;
use Hryvinskyi\BotVerification\Model\Crawler\CrawlerRegistry;
use Hryvinskyi\BotVerification\Model\Range\RangeSnapshot;
use Hryvinskyi\BotVerification\Model\Range\RangeSnapshotBuilder;
use Hryvinskyi\BotVerification\Model\RangeListRefresher;
use Hryvinskyi\BotVerification\Model\RefreshResult;
use Hryvinskyi\BotVerification\Test\Double\FixedTimeSource;
use Hryvinskyi\BotVerification\Test\Double\InMemoryRangeSnapshotRepository;
use Hryvinskyi\BotVerification\Test\Double\ScriptedRangeListFetcher;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

/**
 * The refresher replaces a list's snapshot only with a list fetched and read whole, keeps the previous one for every
 * rejected or collapsed list, and goes on with the other lists.
 */
class RangeListRefresherTest extends TestCase
{
    private const NOW = 1_700_000_000;

    private const MAIN = 'example_main';
    private const EXTRA = 'example_extra';
    private const EDGE = 'example_edge';

    private const MAIN_URL = 'https://example.com/crawler-main.json';
    private const EXTRA_URL = 'https://example.com/crawler-extra.json';
    private const EDGE_URL = 'https://example.com/edge-servers.txt';

    /**
     * Every declared list - crawler lists and the proxy list alike - is fetched, built at the current moment and
     * saved, in declaration order, and reported with the number of ranges stored
     *
     * @return void
     */
    public function testRefreshesEveryDeclaredList(): void
    {
        $fetcher = new ScriptedRangeListFetcher([
            self::MAIN_URL => ['192.0.2.0/24'],
            self::EXTRA_URL => ['203.0.113.0/27', '2001:db8:4801::/64'],
            self::EDGE_URL => ['198.51.100.0/24', '198.51.100.128/25'],
        ]);
        $repository = new InMemoryRangeSnapshotRepository();

        $result = $this->refresher($fetcher, $repository)->refresh();

        self::assertSame([self::MAIN => 1, self::EXTRA => 2, self::EDGE => 1], $result->getRefreshed());
        self::assertSame([], $result->getRejected());
        self::assertSame([self::MAIN, self::EXTRA, self::EDGE], $repository->saved);
        self::assertSame(
            [self::MAIN_URL, self::EXTRA_URL, self::EDGE_URL],
            array_column($fetcher->requests, 'url')
        );
        $edge = $repository->find(self::EDGE);
        self::assertNotNull($edge);
        self::assertSame(self::NOW, $edge->getFetchedAt());
        self::assertTrue($edge->contains(self::packed('198.51.100.200')));
    }

    /**
     * Each list is fetched in the format the registry names for it: the proxy list in its declared format, the others
     * in the default one
     *
     * @return void
     */
    public function testFetchesEachListInItsDeclaredFormat(): void
    {
        $fetcher = new ScriptedRangeListFetcher([
            self::MAIN_URL => ['192.0.2.0/24'],
            self::EXTRA_URL => ['203.0.113.0/27'],
            self::EDGE_URL => ['198.51.100.0/24'],
        ]);

        $this->refresher($fetcher, new InMemoryRangeSnapshotRepository())->refresh();

        self::assertSame(
            [
                ['url' => self::MAIN_URL, 'format' => 'prefix_json'],
                ['url' => self::EXTRA_URL, 'format' => 'prefix_json'],
                ['url' => self::EDGE_URL, 'format' => 'plain_text'],
            ],
            $fetcher->requests
        );
    }

    /**
     * A list whose fetch is rejected is reported with the reason and not saved, so its previous snapshot stays; the
     * lists after it are still refreshed
     *
     * @return void
     */
    public function testARejectedListKeepsItsSnapshotWhileTheOthersAreSaved(): void
    {
        $repository = new InMemoryRangeSnapshotRepository();
        $previous = $this->seed($repository, self::MAIN, 3);
        $fetcher = new ScriptedRangeListFetcher([
            self::MAIN_URL => new RangeListRejectedException(__('The list could not be fetched.')),
            self::EXTRA_URL => ['203.0.113.0/27'],
            self::EDGE_URL => ['198.51.100.0/24'],
        ]);

        $result = $this->refresher($fetcher, $repository)->refresh();

        self::assertSame([self::MAIN => 'The list could not be fetched.'], $result->getRejected());
        self::assertSame([self::EXTRA => 1, self::EDGE => 1], $result->getRefreshed());
        self::assertSame([self::EXTRA, self::EDGE], $repository->saved);
        self::assertSame($previous, $repository->find(self::MAIN));
    }

    /**
     * One malformed prefix rejects the whole list, which is not saved
     *
     * @return void
     */
    public function testAMalformedPrefixRejectsTheList(): void
    {
        $repository = new InMemoryRangeSnapshotRepository();
        $previous = $this->seed($repository, self::MAIN, 3);
        $fetcher = new ScriptedRangeListFetcher([self::MAIN_URL => ['192.0.2.0/24', '192.0.2.300/24']]);

        $result = $this->refresher($fetcher, $repository)->refresh([self::MAIN]);

        self::assertSame(
            [self::MAIN => 'The address range "192.0.2.300/24" is not a valid CIDR prefix.'],
            $result->getRejected()
        );
        self::assertSame([], $result->getRefreshed());
        self::assertSame([], $repository->saved);
        self::assertSame($previous, $repository->find(self::MAIN));
    }

    /**
     * A requested code that no module declares fails the whole refresh before anything is fetched or saved
     *
     * @return void
     */
    public function testAnUnknownCodeFailsBeforeAnythingIsFetched(): void
    {
        $fetcher = new ScriptedRangeListFetcher([self::MAIN_URL => ['192.0.2.0/24']]);
        $repository = new InMemoryRangeSnapshotRepository();

        try {
            $this->refresher($fetcher, $repository)->refresh([self::MAIN, 'example_missing']);
            self::fail('An unknown list code was accepted.');
        } catch (LocalizedException $exception) {
            self::assertSame(
                'No address list is declared as example_missing; nothing was refreshed.',
                $exception->getMessage()
            );
        }

        self::assertSame([], $fetcher->requests);
        self::assertSame([], $repository->saved);
    }

    /**
     * A refresh of some lists fetches and saves only those, in declaration order whatever order they were asked in
     *
     * @return void
     */
    public function testASubsetRefreshTouchesOnlyTheRequestedLists(): void
    {
        $fetcher = new ScriptedRangeListFetcher([
            self::MAIN_URL => ['192.0.2.0/24'],
            self::EDGE_URL => ['198.51.100.0/24'],
        ]);
        $repository = new InMemoryRangeSnapshotRepository();

        $result = $this->refresher($fetcher, $repository)->refresh([self::EDGE, self::MAIN]);

        self::assertSame([self::MAIN => 1, self::EDGE => 1], $result->getRefreshed());
        self::assertSame([self::MAIN_URL, self::EDGE_URL], array_column($fetcher->requests, 'url'));
        self::assertSame([self::MAIN, self::EDGE], $repository->saved);
        self::assertNull($repository->find(self::EXTRA));
    }

    /**
     * A list that shrank to fewer than half of its stored ranges is held back, with the reason, and not saved
     *
     * @return void
     */
    public function testAListThatShrankBelowHalfIsHeldBack(): void
    {
        $repository = new InMemoryRangeSnapshotRepository();
        $previous = $this->seed($repository, self::MAIN, 10);
        $fetcher = new ScriptedRangeListFetcher([self::MAIN_URL => self::prefixes(4)]);

        $result = $this->refresher($fetcher, $repository)->refresh([self::MAIN]);

        self::assertSame(
            [
                self::MAIN => 'The list shrank from 10 to 4 ranges; the previous list is kept. '
                    . 'Refresh with --force to accept it.',
            ],
            $result->getRejected()
        );
        self::assertSame([], $result->getRefreshed());
        self::assertSame([], $repository->saved);
        self::assertSame($previous, $repository->find(self::MAIN));
    }

    /**
     * A list that kept exactly half of its stored ranges is stored
     *
     * @return void
     */
    public function testAListThatKeptHalfIsStored(): void
    {
        $repository = new InMemoryRangeSnapshotRepository();
        $this->seed($repository, self::MAIN, 10);
        $fetcher = new ScriptedRangeListFetcher([self::MAIN_URL => self::prefixes(5)]);

        $result = $this->refresher($fetcher, $repository)->refresh([self::MAIN]);

        self::assertSame([self::MAIN => 5], $result->getRefreshed());
        self::assertSame([self::MAIN], $repository->saved);
    }

    /**
     * A shrink the caller accepts is stored
     *
     * @return void
     */
    public function testAnAcceptedShrinkIsStored(): void
    {
        $repository = new InMemoryRangeSnapshotRepository();
        $this->seed($repository, self::MAIN, 10);
        $fetcher = new ScriptedRangeListFetcher([self::MAIN_URL => self::prefixes(4)]);

        $result = $this->refresher($fetcher, $repository)->refresh([self::MAIN], true);

        self::assertSame([self::MAIN => 4], $result->getRefreshed());
        self::assertSame([], $result->getRejected());
        self::assertSame([self::MAIN], $repository->saved);
        self::assertSame(4, $repository->find(self::MAIN)?->countRanges());
    }

    /**
     * A list stored for the first time is never held back, however few ranges it holds
     *
     * @return void
     */
    public function testAFirstFetchIsNeverHeldBack(): void
    {
        $repository = new InMemoryRangeSnapshotRepository();
        $fetcher = new ScriptedRangeListFetcher([self::MAIN_URL => self::prefixes(1)]);

        $result = $this->refresher($fetcher, $repository)->refresh([self::MAIN]);

        self::assertSame([self::MAIN => 1], $result->getRefreshed());
        self::assertSame([self::MAIN], $repository->saved);
    }

    /**
     * A failure to store a list is not a rejection: it ends the refresh
     *
     * @return void
     */
    public function testAStorageFailureEndsTheRefresh(): void
    {
        $failure = new \RuntimeException('The storage is unavailable.');
        $repository = $this->createMock(RangeSnapshotRepositoryInterface::class);
        $repository->method('find')->willReturn(null);
        $repository->expects(self::once())->method('save')->willThrowException($failure);
        $fetcher = new ScriptedRangeListFetcher([
            self::MAIN_URL => ['192.0.2.0/24'],
            self::EXTRA_URL => ['203.0.113.0/27'],
            self::EDGE_URL => ['198.51.100.0/24'],
        ]);

        $caught = null;
        try {
            $this->refresher($fetcher, $repository)->refresh();
        } catch (\RuntimeException $exception) {
            $caught = $exception;
        }

        self::assertSame($failure, $caught, 'A storage failure did not end the refresh.');
        self::assertSame([self::MAIN_URL], array_column($fetcher->requests, 'url'));
    }

    /**
     * The refresher over two crawler lists and a proxy list in plain text, with a real registry and builder
     *
     * @param ScriptedRangeListFetcher $fetcher Answers in place of the network
     * @param RangeSnapshotRepositoryInterface $repository Where the snapshots are kept
     * @return RangeListRefresher
     */
    private function refresher(
        ScriptedRangeListFetcher $fetcher,
        RangeSnapshotRepositoryInterface $repository
    ): RangeListRefresher {
        $registry = new CrawlerRegistry(
            [
                'example' => [
                    'tokens' => ['examplebot' => 'ExampleBot'],
                    'lists' => [self::MAIN => self::MAIN, self::EXTRA => self::EXTRA],
                ],
            ],
            [self::MAIN => self::MAIN_URL, self::EXTRA => self::EXTRA_URL, self::EDGE => self::EDGE_URL],
            [self::EDGE => 'plain_text'],
            [self::EDGE]
        );
        $resultFactory = $this->createMock(RefreshResultInterfaceFactory::class);
        $resultFactory->method('create')->willReturnCallback(
            static function (array $data): RefreshResultInterface {
                $refreshed = $data['refreshed'] ?? null;
                $rejected = $data['rejected'] ?? null;
                self::assertIsArray($refreshed);
                self::assertIsArray($rejected);

                /**
                 * @var array<string, int> $refreshed
                 * @var array<string, string> $rejected
                 */
                return new RefreshResult($refreshed, $rejected);
            }
        );

        return new RangeListRefresher(
            $registry,
            $fetcher,
            $this->builder(),
            $repository,
            new FixedTimeSource(self::NOW),
            $resultFactory
        );
    }

    /**
     * Store a snapshot of the given number of ranges for a list, as an earlier refresh would have
     *
     * @param InMemoryRangeSnapshotRepository $repository Where to store it
     * @param string $listCode Code of the list
     * @param int $ranges Number of ranges the snapshot holds
     * @return RangeSnapshotInterface The stored snapshot
     */
    private function seed(
        InMemoryRangeSnapshotRepository $repository,
        string $listCode,
        int $ranges
    ): RangeSnapshotInterface {
        $snapshot = $this->builder()->build(self::prefixes($ranges), self::NOW - 21600);
        $repository->save($listCode, $snapshot);
        $repository->saved = [];

        return $snapshot;
    }

    /**
     * The real snapshot builder, through a factory that builds a real snapshot from its arguments
     *
     * @return RangeSnapshotBuilder
     */
    private function builder(): RangeSnapshotBuilder
    {
        $factory = $this->createMock(RangeSnapshotInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(
            static function (array $data): RangeSnapshotInterface {
                $ipv4Ranges = $data['ipv4Ranges'] ?? null;
                $ipv6Ranges = $data['ipv6Ranges'] ?? null;
                $fetchedAt = $data['fetchedAt'] ?? null;
                self::assertIsString($ipv4Ranges);
                self::assertIsString($ipv6Ranges);
                self::assertIsInt($fetchedAt);

                return new RangeSnapshot($ipv4Ranges, $ipv6Ranges, $fetchedAt);
            }
        );

        return new RangeSnapshotBuilder($factory);
    }

    /**
     * Prefixes of single addresses apart from each other, each a range of its own once built
     *
     * @param int $count Number of prefixes, at most 100
     * @return list<string>
     */
    private static function prefixes(int $count): array
    {
        $prefixes = [];
        for ($index = 0; $index < $count; $index++) {
            $prefixes[] = sprintf('198.51.100.%d/32', 2 * $index + 1);
        }

        return $prefixes;
    }

    /**
     * Packed form of an address written as text
     *
     * @param string $address Valid address
     * @return string 4 or 16 bytes
     */
    private static function packed(string $address): string
    {
        $packed = inet_pton($address);
        self::assertIsString($packed, $address . ' is not a valid address');

        return $packed;
    }
}
