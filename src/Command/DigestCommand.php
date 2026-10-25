<?php

declare(strict_types=1);

namespace Acencyril\EchoBundle\Command;

use Acencyril\EchoBundle\Postman;
use Acencyril\EchoBundle\Settings;
use Acencyril\EchoBundle\Store;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One email each morning: yesterday's counters and its notable events. Nothing happened:
 * nothing is sent. Sent once per day, whatever runs it (cron, scheduler, twice by
 * mistake); --force sends again. Also deletes the notable events older than
 * retention_days.
 *
 *     0 8 * * * php bin/console echo:digest
 */
#[AsCommand(name: 'echo:digest', description: 'Email yesterday\'s counters and notable events to the owner (nothing when nothing happened)')]
final class DigestCommand extends Command
{
    /** The counter that marks a day as digested (names starting with "_" are never shown). */
    public const SENT = '_digest';

    private readonly ClockInterface $clock;

    public function __construct(
        private readonly Store $store,
        private readonly Postman $postman,
        private readonly Settings $settings,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('day', null, InputOption::VALUE_REQUIRED, 'The day to report (Y-m-d), default yesterday')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Send even if this day was already sent')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the email instead of sending it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tz = $this->settings->timezone;
        $now = $this->clock->now()->setTimezone($tz);
        $dayOption = $input->getOption('day');
        if (null !== $dayOption) {
            $start = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $dayOption, $tz);
            if (false === $start || $start->format('Y-m-d') !== $dayOption) {
                $output->writeln('<error>--day expects Y-m-d, e.g. 2026-10-24.</error>');

                return Command::INVALID;
            }
        } else {
            $start = $now->modify('yesterday');
        }
        $day = $start->format('Y-m-d');
        $dry = (bool) $input->getOption('dry-run');

        $this->purge($now, $output, $dry);

        if (!$dry && !$input->getOption('force') && $this->store->total($day, self::SENT) > 0) {
            $output->writeln(sprintf('Digest of %s already sent (--force to send again).', $day));

            return Command::SUCCESS;
        }

        $counters = $this->ordered($this->store->counters($day));
        $entries = $this->store->entries($start, $start->modify('+1 day'));
        if ([] === $counters && [] === $entries) {
            $output->writeln(sprintf('Nothing happened on %s: nothing sent.', $day));

            return Command::SUCCESS;
        }

        if ($dry) {
            [$subject, $lines] = $this->postman->digest($start, $counters, $entries);
            $output->writeln($subject);
            $output->writeln('');
            $output->writeln($lines, OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }

        if (!$this->postman->sendDigest($start, $counters, $entries)) {
            $output->writeln('<error>The digest could not be sent: see the log.</error>');

            return Command::FAILURE;
        }
        $this->store->count($day, self::SENT);
        $output->writeln(sprintf('Digest of %s sent: %d counter(s), %d notable event(s).', $day, \count($counters), \count($entries)));

        return Command::SUCCESS;
    }

    /** The configured events first, in the order of the configuration, then the others. */
    private function ordered(array $counters): array
    {
        $out = [];
        foreach ($this->settings->order() as $event) {
            if (isset($counters[$event])) {
                $out[$event] = $counters[$event];
            }
        }

        return $out + $counters;
    }

    private function purge(\DateTimeImmutable $now, OutputInterface $output, bool $dry): void
    {
        if ($dry) {
            return;
        }
        $days = (int) ($this->settings->config['retention_days'] ?? 30);
        $n = $this->store->purge($now->modify(sprintf('-%d days', $days)));
        if ($n > 0) {
            $output->writeln(sprintf('%d notable event(s) older than %d days deleted.', $n, $days), OutputInterface::VERBOSITY_VERBOSE);
        }
    }
}
