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
use Hryvinskyi\BotVerification\Exception\RangeListStorageException;
use Hryvinskyi\BotVerification\Model\Crawler\CrawlerRegistry;
use Hryvinskyi\BotVerification\Model\Range\RangeSnapshot;
use Hryvinskyi\BotVerification\Model\Range\RangeSnapshotBuilder;
use Hryvinskyi\BotVerification\Model\RangeListRefresher;
use Hryvinskyi\BotVerification\Model\RefreshResult;
use Hryvinskyi\BotVerification\Test\Double\FixedTimeSource;
use Hryvinskyi\BotVerification\Test\Double\InMemoryRangeSnapshotRepository;
use Hryvinskyi\BotVerification\Test\Double\SaveFailingRangeSnapshotRepository;
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
                self::MAIN => 'The list\'s IPv4 ranges shrank from 10 addresses to 4 addresses; the previous list '
                    . 'is kept. Refresh with --force to accept it.',
            ],
            $result->getRejected()
        );
        self::assertSame([], $result->getRefreshed());
        self::assertSame([], $repository->saved);
        self::assertSame($previous, $repository->find(self::MAIN));
    }

    /**
     * A publisher that fills the gaps between its blocks covers more address space in fewer blocks: the list is
     * stored, however few blocks it merges into
     *
     * @return void
     */
    public function testAListThatFillsItsGapsIsStoredThoughItMergesIntoFewerBlocks(): void
    {
        $repository = new InMemoryRangeSnapshotRepository();
        $this->seed($repository, self::MAIN, 10);
        $fetcher = new ScriptedRangeListFetcher([self::MAIN_URL => ['198.51.100.0/27']]);

        $result = $this->refresher($fetcher, $repository)->refresh([self::MAIN]);

        self::assertSame([], $result->getRejected());
        self::assertSame([self::MAIN => 1], $result->getRefreshed());
        self::assertSame([self::MAIN], $repository->saved);
    }

    /**
     * A list that drops every IPv4 prefix but keeps as many IPv6 blocks as it had IPv4 ones is held back: the
     * family it lost covers nothing now, whatever the total number of blocks says
     *
     * @return void
     */
    public function testAListThatLosesAFamilyIsHeldBackThoughItKeepsHalfItsBlocks(): void
    {
        $repository = new InMemoryRangeSnapshotRepository();
        $previous = $this->seedPrefixes(
            $repository,
            self::MAIN,
            ['192.0.2.0/24', '198.51.100.0/24', '2001:db8:1::/48', '2001:db8:3::/48']
        );
        $fetcher = new ScriptedRangeListFetcher([self::MAIN_URL => ['2001:db8:1::/48', '2001:db8:3::/48']]);

        $result = $this->refresher($fetcher, $repository)->refresh([self::MAIN]);

        self::assertSame(
            [
                self::MAIN => 'The list\'s IPv4 ranges shrank from 512 addresses to no addresses; the previous list '
                    . 'is kept. Refresh with --force to accept it.',
            ],
            $result->getRejected()
        );
        self::assertSame([], $repository->saved);
        self::assertSame($previous, $repository->find(self::MAIN));
    }

    /**
     * A list whose prefixes all narrowed keeps its number of blocks but covers a fraction of its address space: it
     * is held back
     *
     * @return void
     */
    public function testAListWhosePrefixesNarrowedIsHeldBackThoughItKeepsItsBlocks(): void
    {
        $repository = new InMemoryRangeSnapshotRepository();
        $previous = $this->seedPrefixes(
            $repository,
            self::MAIN,
            ['198.18.0.0/20', '198.18.32.0/20', '198.18.64.0/20']
        );
        $fetcher = new ScriptedRangeListFetcher([
            self::MAIN_URL => ['198.18.0.0/28', '198.18.32.0/28', '198.18.64.0/28'],
        ]);

        $result = $this->refresher($fetcher, $repository)->refresh([self::MAIN]);

        self::assertSame(
            [
                self::MAIN => 'The list\'s IPv4 ranges shrank from 12,288 addresses to 48 addresses; the previous '
                    . 'list is kept. Refresh with --force to accept it.',
            ],
            $result->getRejected()
        );
        self::assertSame([], $repository->saved);
        self::assertSame($previous, $repository->find(self::MAIN));
    }

    /**
     * An IPv6 list that narrowed is held back, its coverage named as the prefix that would cover as much
     *
     * @return void
     */
    public function testAnIpv6ListThatNarrowedIsHeldBackWithItsCoverageAsAPrefix(): void
    {
        $repository = new InMemoryRangeSnapshotRepository();
        $this->seedPrefixes($repository, self::MAIN, ['2001:db8::/32', '192.0.2.0/24']);
        $fetcher = new ScriptedRangeListFetcher([self::MAIN_URL => ['2001:db8::/40', '192.0.2.0/24']]);

        $result = $this->refresher($fetcher, $repository)->refresh([self::MAIN]);

        self::assertSame(
            [
                self::MAIN => 'The list\'s IPv6 ranges shrank from the space of a /32.0 to the space of a /40.0; the '
                    . 'previous list is kept. Refresh with --force to accept it.',
            ],
            $result->getRejected()
        );
        self::assertSame([], $repository->saved);
    }

    /**
     * An IPv6 list that kept exactly half of its address space is stored
     *
     * @return void
     */
    public function testAnIpv6ListThatKeptHalfItsSpaceIsStored(): void
    {
        $repository = new InMemoryRangeSnapshotRepository();
        $this->seedPrefixes($repository, self::MAIN, ['2001:db8::/32']);
        $fetcher = new ScriptedRangeListFetcher([self::MAIN_URL => ['2001:db8::/33']]);

        $result = $this->refresher($fetcher, $repository)->refresh([self::MAIN]);

        self::assertSame([self::MAIN => 1], $result->getRefreshed());
        self::assertSame([self::MAIN], $repository->saved);
    }

    /**
     * A family the stored list never held cannot shrink, and a family the new list adds is growth
     *
     * @return void
     */
    public function testAListThatAddsAFamilyIsStored(): void
    {
        $repository = new InMemoryRangeSnapshotRepository();
        $this->seedPrefixes($repository, self::MAIN, ['192.0.2.0/24']);
        $fetcher = new ScriptedRangeListFetcher([self::MAIN_URL => ['192.0.2.0/24', '2001:db8::/128']]);

        $result = $this->refresher($fetcher, $repository)->refresh([self::MAIN]);

        self::assertSame([self::MAIN => 2], $result->getRefreshed());
    }

    /**
     * A shrink to a single address is named in the singular
     *
     * @return void
     */
    public function testAShrinkToOneAddressIsNamedInTheSingular(): void
    {
        $repository = new InMemoryRangeSnapshotRepository();
        $this->seed($repository, self::MAIN, 3);
        $fetcher = new ScriptedRangeListFetcher([self::MAIN_URL => self::prefixes(1)]);

        $result = $this->refresher($fetcher, $repository)->refresh([self::MAIN]);

        self::assertSame(
            [
                self::MAIN => 'The list\'s IPv4 ranges shrank from 3 addresses to 1 address; the previous list is '
                    . 'kept. Refresh with --force to accept it.',
            ],
            $result->getRejected()
        );
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
     * A failure to store a list is not a rejection: it ends the refresh, naming the list, chaining the storage's
     * exception and carrying what the refresh did before it - the rejection of an earlier list included
     *
     * @return void
     */
    public function testAStorageFailureEndsTheRefreshAndKeepsWhatWasRecordedBeforeIt(): void
    {
        $failure = new \RuntimeException('The storage is unavailable.');
        $repository = new SaveFailingRangeSnapshotRepository(self::EXTRA, $failure);
        $fetcher = new ScriptedRangeListFetcher([
            self::MAIN_URL => new RangeListRejectedException(__('The list could not be fetched.')),
            self::EXTRA_URL => ['203.0.113.0/27'],
            self::EDGE_URL => ['198.51.100.0/24'],
        ]);

        $caught = null;
        try {
            $this->refresher($fetcher, $repository)->refresh();
        } catch (RangeListStorageException $exception) {
            $caught = $exception;
        }

        self::assertNotNull($caught, 'A storage failure did not end the refresh with the storage exception.');
        self::assertSame($failure, $caught->getPrevious());
        self::assertSame(self::EXTRA, $caught->getListCode());
        self::assertSame(
            'The address list example_extra could not be stored and the refresh stopped: The storage is unavailable.',
            $caught->getMessage()
        );
        self::assertSame([self::MAIN => 'The list could not be fetched.'], $caught->getResult()->getRejected());
        self::assertSame([], $caught->getResult()->getRefreshed());
        self::assertSame([self::MAIN_URL, self::EXTRA_URL], array_column($fetcher->requests, 'url'));
        self::assertSame([], $repository->saved);
    }

    /**
     * The lists stored before a storage failure are carried as refreshed
     *
     * @return void
     */
    public function testAStorageFailureCarriesTheListsStoredBeforeIt(): void
    {
        $repository = new SaveFailingRangeSnapshotRepository(
            self::EDGE,
            new \RuntimeException('The storage is unavailable.')
        );
        $fetcher = new ScriptedRangeListFetcher([
            self::MAIN_URL => ['192.0.2.0/24'],
            self::EXTRA_URL => ['203.0.113.0/27', '2001:db8:4801::/64'],
            self::EDGE_URL => ['198.51.100.0/24'],
        ]);

        try {
            $this->refresher($fetcher, $repository)->refresh();
            self::fail('A storage failure did not end the refresh.');
        } catch (RangeListStorageException $exception) {
            self::assertSame([self::MAIN => 1, self::EXTRA => 2], $exception->getResult()->getRefreshed());
            self::assertSame([], $exception->getResult()->getRejected());
            self::assertSame(self::EDGE, $exception->getListCode());
        }
        self::assertSame([self::MAIN, self::EXTRA], $repository->saved);
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
        return $this->seedPrefixes($repository, $listCode, self::prefixes($ranges));
    }

    /**
     * Store a snapshot of the given prefixes for a list, as an earlier refresh would have
     *
     * @param InMemoryRangeSnapshotRepository $repository Where to store it
     * @param string $listCode Code of the list
     * @param list<string> $prefixes Prefixes the stored list was built from
     * @return RangeSnapshotInterface The stored snapshot
     */
    private function seedPrefixes(
        InMemoryRangeSnapshotRepository $repository,
        string $listCode,
        array $prefixes
    ): RangeSnapshotInterface {
        $snapshot = $this->builder()->build($prefixes, self::NOW - 21600);
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
