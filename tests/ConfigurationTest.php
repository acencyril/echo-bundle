<?php

declare(strict_types=1);

namespace Acencyril\EchoBundle\Tests;

use Acencyril\EchoBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    private const MINIMAL = ['owner' => 'me@example.test', 'sender' => 'noreply@example.test', 'site_name' => 'Example'];

    public static function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }

    public function testDefaults(): void
    {
        $c = self::process(self::MINIMAL);

        self::assertSame('en', $c['locale']);
        self::assertSame('count', $c['default_mode']);
        self::assertSame(10, $c['throttle']);
        self::assertSame(30, $c['retention_days']);
        self::assertFalse($c['quiet_hours']['enabled']);
        self::assertSame([], $c['events']);
    }

    public function testAnEventCanBeJustItsMode(): void
    {
        $c = self::process(self::MINIMAL + ['events' => ['signup' => 'immediate', 'page-view' => ['skip_bots' => true]]]);

        self::assertSame(['mode' => 'immediate', 'label' => null, 'min_amount' => null, 'skip_bots' => false, 'throttle' => null], $c['events']['signup']);
        self::assertSame('count', $c['events']['page-view']['mode'], 'names are kept as written');
    }

    public function testQuietHoursEnabledByTheirKeys(): void
    {
        $c = self::process(self::MINIMAL + ['quiet_hours' => ['from' => '21:30']]);
        self::assertTrue($c['quiet_hours']['enabled']);
        self::assertSame('08:00', $c['quiet_hours']['to']);
    }

    public static function invalid(): iterable
    {
        yield 'no owner' => [['sender' => 'a@b.c', 'site_name' => 'x'], 'owner'];
        yield 'mode' => [self::MINIMAL + ['events' => ['signup' => 'loud']], 'loud'];
        yield 'name' => [self::MINIMAL + ['events' => ['Sign Up' => 'immediate']], 'Event names'];
        yield 'hour' => [self::MINIMAL + ['quiet_hours' => ['from' => '8h']], 'HH:MM'];
        yield 'timezone' => [self::MINIMAL + ['timezone' => 'Mars/Olympus'], 'Unknown timezone'];
        yield 'locale' => [self::MINIMAL + ['locale' => 'de'], 'de'];
        yield 'throttle' => [self::MINIMAL + ['throttle' => 0], 'throttle'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testInvalid(array $config, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($message, '/').'/');
        self::process($config);
    }
}
