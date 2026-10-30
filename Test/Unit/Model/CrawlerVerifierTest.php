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
use Hryvinskyi\BotVerification\Api\Data\VerdictInterface;
use Hryvinskyi\BotVerification\Api\Data\VerdictInterfaceFactory;
use Hryvinskyi\BotVerification\Api\Data\VerdictStatus;
use Hryvinskyi\BotVerification\Model\Address\AddressParser;
use Hryvinskyi\BotVerification\Model\Crawler\CrawlerRegistry;
use Hryvinskyi\BotVerification\Model\Crawler\UserAgentClaimMatcher;
use Hryvinskyi\BotVerification\Model\CrawlerVerifier;
use Hryvinskyi\BotVerification\Model\Range\RangeSnapshot;
use Hryvinskyi\BotVerification\Model\Range\RangeSnapshotBuilder;
use Hryvinskyi\BotVerification\Model\Range\SnapshotFreshness;
use Hryvinskyi\BotVerification\Model\Verdict;
use Hryvinskyi\BotVerification\Test\Double\FixedTimeSource;
use Hryvinskyi\BotVerification\Test\Double\InMemoryRangeSnapshotRepository;
use PHPUnit\Framework\TestCase;

/**
 * A crawler vouches for an address in any of its lists, accuses it only with fresh, complete lists, at least one of
 * which publishes the address's family, and a public address, and is unverifiable otherwise; a User-Agent claiming
 * several crawlers gets the worst of their verdicts.
 *
 * Crawler "example" has two lists, "example_main" (203.0.113.0/26) and "example_extra" (198.51.100.0/24); crawler
 * "other" has one, "other_list" (192.0.2.0/24) unless a test seeds it differently. The documentation ranges are
 * public to the address parser.
 */
class CrawlerVerifierTest extends TestCase
{
    private const NOW = 1_700_000_000;
    private const MAX_AGE = 604800;
    private const EXAMPLE_UA = 'Mozilla/5.0 (compatible; ExampleBot/2.1; +http://bot.example/)';
    private const OTHER_UA = 'OtherBot/1.0 (+http://bot.example/other)';
    private const BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/129.0.0.0 Safari/537.36';
    private const IN_MAIN = '203.0.113.7';
    private const IN_EXTRA = '198.51.100.7';
    private const IN_OTHER = '192.0.2.7';
    private const NOWHERE = '203.0.113.200';

    /**
     * A User-Agent that claims no crawler is None, and no list is read
     *
     * @return void
     */
    public function testNoClaimIsNoneAndReadsNothing(): void
    {
        $snapshots = $this->seededRepository();

        $verdict = $this->verifier($snapshots)->verify(self::BROWSER_UA, self::NOWHERE);

        self::assertSame(VerdictStatus::None, $verdict->getStatus());
        self::assertNull($verdict->getCrawlerCode());
        self::assertSame([], $snapshots->asked);
    }

    /**
     * An address that is not a valid public one makes the claim unverifiable, without reading any list
     *
     * @dataProvider notPublicAddresses
     * @param string $clientIp Client address as the request reports it
     * @return void
     */
    public function testAnAddressThatIsNotPublicIsUnverifiable(string $clientIp): void
    {
        $snapshots = $this->seededRepository();

        $verdict = $this->verifier($snapshots)->verify(self::EXAMPLE_UA, $clientIp);

        self::assertVerdict(VerdictStatus::Unverifiable, 'example', $verdict);
        self::assertSame([], $snapshots->asked);
    }

    /**
     * Private, loopback, missing and unreadable addresses
     *
     * @return array<string, array{string}>
     */
    public static function notPublicAddresses(): array
    {
        return [
            'private' => ['10.0.0.1'],
            'loopback' => ['127.0.0.1'],
            'unknown' => [''],
            'unreadable' => ['not an address'],
        ];
    }

    /**
     * An address in a list of the claimed crawler is genuine
     *
     * @return void
     */
    public function testAnAddressInAListIsGenuine(): void
    {
        $verdict = $this->verifier($this->seededRepository())->verify(self::EXAMPLE_UA, self::IN_MAIN);

        self::assertVerdict(VerdictStatus::Genuine, 'example', $verdict);
    }

    /**
     * An address in the second list only is genuine
     *
     * @return void
     */
    public function testAnAddressInTheSecondListOnlyIsGenuine(): void
    {
        $verdict = $this->verifier($this->seededRepository())->verify(self::EXAMPLE_UA, self::IN_EXTRA);

        self::assertVerdict(VerdictStatus::Genuine, 'example', $verdict);
    }

    /**
     * A stale list still vouches for an address it holds
     *
     * @return void
     */
    public function testAStaleListStillVouches(): void
    {
        $snapshots = $this->seededRepository(['example_main' => ['203.0.113.0/26', 10 * self::MAX_AGE]]);

        $verdict = $this->verifier($snapshots)->verify(self::EXAMPLE_UA, self::IN_MAIN);

        self::assertVerdict(VerdictStatus::Genuine, 'example', $verdict);
    }

    /**
     * A public address in none of the crawler's fresh lists is an impostor
     *
     * @dataProvider addressesInNoList
     * @param string $clientIp Public address outside every list
     * @return void
     */
    public function testAnAddressInNoFreshListIsAnImpostor(string $clientIp): void
    {
        $verdict = $this->verifier($this->seededRepository())->verify(self::EXAMPLE_UA, $clientIp);

        self::assertVerdict(VerdictStatus::Impostor, 'example', $verdict);
    }

    /**
     * Public IPv4 addresses that no list holds
     *
     * @return array<string, array{string}>
     */
    public static function addressesInNoList(): array
    {
        return [
            'IPv4' => [self::NOWHERE],
            'IPv4 another crawler vouches for' => [self::IN_OTHER],
        ];
    }

    /**
     * An address of a family none of the crawler's fresh lists publishes cannot be accused: the crawler may crawl from
     * that family without its owner publishing it
     *
     * @return void
     */
    public function testAnAddressOfAFamilyNoListPublishesIsUnverifiable(): void
    {
        $verdict = $this->verifier($this->seededRepository())->verify(self::EXAMPLE_UA, '3fff::7');

        self::assertVerdict(VerdictStatus::Unverifiable, 'example', $verdict);
    }

    /**
     * An IPv6 address in no list is an impostor once a fresh list of the crawler publishes IPv6 ranges, even when its
     * other list publishes IPv4 only
     *
     * @return void
     */
    public function testAnIpv6AddressInNoListOfItsFamilyIsAnImpostor(): void
    {
        $snapshots = $this->seededRepository(['example_extra' => ['2001:db8:4801::/48', 0]]);

        $verdict = $this->verifier($snapshots)->verify(self::EXAMPLE_UA, '3fff::7');

        self::assertVerdict(VerdictStatus::Impostor, 'example', $verdict);
    }

    /**
     * A stale list that publishes only the other family neither vouches nor stops a fresh list of the address's family
     * from accusing
     *
     * @return void
     */
    public function testAStaleListOfAnotherFamilyDoesNotStopAnAccusation(): void
    {
        $snapshots = $this->seededRepository(['example_extra' => ['2001:db8:4801::/48', self::MAX_AGE + 1]]);

        $verdict = $this->verifier($snapshots)->verify(self::EXAMPLE_UA, self::NOWHERE);

        self::assertVerdict(VerdictStatus::Impostor, 'example', $verdict);
    }

    /**
     * With one of the crawler's lists never fetched, an address the other list lacks cannot be accused
     *
     * @return void
     */
    public function testAMissingListMakesAnUnlistedAddressUnverifiable(): void
    {
        $snapshots = $this->seededRepository(['example_main' => null]);

        $verdict = $this->verifier($snapshots)->verify(self::EXAMPLE_UA, self::NOWHERE);

        self::assertVerdict(VerdictStatus::Unverifiable, 'example', $verdict);
    }

    /**
     * With one of the crawler's lists never fetched, the other list still vouches
     *
     * @return void
     */
    public function testAMissingListDoesNotStopTheOtherFromVouching(): void
    {
        $snapshots = $this->seededRepository(['example_main' => null]);

        $verdict = $this->verifier($snapshots)->verify(self::EXAMPLE_UA, self::IN_EXTRA);

        self::assertVerdict(VerdictStatus::Genuine, 'example', $verdict);
    }

    /**
     * With one of the crawler's lists stale, an address in neither list cannot be accused
     *
     * @return void
     */
    public function testAStaleListMakesAnUnlistedAddressUnverifiable(): void
    {
        $snapshots = $this->seededRepository(['example_extra' => ['198.51.100.0/24', self::MAX_AGE + 1]]);

        $verdict = $this->verifier($snapshots)->verify(self::EXAMPLE_UA, self::NOWHERE);

        self::assertVerdict(VerdictStatus::Unverifiable, 'example', $verdict);
    }

    /**
     * A list exactly the maximum age old is still fresh enough to accuse
     *
     * @return void
     */
    public function testAListExactlyTheMaximumAgeOldStillAccuses(): void
    {
        $snapshots = $this->seededRepository([
            'example_main' => ['203.0.113.0/26', self::MAX_AGE],
            'example_extra' => ['198.51.100.0/24', self::MAX_AGE],
        ]);

        $verdict = $this->verifier($snapshots)->verify(self::EXAMPLE_UA, self::NOWHERE);

        self::assertVerdict(VerdictStatus::Impostor, 'example', $verdict);
    }

    /**
     * A crawler named first whose list vouches does not hide the accusation of a crawler named after it
     *
     * @return void
     */
    public function testASecondCrawlerThatAccusesMakesAnImpostor(): void
    {
        $verdict = $this->verifier($this->seededRepository())->verify(
            'OtherBot/1.0 (compatible; ExampleBot/2.1)',
            self::IN_OTHER
        );

        self::assertVerdict(VerdictStatus::Impostor, 'example', $verdict);
    }

    /**
     * When every claimed crawler vouches, the verdict is genuine and names the first claim
     *
     * @return void
     */
    public function testEveryCrawlerVouchingIsGenuineForTheFirstClaim(): void
    {
        $snapshots = $this->seededRepository(['other_list' => ['203.0.113.0/24', 0]]);

        $verdict = $this->verifier($snapshots)->verify('OtherBot/1.0 (compatible; ExampleBot/2.1)', self::IN_MAIN);

        self::assertVerdict(VerdictStatus::Genuine, 'other', $verdict);
    }

    /**
     * When one claimed crawler vouches and another cannot judge for a missing list, the verdict is unverifiable and
     * names the one that could not judge, whichever is named first
     *
     * @dataProvider vouchingAndMissing
     * @param string $userAgent User-Agent naming both crawlers
     * @return void
     */
    public function testACrawlerThatCannotJudgeMakesItUnverifiable(string $userAgent): void
    {
        $snapshots = $this->seededRepository(['example_main' => null]);

        $verdict = $this->verifier($snapshots)->verify($userAgent, self::IN_OTHER);

        self::assertVerdict(VerdictStatus::Unverifiable, 'example', $verdict);
    }

    /**
     * Both orders of naming the two crawlers
     *
     * @return array<string, array{string}>
     */
    public static function vouchingAndMissing(): array
    {
        return [
            'vouching crawler first' => ['OtherBot/1.0 (compatible; ExampleBot/2.1)'],
            'vouching crawler second' => ['ExampleBot/2.1 (compatible; OtherBot/1.0)'],
        ];
    }

    /**
     * A crawler that cannot judge does not hide the accusation of a crawler named after it
     *
     * @return void
     */
    public function testAnAccuserAfterAnUnverifiableCrawlerMakesAnImpostor(): void
    {
        $snapshots = $this->seededRepository(['other_list' => null]);

        $verdict = $this->verifier($snapshots)->verify('OtherBot/1.0 (compatible; ExampleBot/2.1)', self::NOWHERE);

        self::assertVerdict(VerdictStatus::Impostor, 'example', $verdict);
    }

    /**
     * The first crawler that accuses decides at once: the lists of crawlers named after it are not read
     *
     * @return void
     */
    public function testTheFirstAccuserStopsTheJudging(): void
    {
        $snapshots = $this->seededRepository();

        $verdict = $this->verifier($snapshots)->verify('ExampleBot/2.1 (compatible; OtherBot/1.0)', self::NOWHERE);

        self::assertVerdict(VerdictStatus::Impostor, 'example', $verdict);
        self::assertSame(['example_main', 'example_extra'], $snapshots->asked);
    }

    /**
     * A crawler judged on its own reads only its own lists
     *
     * @return void
     */
    public function testReadsOnlyTheClaimedCrawlersLists(): void
    {
        $snapshots = $this->seededRepository();

        $verdict = $this->verifier($snapshots)->verify(self::OTHER_UA, self::IN_OTHER);

        self::assertVerdict(VerdictStatus::Genuine, 'other', $verdict);
        self::assertSame(['other_list'], $snapshots->asked);
    }

    /**
     * Asserts the status and the crawler code of a verdict
     *
     * @param VerdictStatus $status Expected status
     * @param string $crawlerCode Expected crawler code
     * @param VerdictInterface $verdict Verdict to check
     * @return void
     */
    private static function assertVerdict(VerdictStatus $status, string $crawlerCode, VerdictInterface $verdict): void
    {
        self::assertSame($status, $verdict->getStatus());
        self::assertSame($crawlerCode, $verdict->getCrawlerCode());
    }

    /**
     * A repository holding fresh snapshots of the three lists, with some replaced or left out
     *
     * @param array<string, array{string, int}|null> $overrides List code => [prefix, age in seconds], or null for a
     *                                                          list never fetched
     * @return InMemoryRangeSnapshotRepository
     */
    private function seededRepository(array $overrides = []): InMemoryRangeSnapshotRepository
    {
        $lists = array_merge(
            [
                'example_main' => ['203.0.113.0/26', 0],
                'example_extra' => ['198.51.100.0/24', 0],
                'other_list' => ['192.0.2.0/24', 0],
            ],
            $overrides
        );

        $builder = new RangeSnapshotBuilder($this->snapshotFactory());
        $repository = new InMemoryRangeSnapshotRepository();
        foreach ($lists as $listCode => $list) {
            if ($list !== null) {
                $repository->save($listCode, $builder->build([$list[0]], self::NOW - $list[1]));
            }
        }

        return $repository;
    }

    /**
     * The verifier over two neutral crawlers, the given snapshots and a clock standing at NOW
     *
     * @param InMemoryRangeSnapshotRepository $snapshots Snapshots to judge against
     * @return CrawlerVerifier
     */
    private function verifier(InMemoryRangeSnapshotRepository $snapshots): CrawlerVerifier
    {
        $registry = new CrawlerRegistry(
            [
                'example' => ['tokens' => ['ExampleBot'], 'lists' => ['example_main', 'example_extra']],
                'other' => ['tokens' => ['OtherBot'], 'lists' => ['other_list']],
            ],
            [
                'example_main' => 'https://bot.example/main.json',
                'example_extra' => 'https://bot.example/extra.json',
                'other_list' => 'https://other.example/list.json',
            ]
        );

        return new CrawlerVerifier(
            $registry,
            new UserAgentClaimMatcher($registry),
            new AddressParser(),
            $snapshots,
            new SnapshotFreshness(new FixedTimeSource(self::NOW), self::MAX_AGE),
            $this->verdictFactory()
        );
    }

    /**
     * A verdict factory that builds the verdict from the arguments it is given, requiring the status
     *
     * @return VerdictInterfaceFactory
     */
    private function verdictFactory(): VerdictInterfaceFactory
    {
        $factory = $this->createMock(VerdictInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(
            static function (array $data): VerdictInterface {
                $status = $data['status'] ?? null;
                $crawlerCode = $data['crawlerCode'] ?? null;
                self::assertInstanceOf(VerdictStatus::class, $status, 'Every verdict is created with its status.');
                self::assertTrue($crawlerCode === null || is_string($crawlerCode));

                return new Verdict($status, $crawlerCode);
            }
        );

        return $factory;
    }

    /**
     * A snapshot factory that builds the snapshot from the arguments it is given
     *
     * @return RangeSnapshotInterfaceFactory
     */
    private function snapshotFactory(): RangeSnapshotInterfaceFactory
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

        return $factory;
    }
}
