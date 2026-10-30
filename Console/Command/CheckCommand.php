<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Console\Command;

use Hryvinskyi\BotVerification\Api\CrawlerVerifierInterface;
use Hryvinskyi\BotVerification\Api\Data\VerdictInterface;
use Hryvinskyi\BotVerification\Api\ForwardingChainResolverInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bot-verification:check` - the verdict a client address and User-Agent would get, judged against the snapshots
 * stored now
 *
 * It reads only stored snapshots, never the network, and always exits zero: the verdict is the answer, not a fault.
 *
 * With --forwarded-for the address argument is the connecting address of a request, and the client is resolved from
 * it and the given X-Forwarded-For chain the way the shop resolves a live request's. A shop whose admin requests take
 * another path than its storefront requests checks the storefront path this way, with both values copied from a
 * storefront request in the web-server log.
 */
class CheckCommand extends Command
{
    public const COMMAND_NAME = 'bot-verification:check';

    /**
     * The client address to judge
     */
    public const ARGUMENT_IP = 'ip';

    /**
     * The User-Agent whose claim is judged
     */
    public const ARGUMENT_USER_AGENT = 'user-agent';

    /**
     * The X-Forwarded-For chain to resolve the client from; the address argument is then the connecting address
     */
    public const OPTION_FORWARDED_FOR = 'forwarded-for';

    /**
     * @param CrawlerVerifierInterface $verifier Judges a User-Agent's claim against a client address
     * @param ForwardingChainResolverInterface $chainResolver Finds the client of a forwarding chain
     */
    public function __construct(
        private readonly CrawlerVerifierInterface $verifier,
        private readonly ForwardingChainResolverInterface $chainResolver
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAME)
            ->setDescription('Show the verdict for a client address and User-Agent')
            ->addArgument(self::ARGUMENT_IP, InputArgument::REQUIRED, 'Client address')
            ->addArgument(self::ARGUMENT_USER_AGENT, InputArgument::REQUIRED, 'User-Agent header')
            ->addOption(
                self::OPTION_FORWARDED_FOR,
                null,
                InputOption::VALUE_REQUIRED,
                'Resolve the client from this X-Forwarded-For chain; the ip argument is then the connecting address, '
                . 'REMOTE_ADDR'
            );

        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $clientAddress = $this->stringArgument($input, self::ARGUMENT_IP);
        $forwardedFor = $input->getOption(self::OPTION_FORWARDED_FOR);
        if (is_string($forwardedFor)) {
            $clientAddress = $this->resolveClient($output, $clientAddress, $forwardedFor);
        }

        $verdict = $this->verifier->verify($this->stringArgument($input, self::ARGUMENT_USER_AGENT), $clientAddress);
        $this->renderVerdict($output, $verdict);

        return Command::SUCCESS;
    }

    /**
     * Resolve and print the client of a forwarding chain
     *
     * @param OutputInterface $output Console output
     * @param string $remoteAddress Connecting address of the request
     * @param string $forwardedFor X-Forwarded-For header of the request
     * @return string The client address; '' when it cannot be determined, which no crawler can vouch for or accuse
     */
    private function resolveClient(OutputInterface $output, string $remoteAddress, string $forwardedFor): string
    {
        $clientAddress = $this->chainResolver->resolve($remoteAddress, $forwardedFor);
        $output->writeln(sprintf(
            'Client address: %s',
            OutputFormatter::escape($clientAddress ?? 'cannot be determined')
        ));

        return $clientAddress ?? '';
    }

    /**
     * An argument's value as text
     *
     * @param InputInterface $input Console input
     * @param string $name Argument name
     * @return string '' when the argument holds no text
     */
    private function stringArgument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);

        return is_string($value) ? $value : '';
    }

    /**
     * Print the verdict's status and the crawler it is about
     *
     * @param OutputInterface $output Console output
     * @param VerdictInterface $verdict Verdict to print
     * @return void
     */
    private function renderVerdict(OutputInterface $output, VerdictInterface $verdict): void
    {
        $output->writeln(sprintf('Verdict: %s', $verdict->getStatus()->value));
        $output->writeln(sprintf('Crawler: %s', OutputFormatter::escape($verdict->getCrawlerCode() ?? '-')));
    }
}
