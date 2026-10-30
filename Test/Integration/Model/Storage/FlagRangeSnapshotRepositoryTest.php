<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Integration\Model\Storage;

use Hryvinskyi\BotVerification\Api\Data\RangeSnapshotInterface;
use Hryvinskyi\BotVerification\Api\RangeSnapshotBuilderInterface;
use Hryvinskyi\BotVerification\Model\Storage\FlagRangeSnapshotRepository;
use Magento\Framework\Flag;
use Magento\Framework\Flag\FlagResource;
use Magento\Framework\FlagManager;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The durable storage of snapshots in the real flag table: what is saved is found again, and a stored value that
 * cannot be read - including one that is not JSON at all - is treated as never stored, without an exception.
 *
 * @magentoDbIsolation enabled
 * @magentoCache bot_verification enabled
 */
class FlagRangeSnapshotRepositoryTest extends TestCase
{
    private const LIST_CODE = 'example_main';

    /**
     * Code of the flag row that holds the list: the stored format, which a rename would orphan
     */
    private const FLAG_CODE = 'hryvinskyi_bot_verification_example_main';

    private const FETCHED_AT = 1_700_000_000;

    /**
     * A saved snapshot is found with the same records and fetch moment
     *
     * @return void
     */
    public function testFindsWhatWasSaved(): void
    {
        $saved = self::build(['203.0.113.0/27', '198.51.100.0/24', '2001:db8:4801::/64'], self::FETCHED_AT);

        self::repository()->save(self::LIST_CODE, $saved);
        $found = self::repository()->find(self::LIST_CODE);

        self::assertNotNull($found);
        self::assertNotSame($saved, $found);
        self::assertSame($saved->getIpv4Ranges(), $found->getIpv4Ranges());
        self::assertSame($saved->getIpv6Ranges(), $found->getIpv6Ranges());
        self::assertSame(self::FETCHED_AT, $found->getFetchedAt());
        self::assertTrue($found->contains(self::packed('198.51.100.7')));
        self::assertTrue($found->contains(self::packed('2001:db8:4801::1')));
    }

    /**
     * A snapshot with an empty family is found with that family empty
     *
     * @return void
     */
    public function testFindsAnEmptyFamily(): void
    {
        self::repository()->save(self::LIST_CODE, self::build(['2001:db8:4801::/64'], self::FETCHED_AT));

        $found = self::repository()->find(self::LIST_CODE);

        self::assertNotNull($found);
        self::assertSame('', $found->getIpv4Ranges());
        self::assertSame(1, $found->countRanges());
    }

    /**
     * Saving a list again replaces what was stored
     *
     * @return void
     */
    public function testSavingAgainReplaces(): void
    {
        self::repository()->save(self::LIST_CODE, self::build(['192.0.2.0/24'], self::FETCHED_AT));
        self::repository()->save(self::LIST_CODE, self::build(['203.0.113.0/27'], self::FETCHED_AT + 3600));

        $found = self::repository()->find(self::LIST_CODE);

        self::assertNotNull($found);
        self::assertSame(self::FETCHED_AT + 3600, $found->getFetchedAt());
        self::assertFalse($found->contains(self::packed('192.0.2.1')));
        self::assertTrue($found->contains(self::packed('203.0.113.1')));
    }

    /**
     * A list that was never stored is not found
     *
     * @return void
     */
    public function testAListNeverStoredIsNotFound(): void
    {
        self::assertNull(self::repository()->find('example_never_stored'));
    }

    /**
     * A stored value of the wrong shape is treated as never stored
     *
     * @dataProvider unreadableValues
     * @param array<mixed>|string|int|null $value Value stored in the list's flag
     * @return void
     */
    public function testAnUnreadableValueIsNotFound(array|string|int|null $value): void
    {
        self::get(FlagManager::class)->saveFlag(self::FLAG_CODE, $value);

        self::assertNull(self::repository()->find(self::LIST_CODE));
    }

    /**
     * Values a flag can hold that are not a stored snapshot
     *
     * @return array<string, array{array<mixed>|string|int|null}>
     */
    public static function unreadableValues(): array
    {
        $ipv4Record = base64_encode(str_repeat("\x01", 8));

        return [
            'null' => [null],
            'a string' => ['ranges'],
            'a number' => [42],
            'a list' => [['a', 'b', 'c']],
            'no fetch moment' => [['ipv4' => $ipv4Record, 'ipv6' => '']],
            'fetch moment as text' => [['ipv4' => $ipv4Record, 'ipv6' => '', 'fetched_at' => '1700000000']],
            'fetch moment zero' => [['ipv4' => $ipv4Record, 'ipv6' => '', 'fetched_at' => 0]],
            'no IPv6 family' => [['ipv4' => $ipv4Record, 'fetched_at' => self::FETCHED_AT]],
            'IPv6 family not text' => [['ipv4' => $ipv4Record, 'ipv6' => 5, 'fetched_at' => self::FETCHED_AT]],
            'IPv4 family not base64' => [['ipv4' => '***', 'ipv6' => '', 'fetched_at' => self::FETCHED_AT]],
            'IPv4 family not whole records' => [[
                'ipv4' => base64_encode(str_repeat("\x01", 7)),
                'ipv6' => '',
                'fetched_at' => self::FETCHED_AT,
            ]],
            'IPv6 family not whole records' => [[
                'ipv4' => $ipv4Record,
                'ipv6' => base64_encode(str_repeat("\x01", 31)),
                'fetched_at' => self::FETCHED_AT,
            ]],
        ];
    }

    /**
     * A stored value that is not JSON - which the flag table itself fails to read - is treated as never stored, and
     * reading it throws nothing
     *
     * @return void
     */
    public function testAValueThatIsNotJsonIsNotFound(): void
    {
        $flag = Bootstrap::getObjectManager()->create(Flag::class, ['data' => ['flag_code' => self::FLAG_CODE]]);
        self::assertInstanceOf(Flag::class, $flag);
        $flag->setData('flag_data', '{not json');
        self::get(FlagResource::class)->save($flag);

        $unreadable = null;
        try {
            self::get(FlagManager::class)->getFlagData(self::FLAG_CODE);
        } catch (\Exception $exception) {
            $unreadable = $exception;
        }
        self::assertNotNull(
            $unreadable,
            'The flag table read a value that is not JSON, so this test no longer proves the repository survives it.'
        );

        self::assertNull(self::repository()->find(self::LIST_CODE));
    }

    /**
     * A fresh repository as the container builds it
     *
     * @return FlagRangeSnapshotRepository
     */
    private static function repository(): FlagRangeSnapshotRepository
    {
        $repository = Bootstrap::getObjectManager()->create(FlagRangeSnapshotRepository::class);
        self::assertInstanceOf(FlagRangeSnapshotRepository::class, $repository);

        return $repository;
    }

    /**
     * Snapshot of the given prefixes, built by the container's snapshot builder
     *
     * @param list<string> $prefixes CIDR prefixes
     * @param int $fetchedAt Moment the list was fetched
     * @return RangeSnapshotInterface
     */
    private static function build(array $prefixes, int $fetchedAt): RangeSnapshotInterface
    {
        return self::get(RangeSnapshotBuilderInterface::class)->build($prefixes, $fetchedAt);
    }

    /**
     * The shared instance of a type, as the container builds it
     *
     * @template T of object
     * @param class-string<T> $type
     * @return T
     */
    private static function get(string $type): object
    {
        $instance = Bootstrap::getObjectManager()->get($type);
        self::assertInstanceOf($type, $instance);

        return $instance;
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
