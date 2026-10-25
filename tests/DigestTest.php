<?php

declare(strict_types=1);

namespace Acencyril\EchoBundle\Tests;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class DigestTest extends KernelTestCase
{
    private static function digest(array $options = []): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('echo:digest'));
        $tester->execute($options);

        return $tester;
    }

    /** Yesterday's events, then the next morning at 8:00. */
    private static function yesterday(callable $events, array $echo = []): void
    {
        $echo = self::app($echo);
        $events($echo);
        self::clock()->modify('2026-10-25 08:00:00');
    }

    private static function digests(): array
    {
        return array_values(array_filter(self::sent(), static fn ($m) => str_contains((string) $m->getTextBody(), 'Your day of') || str_contains((string) $m->getTextBody(), 'Votre journée du')));
    }

    public function testNothingHappenedNothingIsSent(): void
    {
        self::app();
        self::clock()->modify('2026-10-25 08:00:00');
        $tester = self::digest();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Nothing happened on 2026-10-24', $tester->getDisplay());
        self::assertCount(0, self::sent());
    }

    public function testTheDigestHasYesterdaysCountersAndNotableEvents(): void
    {
        self::yesterday(static function ($echo) {
            foreach (range(1, 3) as $i) {
                $echo->event('page_view', ['user_agent' => 'Mozilla/5.0']);
            }
            $echo->event('newsletter', ['email' => 'a@example.test']);
            self::clock()->modify('+2 hours');
            $echo->event('payment', ['amount' => 4900, 'email' => 'client@example.test']);
            $echo->event('payment', ['amount' => 100]); // below the minimum: counted only
            $echo->event('zebra');
        });
        $tester = self::digest();

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $mails = self::digests();
        self::assertCount(1, $mails);
        self::assertSame('[Example] Saturday, October 24, 2026: Payment 2 · Newsletter 1 · Page views 3', $mails[0]->getSubject());
        self::assertSame(<<<'TXT'
            Your day of Saturday, October 24, 2026

            Payment: 2
            Newsletter: 1
            Page views: 3
            Zebra: 1

            Notable events
            10:00  Newsletter — a@example.test
            12:00  Payment — client@example.test, €49.00

            --
            Admin: https://example.test/admin
            Echo, for Example. What you receive is set in config/packages/echo.yaml.

            TXT, $mails[0]->getTextBody());
    }

    public function testItIsSentOnceADay(): void
    {
        self::yesterday(static fn ($echo) => $echo->event('newsletter'));
        self::digest();
        $again = self::digest();

        self::assertStringContainsString('already sent', $again->getDisplay());
        self::assertCount(1, self::digests());

        self::digest(['--force' => true]);
        self::assertCount(2, self::digests());
    }

    public function testEventsNotEmailedAtTheTimeAreFlagged(): void
    {
        self::yesterday(static function ($echo) {
            foreach (range(1, 5) as $i) {
                $echo->event('signup', ['email' => "u$i@example.test"]);
            }
        });
        self::digest();

        $text = self::digests()[0]->getTextBody();
        self::assertStringContainsString("Sign-up: 5\n", $text);
        self::assertSame(5, substr_count($text, '  Sign-up — u'));
        self::assertStringContainsString('2 of them not emailed at the time (quiet hours or hourly limit).', $text);
    }

    public function testLongListsAreCut(): void
    {
        self::yesterday(static function ($echo) {
            foreach (range(1, 55) as $i) {
                $echo->event('newsletter', ['n' => $i]);
            }
        });
        self::digest();

        $text = self::digests()[0]->getTextBody();
        self::assertSame(50, substr_count($text, '  Newsletter — n: '));
        self::assertStringContainsString('… and 5 more.', $text);
    }

    public function testInFrench(): void
    {
        self::yesterday(static function ($echo) {
            $echo->event('payment', ['amount' => 123456, 'email' => 'client@example.test']);
        }, ['locale' => 'fr']);
        self::digest();

        $mail = self::digests()[0];
        self::assertSame('[Example] samedi 24 octobre 2026 : Paiement 1', $mail->getSubject());
        self::assertStringContainsString("Votre journée du samedi 24 octobre 2026\n\nPaiement : 1\n", $mail->getTextBody());
        self::assertStringContainsString('10:00  Paiement — client@example.test, 1 234,56 €', $mail->getTextBody());
        self::assertStringContainsString('Administration : https://example.test/admin', $mail->getTextBody());
    }

    public function testAnotherDayAndADryRun(): void
    {
        self::yesterday(static fn ($echo) => $echo->event('newsletter', ['email' => 'a@example.test']));

        $dry = self::digest(['--day' => '2026-10-24', '--dry-run' => true]);
        self::assertStringContainsString('10:00  Newsletter — a@example.test', $dry->getDisplay());
        self::assertCount(0, self::sent());

        self::assertStringContainsString('Nothing happened on 2026-10-23', self::digest(['--day' => '2026-10-23'])->getDisplay());
        self::assertSame(2, self::digest(['--day' => '24/10/2026'])->getStatusCode());
    }

    public function testAFailedDigestIsReportedAndCanBeSentAgain(): void
    {
        self::app([], 'broken');
        self::getContainer()->get('echo')->event('newsletter');
        self::clock()->modify('2026-10-25 08:00:00');

        self::assertSame(1, self::digest()->getStatusCode());
        self::assertNotEmpty(self::logger()->errors());
        self::assertStringNotContainsString('already sent', self::digest()->getDisplay(), 'not marked as sent');
    }

    public function testOldNotableEventsArePurged(): void
    {
        self::app(['retention_days' => 7]);
        $echo = self::getContainer()->get('echo');
        self::clock()->modify('2026-10-01 10:00:00');
        $echo->event('newsletter');
        self::clock()->modify('2026-10-25 08:00:00');
        $echo->event('newsletter');
        self::digest();

        self::assertSame(1, (int) self::db()->fetchOne('SELECT COUNT(*) FROM echo_entry'));
        self::assertSame(1, (int) self::db()->fetchOne("SELECT total FROM echo_counter WHERE day = '2026-10-01'"), 'counters stay');
    }
}
