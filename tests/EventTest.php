<?php

declare(strict_types=1);

namespace Acencyril\EchoBundle\Tests;

use Acencyril\EchoBundle\Store;

final class EventTest extends KernelTestCase
{
    private static function today(): string
    {
        return '2026-10-24';
    }

    private static function counter(string $event): int
    {
        return self::getContainer()->get(Store::class)->total(self::today(), $event);
    }

    private static function entries(): array
    {
        return self::db()->fetchAllAssociative('SELECT event, context, mailed FROM echo_entry ORDER BY created_at, id');
    }

    public function testTheSchemaComesFromTheEntities(): void
    {
        self::app();
        $tables = self::db()->createSchemaManager()->listTableNames();

        self::assertContains('echo_counter', $tables);
        self::assertContains('echo_entry', $tables);
    }

    public function testAnImmediateEventIsEmailedCountedAndKeptForTheDigest(): void
    {
        $echo = self::app();

        self::assertTrue($echo->event('signup', ['email' => 'jane@example.test', 'plan' => 'pro']));

        self::assertSame(1, self::counter('signup'));
        $mails = self::sent();
        self::assertCount(1, $mails);
        $mail = $mails[0];
        self::assertSame('[Example] Sign-up: jane@example.test, plan: pro', $mail->getSubject());
        self::assertSame('owner@example.test', $mail->getTo()[0]->getAddress());
        self::assertSame('noreply@example.test', $mail->getFrom()[0]->getAddress());
        self::assertSame('Example', $mail->getFrom()[0]->getName());
        self::assertSame('auto-generated', $mail->getHeaders()->getHeaderBody('Auto-Submitted'));
        self::assertNull($mail->getHtmlBody(), 'plain text only: no pixel, nothing remote');
        $text = $mail->getTextBody();
        self::assertStringContainsString("Sign-up\nSaturday, October 24, 2026, 10:00\n\nemail: jane@example.test\nplan: pro\n", $text);
        self::assertStringContainsString('Admin: https://example.test/admin', $text);

        $entries = self::entries();
        self::assertCount(1, $entries);
        self::assertSame('signup', $entries[0]['event']);
        self::assertTrue((bool) $entries[0]['mailed']);
        self::assertSame(['email' => 'jane@example.test', 'plan' => 'pro'], json_decode($entries[0]['context'], true));
    }

    public function testADigestEventIsCountedAndKeptButNotEmailed(): void
    {
        $echo = self::app();
        $echo->event('newsletter', ['email' => 'a@example.test']);

        self::assertSame(1, self::counter('newsletter'));
        self::assertCount(0, self::sent());
        self::assertCount(1, self::entries());
        self::assertFalse((bool) self::entries()[0]['mailed']);
    }

    public function testACountEventIsOnlyCountedAndAnIgnoredOneNotAtAll(): void
    {
        $echo = self::app();
        $echo->event('page_view', ['user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)']);
        $echo->event('page_view', ['user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)']);
        self::assertFalse($echo->event('healthcheck'));

        self::assertSame(2, self::counter('page_view'));
        self::assertSame(0, self::counter('healthcheck'));
        self::assertSame([], self::entries());
        self::assertCount(0, self::sent());
    }

    public function testAnUnlistedEventFollowsTheDefaultMode(): void
    {
        $echo = self::app();
        $echo->event('quote_request', ['email' => 'b@example.test']);
        self::assertSame(1, self::counter('quote_request'));
        self::assertSame([], self::entries());

        $echo = self::app(['default_mode' => 'immediate']);
        $echo->event('quote_request', ['email' => 'b@example.test']);
        self::assertSame('[Example] Quote request: b@example.test', self::sent()[0]->getSubject(), 'label from the application\'s translations');
    }

    public function testBotsAreNotCountedForEventsThatSkipThem(): void
    {
        $echo = self::app();
        foreach (['Googlebot/2.1 (+http://www.google.com/bot.html)', 'curl/8.5.0', 'facebookexternalhit/1.1', 'UptimeRobot/2.0', '', 'Mozilla/5.0 (X11; Linux x86_64) HeadlessChrome/120.0'] as $ua) {
            self::assertFalse($echo->event('page_view', ['user_agent' => $ua]), $ua);
        }
        self::assertFalse($echo->event('page_view'), 'no request, no user agent: not a person');
        self::assertSame(0, self::counter('page_view'));

        // An event that does not skip bots is counted whoever sends it.
        self::assertTrue($echo->event('newsletter', ['user_agent' => 'curl/8.5.0']));
        self::assertArrayNotHasKey('user_agent', json_decode(self::entries()[0]['context'], true));
    }

    public function testTheUserAgentIsReadFromTheCurrentRequest(): void
    {
        $echo = self::app();
        $requests = self::getContainer()->get('request_stack');
        $requests->push(\Symfony\Component\HttpFoundation\Request::create('/', server: ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0)']));
        self::assertTrue($echo->event('page_view'));
        $requests->push(\Symfony\Component\HttpFoundation\Request::create('/', server: ['HTTP_USER_AGENT' => 'Bingbot/2.0']));
        self::assertFalse($echo->event('page_view'));
        self::assertSame(1, self::counter('page_view'));
    }

    public function testAPaymentBelowTheMinimumIsCountedButNeitherEmailedNorListed(): void
    {
        $echo = self::app();
        $echo->event('payment', ['amount' => 100, 'email' => 'me@example.test']);
        $echo->event('payment', ['email' => 'no-amount@example.test']);
        self::assertSame(2, self::counter('payment'));
        self::assertCount(0, self::sent());
        self::assertSame([], self::entries());

        $echo->event('payment', ['amount' => 4900, 'currency' => 'EUR', 'email' => 'client@example.test']);
        self::assertSame('[Example] Payment: client@example.test, €49.00', self::sent()[0]->getSubject());
        self::assertStringContainsString("amount: €49.00\n", self::sent()[0]->getTextBody());
        self::assertStringNotContainsString('currency', self::sent()[0]->getTextBody());
    }

    public function testImmediateEmailsAreThrottledPerHourAndPerEvent(): void
    {
        $echo = self::app();
        for ($i = 1; $i <= 5; ++$i) {
            $echo->event('signup', ['email' => "u$i@example.test"]);
        }
        self::assertCount(3, self::sent(), 'throttle: 3 an hour');
        self::assertSame(5, self::counter('signup'));
        self::assertSame([1, 1, 1, 0, 0], array_map(static fn ($e) => (int) $e['mailed'], self::entries()));

        // Another event has its own allowance.
        $echo->event('payment', ['amount' => 2000]);
        self::assertCount(4, self::sent());

        // An hour later, the allowance is back.
        self::clock()->modify('+61 minutes');
        $echo->event('signup', ['email' => 'u6@example.test']);
        self::assertCount(5, self::sent());
    }

    public function testThePerEventThrottleOverridesTheGlobalOne(): void
    {
        $echo = self::app(['events' => ['signup' => ['mode' => 'immediate', 'throttle' => 1]]]);
        $echo->event('signup');
        $echo->event('signup');
        self::assertCount(1, self::sent());
    }

    public function testNothingIsEmailedInQuietHours(): void
    {
        $echo = self::app(['quiet_hours' => ['from' => '09:00', 'to' => '18:00']]);
        $echo->event('signup', ['email' => 'night@example.test']);
        self::assertCount(0, self::sent());
        self::assertFalse((bool) self::entries()[0]['mailed'], 'kept for the digest');

        self::clock()->modify('+8 hours'); // 18:00
        $echo->event('signup', ['email' => 'evening@example.test']);
        self::assertCount(1, self::sent());

        $echo = self::app(['quiet_hours' => ['weekends' => true, 'from' => '22:00', 'to' => '07:00']]);
        $echo->event('signup');
        self::assertCount(0, self::sent(), 'Saturday');
    }

    public function testAMailFailureIsLoggedNotThrown(): void
    {
        $echo = self::app([], 'broken');
        self::assertTrue($echo->event('signup', ['email' => 'jane@example.test']));

        self::assertSame(1, self::counter('signup'));
        self::assertFalse((bool) self::entries()[0]['mailed'], 'the digest will tell');
        self::assertCount(1, self::logger()->errors());
        self::assertStringContainsString('could not be sent', self::logger()->errors()[0]);
    }

    public function testADatabaseFailureIsLoggedNotThrown(): void
    {
        $echo = self::app();
        self::db()->executeStatement('DROP TABLE echo_counter');

        self::assertFalse($echo->event('signup'));
        self::assertStringContainsString('event "signup" lost', self::logger()->errors()[0]);
    }

    public function testAnInvalidNameIsRefusedQuietly(): void
    {
        $echo = self::app();
        self::assertFalse($echo->event('Sign Up'));
        self::assertFalse($echo->event(''));
        self::assertSame([], self::db()->fetchAllAssociative('SELECT * FROM echo_counter'));
    }

    public function testTheContextIsKeptScalarAndShort(): void
    {
        $echo = self::app();
        $echo->event('newsletter', [
            'email' => 'a@example.test',
            'at' => new \DateTimeImmutable('2026-10-24 09:30'),
            'object' => new \stdClass(),
            'list' => [1, 2],
            'long' => str_repeat('x', 900),
            'ok' => true,
        ]);
        $context = json_decode(self::entries()[0]['context'], true);

        self::assertSame(['email', 'at', 'long', 'ok'], array_keys($context));
        self::assertSame('2026-10-24 09:30', $context['at']);
        self::assertSame(500, mb_strlen($context['long']));
    }

    public function testCountersAreKeptPerDayInTheConfiguredTimezone(): void
    {
        $echo = self::app();
        self::clock()->modify('2026-10-24 23:30:00'); // Paris, 21:30 UTC
        $echo->event('page_view', ['user_agent' => 'Mozilla/5.0']);
        self::clock()->modify('+1 hour'); // 00:30 Paris, still the 24th in UTC
        $echo->event('page_view', ['user_agent' => 'Mozilla/5.0']);

        $store = self::getContainer()->get(Store::class);
        self::assertSame(1, $store->total('2026-10-24', 'page_view'));
        self::assertSame(1, $store->total('2026-10-25', 'page_view'));
    }
}
