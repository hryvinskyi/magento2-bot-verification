<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Integration\Console\Command;

use Hryvinskyi\BotVerification\Api\Data\RefreshResultInterface;
use Hryvinskyi\BotVerification\Api\Data\RefreshResultInterfaceFactory;
use Hryvinskyi\BotVerification\Console\Command\RefreshCommand;
use Hryvinskyi\BotVerification\Test\Double\ScriptedRangeListRefresher;
use Magento\Framework\Exception\LocalizedException;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `bot-verification:refresh` as the container builds it, over a refresher scripted in place of the network
 */
class RefreshCommandTest extends TestCase
{
    /**
     * Each refreshed list is printed with its ranges and each rejected one with its reason, and a rejection makes the
     * command fail
     *
     * @return void
     */
    public function testPrintsEveryListAndFailsWhenOneIsRejected(): void
    {
        $refresher = new ScriptedRangeListRefresher(self::result(
            ['example_main' => 12],
            ['example_extra' => 'The list shrank from 5 to 2 ranges; the previous list is kept. Refresh with --force '
                . 'to accept it.']
        ));
        $tester = self::tester($refresher);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame(
            "example_main: 12 ranges\n"
            . "example_extra: rejected — The list shrank from 5 to 2 ranges; the previous list is kept. Refresh with "
            . "--force to accept it.\n",
            $tester->getDisplay()
        );
        self::assertSame([['listCodes' => [], 'acceptShrink' => false]], $refresher->calls);
    }

    /**
     * A refresh that rejects nothing succeeds
     *
     * @return void
     */
    public function testSucceedsWhenEveryListIsRefreshed(): void
    {
        $tester = self::tester(new ScriptedRangeListRefresher(self::result(
            ['example_main' => 12, 'example_extra' => 3],
            []
        )));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame("example_main: 12 ranges\nexample_extra: 3 ranges\n", $tester->getDisplay());
    }

    /**
     * A rejected list is printed as an error
     *
     * @return void
     */
    public function testARejectedListIsPrintedAsAnError(): void
    {
        $tester = self::tester(new ScriptedRangeListRefresher(self::result([], ['example_extra' => 'Unreachable.'])));

        $tester->execute([], ['decorated' => true]);

        self::assertStringContainsString(
            self::decorated('<error>example_extra: rejected — Unreachable.</error>'),
            $tester->getDisplay()
        );
    }

    /**
     * A reason that reads like console markup is printed as written, never interpreted
     *
     * @return void
     */
    public function testAReasonIsPrintedAsWritten(): void
    {
        $tester = self::tester(new ScriptedRangeListRefresher(self::result(
            [],
            ['example_extra' => 'Unexpected <info>body</info>.']
        )));

        $tester->execute([]);

        self::assertSame("example_extra: rejected — Unexpected <info>body</info>.\n", $tester->getDisplay());
    }

    /**
     * A list code that is not declared ends the command with the refresher's message, as an error, and a failure
     *
     * @return void
     */
    public function testAnUndeclaredListIsAnError(): void
    {
        $refresher = new ScriptedRangeListRefresher(
            new LocalizedException(__('No address list is declared as %1; nothing was refreshed.', 'example_typo'))
        );
        $tester = self::tester($refresher);

        $exitCode = $tester->execute(['lists' => ['example_typo']], ['decorated' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame(
            self::decorated('<error>No address list is declared as example_typo; nothing was refreshed.</error>')
            . "\n",
            $tester->getDisplay()
        );
        self::assertSame([['listCodes' => ['example_typo'], 'acceptShrink' => false]], $refresher->calls);
    }

    /**
     * The named lists reach the refresher in the order given, and --force reaches it as accepting a shrink
     *
     * @return void
     */
    public function testNamedListsAndForceReachTheRefresher(): void
    {
        $refresher = new ScriptedRangeListRefresher(self::result(['example_extra' => 3, 'example_main' => 12], []));

        self::tester($refresher)->execute(['lists' => ['example_extra', 'example_main'], '--force' => true]);
        self::tester($refresher)->execute(['lists' => ['example_main']]);

        self::assertSame(
            [
                ['listCodes' => ['example_extra', 'example_main'], 'acceptShrink' => true],
                ['listCodes' => ['example_main'], 'acceptShrink' => false],
            ],
            $refresher->calls
        );
    }

    /**
     * A tester of the command as the container builds it, with the given refresher
     *
     * @param ScriptedRangeListRefresher $refresher Answers in place of the real refresh
     * @return CommandTester
     */
    private static function tester(ScriptedRangeListRefresher $refresher): CommandTester
    {
        $command = Bootstrap::getObjectManager()->create(RefreshCommand::class, ['refresher' => $refresher]);
        self::assertInstanceOf(RefreshCommand::class, $command);

        return new CommandTester($command);
    }

    /**
     * A refresh result built by the generated factory
     *
     * @param array<string, int> $refreshed List code => ranges stored
     * @param array<string, string> $rejected List code => why the list was rejected
     * @return RefreshResultInterface
     */
    private static function result(array $refreshed, array $rejected): RefreshResultInterface
    {
        $factory = Bootstrap::getObjectManager()->get(RefreshResultInterfaceFactory::class);
        self::assertInstanceOf(RefreshResultInterfaceFactory::class, $factory);

        return $factory->create(['refreshed' => $refreshed, 'rejected' => $rejected]);
    }

    /**
     * Console markup as a decorated console prints it
     *
     * @param string $markup Text with console style tags
     * @return string
     */
    private static function decorated(string $markup): string
    {
        return (new OutputFormatter(true))->format($markup) ?? '';
    }
}
