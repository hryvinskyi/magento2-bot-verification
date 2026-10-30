<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Console\Command;

use Hryvinskyi\BotVerification\Api\Data\RefreshResultInterface;
use Hryvinskyi\BotVerification\Api\RangeListRefresherInterface;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bot-verification:refresh` - fetch the published address lists now, all of them or the ones named
 *
 * Prints the ranges stored per refreshed list and the reason per rejected list. Exits non-zero when any list was
 * rejected or a named list is not declared, so a script running it notices a list that keeps its previous snapshot.
 */
class RefreshCommand extends Command
{
    public const COMMAND_NAME = 'bot-verification:refresh';

    /**
     * Codes of the lists to refresh
     */
    public const ARGUMENT_LISTS = 'lists';

    /**
     * Store a list even when it lost more than half of its ranges
     */
    public const OPTION_FORCE = 'force';

    /**
     * @param RangeListRefresherInterface $refresher Brings the stored snapshots up to date
     */
    public function __construct(
        private readonly RangeListRefresherInterface $refresher
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAME)
            ->setDescription('Fetch the published address lists now')
            ->addArgument(
                self::ARGUMENT_LISTS,
                InputArgument::OPTIONAL | InputArgument::IS_ARRAY,
                'List codes to refresh; all when omitted'
            )
            ->addOption(
                self::OPTION_FORCE,
                null,
                InputOption::VALUE_NONE,
                'Store a list even when it lost more than half of its ranges since the last refresh'
            );

        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = $this->refresher->refresh(
                $this->requestedLists($input),
                $input->getOption(self::OPTION_FORCE) === true
            );
        } catch (LocalizedException $exception) {
            $output->writeln(sprintf('<error>%s</error>', OutputFormatter::escape($exception->getMessage())));

            return Command::FAILURE;
        }

        $this->render($output, $result);

        return $result->getRejected() === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * The list codes named on the command line
     *
     * @param InputInterface $input Console input
     * @return list<string> Empty when none is named
     */
    private function requestedLists(InputInterface $input): array
    {
        $value = $input->getArgument(self::ARGUMENT_LISTS);
        if (!is_array($value)) {
            return [];
        }

        $codes = [];
        foreach ($value as $code) {
            if (is_string($code)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * Print one line per refreshed list, then one per rejected list
     *
     * @param OutputInterface $output Console output
     * @param RefreshResultInterface $result What the refresh did with each list
     * @return void
     */
    private function render(OutputInterface $output, RefreshResultInterface $result): void
    {
        foreach ($result->getRefreshed() as $listCode => $ranges) {
            $output->writeln(sprintf('%s: %d ranges', OutputFormatter::escape($listCode), $ranges));
        }
        foreach ($result->getRejected() as $listCode => $reason) {
            $output->writeln(sprintf(
                '<error>%s: rejected — %s</error>',
                OutputFormatter::escape($listCode),
                OutputFormatter::escape($reason)
            ));
        }
    }
}
