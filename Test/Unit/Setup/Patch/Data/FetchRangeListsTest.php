<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Unit\Setup\Patch\Data;

use Hryvinskyi\BotVerification\Api\RangeListRefresherInterface;
use Hryvinskyi\BotVerification\Api\RangeListRefresherInterfaceFactory;
use Hryvinskyi\BotVerification\Model\RefreshResult;
use Hryvinskyi\BotVerification\Setup\Patch\Data\FetchRangeLists;
use Hryvinskyi\BotVerification\Test\Double\RecordingLogger;
use Hryvinskyi\BotVerification\Test\Double\ScriptedRangeListRefresher;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * The installation fetches every declared list once and never fails the upgrade: what could not be fetched is a
 * warning left to the scheduled refresh
 */
class FetchRangeListsTest extends TestCase
{
    /**
     * Every declared list is asked for, without accepting a shrink, and a full success logs nothing
     *
     * @return void
     */
    public function testFetchesEveryDeclaredList(): void
    {
        $refresher = new ScriptedRangeListRefresher(new RefreshResult(['example_main' => 12], []));
        $logger = new RecordingLogger();

        (new FetchRangeLists($this->factoryOf($refresher), $logger))->apply();

        $this->assertSame([['listCodes' => [], 'acceptShrink' => false]], $refresher->calls);
        $this->assertSame([], $logger->records);
    }

    /**
     * A rejected list is one warning naming the list and the reason
     *
     * @return void
     */
    public function testWarnsOncePerRejectedList(): void
    {
        $refresher = new ScriptedRangeListRefresher(new RefreshResult(
            ['example_main' => 12],
            ['example_extra' => 'The list could not be fetched.']
        ));
        $logger = new RecordingLogger();

        (new FetchRangeLists($this->factoryOf($refresher), $logger))->apply();

        $this->assertCount(1, $logger->records);
        $this->assertSame(LogLevel::WARNING, $logger->records[0]['level']);
        $this->assertStringContainsString('"example_extra"', $logger->records[0]['message']);
        $this->assertStringContainsString('The list could not be fetched.', $logger->records[0]['message']);
    }

    /**
     * A refresh that fails outright, with a Magento exception, is a warning and not a failed upgrade
     *
     * @return void
     */
    public function testAFailedRefreshIsAWarningNotAFailedUpgrade(): void
    {
        $refresher = new ScriptedRangeListRefresher(new LocalizedException(new Phrase('Storage is unavailable.')));
        $logger = new RecordingLogger();

        (new FetchRangeLists($this->factoryOf($refresher), $logger))->apply();

        $this->assertCount(1, $logger->records);
        $this->assertSame(LogLevel::WARNING, $logger->records[0]['level']);
        $this->assertStringContainsString('Storage is unavailable.', $logger->records[0]['message']);
    }

    /**
     * In the upgrade that first enables the module its wiring is not read yet, so the refresher cannot be built: that is
     * a warning, not a failed upgrade
     *
     * @return void
     */
    public function testARefresherThatCannotBeBuiltIsAWarningNotAFailedUpgrade(): void
    {
        $factory = $this->createMock(RangeListRefresherInterfaceFactory::class);
        $factory->method('create')->willThrowException(new \BadMethodCallException(
            'Cannot instantiate interface Hryvinskyi\\BotVerification\\Api\\RangeListRefresherInterface'
        ));
        $logger = new RecordingLogger();

        (new FetchRangeLists($factory, $logger))->apply();

        $this->assertCount(1, $logger->records);
        $this->assertSame(LogLevel::WARNING, $logger->records[0]['level']);
        $this->assertStringContainsString('Cannot instantiate interface', $logger->records[0]['message']);
    }

    /**
     * Any other failure, such as an error while the refresher is built, does not fail the upgrade either
     *
     * @return void
     */
    public function testAnErrorIsAWarningNotAFailedUpgrade(): void
    {
        $refresher = $this->createMock(RangeListRefresherInterface::class);
        $refresher->method('refresh')->willThrowException(new \Error('Cannot instantiate interface'));
        $logger = new RecordingLogger();

        (new FetchRangeLists($this->factoryOf($refresher), $logger))->apply();

        $this->assertCount(1, $logger->records);
        $this->assertStringContainsString('Cannot instantiate interface', $logger->records[0]['message']);
    }

    /**
     * A factory that builds the given refresher
     *
     * @param RangeListRefresherInterface $refresher The refresher the patch is to use
     * @return RangeListRefresherInterfaceFactory
     */
    private function factoryOf(RangeListRefresherInterface $refresher): RangeListRefresherInterfaceFactory
    {
        $factory = $this->createMock(RangeListRefresherInterfaceFactory::class);
        $factory->method('create')->willReturn($refresher);

        return $factory;
    }
}
