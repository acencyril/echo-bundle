<?php

declare(strict_types=1);

namespace Acencyril\EchoBundle\Tests;

use Acencyril\EchoBundle\OwnerEcho;
use Acencyril\EchoBundle\Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    private static function at(string $local): \DateTimeImmutable
    {
        return new \DateTimeImmutable($local, new \DateTimeZone('Europe/Paris'));
    }

    public static function windows(): iterable
    {
        // [from, to, weekends, local time, quiet?]
        yield 'overnight, late' => ['20:00', '08:00', false, '2026-10-21 22:15', true];
        yield 'overnight, early' => ['20:00', '08:00', false, '2026-10-21 07:59', true];
        yield 'overnight, the end is not quiet' => ['20:00', '08:00', false, '2026-10-21 08:00', false];
        yield 'overnight, the start is quiet' => ['20:00', '08:00', false, '2026-10-21 20:00', true];
        yield 'overnight, daytime' => ['20:00', '08:00', false, '2026-10-21 14:00', false];
        yield 'lunch' => ['12:00', '14:00', false, '2026-10-21 13:00', true];
        yield 'after lunch' => ['12:00', '14:00', false, '2026-10-21 14:30', false];
        yield 'same bounds: never' => ['09:00', '09:00', false, '2026-10-21 09:00', false];
        yield 'saturday' => ['20:00', '08:00', true, '2026-10-24 14:00', true];
        yield 'sunday' => ['20:00', '08:00', true, '2026-10-25 14:00', true];
        yield 'monday' => ['20:00', '08:00', true, '2026-10-26 14:00', false];
    }

    #[DataProvider('windows')]
    public function testQuietHours(string $from, string $to, bool $weekends, string $local, bool $quiet): void
    {
        $s = new Settings(['timezone' => 'Europe/Paris', 'quiet_hours' => ['enabled' => true, 'from' => $from, 'to' => $to, 'weekends' => $weekends]]);
        self::assertSame($quiet, $s->isQuiet(self::at($local)));
    }

    public function testQuietHoursAreReadInTheConfiguredTimezone(): void
    {
        $s = new Settings(['timezone' => 'America/New_York', 'quiet_hours' => ['enabled' => true, 'from' => '20:00', 'to' => '08:00', 'weekends' => false]]);
        // 14:00 in Paris is 08:00 in New York: no longer quiet there.
        self::assertFalse($s->isQuiet(self::at('2026-10-21 14:00')));
        self::assertTrue($s->isQuiet(self::at('2026-10-21 13:59')));
    }

    public function testNoQuietHoursByDefault(): void
    {
        self::assertFalse((new Settings([]))->isQuiet(self::at('2026-10-24 03:00')));
    }

    public function testEventRulesFallBackOnTheDefaultMode(): void
    {
        $s = new Settings(['default_mode' => 'digest', 'throttle' => 7, 'events' => ['payment' => ['mode' => 'immediate', 'throttle' => 2, 'label' => null, 'min_amount' => 500, 'skip_bots' => false]]]);

        self::assertSame('digest', $s->event('other')['mode']);
        self::assertSame(7, $s->throttle('other'));
        self::assertSame(2, $s->throttle('payment'));
        self::assertSame(500, $s->event('payment')['min_amount']);
        self::assertSame(['payment'], $s->order());
    }

    public function testBots(): void
    {
        foreach (['Googlebot/2.1', 'Mozilla/5.0 (compatible; bingbot/2.0)', 'python-requests/2.31', 'Go-http-client/1.1', 'Slackbot-LinkExpanding 1.0', 'WhatsApp/2.23 preview', 'Mozilla/5.0 (compatible; Pingdom.com_bot)', '', null] as $ua) {
            self::assertTrue(OwnerEcho::isBot($ua), (string) $ua);
        }
        foreach (['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36', 'Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0'] as $ua) {
            self::assertFalse(OwnerEcho::isBot($ua), $ua);
        }
    }

    public function testNames(): void
    {
        foreach (['signup', 'page_view', 'checkout.started', 'plan-2', '404'] as $n) {
            self::assertTrue(OwnerEcho::isValidName($n), $n);
        }
        foreach (['', 'Signup', 'sign up', '_digest', str_repeat('a', 65), 'é'] as $n) {
            self::assertFalse(OwnerEcho::isValidName($n), $n);
        }
    }
}
