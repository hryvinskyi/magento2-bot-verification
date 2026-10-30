<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Integration\Model;

use Hryvinskyi\BotVerification\Api\AddressParserInterface;
use Hryvinskyi\BotVerification\Api\Data\VerdictInterfaceFactory;
use Hryvinskyi\BotVerification\Api\Data\VerdictStatus;
use Hryvinskyi\BotVerification\Api\TimeSourceInterface;
use Hryvinskyi\BotVerification\Model\Address\AddressParser;
use Hryvinskyi\BotVerification\Model\SystemTimeSource;
use Hryvinskyi\BotVerification\Model\Verdict;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The contracts of the verdict and the address, as the container fills them.
 */
class ContractWiringTest extends TestCase
{
    /**
     * The generated verdict factory builds a verdict with the given status and code
     *
     * @return void
     */
    public function testVerdictFactoryBuildsAVerdict(): void
    {
        $factory = self::get(VerdictInterfaceFactory::class);

        $genuine = $factory->create(['status' => VerdictStatus::Genuine, 'crawlerCode' => 'examplebot']);
        $none = $factory->create(['status' => VerdictStatus::None]);

        self::assertInstanceOf(Verdict::class, $genuine);
        self::assertTrue($genuine->isGenuine());
        self::assertSame('examplebot', $genuine->getCrawlerCode());
        self::assertSame(VerdictStatus::None, $none->getStatus());
        self::assertNull($none->getCrawlerCode());
        self::assertNotSame($genuine, $none);
    }

    /**
     * The address contract is filled by the address parser
     *
     * @return void
     */
    public function testAddressParserIsWired(): void
    {
        $parser = self::get(AddressParserInterface::class);

        self::assertInstanceOf(AddressParser::class, $parser);
        self::assertSame(inet_pton('203.0.113.7'), $parser->toPublicBinary('203.0.113.7'));
        self::assertNull($parser->toPublicBinary('10.0.0.1'));
    }

    /**
     * The time source contract is filled by the system clock
     *
     * @return void
     */
    public function testTimeSourceIsTheSystemClock(): void
    {
        $timeSource = self::get(TimeSourceInterface::class);

        self::assertInstanceOf(SystemTimeSource::class, $timeSource);
        self::assertEqualsWithDelta(time(), $timeSource->now(), 5);
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
}
