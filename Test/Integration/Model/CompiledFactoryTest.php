<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Integration\Model;

use Hryvinskyi\BotVerification\Api\Data\VerdictStatus;
use Hryvinskyi\BotVerification\Model\Range\RangeSnapshot;
use Hryvinskyi\BotVerification\Model\RefreshResult;
use Hryvinskyi\BotVerification\Model\Verdict;
use Magento\Framework\ObjectManager\Config\Compiled as CompiledConfig;
use Magento\Framework\ObjectManager\ConfigInterface;
use Magento\Framework\ObjectManager\Factory\Compiled as CompiledFactory;
use Magento\Setup\Module\Di\Code\Reader\ClassReaderDecorator;
use Magento\Setup\Module\Di\Compiler\ArgumentsResolver;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The values the module builds through generated factories keep what they are given in production mode.
 *
 * Production mode builds objects with the compiled object manager, which takes a passed argument only when it is
 * set, and otherwise falls back to what the compilation recorded for the parameter: for an enum-typed parameter, a
 * shared instance of the enum, which cannot be instantiated; for a parameter whose default is null, null. The suite
 * itself builds objects with the developer factory, which behaves differently, so these tests compile the
 * constructor arguments the way the compilation does and build through the compiled factory.
 */
class CompiledFactoryTest extends TestCase
{
    /**
     * A verdict keeps the status and the crawler code it is given
     *
     * @return void
     */
    public function testVerdictKeepsStatusAndCode(): void
    {
        $verdict = $this->compiledFactory(Verdict::class)->create(Verdict::class, [
            'status' => VerdictStatus::Genuine,
            'crawlerCode' => 'example',
        ]);

        self::assertInstanceOf(Verdict::class, $verdict);
        self::assertSame(VerdictStatus::Genuine, $verdict->getStatus());
        self::assertSame('example', $verdict->getCrawlerCode());
    }

    /**
     * A None verdict given a null code keeps it null and is not refused
     *
     * @return void
     */
    public function testNoneVerdictKeepsANullCode(): void
    {
        $verdict = $this->compiledFactory(Verdict::class)->create(Verdict::class, [
            'status' => VerdictStatus::None,
            'crawlerCode' => null,
        ]);

        self::assertInstanceOf(Verdict::class, $verdict);
        self::assertSame(VerdictStatus::None, $verdict->getStatus());
        self::assertNull($verdict->getCrawlerCode());
    }

    /**
     * A snapshot given no IPv6 ranges keeps an empty IPv6 family beside the IPv4 records it is given
     *
     * @return void
     */
    public function testSnapshotKeepsAnEmptyFamily(): void
    {
        $ipv4Ranges = self::packed('192.0.2.0') . self::packed('192.0.2.255');

        $snapshot = $this->compiledFactory(RangeSnapshot::class)->create(RangeSnapshot::class, [
            'ipv4Ranges' => $ipv4Ranges,
            'ipv6Ranges' => '',
            'fetchedAt' => 1_700_000_000,
        ]);

        self::assertInstanceOf(RangeSnapshot::class, $snapshot);
        self::assertSame($ipv4Ranges, $snapshot->getIpv4Ranges());
        self::assertSame('', $snapshot->getIpv6Ranges());
        self::assertSame(1_700_000_000, $snapshot->getFetchedAt());
        self::assertSame(1, $snapshot->countRanges());
        self::assertTrue($snapshot->contains(self::packed('192.0.2.1')));
    }

    /**
     * A refresh result given no refreshed list keeps that empty beside the rejected list it is given
     *
     * @return void
     */
    public function testRefreshResultKeepsBothLists(): void
    {
        $result = $this->compiledFactory(RefreshResult::class)->create(RefreshResult::class, [
            'refreshed' => [],
            'rejected' => ['example_list' => 'reason'],
        ]);

        self::assertInstanceOf(RefreshResult::class, $result);
        self::assertSame([], $result->getRefreshed());
        self::assertSame(['example_list' => 'reason'], $result->getRejected());
    }

    /**
     * The compiled factory, holding the constructor arguments of one class as the compilation records them
     *
     * @param class-string $className Class to compile the arguments of
     * @return CompiledFactory
     */
    private function compiledFactory(string $className): CompiledFactory
    {
        $objectManager = Bootstrap::getObjectManager();
        $config = $objectManager->get(ConfigInterface::class);
        self::assertInstanceOf(ConfigInterface::class, $config);
        $classReader = $objectManager->get(ClassReaderDecorator::class);
        self::assertInstanceOf(ClassReaderDecorator::class, $classReader);
        $constructor = $classReader->getConstructor($className);
        self::assertNotNull($constructor, $className . ' declares no constructor to compile');
        $resolver = new ArgumentsResolver($config);

        return new CompiledFactory(new CompiledConfig([
            'arguments' => [$className => $resolver->getResolvedConstructorArguments($className, $constructor)],
        ]));
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
